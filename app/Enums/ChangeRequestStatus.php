<?php

namespace App\Enums;

/**
 * TZ v2.0 §7.15 / §9.3 — ChangeRequest state machine.
 *
 * Axın: qaralama → təsiri hesablanır → studiyada təsdiq → müştəri təsdiqi →
 * icra → tamamlanıb. Rədd/ləğv ilə sorğu bağlanır.
 */
enum ChangeRequestStatus: string
{
    case Draft = 'draft';
    case ImpactAssessment = 'impact_assessment';
    case WaitingInternalApproval = 'waiting_internal_approval';
    case WaitingClientApproval = 'waiting_client_approval';
    case Approved = 'approved';
    case InImplementation = 'in_implementation';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        // Etiketlər «sorğu indi nəyi gözləyir» sualına cavab verir. Əvvəlki
        // «Təsir qiymətləndirməsi», «Daxili təsdiq gözlənilir» kimi terminlər
        // bölmənin nə üçün olduğunu izah etmirdi (müştəri şikayəti).
        return match ($this) {
            self::Draft => 'Qaralama',
            self::ImpactAssessment => 'Təsiri hesablanır',
            self::WaitingInternalApproval => 'Studiyada təsdiq gözləyir',
            self::WaitingClientApproval => 'Müştəri təsdiqi gözləyir',
            self::Approved => 'Müştəri təsdiqlədi',
            self::InImplementation => 'İcra olunur',
            self::Completed => 'Tamamlanıb',
            self::Rejected => 'Rədd edilib',
            self::Cancelled => 'Ləğv edilib',
        };
    }

    /**
     * Bu statusa KEÇİRƏN düymənin adı. Status adı vəziyyəti, düymə adı isə
     * hərəkəti bildirir — əvvəlki «Növbəti mərhələ» + açılan siyahı növbəti
     * addımın nə olduğunu demirdi.
     */
    public function actionLabel(): string
    {
        return match ($this) {
            // Heç bir keçid qaralamaya qaytarmır; match tam olsun deyə saxlanılır.
            self::Draft => 'Qaralamaya qaytar',
            self::ImpactAssessment => 'Təsirini hesablamağa başla',
            self::WaitingInternalApproval => 'Studiyada təsdiqə göndər',
            self::WaitingClientApproval => 'Müştəriyə təsdiqə göndər',
            self::Approved => 'Müştəri təsdiqlədi',
            self::InImplementation => 'İcraya başla',
            self::Completed => 'Tamamlandı',
            self::Rejected => 'Rədd edildi',
            self::Cancelled => 'Ləğv et',
        };
    }

    /** Keçidin təsdiq pəncərəsində göstərilən izah — düymənin nəticəsini əvvəlcədən deyir. */
    public function actionDescription(): string
    {
        return match ($this) {
            self::Draft => 'Sorğu yenidən qaralamaya qaytarılacaq.',
            self::ImpactAssessment => 'Dəyişikliyin qiymətə, müddətə və iş saatına təsiri hesablanır. Rəqəmləri «Redaktə et» ilə doldurun.',
            self::WaitingInternalApproval => 'Hesablanmış təsir studiya rəhbərliyinin (sahibkar / layihə meneceri) təsdiqinə verilir.',
            self::WaitingClientApproval => 'Studiya təsiri təsdiqlədi. İndi dəyişikliyi, əlavə dəyəri və müddəti müştəriyə təqdim edin.',
            self::Approved => 'Müştəri dəyişikliyi, onun dəyərini və müddətini qəbul etdi. Bundan sonra sorğunun rəqəmləri dəyişdirilə və sorğu silinə bilməz.',
            self::InImplementation => 'Təsdiqlənmiş dəyişiklik üzrə iş başlayır.',
            self::Completed => 'Dəyişiklik üzrə iş başa çatıb.',
            self::Rejected => 'Dəyişiklik qəbul edilmədi (studiya və ya müştəri tərəfindən). Sorğu bağlanır, iş görülmür.',
            self::Cancelled => 'Sorğu ləğv olunur — dəyişikliyə ehtiyac qalmayıb. İş görülmür.',
        };
    }

    /**
     * Bu statusa keçid QƏRAR sayılırmı (policy `approve`, sahibkar / layihə
     * meneceri). Studiyanın təsdiqi müştəriyə göndərmə ilə, müştərinin cavabı
     * isə «təsdiqlədi» / «rədd edildi» ilə qeyd olunur. Ləğv qərar deyil —
     * dəyişikliyə ehtiyac qalmayıbsa, sorğunu açan əməkdaş da bağlaya bilər.
     */
    public function isDecision(): bool
    {
        return in_array($this, [self::WaitingClientApproval, self::Approved, self::Rejected], true);
    }

    /**
     * Müştəri təsdiqindən sonra dəyər/müddət rəqəmləri «razılaşdırılmış» sayılır:
     * onları sonradan sakitcə dəyişmək və ya sorğunu silmək razılaşmanın izini
     * itirərdi. Düzəliş lazımdırsa, yeni dəyişiklik sorğusu açılır.
     */
    public function isLocked(): bool
    {
        return in_array($this, [self::Approved, self::InImplementation, self::Completed], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::ImpactAssessment, self::WaitingInternalApproval, self::WaitingClientApproval => 'warning',
            self::Approved, self::InImplementation => 'info',
            self::Completed => 'success',
            self::Rejected, self::Cancelled => 'danger',
        };
    }

    /** @return array<string, list<string>> */
    public static function allowed(): array
    {
        return [
            self::Draft->value => [self::ImpactAssessment->value, self::Cancelled->value],
            self::ImpactAssessment->value => [self::WaitingInternalApproval->value, self::Cancelled->value],
            self::WaitingInternalApproval->value => [self::WaitingClientApproval->value, self::Rejected->value, self::Cancelled->value],
            self::WaitingClientApproval->value => [self::Approved->value, self::Rejected->value, self::Cancelled->value],
            self::Approved->value => [self::InImplementation->value],
            self::InImplementation->value => [self::Completed->value],
            self::Completed->value => [],
            self::Rejected->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::allowed()[$this->value] ?? [], true);
    }
}
