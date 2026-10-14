<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\ApprovalStatus;
use App\Filament\Resources\ApprovalResource\ApprovalRequestForm;
use App\Models\Approval;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Layihənin razılaşdırmaları — layihənin içindən görünür və yaradılır.
 *
 * Əvvəl layihə səhifəsində razılaşdırma tabı yox idi: sorğular yalnız ümumi
 * «Razılaşdırmalar» siyahısında görünürdü, yaratmaq isə ümumiyyətlə mümkün
 * deyildi. Tabı görmək üçün `ApprovalPolicy::viewAny` lazımdır (pul domeni
 * də daxil), yəni vizualizator onu görmür — ümumi siyahıda olduğu kimi.
 */
class ApprovalsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'approvals';

    protected static ?string $title = 'Razılaşdırmalar';

    protected static ?string $modelLabel = 'Razılaşdırma';

    protected static ?string $pluralModelLabel = 'Razılaşdırmalar';

    public function table(Table $table): Table
    {
        return $table
            ->description(ApprovalRequestForm::HELP)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['approvable', 'requestedBy']))
            ->columns([
                Tables\Columns\TextColumn::make('subject')
                    ->label('Obyekt')
                    ->state(fn (Approval $r) => $r->subjectLabel())
                    ->description(fn (Approval $r) => $r->note ? str($r->note)->limit(80)->toString() : null)
                    ->wrap(),
                Tables\Columns\TextColumn::make('version')
                    ->label('Versiya')
                    ->formatStateUsing(fn ($state) => 'v'.$state),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ApprovalStatus $state) => $state->label())
                    ->color(fn (ApprovalStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('requestedBy.name')
                    ->label('Göndərən'),
                Tables\Columns\TextColumn::make('respond_by')
                    ->label('Cavab müddəti')
                    ->date('d.m.Y')
                    ->color(fn (Approval $r) => $r->isOverdue() ? 'danger' : null),
                Tables\Columns\TextColumn::make('comment')
                    ->label('Müştərinin şərhi')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                ApprovalRequestForm::action($this->getOwnerRecord()),
            ])
            ->actions([])
            ->emptyStateHeading('Hələ razılaşdırma yoxdur')
            ->emptyStateDescription('Faylı, sənədi, dizayn materialını və ya mərhələni müştəriyə təsdiqə göndərmək üçün «Razılaşdırma yarat» düyməsini basın.');
    }
}
