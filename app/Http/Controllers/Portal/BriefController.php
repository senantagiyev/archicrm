<?php

namespace App\Http\Controllers\Portal;

use App\Enums\BriefStatus;
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

        // Spec 13.2 №9: first open of a sent brief → in_progress.
        if ($brief->statusEnum() === BriefStatus::Sent) {
            $brief->forceFill(['status' => BriefStatus::InProgress->value])->save();
        }

        $brief->load('rooms');

        $map = $this->briefs->sectionMap($brief);
        $openComments = $brief->openComments()->count();

        return view('portal.brief.index', compact('project', 'brief', 'map', 'openComments'));
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

        // Screen 13: while clarification is pending only the flagged questions stay editable.
        $comments = $brief->needsClarification()
            ? $brief->openComments()->where('brief_room_id', $room?->id)->with('user')->get()->keyBy('brief_question_id')
            : collect();
        $editableQuestionIds = $brief->isLocked() ? $comments->keys()->all() : null;

        return view('portal.brief.section', compact('project', 'brief', 'section', 'room', 'answers', 'map', 'values', 'comments', 'editableQuestionIds'));
    }

    /** Whether the client may write this question right now (free edit, or flagged during clarification). */
    private function canEdit(Brief $brief, int $questionId, ?BriefRoom $room): bool
    {
        if (! $brief->isLocked()) {
            return true;
        }

        return $brief->needsClarification()
            && $brief->openComments()->where('brief_question_id', $questionId)->where('brief_room_id', $room?->id)->exists();
    }

    /** Debounced autosave from the wizard (one field per request). */
    public function autosave(Request $request, int $project, BriefSection $section)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);
        $room = $this->resolveRoom($request, $brief);

        $validated = $request->validate([
            'question_id' => ['required', 'integer'],
            'value' => ['nullable'],
            'delegated' => ['required', 'boolean'],
        ]);

        $question = $section->questions()->findOrFail($validated['question_id']);

        // Locked brief: only questions flagged for clarification may change (Screen 13).
        abort_unless($this->canEdit($brief, $question->id, $room), 403);

        $value = $validated['value'];

        // Part 10 №16 exclusive_override: «Dizaynerin ixtiyarına» cancels sibling picks.
        if (is_array($value) && array_is_list($value) && in_array('designer', $value, true) && count($value) > 1) {
            $value = ['designer'];
        }

        // Screen 02 inline rule: design area cannot exceed total area.
        if (in_array($question->key, ['design_area_sqm', 'total_area_sqm'], true) && ! $validated['delegated']) {
            $current = $this->briefs->valuesByKey($brief, $room);
            $total = (float) ($question->key === 'total_area_sqm' ? $value : ($current['total_area_sqm'] ?? 0));
            $design = (float) ($question->key === 'design_area_sqm' ? $value : ($current['design_area_sqm'] ?? 0));

            if ($total > 0 && $design > $total) {
                return response()->json(['ok' => false, 'error' => t('portal.brief_area_error')], 422);
            }
        }

        $brief->answers()->updateOrCreate(
            [
                'brief_question_id' => $question->id,
                'brief_room_id' => $room?->id,
            ],
            [
                'value' => $validated['delegated'] ? null : $value,
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

        $validated = $request->validate([
            'question_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', SafeUpload::document()],
        ]);

        $question = $section->questions()->findOrFail($validated['question_id']);
        abort_unless($question->type === 'file', 422);
        abort_unless($this->canEdit($brief, $question->id, $room), 403);

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
        $validationErrors = $this->briefs->validationErrors($brief);

        return view('portal.brief.summary', compact('project', 'brief', 'map', 'missing', 'values', 'consented', 'validationErrors'));
    }

    /** Screen 12 — confirmation; on deep-link reopen shows the current lifecycle state. */
    public function sent(int $project)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);

        if (! $brief->isLocked()) {
            return redirect()->route('portal.brief', $project);
        }

        $openComments = $brief->openComments()->count();

        return view('portal.brief.sent', compact('project', 'brief', 'openComments'));
    }

    /** Screen 13 — Needs Clarification: only the flagged questions are editable. */
    public function clarifications(int $project)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);

        $comments = $brief->openComments()->with(['question.section', 'room', 'user'])->latest()->get();

        return view('portal.brief.clarifications', compact('project', 'brief', 'comments'));
    }

    /** Screen 13 → v(n+1): resolves every open comment and returns the brief to submitted. */
    public function sendClarifications(int $project)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);

        abort_unless($brief->needsClarification(), 403);

        $this->briefs->answerClarifications($brief, auth('customer')->user());

        return redirect()
            ->route('portal.brief.sent', $project)
            ->with('status', t('portal.brief_clarifications_sent'));
    }

    /** Screen 11 → 12: whole-brief submit, blocked until Required + consent are in. */
    public function submitBrief(int $project)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);

        if ($brief->isLocked()) {
            return redirect()->route('portal.brief.sent', $project);
        }

        $missing = $this->briefs->missingRequired($brief);
        $consented = ($this->briefs->valuesByKey($brief)['pdpa_consent'] ?? null) === '1';
        $validationErrors = $this->briefs->validationErrors($brief);

        if ($missing->isNotEmpty() || ! $consented || $validationErrors !== []) {
            return back()->withErrors([
                'brief' => $validationErrors[0]
                    ?? ($consented
                        ? t('portal.brief_required_missing', ['count' => $missing->count()])
                        : t('portal.brief_consent_required')),
            ]);
        }

        $this->briefs->submit($brief, auth('customer')->user());

        return redirect()
            ->route('portal.brief.sent', $project)
            ->with('status', t('portal.brief_sent_success'));
    }

    private function resolveRoom(Request $request, Brief $brief): ?BriefRoom
    {
        $roomId = $request->input('room_id');

        return $roomId ? $brief->rooms()->findOrFail($roomId) : null;
    }
}
