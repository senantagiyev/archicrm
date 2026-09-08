<?php

namespace App\Services\Brief;

use App\Models\Brief;
use Illuminate\Support\Carbon;

/**
 * Brif spesifikasiyası Part 14 — dizayner üçün avtomatik xəbərdarlıqlar.
 * MVP dəsti: R1, R2, R4, R5, R6, R8 (xarici qiymət sorğu kitabları tələb
 * etməyən qaydalar). R3/R7 — V2.
 *
 * Hər risk: ['code', 'level' => critical|important|missing, 'message', 'keys' => [question keys]].
 * `keys` lets the Designer View raise the priority of the exact answers involved.
 */
class BriefRiskDetector
{
    /** R1 üçün studiya həddi: 1 m² dizayn sahəsinə minimal realistik büdcə. */
    public const BUDGET_PER_SQM_THRESHOLD = 300;

    /** R2 üçün əməkdaşlıq formatına görə minimal realistik müddət (gün). */
    private const MIN_DAYS_BY_SCOPE = [
        'design_only' => 45,
        'design_procurement' => 90,
        'design_supervision' => 90,
        'turnkey' => 150,
        'rooms_only' => 30,
    ];

    /** @return array<int, array{code: string, level: string, message: string, keys: list<string>}> */
    public function detect(Brief $brief): array
    {
        $v = app(BriefService::class)->valuesByKey($brief);
        $risks = [];

        // ── R1: büdcə vs sahə ──
        $area = (float) ($v['design_area_sqm'] ?? 0);
        $budgetMax = (float) ($v['project_budget_range']['max'] ?? 0);

        if ($area > 0 && $budgetMax > 0 && $budgetMax < $area * self::BUDGET_PER_SQM_THRESHOLD) {
            $risks[] = [
                'code' => 'R1',
                'level' => 'critical',
                'message' => 'Göstərilən büdcə '.rtrim(rtrim(number_format($area, 1, ',', ' '), '0'), ',')
                    .' m² sahə üçün kifayət etməyə bilər. Konsepsiyanın startından əvvəl gözləntiləri müzakirə etməyi tövsiyə edirik.',
                'keys' => ['project_budget_range', 'design_area_sqm'],
            ];
        }

        // ── R2: müddət vs iş həcmi ──
        $scope = $v['cooperation_scope'] ?? null;
        $completion = $v['desired_completion_date'] ?? null;

        if ($scope && $completion && isset(self::MIN_DAYS_BY_SCOPE[$scope])) {
            $days = now()->startOfDay()->diffInDays(Carbon::parse($completion), false);

            if ($days < self::MIN_DAYS_BY_SCOPE[$scope]) {
                $risks[] = [
                    'code' => 'R2',
                    'level' => 'important',
                    'message' => 'Müddət seçilmiş əməkdaşlıq formatı üçün riskli görünür.',
                    'keys' => ['desired_completion_date', 'cooperation_scope'],
                ];
            }
        }

        // ── R4: obmer planı yoxdur ──
        if (($v['has_measurement_plan'] ?? null) === 'no') {
            $risks[] = [
                'code' => 'R4',
                'level' => 'missing',
                'message' => 'Obmer planı yoxdur — planlaşdırmanın startından əvvəl sifariş edilməlidir.',
                'keys' => ['has_measurement_plan'],
            ];
        }

        // ── R5: «pərdəsiz» + qaranlıqlaşdırma konflikti ──
        if (($v['curtains_type'] ?? null) === 'none' && filled($v['curtains_blackout_location'] ?? null)) {
            $risks[] = [
                'code' => 'R5',
                'level' => 'important',
                'message' => '«Pərdəsiz» göstərilib və eyni zamanda qaranlıqlaşdırma tələbi var — müştəridən dəqiqləşdirin.',
                'keys' => ['curtains_type', 'curtains_blackout_location'],
            ];
        }

        // ── R6: exclusive_override safety net ──
        foreach (['wall_materials' => 'Divar', 'floor_materials' => 'Döşəmə', 'ceiling_materials' => 'Tavan'] as $key => $label) {
            $picked = (array) ($v[$key] ?? []);

            if (in_array('designer', $picked, true) && count($picked) > 1) {
                $risks[] = [
                    'code' => 'R6',
                    'level' => 'critical',
                    'message' => $label.' materialları blokunda məlumat konflikti aşkarlandı — «Dizaynerin ixtiyarına» konkret seçimlərlə birlikdə işarələnib.',
                    'keys' => [$key],
                ];
            }
        }

        // ── R8: demontaj vs əməkdaşlıq formatı ──
        if (($v['demolition_needed'] ?? null) === 'yes' && $scope === 'design_only') {
            $risks[] = [
                'code' => 'R8',
                'level' => 'important',
                'message' => 'Müştəriyə demontaj üzrə podratçı köməyi lazım ola bilər — seçilmiş əməkdaşlıq formatı bunu əhatə etmir.',
                'keys' => ['demolition_needed', 'cooperation_scope'],
            ];
        }

        return $risks;
    }
}
