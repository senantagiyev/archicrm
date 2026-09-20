<?php

namespace App\Filament\Resources\BriefQuestionResource\Pages;

use App\Filament\Resources\BriefQuestionResource;
use Filament\Resources\Pages\ListRecords;

class ListBriefQuestions extends ListRecords
{
    protected static string $resource = BriefQuestionResource::class;

    /**
     * Sual yaratmaq/silmək QƏSDƏN yoxdur: sual bankı git-dədir
     * (`database/seeders/brief/bank.php`) və panel nüsxəsi ilə ayrılsa,
     * seeder-in növbəti işləməsi fərqi səssizcə geri qaytarardı.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
