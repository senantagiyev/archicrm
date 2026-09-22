<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ApprovalStatus;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    use ResolvesClientProjects;

    public function index(int $project)
    {
        $project = $this->clientProject($project);

        $documents = $project->documents()
            ->where('visible_to_client', true)
            ->latest()
            ->get();

        // Roomix sənəd siyahısında hələ hazır olmayan sənədlər də görünür
        // («Not started»): müştəri nəyin gözlənildiyini bilir və hər dəfə
        // dizaynerdən soruşmur. Yalnız müqavilə axınının SABİT sənədləri —
        // ixtiyari yüklənən fayllar üçün slot uydurmuruq.
        $present = $documents->pluck('type')->all();

        $missing = collect([DocumentType::TechnicalSpec, DocumentType::Contract, DocumentType::Act])
            ->reject(fn (DocumentType $type) => in_array($type, $present, true))
            ->values();

        // Roomix-də smeta və komplektasiya fayl deyil, CANLI sənəd səhifəsidir
        // («12 items · 552 140 ₽») və məhz sənədlər siyahısından açılır. Say və
        // məbləğ burada göstərilir ki, müştəri səhifəni açmadan vəziyyəti bilsin.
        $estimateLines = $project->budgetLines()->where('visible_to_client', true)->get();
        $procurementItems = $project->procurementItems()->where('visible_to_client', true)->get();

        $sheets = [
            [
                'url' => route('portal.estimate', $project),
                'label' => t('portal.nav_estimate'),
                'count' => $estimateLines->count(),
                'total' => (float) $estimateLines->sum('total'),
            ],
            [
                'url' => route('portal.procurement', $project),
                'label' => t('portal.nav_procurement'),
                'count' => $procurementItems->count(),
                'total' => $procurementItems->sum(fn ($i) => (float) $i->totalWithDiscount()),
            ],
        ];

        $pendingApprovals = $project->approvals()
            ->where('status', ApprovalStatus::Pending->value)
            ->count();

        return view('portal.documents', compact(
            'project', 'documents', 'missing', 'sheets', 'pendingApprovals',
        ));
    }

    public function download(int $project, int $document)
    {
        $project = $this->clientProject($project);

        $document = $project->documents()
            ->where('visible_to_client', true)
            ->findOrFail($document);

        $ext = pathinfo($document->file_path, PATHINFO_EXTENSION);
        $name = Str::of($document->title)->ascii()->replaceMatches('/[^A-Za-z0-9 _-]/', '')->trim();
        $filename = ($name->isEmpty() ? 'document' : $name).($ext ? '.'.$ext : '');

        return Storage::disk('public')->download($document->file_path, $filename);
    }
}
