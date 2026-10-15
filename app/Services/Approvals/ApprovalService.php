<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Approval;
use App\Models\BudgetLine;
use App\Models\ChangeRequest;
use App\Models\ClientUser;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\Document;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\Stage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ApprovalDecided;
use App\Notifications\ApprovalRequested;
use App\Services\Automation\AutomationEngine;
use App\Services\Design\DeliverableService;
use App\Services\Design\MaterialService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class ApprovalService
{
    /** Paneldəki «Razılaşdırma yarat» formasında «Nə razılaşdırılır?» seçimləri. */
    public const SUBJECT_UPLOAD = 'upload';

    public const SUBJECT_DOCUMENT = 'document';

    public const SUBJECT_DELIVERABLE = 'deliverable';

    public const SUBJECT_STAGE = 'stage';

    /**
     * Send a row (smeta line, procurement item, stage or document) to the
     * customer for approval. Notifies every portal account of the client.
     *
     * @param  array<int, array{key: string, label: string, note?: string}>  $variants
     *                                                                                  Roomix-dəki «pick one option» halı: bir neçə variant göndərilir,
     *                                                                                  müştəri birini seçir. Boş massiv adi bəli/xeyr deməkdir.
     * @param  DeliverableVersion|null  $deliverableVersion  material üçün razılaşdırılan VERSİYA
     *                                                       (verilməyibsə — cari versiya). Qərar
     *                                                       yalnız bu versiyaya tətbiq olunur.
     */
    public function request(Model $approvable, User $requestedBy, ?Carbon $respondBy = null, array $variants = [], ?string $note = null, ?DeliverableVersion $deliverableVersion = null): Approval
    {
        if ($deliverableVersion !== null
            && (! $approvable instanceof Deliverable || (int) $deliverableVersion->deliverable_id !== (int) $approvable->getKey())) {
            throw new InvalidArgumentException('Versiya bu materiala aid deyil.');
        }

        // `visible_to_client = false` means the studio marked this line internal.
        // The portal prints the approval's subject label and total verbatim, so
        // the flag has to stop the request, not just hide a column in the panel.
        if ($approvable instanceof BudgetLine && ! $approvable->visible_to_client) {
            throw new InvalidArgumentException(
                'Müştəriyə gizli işarələnmiş smeta sətri razılaşdırmaya göndərilə bilməz.'
            );
        }

        // Dəyişiklik sorğusu müştəriyə yalnız studiya rəhbərliyi təsiri
        // təsdiqlədikdən sonra gedir (və ya artıq müştəridə olanda təkrar
        // göndərilir). Qaralamanı və ya hesablanmamış təsiri müştəriyə
        // göndərmək razılaşdırılmamış rəqəmləri ona təqdim etmək olardı.
        if ($approvable instanceof ChangeRequest && ! in_array($approvable->status, [
            ChangeRequestStatus::WaitingInternalApproval,
            ChangeRequestStatus::WaitingClientApproval,
        ], true)) {
            throw new InvalidArgumentException(
                'Dəyişiklik sorğusu müştəriyə yalnız studiyada təsdiqləndikdən sonra göndərilə bilər. Hazırkı vəziyyət: «'.$approvable->status->label().'».'
            );
        }

        $project = $approvable->project;

        // Supersede a previous pending request for the same row.
        Approval::query()
            ->where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->where('status', ApprovalStatus::Pending->value)
            ->update(['status' => ApprovalStatus::Draft->value]);

        // Versiya nömrəsi bu obyektin BÜTÜN keçmiş göndərişlərinə görə artır —
        // rədd edilmiş dövrlər də sayılır, çünki müştəri «v2» deyəndə neçənci
        // dəfə baxdığını nəzərdə tutur, neçənci dəfə bəyəndiyini yox.
        $version = 1 + (int) Approval::query()
            ->where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->max('version');

        // Cavab müddəti verilməyibsə layihənin öz pəncərəsindən götürülür
        // (Roomix «Client response window»), yoxsa hər sorğuda əl ilə yazılardı.
        $respondBy ??= $project->client_response_days
            ? now()->addDays($project->client_response_days)
            : null;

        $approval = Approval::create([
            'approvable_type' => $approvable->getMorphClass(),
            'approvable_id' => $approvable->getKey(),
            'deliverable_version_id' => $approvable instanceof Deliverable
                ? ($deliverableVersion?->id ?? $approvable->current_version_id)
                : null,
            'project_id' => $project->id,
            'requested_by_user_id' => $requestedBy->id,
            'status' => ApprovalStatus::Pending,
            'version' => $version,
            'variants' => $variants === [] ? null : array_values($variants),
            'note' => filled($note) ? trim($note) : null,
            'respond_by' => $respondBy,
        ]);

        $this->setSubjectStatus($approvable, ApprovalStatus::Pending, $approval);

        foreach ($project->client->clientUsers as $clientUser) {
            $this->notifySafely($clientUser, new ApprovalRequested($approval));
        }

        return $approval;
    }

    /**
     * Paneldəki «Razılaşdırma yarat» formasının girişi.
     *
     * Əvvəl razılaşdırma yalnız smeta sətri, komplektasiya və dizayn materialının
     * öz cədvəlindəki düymədən yaranırdı — «Razılaşdırmalar» bölməsində isə
     * yaratmaq üçün heç nə yox idi və müştəriyə sadə bir faylı (çertyoj, plan,
     * kollaj) təsdiqə göndərmək mümkün deyildi. Burada obyekt seçilir (və ya
     * yeni fayl sənəd kimi yaradılır), sonra HƏMİŞƏ `request()` çağırılır ki,
     * versiya, köhnə sorğunun əvəzlənməsi, cavab müddəti və müştəriyə bildiriş
     * eyni qaydada işləsin.
     *
     * Obyekt yalnız VERİLƏN layihədən axtarılır: başqa layihənin (və ya başqa
     * studiyanın) sənədinin id-si göndərilsə, `findOrFail` 404 verir.
     *
     * @param  array{subject_type: string, subject_id?: int|string|null, title?: string|null, material_type?: string|null, stage_id?: int|string|null, file_path?: string|array<int, string>|null, note?: string|null, respond_by?: string|null}  $data
     */
    public function requestFromPanel(Project $project, User $requestedBy, array $data): Approval
    {
        $respondBy = filled($data['respond_by'] ?? null) ? Carbon::parse($data['respond_by']) : null;
        $note = $data['note'] ?? null;

        return DB::transaction(function () use ($project, $requestedBy, $data, $respondBy, $note): Approval {
            $subject = match ($data['subject_type'] ?? null) {
                // Yeni fayl «Fayllar» bölməsində material kartı olur (TZ «Layihə
                // naviqasiyası» §5–6): əvvəl «Sənədlər»ə «Digər» sənəd kimi
                // düşürdü, o tab isə artıq yoxdur. Dərc aşağıda, sonra razılaşdırma.
                self::SUBJECT_UPLOAD => app(MaterialService::class)->create($project, [
                    'title' => $data['title'],
                    'type' => $data['material_type'] ?? null,
                    'stage_id' => $data['stage_id'] ?? null,
                    'files' => $data['file_path'],
                ], $requestedBy),
                self::SUBJECT_DOCUMENT => $project->documents()->findOrFail($data['subject_id'] ?? null),
                self::SUBJECT_DELIVERABLE => $project->deliverables()->findOrFail($data['subject_id'] ?? null),
                self::SUBJECT_STAGE => $project->stages()->findOrFail($data['subject_id'] ?? null),
                default => throw new InvalidArgumentException('Razılaşdırılan obyektin növü seçilməyib.'),
            };

            // Razılaşdırmaya göndərmək = müştəriyə göstərmək. Dizayn materialının
            // öz düyməsi də `client_visible`-ı açır; mövcud daxili sənəd də
            // açılmasa, müştəri kartı görərdi, faylı isə yükləyə bilməzdi.
            if ($subject instanceof Document && ! $subject->visible_to_client) {
                $subject->update(['visible_to_client' => true]);
            }

            // Material: müştəri razılaşdırdığı versiyanı görməlidir — cari versiya
            // dərc edilir (dərc ≠ təsdiq; təsdiq müştərinin qərarıdır).
            if ($subject instanceof Deliverable && $subject->current_version_id
                && (int) $subject->published_version_id !== (int) $subject->current_version_id) {
                $subject->forceFill(['published_version_id' => $subject->current_version_id])->save();
            }

            return $this->request($subject, $requestedBy, $respondBy, [], $note);
        });
    }

    /**
     * Record the customer's decision. Rejection requires a comment (TZ §5.7).
     */
    public function decide(
        Approval $approval,
        bool $approved,
        ?string $comment,
        ?ClientUser $decidedBy = null,
        ?string $chosenVariant = null,
    ): Approval {
        // Qərar bir dəfə verilir. Yoxlama olmadan təsdiqlənmiş razılaşdırmanı
        // «rədd edilmiş»ə çevirmək mümkün idi — həm subyektin statusu geri
        // qayıdır, həm də yenidən düzəliş tapşırığı yaranırdı. Yeni dövrə
        // lazımdırsa, dizayner `request()` ilə növbəti versiyanı göndərir.
        if ($approval->status !== ApprovalStatus::Pending) {
            throw new InvalidArgumentException('Bu razılaşdırma üzrə qərar artıq verilib.');
        }

        if (! $approved && blank($comment)) {
            throw new InvalidArgumentException('Rədd edərkən şərh məcburidir.');
        }

        // Variantlı razılaşdırmanı «təsdiq» etmək seçim etmək deməkdir: hansının
        // seçildiyi bilinmədən təsdiq dizaynerə heç nə demir.
        if ($approved && $approval->hasVariants()) {
            $allowed = collect($approval->variants)->pluck('key')->all();

            if (! in_array($chosenVariant, $allowed, true)) {
                throw new InvalidArgumentException('Təsdiq üçün variantlardan biri seçilməlidir.');
            }
        }

        // One transaction: the decision and the subject's status have to move
        // together. Without it a failure in setSubjectStatus left the approval
        // reading "approved" while the budget line still read "pending", so the
        // amount never reached projects.debt and staff saw a request the client
        // had already answered.
        DB::transaction(function () use ($approval, $approved, $comment, $decidedBy, $chosenVariant): void {
            $approval->update([
                'status' => $approved ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                'comment' => $comment,
                'chosen_variant' => $approved ? $chosenVariant : null,
                'client_user_id' => $decidedBy?->id ?? $approval->client_user_id,
                'decided_at' => now(),
            ]);

            $approval->loadMissing('approvable');

            // A subject deleted after the request went out is not a reason to 500
            // on the client — the decision itself is still recorded.
            if ($approval->approvable) {
                $this->setSubjectStatus($approval->approvable, $approval->status, $approval);
            }

            // Rədd edilmiş dəyişiklik sorğusu bağlanır — onun üçün «Düzəliş»
            // tapşırığı mənasızdır: müştəri dəyişikliyi istəmir, düzəldiləcək iş yoxdur.
            if (! $approved && ! $approval->approvable instanceof ChangeRequest) {
                $this->createRevisionTask($approval, $comment);
            }
        });

        if ($approval->requestedBy !== null) {
            $this->notifySafely($approval->requestedBy, new ApprovalDecided($approval));
        }

        return $approval;
    }

    /**
     * Bildiriş razılaşdırmanın özünü sındırmamalıdır. Bildirişlər `ShouldQueue`
     * olsa da `QUEUE_CONNECTION=sync` olduqda poçt xətası (SMTP limiti — smoke
     * testdə Mailtrap «Too many emails per second», prod-da Gmail kvotası)
     * birbaşa bura atılırdı: «Razılaşdırmaya göndər» bütünlüklə geri qaytarılırdı,
     * müştərinin portaldakı qərarı isə 500 ilə bitirdi. Panel bildirişi (database
     * kanalı) poçtdan əvvəl yazılır; poçt xətası jurnala düşür.
     */
    private function notifySafely(object $notifiable, Notification $notification): void
    {
        try {
            $notifiable->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Əlavə B rule 18: a rejected approval spawns a revision task for the requester.
     * Tasks require a stage; when the project has one we attach the task there,
     * otherwise the ApprovalDecided notification above is the only signal.
     */
    private function createRevisionTask(Approval $approval, ?string $comment): void
    {
        if (! app(AutomationEngine::class)->isEnabled('rule-18') || ! $approval->project_id) {
            return;
        }

        // Materialın öz mərhələsi varsa, düzəliş tapşırığı ora düşür.
        $subjectStage = $approval->approvable instanceof Deliverable && $approval->approvable->stage_id
            ? Stage::query()->where('project_id', $approval->project_id)->find($approval->approvable->stage_id)
            : null;

        $stage = $subjectStage ?? Stage::query()
            ->where('project_id', $approval->project_id)
            ->orderBy('position')
            ->first();

        if (! $stage) {
            return;
        }

        Task::create([
            'stage_id' => $stage->id,
            'project_id' => $approval->project_id,
            'title' => 'Düzəliş: '.$approval->subjectLabel(),
            'description' => $comment,
            'assignee_user_id' => $approval->requested_by_user_id,
            'author_user_id' => $approval->requested_by_user_id,
            'status' => TaskStatus::Todo->value,
            'priority' => TaskPriority::High->value,
        ]);
    }

    private function setSubjectStatus(Model $approvable, ApprovalStatus $status, ?Approval $approval = null): void
    {
        match (true) {
            // forceFill: approval_status is deliberately non-fillable (audit HIGH-2).
            $approvable instanceof BudgetLine,
            $approvable instanceof ProcurementItem => $approvable->forceFill(['approval_status' => $status])->save(),
            // Deliverables run their own version state machine (TZ §9.2).
            $approvable instanceof Deliverable => $this->applyDeliverableDecision($approvable, $status, $approval),
            $approvable instanceof ChangeRequest => $this->applyChangeRequestDecision($approvable, $status),
            $approvable instanceof Stage, $approvable instanceof Document => null,
            default => throw new InvalidArgumentException('Bu obyekt razılaşdırıla bilməz: '.$approvable::class),
        };
    }

    /**
     * Müştəriyə göndərilmiş, hələ cavablanmamış razılaşdırmanı geri çəkir —
     * məsələn, dəyişiklik sorğusu ləğv ediləndə. Sətir silinmir (tarixçə qalır),
     * «əvəzlənmiş» kimi qaralamaya keçir və portalda görünmür.
     */
    public function withdrawPending(Model $approvable): int
    {
        return Approval::query()
            ->where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->where('status', ApprovalStatus::Pending->value)
            ->update(['status' => ApprovalStatus::Draft->value]);
    }

    /** Obyektin cavab gözləyən (portalda açıq) razılaşdırması. */
    public function pendingFor(Model $approvable): ?Approval
    {
        return Approval::query()
            ->where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->where('status', ApprovalStatus::Pending->value)
            ->latest('id')
            ->first();
    }

    /**
     * Müştərinin qərarı dəyişiklik sorğusunun vəziyyətinə yazılır:
     * göndəriş → «Müştəri təsdiqi gözləyir», təsdiq → «Müştəri təsdiqlədi»,
     * rədd → «Rədd edilib». Sorğu artıq həmin vəziyyətdədirsə (təkrar
     * göndəriş) heç nə dəyişmir.
     */
    private function applyChangeRequestDecision(ChangeRequest $changeRequest, ApprovalStatus $status): void
    {
        $target = match ($status) {
            ApprovalStatus::Pending => ChangeRequestStatus::WaitingClientApproval,
            ApprovalStatus::Approved => ChangeRequestStatus::Approved,
            ApprovalStatus::Rejected => ChangeRequestStatus::Rejected,
            default => null,
        };

        if ($target === null || $changeRequest->status === $target) {
            return;
        }

        $changeRequest->transitionTo($target);
    }

    /**
     * Qərar razılaşdırmanın VERSİYASINA tətbiq olunur (köhnə sətirlərdə versiya
     * yoxdursa — cari versiyaya). Mərhələyə toxunulmur: materialın təsdiqi
     * bütün mərhələni avtomatik tamamlamır (TZ «Layihə naviqasiyası» §4).
     */
    private function applyDeliverableDecision(Deliverable $deliverable, ApprovalStatus $status, ?Approval $approval = null): void
    {
        $service = app(DeliverableService::class);

        $version = $approval?->deliverable_version_id
            ? $deliverable->versions()->whereKey($approval->deliverable_version_id)->first()
            : null;

        match ($status) {
            ApprovalStatus::Pending => $service->markSentForApproval($deliverable, $version),
            ApprovalStatus::Approved => $service->onApproved($deliverable, $version),
            ApprovalStatus::Rejected => $service->onRevisionRequired($deliverable, $version),
            default => null,
        };
    }
}
