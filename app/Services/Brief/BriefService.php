<?php

namespace App\Services\Brief;

use App\Enums\BriefStatus;
use App\Enums\DocumentType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Brief;
use App\Models\BriefAnswer;
use App\Models\BriefComment;
use App\Models\BriefQuestion;
use App\Models\BriefRoom;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Models\BriefVersion;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Project;
use App\Models\Stage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AutomationAlert;
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
    /** @var array<int, Collection<int, int>> question ids per template, memoised per request */
    private array $templateQuestionIds = [];

    /** @return Collection<int, int> question ids of one template, keyed for has() lookups */
    private function templateQuestionIds(int $templateId): Collection
    {
        return $this->templateQuestionIds[$templateId] ??= BriefQuestion::whereIn(
            'brief_section_id',
            BriefSection::where('brief_template_id', $templateId)->select('id')
        )->pluck('id')->flip();
    }

    /**
     * Spec Part 8.1 — moving a brief between the two levels (typically Quick →
     * Premium once the contract is signed). Answers are re-pointed by question
     * key, so everything the client already typed survives the upgrade; the old
     * rows are kept, because switching back must not lose anything either.
     */
    public function switchTemplate(Brief $brief, BriefTemplate $template): void
    {
        if ($brief->brief_template_id === $template->id) {
            return;
        }

        $targetQuestions = BriefQuestion::whereIn(
            'brief_section_id',
            BriefSection::where('brief_template_id', $template->id)->select('id')
        )->get()->keyBy('key');

        $brief->load('answers.question');

        foreach ($brief->answers as $answer) {
            $target = $answer->question ? $targetQuestions->get($answer->question->key) : null;

            if (! $target || $target->id === $answer->brief_question_id) {
                continue;
            }

            $brief->answers()->updateOrCreate(
                ['brief_question_id' => $target->id, 'brief_room_id' => $answer->brief_room_id],
                [
                    'value' => $answer->value,
                    'delegated_to_designer' => $answer->delegated_to_designer,
                    'answered_at' => $answer->answered_at ?? now(),
                ],
            );
        }

        $brief->forceFill(['brief_template_id' => $template->id])->save();

        // The room set belongs to the new template's room sections.
        $inventory = $this->valuesByKey($brief->fresh(['answers.question']))['room_inventory'] ?? [];
        $this->syncRooms($brief->fresh(), (array) $inventory);

        $this->recalculateProgress($brief->fresh());
    }

    public function valuesByKey(Brief $brief, ?BriefRoom $room = null): array
    {
        // Reuse the eager-loaded relation when the caller already primed it
        // (sectionMap does, so a 18-room brief stays at one query).
        $answers = $brief->relationLoaded('answers')
            ? $brief->answers
            : $brief->answers()->with('question')->get();

        // A brief that has moved between templates (Quick → Premium) still holds
        // the old template's rows. They share question keys with the new ones, so
        // without this filter a stale answer could shadow the current one.
        if ($brief->brief_template_id) {
            $current = $this->templateQuestionIds($brief->brief_template_id);

            $answers = $answers->filter(fn (BriefAnswer $a) => $current->has($a->brief_question_id));
        }

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

        // Locked briefs keep their lifecycle status; `sent` stays until the first answer.
        $status = $brief->statusEnum();
        $next = $status->isLocked()
            ? $status->value
            : ($answered > 0 ? BriefStatus::InProgress->value : ($status === BriefStatus::Sent ? BriefStatus::Sent->value : BriefStatus::Draft->value));

        $brief->forceFill(['progress' => $progress, 'status' => $next])->save();
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
            $this->submit($brief);
        }
    }

    /** Backward-compatible alias — the lifecycle verb is submit(). */
    public function complete(Brief $brief): void
    {
        $this->submit($brief);
    }

    /**
     * Screen 11 → 12 (spec 13.2 №1): status → submitted, immutable BriefVersion v1,
     * CRM field sync (13.3), post-submit automations (13.2 №2), PDF + team notice.
     */
    public function submit(Brief $brief, ?ClientUser $by = null): void
    {
        if ($brief->isLocked()) {
            return;
        }

        $brief->forceFill([
            'status' => BriefStatus::Submitted->value,
            'submitted_at' => now(),
            'completed_at' => $brief->completed_at ?? now(),
            'progress' => 100,
        ])->save();

        // Every section counts as submitted once the brief itself is sent.
        $this->sectionMap($brief->fresh(['rooms']))->each(function (array $entry) use ($brief) {
            $brief->sectionStates()->updateOrCreate(
                ['brief_section_id' => $entry['section']->id, 'brief_room_id' => $entry['room']?->id],
                ['status' => 'submitted', 'submitted_at' => now()],
            );
        });

        $this->createVersion($brief, $by, 'İlkin göndəriş');
        $this->syncToCrm($brief);
        $this->afterSubmitAutomations($brief);

        $document = $this->exportPdf($brief);

        $project = $brief->project;

        // Əlavə B rule 13: notify the team the brief is ready for review (toggleable).
        if ($project->manager && app(AutomationEngine::class)->isEnabled('rule-13')) {
            $project->manager->notify(new BriefCompleted($brief, $document));
        }
    }

    /** Spec 13.2 №5 / Screen 13: designer flags one question; the client may edit only that. */
    public function requestClarification(Brief $brief, BriefQuestion $question, ?BriefRoom $room, User $designer, string $body): BriefComment
    {
        $comment = $brief->comments()->create([
            'brief_question_id' => $question->id,
            'brief_room_id' => $room?->id,
            'user_id' => $designer->id,
            'body' => $body,
            'status' => 'open',
        ]);

        $brief->forceFill(['status' => BriefStatus::NeedsClarification->value])->save();

        $project = $brief->project()->with('client.clientUsers')->first();
        foreach ($project?->client?->clientUsers ?? [] as $clientUser) {
            $clientUser->notify(new AutomationAlert(
                'Brif üzrə dəqiqləşdirmə lazımdır',
                '«'.$question->getTranslation('label', 'az').'» sualı üzrə dizaynerin dəqiqləşdirmə sorğusu var.',
                route('portal.brief.clarifications', $project),
                ['brief_id' => $brief->id, 'project_id' => $brief->project_id, 'comment_id' => $comment->id],
                'brief-clarification',
            ));
        }

        return $comment;
    }

    /** Spec 13.2 №6: client answered → v(n+1), comments resolved, back to submitted. */
    public function answerClarifications(Brief $brief, ?ClientUser $by = null): void
    {
        if (! $brief->needsClarification()) {
            return;
        }

        $brief->openComments()->update(['status' => 'resolved', 'resolved_at' => now()]);
        $brief->forceFill(['status' => BriefStatus::Submitted->value])->save();

        $this->createVersion($brief, $by, 'Dəqiqləşdirmələr');

        $project = $brief->project;
        $project->manager?->notify(new AutomationAlert(
            'Brif dəqiqləşdirmələri cavablandı',
            $project->name.' — müştəri dəqiqləşdirmələri göndərdi, yeni versiya yaradıldı.',
            null,
            ['brief_id' => $brief->id, 'project_id' => $brief->project_id],
            'brief-clarified',
        ));
    }

    /** Spec 13.2 №7: baseline for design work; answers become read-only for the client. */
    public function approve(Brief $brief, User $designer): void
    {
        $brief->forceFill(['status' => BriefStatus::Approved->value, 'approved_at' => now()])->save();

        $project = $brief->project()->with('client.clientUsers')->first();
        foreach ($project?->client?->clientUsers ?? [] as $clientUser) {
            $clientUser->notify(new AutomationAlert(
                'Brif təsdiqləndi',
                '«'.$project->name.'» layihəsi üzrə brifiniz dizayner tərəfindən təsdiqləndi — layihələndirmə başlayır.',
                route('portal.brief.sent', $project),
                ['brief_id' => $brief->id, 'project_id' => $brief->project_id],
                'brief-approved',
            ));
        }
    }

    /** Immutable snapshot of every answer (general + per room). */
    public function createVersion(Brief $brief, ClientUser|User|null $by, ?string $note = null): BriefVersion
    {
        $version = $brief->versions()->create([
            'version' => (int) $brief->current_version + 1,
            'snapshot' => $this->snapshot($brief),
            'created_by_type' => $by ? $by->getMorphClass() : null,
            'created_by_id' => $by?->getKey(),
            'note' => $note,
            'created_at' => now(),
        ]);

        $brief->forceFill(['current_version' => $version->version])->save();

        return $version;
    }

    /** @return array{general: array<string, mixed>, rooms: array<int, array{label: string, answers: array<string, mixed>}>} */
    public function snapshot(Brief $brief): array
    {
        $out = ['general' => [], 'rooms' => []];
        $rooms = $brief->rooms()->get()->keyBy('id');

        foreach ($brief->answers()->with('question')->get() as $answer) {
            if (! $answer->question) {
                continue;
            }
            $entry = ['value' => $answer->value, 'delegated' => (bool) $answer->delegated_to_designer];

            if ($answer->brief_room_id) {
                $out['rooms'][$answer->brief_room_id]['label'] ??= $rooms[$answer->brief_room_id]->label ?? 'Otaq';
                $out['rooms'][$answer->brief_room_id]['answers'][$answer->question->key] = $entry;
            } else {
                $out['general'][$answer->question->key] = $entry;
            }
        }

        return $out;
    }

    /** Spec 13.3: contacts → client card, budget → project plan (BriefAnswer is the source of truth). */
    public function syncToCrm(Brief $brief): void
    {
        $v = $this->valuesByKey($brief);
        $project = $brief->project()->with('client')->first();

        if ($client = $project?->client) {
            $client->forceFill(array_filter([
                'phone' => blank($client->phone) ? ($v['contact_phone'] ?? null) : null,
                'email' => blank($client->email) ? ($v['contact_email'] ?? null) : null,
            ]))->save();
        }

        $max = (float) ($v['project_budget_range']['max'] ?? 0);
        if ($project && $max > 0) {
            $project->forceFill(['budget_plan' => $max])->save();
        }
    }

    /** Spec 13.2 №2 / Part 10 №5: no measurement plan → task for the manager to order one. */
    public function afterSubmitAutomations(Brief $brief): void
    {
        $v = $this->valuesByKey($brief);
        $project = $brief->project()->with('manager')->first();

        if (! $project?->manager || ($v['has_measurement_plan'] ?? null) !== 'no') {
            return;
        }

        $stage = Stage::query()->where('project_id', $project->id)->orderBy('position')->first();

        if ($stage) {
            Task::create([
                'stage_id' => $stage->id,
                'project_id' => $project->id,
                'title' => 'Obyektin obmerini sifariş et',
                'description' => 'Brifdə obmer/BTİ planının olmadığı göstərilib — layihələndirmənin startından əvvəl obmer sifariş edilməlidir.',
                'assignee_user_id' => $project->manager_user_id,
                'author_user_id' => $project->manager_user_id,
                'deadline' => now()->addDays(7),
                'status' => TaskStatus::Todo->value,
                'priority' => TaskPriority::High->value,
            ]);

            return;
        }

        $project->manager->notify(new AutomationAlert(
            'Obmer sifariş edilməlidir',
            $project->name.' — brifdə obmer planı yoxdur; obmer sifariş edin.',
            null,
            ['brief_id' => $brief->id, 'project_id' => $project->id],
            'brief-measurement',
        ));
    }

    /** Screen 02 cross-field rules that must hold before sending. @return list<string> */
    public function validationErrors(Brief $brief): array
    {
        $v = $this->valuesByKey($brief);
        $errors = [];

        $total = (float) ($v['total_area_sqm'] ?? 0);
        $design = (float) ($v['design_area_sqm'] ?? 0);
        if ($total > 0 && $design > $total) {
            $errors[] = t('portal.brief_area_error');
        }

        return $errors;
    }

    /**
     * Screen 14 §6 — answer-level priority: critical / important / normal / missing.
     *
     * @return Collection<int, array{section: BriefSection, room: ?BriefRoom, question: BriefQuestion, answer: ?BriefAnswer, priority: string, note: ?string}>
     */
    public function answerPriorities(Brief $brief): Collection
    {
        $riskByKey = [];
        foreach (app(BriefRiskDetector::class)->detect($brief) as $risk) {
            foreach ($risk['keys'] ?? [] as $key) {
                $riskByKey[$key] = $risk;
            }
        }

        $concrete = ['project_budget_range', 'cooperation_scope', 'room_inventory', 'property_readiness', 'desired_completion_date', 'total_area_sqm', 'design_area_sqm'];
        $answers = $brief->answers()->get()->groupBy(fn (BriefAnswer $a) => $a->brief_question_id.':'.($a->brief_room_id ?? 0));

        return $this->sectionMap($brief)->flatMap(function (array $entry) use ($answers, $riskByKey, $concrete) {
            $roomId = $entry['room']?->id ?? 0;

            return $entry['section']->questions
                ->filter(fn (BriefQuestion $q) => $q->shouldShow($entry['values']))
                ->map(function (BriefQuestion $q) use ($answers, $riskByKey, $concrete, $entry, $roomId) {
                    $answer = $answers->get($q->id.':'.$roomId)?->first();
                    $answered = $answer?->isAnswered() ?? false;
                    $delegated = (bool) ($answer?->delegated_to_designer ?? false);

                    [$priority, $note] = match (true) {
                        $q->is_required && ! $answered => ['critical', 'Məcburi sahə doldurulmayıb'],
                        isset($riskByKey[$q->key]) => [$riskByKey[$q->key]['level'] === 'missing' ? 'missing' : $riskByKey[$q->key]['level'], $riskByKey[$q->key]['code'].' — '.$riskByKey[$q->key]['message']],
                        $delegated && (in_array($q->key, $concrete, true) || $q->is_required) => ['important', 'Dizaynerin ixtiyarına buraxılıb — burada konkret cavab gözlənilir'],
                        $answered => ['normal', null],
                        default => ['missing', 'Doldurulmayıb (məcburi deyil)'],
                    };

                    return ['section' => $entry['section'], 'room' => $entry['room'], 'question' => $q, 'answer' => $answer, 'priority' => $priority, 'note' => $note];
                });
        })->values();
    }

    /** Screen 14 §7 — every uploaded file with the question it came from. */
    public function attachments(Brief $brief): Collection
    {
        return $brief->answers()->with(['question', 'room'])->get()
            ->filter(fn (BriefAnswer $a) => $a->question?->type === 'file' && is_array($a->value) && ! empty($a->value['path']))
            ->map(fn (BriefAnswer $a) => [
                'question' => $a->question->getTranslation('label', 'az'),
                'room' => $a->room?->label,
                'name' => $a->value['name'] ?? basename($a->value['path']),
                'url' => asset('storage/'.ltrim($a->value['path'], '/')),
                'answered_at' => $a->answered_at,
            ])->values();
    }

    /** Screen 14 §2 — sticky summary panel. @return array<string, mixed> */
    public function summaryPanel(Brief $brief): array
    {
        $v = $this->valuesByKey($brief);
        $label = function (string $key, mixed $value) {
            $q = BriefQuestion::where('key', $key)->first();

            return $q ? $q->displayValue($value) : (string) $value;
        };
        $budget = $v['project_budget_range'] ?? null;

        return [
            'address' => $v['object_address'] ?? null,
            'type' => isset($v['object_type']) ? $label('object_type', $v['object_type']) : null,
            'total_area' => $v['total_area_sqm'] ?? null,
            'design_area' => $v['design_area_sqm'] ?? null,
            'budget' => is_array($budget) && (($budget['min'] ?? null) || ($budget['max'] ?? null))
                ? trim(($budget['min'] ?? '').' – '.($budget['max'] ?? '').' '.($budget['currency'] ?? ''))
                : null,
            'timeline' => trim(($v['desired_start_date'] ?? '').' → '.($v['desired_completion_date'] ?? ''), ' →'),
            'scope' => isset($v['cooperation_scope']) ? $label('cooperation_scope', $v['cooperation_scope']) : null,
            'styles' => isset($v['style_preferences']) ? array_slice((array) $v['style_preferences'], 0, 3) : [],
            'rooms' => $brief->rooms()->pluck('label')->all(),
        ];
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
