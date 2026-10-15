<?php

namespace App\Services\Portal;

use App\Models\Project;

/**
 * Portal layihəsinin bölmələri — heyət paneli ilə EYNİ altı bölmə, eyni
 * sıra ilə (TZ «Layihə naviqasiyası» §1, qəbul meyarı 1):
 * İcmal · Brif · Mərhələlər · Fayllar · Komplektasiya · Müəllif nəzarəti.
 *
 * Çat bölmə deyil, ayrıca keçiddir (əvvəlki kimi əlçatandır). Ödənişlər,
 * müqavilələr/sənədlər, smeta və razılaşdırmalar siyahısı da bölmə DEYİL —
 * «İcmal»dan ikinci dərəcəli keçidlə açılır, ona görə həmin səhifələrdə
 * aktiv bölmə «İcmal»dır.
 */
class ProjectSections
{
    /** Bölmə sırası. Açarlar səhifələrin `active` parametri ilə eynidir. */
    public const ORDER = ['overview', 'brief', 'stages', 'files', 'procurement', 'diary'];

    /** Bölmə olmayan, İcmaldan açılan səhifələr. */
    private const SECONDARY = ['documents', 'payments', 'estimate', 'approvals'];

    private const ICONS = [
        'overview' => 'M3 11l9-8 9 8M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5',
        'brief' => 'M8 4h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2M9.5 9h5M9.5 13h5M9.5 17h3',
        'stages' => 'M4 6h16M4 12h16M4 18h9M2.5 6h.01M2.5 12h.01M2.5 18h.01',
        'files' => 'M4 5a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5Z',
        'procurement' => 'M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6M10 21h.01M17 21h.01',
        'diary' => 'M4 4h13l3 3v13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1ZM7 10h9M7 14h6',
        'chat' => 'M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5',
    ];

    /**
     * `available = false` — bölmə strukturda görünür (sıra hamı üçün eynidir),
     * amma hələ açılmır: Brif studiya onu TƏQDİM EDƏNƏ qədər müştəri üçün
     * mövcud deyil (keçid verilmir, səhifə birbaşa URL ilə izahlı boşdur).
     *
     * @return array<string, array{url: string, label: string, icon: string, available: bool}>
     */
    public function sections(Project $project): array
    {
        $briefPresented = $project->briefs()->whereNotNull('presented_at')->exists();

        $routes = [
            'overview' => ['portal.projects.show', 'portal.nav_overview'],
            'brief' => ['portal.brief', 'portal.nav_brief'],
            'stages' => ['portal.stages', 'portal.nav_stages'],
            'files' => ['portal.files', 'portal.nav_files'],
            'procurement' => ['portal.procurement', 'portal.nav_procurement'],
            'diary' => ['portal.diary', 'portal.nav_diary'],
        ];

        return collect(self::ORDER)
            ->mapWithKeys(fn (string $key) => [$key => [
                'url' => route($routes[$key][0], $project),
                'label' => t($routes[$key][1]),
                'icon' => self::ICONS[$key],
                'available' => $key !== 'brief' || $briefPresented,
            ]])
            ->all();
    }

    /** @return array{url: string, label: string, icon: string, available: bool} */
    public function chat(Project $project): array
    {
        return [
            'url' => route('portal.chat', $project),
            'label' => t('portal.nav_chat'),
            'icon' => self::ICONS['chat'],
            'available' => true,
        ];
    }

    /** Səhifənin `active` açarı → vurğulanan bölmə (çat öz keçidini vurğulayır). */
    public function activeSection(?string $active): ?string
    {
        if (in_array($active, self::SECONDARY, true)) {
            return 'overview';
        }

        return in_array($active, [...self::ORDER, 'chat'], true) ? $active : null;
    }
}
