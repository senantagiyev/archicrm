<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\Brief;
use App\Models\BriefRoom;
use App\Models\BriefSection;
use App\Rules\SafeUpload;
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

        return view('portal.brief.index', compact('project', 'brief', 'map'));
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

        // Conditional logic (spec Part 10) crosses section boundaries, so the
        // wizard is seeded with the whole brief's answers, not just this page's.
        $values = $this->briefs->valuesByKey($brief, $room);

        return view('portal.brief.section', compact('project', 'brief', 'section', 'room', 'answers', 'map', 'values'));
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

        // Dynamic Room Setup (spec Ə11): the inventory answer materialises the
        // per-room accordions immediately, so the client sees them on return.
        if ($question->type === 'room_inventory') {
            $this->briefs->syncRooms($brief, (array) ($validated['value'] ?? []));
        }

        // Mark the section in progress (unless already submitted).
        $brief->sectionStates()->firstOrCreate(
            ['brief_section_id' => $section->id, 'brief_room_id' => $room?->id],
            ['status' => 'in_progress'],
        );

        $this->briefs->recalculateProgress($brief);

        return response()->json(['ok' => true, 'saved_at' => now()->format('H:i')]);
    }

    /** File answers (spec Ə2: obmer/BTİ planı) — stored, then referenced by value. */
    public function upload(Request $request, int $project, BriefSection $section)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);
        $room = $this->resolveRoom($request, $brief);

        abort_if($brief->isCompleted(), 403);

        $validated = $request->validate([
            'question_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', SafeUpload::document()],
        ]);

        $question = $section->questions()->findOrFail($validated['question_id']);
        abort_unless($question->type === 'file', 422);

        $file = $request->file('file');
        $path = $file->store('brief/'.$brief->id, 'public');

        $brief->answers()->updateOrCreate(
            ['brief_question_id' => $question->id, 'brief_room_id' => $room?->id],
            [
                'value' => ['path' => $path, 'name' => $file->getClientOriginalName()],
                'delegated_to_designer' => false,
                'answered_at' => now(),
            ],
        );

        $this->briefs->recalculateProgress($brief);

        return back()->with('status', t('portal.brief_file_uploaded'));
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

        $values = $this->briefs->valuesByKey($brief, $room);

        // A required question is only "missing" when it is actually shown (skip
        // logic satisfied) and neither answered nor delegated.
        $missing = $section->questions
            ->filter(fn ($q) => $q->is_required
                && $q->shouldShow($values)
                && ! ($answers->get($q->id)?->isAnswered() ?? false));

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

    /** Screen 11 — pre-submit review: key answers, missing required, conflicts. */
    public function summary(int $project)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);
        $brief->load('rooms');

        $map = $this->briefs->sectionMap($brief);
        $missing = $this->briefs->missingRequired($brief);
        $values = $this->briefs->valuesByKey($brief);

        // Consent is what gates the button (spec Part 10 №20) — it is also a
        // required question, so it is listed in $missing; the view needs it flagged.
        $consented = ($values['pdpa_consent'] ?? null) === '1';

        return view('portal.brief.summary', compact('project', 'brief', 'map', 'missing', 'values', 'consented'));
    }

    /** Screen 11 → 12: whole-brief submit, blocked until Required + consent are in. */
    public function submitBrief(int $project)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);

        if ($brief->isCompleted()) {
            return redirect()->route('portal.brief', $project);
        }

        $missing = $this->briefs->missingRequired($brief);
        $consented = ($this->briefs->valuesByKey($brief)['pdpa_consent'] ?? null) === '1';

        if ($missing->isNotEmpty() || ! $consented) {
            return back()->withErrors([
                'brief' => $consented
                    ? t('portal.brief_required_missing', ['count' => $missing->count()])
                    : t('portal.brief_consent_required'),
            ]);
        }

        $this->briefs->complete($brief);

        return redirect()
            ->route('portal.brief', $project)
            ->with('status', t('portal.brief_sent_success'));
    }

    private function resolveRoom(Request $request, Brief $brief): ?BriefRoom
    {
        $roomId = $request->input('room_id');

        return $roomId ? $brief->rooms()->findOrFail($roomId) : null;
    }
}
