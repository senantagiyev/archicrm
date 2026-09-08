<?php

namespace App\Services\Brief;

use App\Enums\DocumentType;
use App\Models\Brief;
use App\Models\BriefAnswer;
use App\Models\BriefQuestion;
use App\Models\BriefRoom;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Models\Document;
use App\Models\Project;
use App\Notifications\BriefCompleted;
use App\Notifications\BriefSectionSubmitted;
use App\Services\Automation\AutomationEngine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class BriefService
{
    public function forProject(Project $project): Brief
    {
        $brief = Brief::firstOrCreate(
            ['project_id' => $project->id],
            ['brief_template_id' => optional(BriefTemplate::default())->id],
        );

        // Legacy briefs created before templates existed → attach the default.
        if (! $brief->brief_template_id && ($default = BriefTemplate::default())) {
            $brief->forceFill(['brief_template_id' => $default->id])->save();
        }

        return $brief;
    }

    /**
     * Every answer keyed by its question key — the input for conditional logic
     * (spec Part 10). Rules cross section boundaries (e.g. §7 «Mühəndislik»
     * depends on §8 `room_inventory`), so the map is built brief-wide: general
     * answers first, then the current room's own answers on top.
     *
     * @return array<string, mixed>
     */
    public function valuesByKey(Brief $brief, ?BriefRoom $room = null): array
    {
        // Reuse the eager-loaded relation when the caller already primed it
        // (sectionMap does, so a 18-room brief stays at one query).
        $answers = $brief->relationLoaded('answers')
            ? $brief->answers
            : $brief->answers()->with('question')->get();

        $values = [];

        foreach ($answers->whereNull('brief_room_id') as $answer) {
            if ($answer->question) {
                $values[$answer->question->key] = $answer->delegated_to_designer ? null : $answer->value;
            }
        }

        if ($room) {
            foreach ($answers->where('brief_room_id', $room->id) as $answer) {
                if ($answer->question) {
                    $values[$answer->question->key] = $answer->delegated_to_designer ? null : $answer->value;
                }
            }
        }

        return $values;
    }

    /**
     * Dynamic Room Setup (spec Ə11 / Part 10 №18–19): the `room_inventory`
     * answer — {room_type: count} — is the single source of truth for which
     * room accordions exist. Surplus rooms are removed only while still empty,
     * so unchecking a box never silently destroys typed answers.
     *
     * @param  array<string, mixed>  $inventory
     */
    public function syncRooms(Brief $brief, array $inventory): void
    {
        $sections = BriefSection::where('active', true)
            ->whereNotNull('room_type')
            ->when($brief->brief_template_id, fn ($q, $id) => $q->where('brief_template_id', $id))
            ->get()
            ->keyBy('room_type');

        $brief->load('rooms');

        foreach ($sections as $roomType => $section) {
            $wanted = max(0, (int) ($inventory[$roomType] ?? 0));
            $existing = $brief->rooms->where('room_type', $roomType)->values();
            $name = $section->getTranslation('name', app()->getLocale());

            for ($i = $existing->count(); $i < $wanted; $i++) {
                $brief->rooms()->create([
                    'room_type' => $roomType,
                    'label' => $name,
                    'position' => ($brief->rooms()->max('position') ?? 0) + 1,
                ]);
            }

            // Trim from the end, but keep any room the client already filled in.
            for ($i = $existing->count() - 1; $i >= $wanted; $i--) {
                $room = $existing[$i];

                if ($brief->answers()->where('brief_room_id', $room->id)->doesntExist()) {
                    $room->delete();
                }
            }

            $this->renumberRooms($brief, $roomType, $name);
        }

        $brief->load('rooms');
    }

    /**
     * Spec Part 10 №19: several rooms of one type become "Uşaq otağı 1/2/…".
     * Numbering is recomputed from the current set (rather than assigned once at
     * creation) so adds, removals and out-of-order saves all converge. Labels the
     * client renamed by hand are left alone.
     */
    private function renumberRooms(Brief $brief, string $roomType, string $name): void
    {
        $rooms = $brief->rooms()->where('room_type', $roomType)->orderBy('position')->orderBy('id')->get();
        $multiple = $rooms->count() > 1;

        foreach ($rooms->values() as $index => $room) {
            $expected = $name.($multiple ? ' '.($index + 1) : '');

            if ($room->label !== $expected && str_starts_with($room->label, $name)) {
                $room->update(['label' => $expected]);
            }
        }
    }

    /**
     * The section map shown as brief navigation: every general section plus one
     * entry per added room, each with its fill % and state. Only questions that
     * conditional logic actually shows are counted.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function sectionMap(Brief $brief): Collection
    {
        // Scope to the brief's template; a template-less brief (legacy/tests) sees all.
        $sections = BriefSection::where('active', true)
            ->when($brief->brief_template_id, fn ($q, $id) => $q->where('brief_template_id', $id))
            ->orderBy('position')
            ->with('questions')
            ->get();

        $brief->load('answers.question');

        $answers = $brief->answers->groupBy(fn (BriefAnswer $a) => $a->brief_question_id.':'.($a->brief_room_id ?? 0));
        $states = $brief->sectionStates()->get()->keyBy(fn ($s) => $s->brief_section_id.':'.($s->brief_room_id ?? 0));
        $generalValues = $this->valuesByKey($brief);

        $map = collect();

        foreach ($sections as $section) {
            if ($section->isRoomSection()) {
                foreach ($brief->rooms->where('room_type', $section->room_type) as $room) {
                    $map->push($this->mapEntry($section, $room, $answers, $states, $this->valuesByKey($brief, $room)));
                }

                continue;
            }

            $map->push($this->mapEntry($section, null, $answers, $states, $generalValues));
        }

        return $map;
    }

    /**
     * Required questions that are visible but still unanswered (spec Screen 11).
     *
     * @return Collection<int, array{section: BriefSection, room: ?BriefRoom, question: BriefQuestion}>
     */
    public function missingRequired(Brief $brief): Collection
    {
        $answers = $brief->answers()->get()->groupBy(fn (BriefAnswer $a) => $a->brief_question_id.':'.($a->brief_room_id ?? 0));

        return $this->sectionMap($brief)->flatMap(function (array $entry) use ($answers) {
            $values = $entry['values'];

            return $entry['section']->questions
                ->filter(fn (BriefQuestion $q) => $q->is_required
                    && $q->shouldShow($values)
                    && ! ($answers->get($q->id.':'.($entry['room']->id ?? 0))?->first()?->isAnswered() ?? false))
                ->map(fn (BriefQuestion $q) => [
                    'section' => $entry['section'],
                    'room' => $entry['room'],
                    'question' => $q,
                ])
                ->values();
        });
    }

    /** Overall brief progress = answered share across all visible questions. */
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

        // Every section counts as submitted once the brief itself is sent.
        $this->sectionMap($brief->fresh(['rooms']))->each(function (array $entry) use ($brief) {
            $brief->sectionStates()->updateOrCreate(
                ['brief_section_id' => $entry['section']->id, 'brief_room_id' => $entry['room']?->id],
                ['status' => 'submitted', 'submitted_at' => now()],
            );
        });

        $document = $this->exportPdf($brief);

        $project = $brief->project;

        // Əlavə B rule 13: notify the team the brief is ready for review (toggleable).
        if ($project->manager && app(AutomationEngine::class)->isEnabled('rule-13')) {
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
            'risks' => app(BriefRiskDetector::class)->detect($brief),
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

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function mapEntry(BriefSection $section, ?BriefRoom $room, Collection $answers, Collection $states, array $values): array
    {
        $visible = $section->questions->filter(fn (BriefQuestion $q) => $q->shouldShow($values));

        $questionCount = $visible->count();
        $answeredCount = $visible
            ->filter(function (BriefQuestion $question) use ($answers, $room) {
                $answer = $answers->get($question->id.':'.($room->id ?? 0))?->first();

                return $answer?->isAnswered() ?? false;
            })
            ->count();

        $state = $states->get($section->id.':'.($room->id ?? 0));

        return [
            'section' => $section,
            'room' => $room,
            'values' => $values,
            'required_count' => $visible->where('is_required', true)->count(),
            'question_count' => $questionCount,
            'answered_count' => $answeredCount,
            'progress' => $questionCount > 0 ? (int) round($answeredCount / $questionCount * 100) : 0,
            'status' => $state?->status ?? ($answeredCount > 0 ? 'in_progress' : 'empty'),
        ];
    }
}
