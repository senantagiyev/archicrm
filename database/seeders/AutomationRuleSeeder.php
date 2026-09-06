<?php

namespace Database\Seeders;

use App\Models\AutomationRule;
use Illuminate\Database\Seeder;

/**
 * Seeds the full Əlavə B catalog (41 rules). Idempotent by `code` — re-running
 * refreshes name/trigger/priority but preserves each rule's `enabled` toggle so an
 * admin's on/off choice survives redeploys.
 */
class AutomationRuleSeeder extends Seeder
{
    public function run(): void
    {
        // [code, trigger (AZ), action summary (AZ), priority]
        $rules = [
            ['rule-1', 'Lid yaradılıb', 'Owner təyin et, follow-up yarat, first-contact SLA-nı işə sal', 'critical'],
            ['rule-2', 'Lidlə əlaqə yoxdur (SLA pozulub)', 'Menecerə/rəhbərə alert göndər', 'critical'],
            ['rule-3', 'Proposal qəbul edilib', '"Müqavilə hazırla" tapşırığı yarat', 'high'],
            ['rule-4', 'Müqavilə imzalanıb', 'İlk hesab-faktura (Invoice) yarat', 'critical'],
            ['rule-5', 'İlk ödəniş "paid" oldu', 'Layihə yarat, susmaya görə StageTemplate tətbiq et, PM-ə bildir', 'critical'],
            ['rule-6', 'İlk ödənişdən sonra', 'Client statusunu lead/negotiation → client dəyiş', 'critical'],
            ['rule-7', 'Layihə yaradılıb', 'Susmaya görə StageTemplate-i tətbiq et', 'critical'],
            ['rule-8', 'Mərhələ "icradadır" oldu', 'Şablon checklist-inə görə tapşırıqları avtomatik yarat', 'high'],
            ['rule-9', 'Tapşırığın son tarixi keçib', 'İcraçıya bildiriş; 24 saatdan sonra məsul şəxsə eskalasiya', 'high'],
            ['rule-10', 'Kritik tapşırıq gecikib', 'Dərhal PM-ə eskalasiya', 'critical'],
            ['rule-11', 'Mərhələ gecikib', 'Layihə sağlamlığı (Health) → Qırmızı', 'critical'],
            ['rule-12', 'Brif 3 gündən çox başlanmayıb', 'Müştəriyə xatırlatma göndər', 'critical'],
            ['rule-13', 'Brif tamamlandı', 'Komandaya "brif yoxlanmağa hazırdır" bildirişi', 'medium'],
            ['rule-14', 'Deliverable hazırdır', 'Daxili baxışı (internal review) başlat', 'high'],
            ['rule-15', 'Daxili baxış təsdiqlənib', 'Müştəriyə approval sorğusu göndər', 'high'],
            ['rule-16', 'Approval gecikib (≥2 gün)', 'Müştəriyə xatırlatma göndər', 'critical'],
            ['rule-17', 'Approval müştəridən cavabsız (>5 gün)', 'Bildiriş + PM-ə eskalasiya', 'medium'],
            ['rule-18', 'Approval rədd edilib', 'Revision (düzəliş) tapşırığı yarat', 'high'],
            ['rule-19', 'Son versiya təsdiqlənib', 'Versiyanı kilidlə, sənədi locked et, final-da dərc et', 'high'],
            ['rule-20', 'Kilidli obyekti redaktə cəhdi', 'Blokla, Change Request yaratmağı təklif et', 'critical'],
            ['rule-21', 'Change Request təsdiqlənib', 'Yeni versiya, tapşırıqlar, son tarix/contract_value recalc, procurement yenilə', 'high'],
            ['rule-22', 'Seçim (selection) təsdiqlənib', 'ProcurementItem yarat', 'high'],
            ['rule-23', 'Satınalma sifarişi yerləşdirilib', 'Çatdırılma (delivery) milestone yarat', 'high'],
            ['rule-24', 'Çatdırılma gecikib', 'Satınalma (procurement) alert', 'medium'],
            ['rule-25', 'Smeta/komplektasiya pozisiyası dəyişib', 'Layihə səviyyəsində totals-ı yenidən hesabla', 'high'],
            ['rule-26', 'Hesab-fakturanın ödəniş tarixi çatıb', 'Müştəriyə xatırlatma', 'critical'],
            ['rule-27', 'Hesab-faktura gecikib', 'Mühasibə + PM-ə alert', 'critical'],
            ['rule-28', 'Ödəniş yaradılıb/dəyişib', 'Mühasibə bildiriş', 'high'],
            ['rule-29', 'Ödəniş alınıb', 'Hesab-fakturanı yenilə (statusu/qalıq)', 'critical'],
            ['rule-30', 'Faktiki büdcə planı aşıb', 'Rəhbərliyə alert', 'high'],
            ['rule-31', 'Vaxt istifadəsi həddi keçib', 'Profitability (rentabellik) alert', 'medium'],
            ['rule-32', 'Final approval', 'Final package yarat', 'high'],
            ['rule-33', 'Layihə tamamlanıb', 'Final hesab-faktura yarat', 'high'],
            ['rule-34', 'Final ödəniş alınıb', 'Handover (təhvil) workflow-nu başlat', 'high'],
            ['rule-35', 'Handover tamamlanıb', 'Layihəni arxivləşdir', 'medium'],
            ['rule-36', 'Layihə "completed" (N gün keçib)', 'Arxivləşdir, faylları archive et, müştəridən rəy sorğusu', 'medium'],
            ['rule-37', 'Zəmanət (warranty) müddəti yaxınlaşır', 'Xatırlatma göndər', 'medium'],
            ['rule-38', 'Müştəri mesajı cavabsız qalıb', 'PM-ə alert', 'high'],
            ['rule-39', 'Görüş (meeting) yaxınlaşır', '24 saat və 1 saat qabaqdan bildiriş', 'high'],
            ['rule-40', 'Mərhələnin son tarixi sürüşdürülüb', 'manual_override nəzərə alınmaqla sonrakı mərhələləri recalc et', 'medium'],
            ['rule-41', 'İstənilən əsas əməliyyat (approve/publish/pay/change)', 'AuditLogEntry-ə yazı əlavə et', 'high'],
        ];

        foreach ($rules as [$code, $trigger, $action, $priority]) {
            AutomationRule::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $action, 'trigger' => $trigger, 'priority' => $priority],
                // enabled is intentionally NOT overwritten on re-seed (see class doc).
            );
        }
    }
}
