<?php

namespace App\Http\Controllers\Portal;

use App\Enums\BriefStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\Brief;
use App\Models\BriefQuestion;
use App\Models\BriefRoom;
use App\Models\BriefSection;
use App\Rules\SafeUpload;
use App\Services\Brief\BriefService;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BriefController extends Controller
{
    use ResolvesClientProjects;

    /** `sanitiseAnswer()` uyğun gəlməyən cavabı bu nişanla qaytarır — `null` real cavabdır. */
    private const INVALID_ANSWER = "\0invalid";

    /** Mətn hədləri: əvvəl heç bir hədd yox idi, 200 000 simvol da qəbul olunurdu. */
    private const TEXT_MAX = 1000;

    private const TEXTAREA_MAX = 5000;

    private const LIST_MAX = 200;

    private const COMPOSITE_MAX = 20000;

    public function __construct(
        private readonly BriefService $briefs,
        private readonly ChatService $chat,
    ) {}

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

        // `progress` keşlənmiş sütundur. Kilidi açılmış brifdə o, göndəriş
        // anındakı 100%-i daşıya bilər (bu baq düzəldilib, amma ondan ƏVVƏL
        // yenidən açılmış briflər bazada həmin köhnə rəqəmlə qalıb). Xəritə
        // onsuz da burada hesablanır, ona görə fərq görünəndə sütunu bir dəfə
        // düzəldirik — müştəri «100% dolduruldu» yazısını boş bölmələrlə yan-yana
        // görməsin.
        if (! $brief->isLocked()) {
            $total = $map->sum(fn ($entry) => $entry['question_count']);
            $answered = $map->sum(fn ($entry) => $entry['answered_count']);
            $actual = $total > 0 ? (int) round($answered / $total * 100) : 0;

            if ((int) $brief->progress !== $actual) {
                $this->briefs->recalculateProgress($brief);
                $brief->refresh();
            }
        }

        $openComments = $brief->openComments()->count();

        return view('portal.brief.index', compact('project', 'brief', 'map', 'openComments'));
    }

    public function section(int $project, BriefSection $section, ?BriefRoom $room = null)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);

        $this->assertSectionBelongsToBrief($brief, $section);
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

        // Roomix-in yapışqan alt paneli üçün: «Bölmə N / M», bu bölmənin faizi
        // və qonşu bölmələrə keçid. Sıra `sectionMap()`-dəkidir — otaq blokları
        // da daxil, yəni «İrəli» istifadəçini otaqların içindən də keçirir.
        $flat = $map->values();
        $index = $flat->search(
            fn (array $e) => $e['section']->id === $section->id && ($e['room']?->id) === ($room?->id)
        );
        $nav = [
            'position' => $index === false ? null : $index + 1,
            'total' => $flat->count(),
            'progress' => $index === false ? 0 : $flat[$index]['progress'],
            'prev' => $index > 0 ? $flat[$index - 1] : null,
            'next' => $index !== false && $index + 1 < $flat->count() ? $flat[$index + 1] : null,
        ];

        return view('portal.brief.section', compact('project', 'brief', 'section', 'room', 'answers', 'map', 'values', 'comments', 'editableQuestionIds', 'nav'));
    }

    /**
     * Roomix «Discuss with the designer» — bölmə səviyyəsində sual.
     *
     * Cavabı DƏYİŞMİR və brifi bloklamır: sadəcə layihə çatına bölməyə keçidli
     * mesaj atır. Söhbət brifin içində gizli qalmasın deyə məhz çata gedir —
     * dizayner onu digər mesajlarla bir yerdə görür.
     */
    public function discuss(Request $request, int $project, BriefSection $section)
    {
        $project = $this->clientProject($project);
        $brief = $this->briefs->forProject($project);
        $room = $this->resolveRoom($request, $brief);

        abort_if($room && $room->brief_id !== $brief->id, 404);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $title = $room?->label ?? $section->getTranslation('name', app()->getLocale());

        $body = t('portal.brief_discuss_message', ['section' => $title]);

        if (filled($validated['note'] ?? null)) {
            $body .= "\n\n".$validated['note'];
        }

        $body .= "\n".route('portal.brief.section', array_filter([$project->id, $section->id, $room?->id]));

        $this->chat->send($project, Auth::guard('customer')->user(), $body);

        return back()->with('status', t('portal.brief_discuss_sent'));
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
        $this->assertSectionBelongsToBrief($brief, $section);
        $room = $this->resolveRoom($request, $brief);

        $validated = $request->validate([
            'question_id' => ['required', 'integer'],
            'value' => ['nullable'],
            'delegated' => ['required', 'boolean'],
        ]);

        // Otaq bölməsinin cavabı otaqsız yazılsaydı, sətir `brief_room_id = null`
        // ilə yaranıb bütün otaqlara aid ÜMUMİ təbəqəyə düşürdü — `section()`
        // eyni yoxlamanı artıq edir, autosave isə etmirdi.
        abort_if($section->isRoomSection() && ! $room, 404);

        $question = $section->questions()->findOrFail($validated['question_id']);

        // Locked brief: only questions flagged for clarification may change (Screen 13).
        abort_unless($this->canEdit($brief, $question->id, $room), 403);

        // «Dizaynerin ixtiyarına» yalnız bankda bu güzəşt verilmiş suallarda
        // mümkündür. Yoxlama olmadan müştəri İSTƏNİLƏN məcburi sualı (ünvan,
        // əlaqə) boş qoyub həvalə kimi bağlaya, brifi isə tam sayıla bilərdi.
        if ($validated['delegated'] && ! $question->allows_designer_choice) {
            return response()->json(['ok' => false, 'error' => t('portal.brief_value_error')], 422);
        }

        $value = $this->sanitiseAnswer($question, $validated['value']);

        if ($value === self::INVALID_ANSWER) {
            return response()->json(['ok' => false, 'error' => t('portal.brief_value_error')], 422);
        }

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
            $this->briefs->syncRooms($brief, (array) ($value ?? []));
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
        $this->assertSectionBelongsToBrief($brief, $section);
        $room = $this->resolveRoom($request, $brief);

        abort_if($section->isRoomSection() && ! $room, 404);

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
        $this->assertSectionBelongsToBrief($brief, $section);
        $room = $this->resolveRoom($request, $brief);

        // Kilidli brifdə bölmə göndərmək cavabları dəyişmirdi, amma bölmənin
        // `submitted_at` damğasını yenidən yazır və menecerə hər dəfə yeni
        // «bölmə göndərildi» bildirişi göndərirdi.
        abort_if($brief->isLocked(), 403);

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

        // Roomix-dəki «Quick summary» kartı: müştəri göndərdikdən sonra nəyin
        // yola düşdüyünü bir baxışda görsün. Dəyərlər brifin öz cavablarındandır,
        // ona görə ayrıca saxlama lazım deyil.
        $values = $this->briefs->valuesByKey($brief);
        $questions = BriefQuestion::whereIn('key', ['object_type', 'style_preferences'])->get()->keyBy('key');

        $summary = array_filter([
            t('portal.brief_sum_object_type') => $questions->get('object_type')?->displayValue($values['object_type'] ?? null),
            t('portal.brief_sum_area') => filled($values['total_area_sqm'] ?? null) ? $values['total_area_sqm'].' m²' : null,
            t('portal.brief_sum_address') => $values['object_address'] ?? null,
            t('portal.brief_sum_styles') => (string) count((array) ($values['style_preferences'] ?? [])),
            t('portal.brief_sum_client') => $values['contact_full_name'] ?? null,
        ], fn ($v) => filled($v));

        return view('portal.brief.sent', compact('project', 'brief', 'openComments', 'summary'));
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

    /**
     * Bölmə marşrut bağlaması QLOBALDIR — `{section}` istənilən şablonun
     * bölməsini gətirə bilirdi. Yoxlama olmadan müştəri öz layihəsinin URL-inə
     * yad şablonun bölmə id-sini yazıb həmin bölməni açır və ora cavab yazırdı:
     * sətir bazada qalırdı, heç bir ekranda görünmürdü.
     */
    private function assertSectionBelongsToBrief(Brief $brief, BriefSection $section): void
    {
        abort_if($section->brief_template_id !== $brief->brief_template_id, 404);
    }

    /**
     * Cavabın sualın tipinə uyğunluğunu yoxlayır və uyğun gəlməyəndə
     * `INVALID_ANSWER` qaytarır.
     *
     * Əvvəl `value` sadəcə `nullable` idi — yəni brauzerə müdaxilə edən şəxs
     * (və ya səhv işləyən skript) istənilən sualın cavabına istənilən mətni,
     * massivi və ya variant siyahısında olmayan açarı yaza bilirdi. Belə dəyər
     * sonra olduğu kimi dizaynerin ekranına, brif PDF-inə və texniki tapşırığa
     * düşürdü — yəni baza da, sənəd də mənasız məlumatla dolurdu.
     *
     * Yoxlama `section.blade.php`-dəki `collect()` funksiyasının qaytardığı
     * formaları əks etdirir; tanınmayan tip üçün yalnız ölçü həddi tətbiq
     * olunur ki, yeni sual tipi əlavə edəndə bu metod sükutla maneə olmasın.
     */
    private function sanitiseAnswer(BriefQuestion $question, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return $value;
        }

        $allowed = $this->allowedOptionValues($question);
        $picked = fn ($item) => is_scalar($item)
            && ($allowed === [] || in_array((string) $item, $allowed, true));

        return match ($question->type) {
            'text' => is_string($value) && mb_strlen($value) <= self::TEXT_MAX ? $value : self::INVALID_ANSWER,
            'textarea' => is_string($value) && mb_strlen($value) <= self::TEXTAREA_MAX ? $value : self::INVALID_ANSWER,
            'number' => is_numeric($value) ? $value : self::INVALID_ANSWER,
            'date' => is_string($value) && strtotime($value) !== false ? $value : self::INVALID_ANSWER,
            'boolean', 'consent' => is_scalar($value) && in_array((string) $value, ['0', '1'], true)
                ? (string) $value
                : self::INVALID_ANSWER,
            'select', 'image_select' => $picked($value) ? (string) $value : self::INVALID_ANSWER,
            'multiselect', 'image_multiselect' => is_array($value)
                && array_is_list($value)
                && count($value) <= self::LIST_MAX
                && collect($value)->every($picked)
                    ? array_values(array_map('strval', $value))
                    : self::INVALID_ANSWER,
            // Açar — variantın özü, dəyər isə yalnız bəyənmə/bəyənməmədir.
            'image_rating' => is_array($value)
                && ! array_is_list($value)
                && collect($value)->keys()->every($picked)
                && collect($value)->every(fn ($v) => is_scalar($v) && in_array((string) $v, ['like', 'dislike'], true))
                    ? $value
                    : self::INVALID_ANSWER,
            default => $this->withinSizeLimit($value) ? $value : self::INVALID_ANSWER,
        };
    }

    /**
     * Sualın qəbul etdiyi variant açarları. Bankda variantlar ya düz siyahıdır,
     * ya da `std_or_custom`-da olduğu kimi `items` altındadır.
     *
     * @return list<string>
     */
    private function allowedOptionValues(BriefQuestion $question): array
    {
        $options = $question->options ?? [];
        $rows = is_array($options) && ! array_is_list($options)
            ? ($options['items'] ?? [])
            : $options;

        $values = [];

        foreach ((array) $rows as $option) {
            if (is_array($option) && isset($option['value']) && is_scalar($option['value'])) {
                $values[] = (string) $option['value'];
            }
        }

        // «Dizaynerin ixtiyarına» variant siyahısında yazılmır — onu skript
        // `designer` açarı kimi göndərir.
        if ($values !== [] && $question->allows_designer_choice) {
            $values[] = 'designer';
        }

        return $values;
    }

    /** Tərkibi sərbəst olan tiplərdə (matris, repeater, büdcə) yalnız ölçü həddi. */
    private function withinSizeLimit(mixed $value): bool
    {
        return mb_strlen((string) json_encode($value)) <= self::COMPOSITE_MAX;
    }
}
