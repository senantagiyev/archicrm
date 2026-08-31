<?php

namespace App\Filament\Resources;

use App\Enums\ApprovalStatus;
use App\Filament\Resources\ApprovalResource\Pages;
use App\Models\Approval;
use App\Models\Project;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ApprovalResource extends Resource
{
    protected static ?string $model = Approval::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static string|\UnitEnum|null $navigationGroup = 'Layihələr';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Razılaşdırmalar';

    protected static ?string $modelLabel = 'Razılaşdırma';

    protected static ?string $pluralModelLabel = 'Razılaşdırmalar';

    public static function getNavigationBadge(): ?string
    {
        $count = Approval::where('status', ApprovalStatus::Pending->value)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subject')
                    ->label('Obyekt')
                    ->state(fn (Approval $r) => $r->subjectLabel())
                    ->wrap(),
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Layihə')
                    ->sortable(),
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
                    ->color(fn (Approval $r) => $r->status === ApprovalStatus::Pending && $r->respond_by?->isPast() ? 'danger' : null),
                Tables\Columns\TextColumn::make('decided_at')
                    ->label('Qərar tarixi')
                    ->dateTime('d.m.Y H:i')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('comment')
                    ->label('Şərh')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ApprovalStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['approvable', 'project', 'requestedBy']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovals::route('/'),
        ];
    }
}
