<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Filament\Resources\PaymentResource;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Köhnə layihə tabı. Ödənişlər artıq «Maliyyə → Ödənişlər»-dədir
 * (`PaymentResource`); forma və cədvəl tərifi oradan gəlir ki, iki nüsxə
 * bir-birindən ayrılmasın.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'payments';

    protected static ?string $title = 'Ödənişlər';

    protected static ?string $modelLabel = 'Ödəniş';

    public function form(Schema $form): Schema
    {
        return $form->schema(PaymentResource::formFields(withProject: false))->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('procurementItem'))
            ->columns(PaymentResource::tableColumns(withProject: false))
            ->defaultSort('due_date')
            ->headerActions([
                Actions\CreateAction::make()->label('Ödəniş əlavə et'),
            ])
            ->actions(PaymentResource::tableActions(inRelationManager: true));
    }
}
