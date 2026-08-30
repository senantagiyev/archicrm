<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\StageStatus;
use App\Models\StageTemplate;
use App\Models\User;
use App\Services\Stages\StageTemplateService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class StagesRelationManager extends RelationManager
{
    protected static string $relationship = 'stages';

    protected static ?string $title = 'Mərhələlər';

    protected static ?string $modelLabel = 'Mərhələ';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Ad')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
            Forms\Components\Select::make('status')
                ->label('Status')
                ->options(collect(StageStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                ->default(StageStatus::NotStarted->value)
                ->required()
                ->native(false),
            Forms\Components\Select::make('responsible_user_id')
                ->label('Məsul şəxs')
                ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->native(false),
            Forms\Components\DatePicker::make('date_plan_start')
                ->label('Başlanğıc (plan)')
                ->native(false),
            Forms\Components\DatePicker::make('date_plan_end')
                ->label('Bitmə (plan)')
                ->native(false)
                ->afterOrEqual('date_plan_start'),
            Forms\Components\TextInput::make('weight')
                ->label('Çəki')
                ->numeric()
                ->default(1)
                ->minValue(1)
                ->maxValue(10)
                ->helperText('Layihənin hazırlıq %-ində bu mərhələnin payı.'),
            Forms\Components\TextInput::make('position')
                ->label('Sıra')
                ->numeric()
                ->default(fn () => ($this->getOwnerRecord()->stages()->max('position') ?? 0) + 1),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                Tables\Columns\TextColumn::make('position')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Mərhələ')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StageStatus $state) => $state->label())
                    ->color(fn (StageStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('responsible.name')
                    ->label('Məsul'),
                Tables\Columns\TextColumn::make('date_plan_end')
                    ->label('Bitmə (plan)')
                    ->date('d.m.Y')
                    ->color(fn ($record) => $record->isOverdue() ? 'danger' : null),
                Tables\Columns\TextColumn::make('readiness')
                    ->label('Hazırlıq')
                    ->formatStateUsing(fn ($state) => $state.'%'),
                Tables\Columns\TextColumn::make('tasks_count')
                    ->label('Tapşırıqlar')
                    ->counts('tasks'),
            ])
            ->headerActions([
                Actions\Action::make('applyTemplate')
                    ->label('Şablon tətbiq et')
                    ->icon('heroicon-o-document-duplicate')
                    ->form([
                        Forms\Components\Select::make('template_id')
                            ->label('Şablon')
                            ->options(fn () => StageTemplate::where('active', true)
                                ->orderBy('position')
                                ->get()
                                ->mapWithKeys(fn ($t) => [$t->id => $t->getTranslation('name', app()->getLocale())]))
                            ->required()
                            ->native(false),
                        Forms\Components\DatePicker::make('start_from')
                            ->label('Başlanğıc tarixi')
                            ->default(today())
                            ->native(false)
                            ->helperText('Mərhələlərin plan tarixləri bu tarixdən ardıcıl hesablanacaq.'),
                    ])
                    ->action(function (array $data, StageTemplateService $service) {
                        $template = StageTemplate::findOrFail($data['template_id']);
                        $service->apply(
                            $this->getOwnerRecord(),
                            $template,
                            $data['start_from'] ? Carbon::parse($data['start_from']) : null,
                        );
                    })
                    ->successNotificationTitle('Şablon tətbiq edildi'),
                Actions\CreateAction::make()->label('Mərhələ əlavə et'),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('Mərhələ tapşırıqları ilə birlikdə silinəcək.'),
            ]);
    }
}
