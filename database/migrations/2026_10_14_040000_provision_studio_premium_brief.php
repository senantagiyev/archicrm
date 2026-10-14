<?php

use App\Services\Brief\BriefBuilderService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hər mövcud studiyaya «Premium brif (şəkilli)» — sistemin tam şəkilli
 * brifinin (Roomix nümunəsində qurulmuş «Yaşayış obyekti») studiya nüsxəsi.
 *
 * Müştəri tələbi (2026-10-14): o brif şablon kimi qalsın, defolt olsun və digər
 * briflər kimi redaktə edilə bilsin. Sistem sətri ortaqdır, ona görə studiya
 * öz nüsxəsini alır; nüsxə siyahıda orijinalın yerini tutur və yeni
 * layihələrin defoltu olur. Studiyanın artıq nüsxəsi varsa toxunulmur.
 *
 * Yeni studiyalar üçün eyni iş `CreateTenant` səhifəsində görülür.
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = app(BriefBuilderService::class);

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            // Yaradan kimi studiyanın sahibkarı yazılır (yoxdursa — boş).
            $creatorId = DB::table('users')
                ->where('tenant_id', $tenantId)
                ->orderByRaw("role = 'owner' desc")
                ->orderBy('id')
                ->value('id');

            $service->provisionStudioPremium((int) $tenantId, $creatorId);
        }
    }

    public function down(): void
    {
        // Geri qaytarma studiyanın redaktə etdiyi brifi silərdi — qəsdən boşdur.
    }
};
