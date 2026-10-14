<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\ChangeRequestStatus;
use App\Models\ChangeRequest;
use App\Models\User;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Dəyişiklik sorğuları (TZ §7.15).
 *
 * Razılaşdırılmış işdən SONRA müştərinin istədiyi dəyişikliyi (divarın yerini
 * dəyişmək, materialı əvəzləmək və s.) qeydə alır: nə dəyişir, niyə, qiymətə və
 * müddətə təsiri nədir və müştəri bunu iş başlamazdan ƏVVƏL təsdiqləyibmi.
 * Müştəri portalında bu sorğular göstərilmir — müştərinin cavabını studiya
 * əməkdaşı «Müştəri təsdiqlədi» / «Rədd edildi» düymələri ilə qeyd edir.
 */
class ChangeRequestsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'changeRequests';

    protected static ?string $title = 'Dəyişiklik sorğuları';

    protected static ?string $modelLabel = 'Dəyişiklik sorğusu';

    protected static ?string $pluralModelLabel = 'Dəyişiklik sorğuları';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Nə dəyişir?')
                ->placeholder('Məs.: Mətbəx ilə qonaq otağı arasındakı divarın sökülməsi')
                ->helperText('Dəyişikliyin qısa adı — siyahıda bu görünəcək.')
                ->required()->maxLength(191)->columnSpanFull(),
            Forms\Components\Textarea::make('description')
                ->label('Təfərrüat')
                ->placeholder('Məs.: Divar sökülür, yerinə bar piştaxtası qurulur; parket qonaq otağına qədər uzadılır.')
                ->helperText('Əvvəl necə razılaşdırılmışdı və indi necə olacaq.')
                ->rows(3)->columnSpanFull(),
            Forms\Components\Textarea::make('reason')
                ->label('Səbəb')
                ->placeholder('Məs.: Müştəri açıq planlı mətbəx istəyir.')
                ->helperText('Dəyişiklik niyə lazımdır — sonradan «bunu kim, niyə istədi» sualına cavab olur.')
                ->rows(2)->columnSpanFull(),
            Forms\Components\Select::make('requested_by')
                ->label('Dəyişikliyi kim istədi?')
                ->options(ChangeRequest::REQUESTERS)
                ->default('client')->required()->native(false),
            Forms\Components\Select::make('responsible_user_id')
                ->label('Məsul əməkdaş')
                ->helperText('Təsiri hesablayan və müştəri ilə razılaşdıran şəxs.')
                ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()->native(false),
            // Filament 4-də Section `Filament\Schemas\Components` altındadır.
            // Köhnə `Forms\Components\Section` sinfi mövcud deyil və «yarat» /
            // «redaktə et» pəncərəsi hər dəfə «Class not found» ilə çökürdü.
            Section::make('Dəyişikliyin təsiri')
                ->description('Dəyişiklik layihəyə nə qədər əlavə pul və vaxt gətirir. Müştəri məhz bu rəqəmləri təsdiqləyir. Ucuzlaşma və ya qısalma varsa, mənfi rəqəm yazın.')
                ->columns(3)->columnSpanFull()
                ->schema([
                    Forms\Components\TextInput::make('cost_impact')
                        ->label('Qiymətə təsiri')
                        ->helperText('Müştəri üçün əlavə məbləğ.')
                        ->numeric()->default(0)->suffix('₼')
                        // decimal(12,2) sütununun həddi — artıq dəyər MySQL-də 500 verərdi.
                        ->minValue(-9999999999.99)->maxValue(9999999999.99),
                    Forms\Components\TextInput::make('schedule_impact_days')
                        ->label('Müddətə təsiri')
                        ->helperText('Təhvil tarixi neçə gün uzanır.')
                        ->integer()->default(0)->suffix('gün')
                        ->minValue(-3650)->maxValue(3650),
                    Forms\Components\TextInput::make('estimated_hours')
                        ->label('Əlavə iş saatı')
                        ->helperText('Studiyanın daxili əmək sərfi.')
                        ->numeric()->default(0)->suffix('saat')
                        ->minValue(0)->maxValue(999999.99),
                ]),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Razılaşdırılmış işdən sonra müştərinin istədiyi dəyişikliklər (məs. divarın yerinin dəyişməsi, materialın əvəzlənməsi). Hər sorğuda dəyişikliyin qiymətə və müddətə təsiri yazılır və iş başlamazdan əvvəl müştəri təsdiqi qeyd olunur — sonradan «bunu kim istədi, neçəyə razılaşdıq» sualı yaranmır.')
            // Siyasət (policy) hər sətir üçün `$record->project`-ə baxır. Əvvəlcədən
            // yüklənməsə, iki və daha çox sorğu olan layihədə qeyri-production
            // mühitdə cədvəl «Attempted to lazy load [project]» ilə çökürdü.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'responsible']))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Nömrə')->searchable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Nə dəyişir')
                    ->searchable()
                    ->wrap()
                    ->description(fn (ChangeRequest $record) => filled($record->reason)
                        ? str($record->reason)->limit(80)->toString()
                        : null),
                Tables\Columns\TextColumn::make('requested_by')
                    ->label('Kim istədi')
                    ->formatStateUsing(fn (?string $state) => ChangeRequest::REQUESTERS[$state] ?? '—'),
                Tables\Columns\TextColumn::make('cost_impact')
                    ->label('Qiymətə təsiri')
                    ->money('AZN')
                    ->sortable(),
                Tables\Columns\TextColumn::make('schedule_impact_days')
                    ->label('Müddətə təsiri')
                    ->formatStateUsing(fn ($state) => (int) $state === 0
                        ? 'Dəyişmir'
                        : sprintf('%+d gün', (int) $state)),
                Tables\Columns\TextColumn::make('responsible.name')
                    ->label('Məsul')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Vəziyyət')->badge()
                    ->formatStateUsing(fn (ChangeRequestStatus $state) => $state->label())
                    ->color(fn (ChangeRequestStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Yaradılıb')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Dəyişiklik sorğusu yoxdur')
            ->emptyStateDescription('Müştəri razılaşdırılmış layihədə nəyisə dəyişmək istəyəndə burada sorğu açın: dəyişikliyi, onun qiymətə və müddətə təsirini yazın, sonra müştəri təsdiqini qeyd edin.')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Vəziyyət')
                    ->options(collect(ChangeRequestStatus::cases())->mapWithKeys(fn (ChangeRequestStatus $status) => [$status->value => $status->label()])),
                Tables\Filters\SelectFilter::make('requested_by')
                    ->label('Kim istədi')
                    ->options(ChangeRequest::REQUESTERS),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Dəyişiklik sorğusu yarat')
                    ->mutateDataUsing(function (array $data): array {
                        $data['requested_by_user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Actions\ActionGroup::make($this->transitionActions())
                    ->label('Növbəti addım')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->button()
                    ->color('primary'),
                Actions\EditAction::make()
                    ->hidden(fn (ChangeRequest $record) => $record->status->isLocked()),
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->hidden(fn (ChangeRequest $record) => $record->status->isLocked()),
            ]);
    }

    /**
     * Hər icazəli keçid üçün ayrıca, adı hərəkəti bildirən düymə. Yalnız cari
     * statusdan icazəli olanlar görünür — istifadəçi növbəti addımı açılan
     * siyahıdan təxmin etməyə məcbur qalmır.
     *
     * @return list<Action>
     */
    private function transitionActions(): array
    {
        return collect(ChangeRequestStatus::cases())
            ->reject(fn (ChangeRequestStatus $to) => $to === ChangeRequestStatus::Draft)
            ->map(fn (ChangeRequestStatus $to) => Action::make('to_'.$to->value)
                ->label($to->actionLabel())
                ->color(match ($to) {
                    ChangeRequestStatus::Rejected, ChangeRequestStatus::Cancelled => 'danger',
                    ChangeRequestStatus::Approved, ChangeRequestStatus::Completed => 'success',
                    default => 'primary',
                })
                ->visible(fn (ChangeRequest $record) => auth()->user()?->can($to->isDecision() ? 'approve' : 'update', $record)
                    && $record->status->canTransitionTo($to))
                ->requiresConfirmation()
                ->modalHeading(fn (ChangeRequest $record) => $record->number.' — '.$to->actionLabel())
                ->modalDescription($to->actionDescription())
                ->action(function (ChangeRequest $record, Action $action) use ($to): void {
                    try {
                        $record->transitionTo($to);
                    } catch (RuntimeException) {
                        // Başqa əməkdaş eyni anda statusu dəyişibsə keçid artıq
                        // qadağandır — 500 əvəzinə səbəbi göstəririk.
                        Notification::make()
                            ->danger()
                            ->title('Vəziyyət dəyişdirilmədi')
                            ->body('Sorğunun vəziyyəti artıq «'.$record->fresh()?->status->label().'»-dir. Səhifəni yeniləyin.')
                            ->send();

                        $action->cancel();
                    }
                })
                ->successNotificationTitle('Vəziyyət: '.$to->label()))
            ->values()
            ->all();
    }
}
