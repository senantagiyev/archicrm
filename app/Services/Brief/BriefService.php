<?php

namespace App\Services\Brief;

use App\Enums\DocumentType;
use App\Models\Brief;
use App\Models\BriefAnswer;
use App\Models\BriefRoom;
use App\Models\BriefSection;
use App\Models\Document;
use App\Models\Project;
use App\Notifications\BriefCompleted;
use App\Notifications\BriefSectionSubmitted;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class BriefService
{
    public function forProject(Project $project): Brief
    {
        return Brief::firstOrCreate(['project_id' => $project->id]);
    }

    /**
     * The section map shown as brief navigation: every general section plus one
     * entry per added room, each with its fill % and state.
     *
     * @return Collection<int, array{section: BriefSection, room: ?BriefRoom, progress: int, status: string}>
     */
    public function sectionMap(Brief $brief): Collection
    {
        $sections = BriefSection::where('active', true)
            ->orderBy('position')
            ->with('questions')
            ->get();

        $answers = $brief->answers()->get()->groupBy(fn (BriefAnswer $a) => $a->brief_question_id.':'.($a->brief_room_id ?? 0));
        $states = $brief->sectionStates()->get()->keyBy(fn ($s) => $s->brief_section_id.':'.($s->brief_room_id ?? 0));

        $map = collect();

        foreach ($sections as $section) {
            if ($section->isRoomSection()) {
                foreach ($brief->rooms->where('room_type', $section->room_type) as $room) {
                    $map->push($this->mapEntry($section, $room, $answers, $states));
                }

                continue;
            }

            $map->push($this->mapEntry($section, null, $answers, $states));
        }

        return $map;
    }

    /** Overall brief progress = answered share across all mapped questions. */
    public function recalculateProgress(Brief $brief): void
    {
        $map = $this->sectionMap($brief);

        $total = $map->sum(fn ($entry) => $entry['question_count']);
        $answered = $map->sum(fn ($entry) => $entry['answered_count']);

        $progress = $total > 0 ? (int) round($answered / $total * 100) : 0;

        $brief->forceFill([
            'progress' => $progress,
            'status' => $brief->isCompleted() ? 'completed' : ($answered > 0 ? 'in_progress' : 'draft'),
        ])->save();
    }

    /**
     * Per-section submit (TZ: hissə-hissə göndərmə). Validates required
     * questions in the app layer; notifies the project designer/manager.
     */
    public function submitSection(Brief $brief, BriefSection $section, ?BriefRoom $room): void
    {
        $brief->sectionStates()->updateOrCreate(
            ['brief_section_id' => $section->id, 'brief_room_id' => $room?->id],
            ['status' => 'submitted', 'submitted_at' => now()],
        );

        $this->recalculateProgress($brief);

        $project = $brief->project;

        if ($project->manager) {
            $project->manager->notify(new BriefSectionSubmitted($brief, $section, $room));
        }

        // All sections submitted → the brief is completed.
        $map = $this->sectionMap($brief->fresh(['rooms']));

        if ($map->isNotEmpty() && $map->every(fn ($entry) => $entry['status'] === 'submitted')) {
            $this->complete($brief);
        }
    }

    public function complete(Brief $brief): void
    {
        if ($brief->isCompleted()) {
            return;
        }

        $brief->forceFill(['status' => 'completed', 'completed_at' => now(), 'progress' => 100])->save();

        $document = $this->exportPdf($brief);

        $project = $brief->project;

        if ($project->manager) {
            $project->manager->notify(new BriefCompleted($brief, $document));
        }
    }

    /** Render the whole brief to PDF and attach it to the project documents. */
    public function exportPdf(Brief $brief): Document
    {
        $map = $this->sectionMap($brief);
        $answers = $brief->answers()->with('question')->get();

        $pdf = Pdf::loadView('portal.brief.pdf', [
            'brief' => $brief,
            'project' => $brief->project,
            'map' => $map,
            'answers' => $answers,
        ]);

        $path = 'documents/brief-'.$brief->project_id.'-'.now()->format('YmdHis').'.pdf';
        Storage::disk('public')->put($path, $pdf->output());

        return $brief->project->documents()->create([
            'type' => DocumentType::BriefExport,
            'title' => 'Brif — '.$brief->project->name,
            'file_path' => $path,
            'mime' => 'application/pdf',
            'visible_to_client' => true,
        ]);
    }

    /** @return array{section: BriefSection, room: ?BriefRoom, question_count: int, answered_count: int, progress: int, status: string} */
    private function mapEntry(BriefSection $section, ?BriefRoom $room, Collection $answers, Collection $states): array
    {
        $questionCount = $section->questions->count();
        $answeredCount = $section->questions
            ->filter(function ($question) use ($answers, $room) {
                $answer = $answers->get($question->id.':'.($room->id ?? 0))?->first();

                return $answer?->isAnswered() ?? false;
            })
            ->count();

        $state = $states->get($section->id.':'.($room->id ?? 0));

        return [
            'section' => $section,
            'room' => $room,
            'question_count' => $questionCount,
            'answered_count' => $answeredCount,
            'progress' => $questionCount > 0 ? (int) round($answeredCount / $questionCount * 100) : 0,
            'status' => $state?->status ?? ($answeredCount > 0 ? 'in_progress' : 'empty'),
        ];
    }
}
