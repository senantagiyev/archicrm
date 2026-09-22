<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\BudgetLine;
use App\Models\Project;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Roomix-dəki «Work estimate» (`/estimate/<id>`) səhifəsinin qarşılığı.
 *
 * Sətirlər artıq `budget_lines` cədvəlindədir — yeni model qurulmur.
 * Smetada daxili (studiyanın öz marjası ilə) sətirlər də olur, ona görə
 * müştəriyə YALNIZ `visible_to_client = true` sətirlər verilir; eyni filtr
 * həm səhifədə, həm CSV ixracında tətbiq olunur ki, ixrac gizli sətri
 * arxa qapıdan çıxarmasın.
 */
class EstimateController extends Controller
{
    use ResolvesClientProjects;

    /** CSV başlıqları — səhifə cədvəli ilə eyni sıra. */
    private const CSV_HEADERS = [
        'İş növü', 'Otaq', 'Ölçü vahidi', 'Həcm',
        'İşin qiyməti', 'Materialın qiyməti', 'Cəmi', 'Status', 'Razılaşdırma',
    ];

    public function index(int $project)
    {
        $project = $this->clientProject($project);

        $lines = $this->clientLines($project);
        // Cəmi DB-dən deyil, məhz göstərilən sətirlərdən yığılır — beləliklə
        // ekrandakı sətirlərin cəmi ilə «Smetanın cəmi» həmişə üst-üstə düşür.
        $total = $lines->sum(fn ($line) => (float) $line->total);

        return view('portal.estimate', compact('project', 'lines', 'total'));
    }

    public function export(int $project): StreamedResponse
    {
        $project = $this->clientProject($project);

        $lines = $this->clientLines($project);
        $total = $lines->sum(fn ($line) => (float) $line->total);

        $filename = 'smeta-'.$project->id.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($lines, $total): void {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM: Excel BOM olmayan faylı sistem kodlaşdırması ilə açır
            // və Azərbaycan hərfləri (ə, ğ, ş, ı) korlanır.
            fwrite($out, "\xEF\xBB\xBF");

            // Ayırıcı nöqtəli vergüldür: Excel-in Azərbaycan/Rusiya regional
            // ayarlarında siyahı ayırıcısı məhz `;`-dir, vergüllü fayl isə
            // bütün sətri bir xanaya yığır. Üstəlik onluq ayırıcı vergül
            // olduqda `;` ziddiyyət yaratmır.
            fputcsv($out, self::CSV_HEADERS, ';');

            foreach ($lines as $line) {
                // Xanalar `Csv::row()`-dan keçir: `=`, `+`, `-`, `@` ilə başlayan
                // dəyəri Excel DÜSTUR kimi icra edir, bu fayl isə müştəriyə gedir.
                fputcsv($out, Csv::row([
                    $line->work_type,
                    $line->room ?? '',
                    $line->unit ?? '',
                    $this->money($line->qty),
                    $this->money($line->work_price),
                    $this->money($line->material_price),
                    $this->money($line->total),
                    $line->stage?->status?->label() ?? '',
                    $line->approval_status?->label() ?? '',
                ]), ';');
            }

            // Yekun sətri: faylı Excel-də açan adam cəmi əl ilə yığmasın.
            fputcsv($out, ['', '', '', '', '', t('portal.estimate_total'), $this->money($total), '', ''], ';');

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Müştəriyə açıq smeta sətirləri.
     *
     * @return Collection<int, BudgetLine>
     */
    private function clientLines(Project $project): Collection
    {
        return $project->budgetLines()
            ->where('visible_to_client', true)
            // «Status» sütunu mərhələnin vəziyyətindən gəlir (Roomix-də
            // «Planned/In progress»); N+1 olmasın deyə əvvəlcədən yüklənir.
            ->with('stage')
            ->get();
    }

    /** CSV-də rəqəmlər qrup ayırıcısız verilir — Excel onları mətn kimi oxumasın. */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
