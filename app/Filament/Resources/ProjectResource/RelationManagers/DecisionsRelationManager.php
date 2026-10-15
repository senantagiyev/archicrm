<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\DecisionSource;
use App\Filament\Actions\LinkToSubjectAction;
use App\Filament\Resources\ProjectResource\RelationManagers\Concerns\ScopesToChangeSubject;
use App\Models\ProjectDecision;
use App\Support\ChangeSubjects;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qərarlar jurnalı (TZ §9): qərarın tarixçəsi öz obyektinin (material,
 * mərhələ, irad) daxilində göstərilir — `ChangesAndDecisionsAction` bu RM-i
 * obyektə məhdudlaşdırılmış halda açır. Qərarlar DAXİLİDİR: müştəri portalında
 * heç yerdə göstərilmir (icmalın hadisə lenti də müştəri üçün onları süzür).
 */
class DecisionsRelationManager extends RelationManager
{
    use ScopesToChangeSubject;

    protected static bool $isLazy = false;

    protected static string $relationship = 'decisions';

    protected static ?string $title = 'Qərarlar jurnalı';

    protected static ?string $modelLabel = 'Qərar';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Başlıq')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('category')
                ->label('Kateqoriya')
                ->maxLength(191),
            Forms\Components\Select::make('source')
                ->label('Mənbə')
                ->options(collect(DecisionSource::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                ->default(DecisionSource::Manual->value)
                ->required()
                ->native(false),
            Forms\Components\Textarea::make('decision')
                ->label('Qərar')
                ->required()
                ->rows(3)
                ->columnSpanFull(),
            Forms\Components\DateTimePicker::make('decided_at')
                ->label('Qərar tarixi')
                ->default(fn () => now())
                ->native(false),
            Forms\Components\Toggle::make('client_approved')
                ->label('Sifarişçi təsdiqi'),
            Forms\Components\Textarea::make('notes')
                ->label('Qeydlər')
                ->helperText('Daxili qeyd — müştəriyə göstərilmir.')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            // Policy hər sətir üçün `$record->project`-i oxuyur. Əvvəlcədən
            // yüklənməsə, lokal mühitdə (preventLazyLoading) iki sətirdən sonra
            // tab qəzaya uğrayırdı, prod-da isə hər sətrə ayrıca sorğu gedirdi.
            ->modifyQueryUsing(fn (Builder $query) => $this->scopeToChangeSubject(
                $query->with(['project', 'madeBy', 'relatedEntity'])
            ))
            ->heading(fn () => $this->isScopedToSubject() ? 'Qərarlar' : null)
            ->description(fn () => $this->isScopedToSubject()
                ? $this->changeSubjectDescription().' — bu obyektə aid qərarların tarixçəsi. Burada yazılan qərar avtomatik ona bağlanır və layihənin hadisə lentinə düşür.'
                : null)
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Başlıq')
                    ->searchable()
                    ->wrap()
                    ->description(fn (ProjectDecision $record) => filled($record->decision)
                        ? str($record->decision)->limit(120)->toString()
                        : null),
                Tables\Columns\TextColumn::make('source')
                    ->label('Mənbə')
                    ->badge()
                    ->formatStateUsing(fn (DecisionSource $state) => $state->label())
                    ->color(fn (DecisionSource $state) => $state->color()),
                Tables\Columns\TextColumn::make('related_entity')
                    ->label('Aid olduğu obyekt')
                    ->state(fn (ProjectDecision $record) => ChangeSubjects::describe($record->relatedEntity))
                    ->placeholder('Əlaqələndirilməyib')
                    ->wrap()
                    ->hidden(fn () => $this->isScopedToSubject()),
                Tables\Columns\TextColumn::make('madeBy.name')
                    ->label('Kim')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('decided_at')
                    ->label('Tarix')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\IconColumn::make('client_approved')
                    ->label('Sifarişçi təsdiqi')
                    ->boolean(),
            ])
            ->defaultSort('decided_at', 'desc')
            ->emptyStateHeading('Qərar yoxdur')
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Qərar əlavə et')
                    ->mutateDataUsing(function (array $data): array {
                        $data['made_by_user_id'] = auth()->id();

                        return $this->withChangeSubject($data, ProjectDecision::class);
                    }),
            ])
            ->actions([
                LinkToSubjectAction::make()
                    ->hidden(fn () => $this->isScopedToSubject()),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->requiresConfirmation(),
            ]);
    }
}
