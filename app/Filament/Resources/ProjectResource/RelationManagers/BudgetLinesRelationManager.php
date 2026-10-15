<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Filament\Resources\BudgetLineResource;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Köhnə layihə tabı. Smeta artıq «Maliyyə → Smeta»-dadır
 * (`BudgetLineResource`); forma, cədvəl və razılaşdırma action-ları oradan
 * gəlir ki, iki nüsxə bir-birindən ayrılmasın.
 */
class BudgetLinesRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'budgetLines';

    protected static ?string $title = 'Smeta';

    protected static ?string $modelLabel = 'Smeta sətri';

    public function form(Schema $form): Schema
    {
        return $form->schema(BudgetLineResource::formFields(withProject: false))->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('stage'))
            ->columns(BudgetLineResource::tableColumns(withProject: false))
            ->defaultSort('position')
            ->filters(BudgetLineResource::tableFilters())
            ->headerActions([
                Actions\CreateAction::make()->label('Sətir əlavə et'),
            ])
            ->actions(BudgetLineResource::tableActions(inRelationManager: true))
            ->bulkActions(BudgetLineResource::tableBulkActions());
    }
}
