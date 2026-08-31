<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\Brief;
use App\Models\BriefRoom;
use App\Models\BriefSection;
use App\Services\Brief\BriefService;
use Illuminate\Http\Request;

class BriefController extends Controller
{
    use ResolvesClientProjects;

    public function __construct(private readonly BriefService $briefs) {}

    /** Section map with per-section progress (TZ: proqres-naviqasiya). */
    public function index(int $project)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);
        $brief->load('rooms');

        $map = $this->briefs->sectionMap($brief);
        $roomSections = BriefSection::where('active', true)->whereNotNull('room_type')->orderBy('position')->get();

        return view('portal.brief.index', compact('project', 'brief', 'map', 'roomSections'));
    }

    public function addRoom(Request $request, int $project)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);

        $validated = $request->validate([
            'room_type' => ['required', 'exists:brief_sections,room_type'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $section = BriefSection::where('room_type', $validated['room_type'])->firstOrFail();
        $count = $brief->rooms()->where('room_type', $validated['room_type'])->count();

        $brief->rooms()->create([
            'room_type' => $validated['room_type'],
            'label' => $validated['label'] ?: $section->getTranslation('name', app()->getLocale()).($count ? ' '.($count + 1) : ''),
            'position' => ($brief->rooms()->max('position') ?? 0) + 1,
        ]);

        return back();
    }

    public function section(int $project, BriefSection $section, ?BriefRoom $room = null)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);

        abort_if($room && $room->brief_id !== $brief->id, 404);
        abort_if($section->isRoomSection() && ! $room, 404);

        $section->load('questions');

        $answers = $brief->answers()
            ->whereIn('brief_question_id', $section->questions->pluck('id'))
            ->where('brief_room_id', $room?->id)
            ->get()
            ->keyBy('brief_question_id');

        $map = $this->briefs->sectionMap($brief->load('rooms'));

        return view('portal.brief.section', compact('project', 'brief', 'section', 'room', 'answers', 'map'));
    }

    /** Debounced autosave from the wizard (one field per request). */
    public function autosave(Request $request, int $project, BriefSection $section)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);
        $room = $this->resolveRoom($request, $brief);

        abort_if($brief->isCompleted(), 403);

        $validated = $request->validate([
            'question_id' => ['required', 'integer'],
            'value' => ['nullable'],
            'delegated' => ['required', 'boolean'],
        ]);

        $question = $section->questions()->findOrFail($validated['question_id']);

        $brief->answers()->updateOrCreate(
            [
                'brief_question_id' => $question->id,
                'brief_room_id' => $room?->id,
            ],
            [
                'value' => $validated['delegated'] ? null : $validated['value'],
                'delegated_to_designer' => $validated['delegated'],
                'answered_at' => now(),
            ],
        );

        // Mark the section in progress (unless already submitted).
        $brief->sectionStates()->firstOrCreate(
            ['brief_section_id' => $section->id, 'brief_room_id' => $room?->id],
            ['status' => 'in_progress'],
        );

        $this->briefs->recalculateProgress($brief);

        return response()->json(['ok' => true, 'saved_at' => now()->format('H:i')]);
    }

    /** Per-section submit with required-question validation. */
    public function submit(Request $request, int $project, BriefSection $section)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);
        $room = $this->resolveRoom($request, $brief);
        $section->load('questions');

        $answers = $brief->answers()
            ->whereIn('brief_question_id', $section->questions->pluck('id'))
            ->where('brief_room_id', $room?->id)
            ->get()
            ->keyBy('brief_question_id');

        $missing = $section->questions
            ->filter(fn ($q) => $q->is_required && ! ($answers->get($q->id)?->isAnswered() ?? false));

        if ($missing->isNotEmpty()) {
            return back()->withErrors([
                'section' => t('portal.brief_required_missing', ['count' => $missing->count()]),
            ]);
        }

        $this->briefs->submitSection($brief, $section, $room);

        return redirect()
            ->route('portal.brief', $project)
            ->with('status', t('portal.brief_section_submitted'));
    }

    private function resolveRoom(Request $request, Brief $brief): ?BriefRoom
    {
        $roomId = $request->input('room_id');

        return $roomId ? $brief->rooms()->findOrFail($roomId) : null;
    }
}
