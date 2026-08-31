<?php

namespace App\Exports;

use App\Models\Project;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProcurementExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Project $project) {}

    public function collection()
    {
        return $this->project->procurementItems()->get();
    }

    public function headings(): array
    {
        return ['Artikul', 'Ad', 'Kateqoriya', 'Otaq', 'Qiymət', 'Miqdar', 'Cəmi', 'Mağaza', 'Razılaşma', 'Satınalma', 'Ödənilib'];
    }

    public function map($item): array
    {
        return [
            $item->sku,
            $item->name,
            $item->category,
            $item->room,
            (float) $item->price,
            (float) $item->qty,
            (float) $item->total,
            $item->store,
            $item->approval_status->label(),
            $item->purchase_status->label(),
            $item->paid ? 'Bəli' : 'Xeyr',
        ];
    }
}
