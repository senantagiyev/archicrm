<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\PunchIssuePriority;
use App\Enums\PunchIssueStatus;
use App\Filament\Actions\ChangesAndDecisionsAction;
use App\Filament\Resources\ProjectResource;
use App\Models\DiaryEntry;
use App\Models\Project;
use App\Models\PunchListIssue;
use App\Models\User;
use App\Rules\SafeUpload;
use App\Services\Supervision\PunchIssueTimeline;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use RuntimeException;

/**
 * Müəllif nəzarəti → «İradlar» (köhnə Punch List, TZ §8, ssenari 3).
 *
 * Telefondan qısa forma: başlıq, foto (kamera və ya qalereya), məsul şəxs,
 * aradan qaldırılma müddəti — qalanı «Əlavə məlumat»da. Məsul şəxs iradı
 * «Mənə təyin olunanlar» süzgəci ilə tapır və «Nəticəni qeyd et» ilə düzəlişin
 * nəticəsini (qeyd + foto) yazır; yoxlayan bağlayır və ya yenidən işə
 * qaytarır. Bütün dəyişikliklər və müzakirə «Müzakirə və tarixçə»dədir.
 */
class PunchListRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'punchListIssues';

    protected static ?string $title = 'İradlar';

    protected static ?string $modelLabel = 'İrad';

    protected static ?string $pluralModelLabel = 'İradlar';

    /**
     * Layihə səhifəsində bu tabın birbaşa linki (bildiriş və dashboard üçün).
     *
     * Tab açarı `ProjectResource::getRelations()`-dakı açardır — sabit rəqəm
     * yazılmır ki, siyahıya yeni tab əlavə olunanda link başqa taba düşməsin.
     * RM «Müəllif nəzarəti» qrupunun (`RelationGroup`) içindədirsə, qrupun açarı
     * götürülür.
     */
    public static function urlFor(Project $project): string
    {
        $key = null;

        foreach (ProjectResource::getRelations() as $relationKey => $manager) {
            if ($manager === static::class
                || ($manager instanceof RelationGroup && in_array(static::class, $manager->getManagers(), true))) {
                $key = (string) $relationKey;

                break;
            }
        }

        return ProjectResource::getUrl('edit', array_filter([
            'record' => $project,
            'relation' => $key,
        ], fn ($value) => $value !== null));
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Nə aşkar edilib?')
                ->placeholder('Məs.: Mətbəxdə kafel çatlayıb')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
            self::photoUpload('photos', 'Fotolar')
                ->helperText('Telefondan birbaşa kamera ilə çəkin və ya qalereyadan seçin (10 fotoya qədər). Fotolar iradın içində saxlanır — başqa yerdə yenidən yükləmək lazım deyil.'),
            Forms\Components\Select::make('responsible_user_id')
                ->label('Məsul şəxs')
                ->options(fn (?PunchListIssue $record) => $this->responsibleOptions($record))
                ->searchable()
                ->native(false)
                ->helperText('Siyahıda yalnız bu layihəni aça bilən işçilər var. Məsul şəxs bildiriş alır.'),
            // Telefonda brauzerin öz tarix seçicisi daha rahatdır.
            Forms\Components\DatePicker::make('due_date')
                ->label('Aradan qaldırılma müddəti'),
            Forms\Components\Select::make('status')
                ->label('Status')
                ->options(collect(PunchIssueStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                ->required()
                ->native(false)
                // Yaradılışda status avtomatikdir (məsul şəxs varsa «Təyin edilib»).
                ->hiddenOn('create')
                ->helperText('Düzəlişin nəticəsi, bağlama və yenidən işə qaytarma sətirdəki düymələrlədir — tarixçə orada saxlanır.'),
            Section::make('Əlavə məlumat')
                ->collapsed()
                ->columns(['default' => 1, 'sm' => 2])
                ->columnSpanFull()
                ->schema([
                    Forms\Components\TextInput::make('room')
                        ->label('Otaq / zona')
                        ->maxLength(191),
                    Forms\Components\Select::make('priority')
                        ->label('Prioritet')
                        ->options(collect(PunchIssuePriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()]))
                        ->default(PunchIssuePriority::Normal->value)
                        ->required()
                        ->native(false),
                    Forms\Components\Textarea::make('description')
                        ->label('Təsvir')
                        ->rows(3)
                        ->columnSpanFull(),
                    Forms\Components\Select::make('diary_entry_id')
                        ->label('Səfər / hesabat')
                        ->helperText('İrad hansı səfərdə aşkar edilib (istəyə görə).')
                        ->options(fn () => $this->diaryOptions())
                        ->searchable()
                        ->native(false)
                        ->columnSpanFull(),
                ]),
        ])->columns(['default' => 1, 'sm' => 2]);
    }

    public function table(Table $table): Table
    {
        return $table
            // Policy hər sətir üçün `$record->project`-i oxuyur. Əvvəlcədən
            // yüklənməsə, lokal mühitdə (preventLazyLoading) iki sətirdən sonra
            // tab qəzaya uğrayırdı, prod-da isə hər sətrə ayrıca sorğu gedirdi.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'responsible']))
            // Müştərinin sualı: «bu nə üçündür?» — tabın özü izah etsin.
            ->description('Obyektdə iş gedərkən və ya təhvil-təslim zamanı aşkar edilən iradlar. Hər irad məsul şəxsə müddətlə təyin olunur — o, bildiriş alır və iradı «Mənə təyin olunanlar» süzgəci ilə tapır. Məsul şəxs «Nəticəni qeyd et» ilə düzəlişi foto və qeydlə təsdiqləyir, yoxlayan isə iradı bağlayır və ya yenidən işə qaytarır.')
            ->columns([
                Tables\Columns\ImageColumn::make('photo')
                    ->label('Foto')
                    ->disk('public')
                    ->state(fn (PunchListIssue $record) => $record->photoPaths()[0] ?? null)
                    ->square()
                    ->imageSize(48),
                Tables\Columns\TextColumn::make('title')
                    ->label('İrad')
                    ->searchable()
                    ->wrap()
                    ->description(fn (PunchListIssue $record) => $record->room),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioritet')
                    ->badge()
                    ->formatStateUsing(fn (PunchIssuePriority $state) => $state->label())
                    ->color(fn (PunchIssuePriority $state) => $state->color())
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PunchIssueStatus $state) => $state->label())
                    ->color(fn (PunchIssueStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('responsible.name')
                    ->label('Məsul')
                    ->placeholder('—')
                    // İşçi öz sətrini siyahıda dərhal tanısın.
                    ->weight(fn (PunchListIssue $record) => $record->responsible_user_id === auth()->id() ? 'bold' : null)
                    ->icon(fn (PunchListIssue $record) => $record->responsible_user_id === auth()->id() ? 'heroicon-m-user' : null),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Müddət')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn ($record) => $record->due_date
                        && $record->due_date->isPast()
                        && ! in_array($record->status, PunchListIssue::RESOLVED_STATUSES, true)
                            ? 'danger' : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('assigned_to_me')
                    ->label('Mənə təyin olunanlar')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('responsible_user_id', auth()->id())),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(PunchIssueStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('priority')
                    ->label('Prioritet')
                    ->options(collect(PunchIssuePriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('İrad əlavə et')
                    ->mutateDataUsing(function (array $data): array {
                        // Məsul şəxslə açılan irad dərhal «Təyin edilib»-dir —
                        // telefondan yaradanda statusu ayrıca seçmək lazım gəlmir.
                        $data['status'] = filled($data['responsible_user_id'] ?? null)
                            ? PunchIssueStatus::Assigned->value
                            : PunchIssueStatus::Open->value;

                        return $data;
                    }),
            ])
            ->actions([
                $this->recordResolutionAction(),
                $this->closeAction(),
                Actions\ActionGroup::make([
                    $this->historyAction(),
                    $this->returnForReworkAction(),
                    ChangesAndDecisionsAction::make(),
                    Actions\EditAction::make(),
                    Actions\DeleteAction::make()->requiresConfirmation(),
                ]),
            ]);
    }

    // ─────────────── Sətir əməliyyatları ───────────────

    /** Məsul şəxs (və ya redaktə hüququ olan) düzəlişin nəticəsini yazır. */
    private function recordResolutionAction(): Action
    {
        return Action::make('recordResolution')
            ->label('Nəticəni qeyd et')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->button()
            ->visible(fn (PunchListIssue $record) => ! in_array($record->status, PunchListIssue::RESOLVED_STATUSES, true)
                && auth()->user()?->can('update', $record))
            ->modalHeading(fn (PunchListIssue $record) => 'Düzəlişin nəticəsi — '.$record->title)
            ->modalDescription('Nə edildiyini yazın və mümkünsə düzəlişdən sonrakı fotonu əlavə edin. İrad «Həll edilib» olur və yoxlamaya keçir.')
            ->modalSubmitActionLabel('Yadda saxla')
            ->schema([
                Forms\Components\Textarea::make('resolution_note')
                    ->label('Nə edildi?')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
                self::photoUpload('resolved_photos', 'Düzəlişdən sonra foto'),
            ])
            ->action(fn (PunchListIssue $record, array $data) => $record->recordResolution(
                auth()->user(),
                $data['resolution_note'],
                (array) ($data['resolved_photos'] ?? []),
            ))
            ->successNotificationTitle('Nəticə qeyd edildi — irad yoxlamaya keçdi');
    }

    /** Yoxlayan düzəlişi qəbul edir. */
    private function closeAction(): Action
    {
        return Action::make('closeIssue')
            ->label('Bağla')
            ->icon('heroicon-o-lock-closed')
            ->color('primary')
            ->button()
            ->visible(fn (PunchListIssue $record) => in_array($record->status, PunchListIssue::REVIEWABLE_STATUSES, true)
                && auth()->user()?->can('update', $record))
            ->modalHeading(fn (PunchListIssue $record) => 'İradı bağla — '.$record->title)
            ->modalDescription(fn (PunchListIssue $record) => filled($record->resolution_note)
                ? 'Məsul şəxsin qeydi: «'.str($record->resolution_note)->limit(300).'»'
                : 'Düzəliş yoxlanılıb və qəbul edilir.')
            ->modalSubmitActionLabel('Bağla')
            ->schema([
                Forms\Components\Textarea::make('comment')
                    ->label('Şərh (istəyə görə)')
                    ->rows(2)
                    ->maxLength(2000),
            ])
            ->action(fn (PunchListIssue $record, array $data, Action $action) => $this->runTransition(
                $action,
                fn () => $record->close(auth()->user(), $data['comment'] ?? null),
            ))
            ->successNotificationTitle('İrad bağlandı');
    }

    /** Yoxlayan düzəlişi qəbul etmir — səbəb müzakirəyə düşür. */
    private function returnForReworkAction(): Action
    {
        return Action::make('returnForRework')
            ->label('Yenidən işə qaytar')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (PunchListIssue $record) => in_array($record->status, [...PunchListIssue::REVIEWABLE_STATUSES, PunchIssueStatus::Closed], true)
                && auth()->user()?->can('update', $record))
            ->modalHeading(fn (PunchListIssue $record) => 'Yenidən işə qaytar — '.$record->title)
            ->modalSubmitActionLabel('Qaytar')
            ->schema([
                Forms\Components\Textarea::make('reason')
                    ->label('Nə düzəldilməyib?')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(fn (PunchListIssue $record, array $data, Action $action) => $this->runTransition(
                $action,
                fn () => $record->returnForRework(auth()->user(), $data['reason']),
            ))
            ->successNotificationTitle('İrad yenidən işə qaytarıldı');
    }

    /**
     * Müzakirə və tarixçə: fotolar (baxış / endirmə), düzəlişin nəticəsi,
     * bütün dəyişikliklər və şərhlər. Şərh yazmaq redaktə hüququ tələb edir.
     */
    private function historyAction(): Action
    {
        return Action::make('history')
            ->label('Müzakirə və tarixçə')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('gray')
            ->visible(fn (PunchListIssue $record) => auth()->user()?->can('view', $record))
            ->slideOver()
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading(fn (PunchListIssue $record) => $record->title)
            ->modalContent(fn (PunchListIssue $record) => view('filament.supervision.punch-issue-history', [
                'issue' => $record->loadMissing(['responsible', 'createdBy', 'resolvedBy', 'closedBy', 'diaryEntry']),
                'timeline' => app(PunchIssueTimeline::class)->for($record),
            ]))
            ->schema(fn (PunchListIssue $record) => auth()->user()?->can('update', $record)
                ? [
                    Forms\Components\Textarea::make('comment')
                        ->label('Şərh yaz')
                        ->helperText('Daxili müzakirə — müştəriyə göstərilmir.')
                        ->required()
                        ->rows(2)
                        ->maxLength(2000),
                ]
                : [])
            ->modalSubmitActionLabel('Şərhi göndər')
            // `$action` burada pəncərənin «göndər» düyməsidir (Filament onu ötürür).
            ->modalSubmitAction(fn (PunchListIssue $record, Action $action) => auth()->user()?->can('update', $record)
                ? $action
                : false)
            ->modalCancelActionLabel('Bağla')
            ->action(function (PunchListIssue $record, array $data): void {
                abort_unless(auth()->user()?->can('update', $record), 403);

                $record->addComment(auth()->user(), $data['comment']);
            })
            ->successNotificationTitle('Şərh əlavə edildi');
    }

    /** Keçid qadağandırsa (başqası eyni anda dəyişib) 500 əvəzinə səbəb göstərilir. */
    private function runTransition(Action $action, callable $callback): void
    {
        try {
            $callback();
        } catch (RuntimeException|InvalidArgumentException $e) {
            Notification::make()->danger()->title('Status dəyişdirilmədi')->body($e->getMessage())->send();

            $action->halt();
        }
    }

    /** Telefon kamerası və ya qalereya: şəkillər yüklənmədən əvvəl kiçildilir. */
    public static function photoUpload(string $name, string $label): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make($name)
            ->label($label)
            ->image()
            ->multiple()
            ->reorderable()
            ->appendFiles()
            ->maxFiles(10)
            ->maxSize(10240)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->rules([SafeUpload::image()])
            // Telefon fotosu 4–12 MB olur; obyektdə mobil internetlə yükləmə
            // uzun çəkməsin deyə brauzer onu 1920 px-ə qədər kiçildir.
            ->automaticallyResizeImagesMode('contain')
            ->automaticallyResizeImagesToWidth('1920')
            ->automaticallyResizeImagesToHeight('1920')
            ->automaticallyUpscaleImagesWhenResizing(false)
            ->panelLayout('grid')
            ->openable()
            ->downloadable()
            // Disk AÇIQ yazılmalıdır (bax DiaryRelationManager): yükləyən və
            // oxuyan eyni diski göstərməlidir.
            ->disk('public')
            ->directory('punchlist')
            ->columnSpanFull();
    }

    /**
     * Məsul şəxs seçimləri: layihəyə girişi olan işçilər (`assignableUsers`).
     *
     * Köhnə qaydayla girişi OLMAYAN birinə artıq təyin edilmiş irad redaktə
     * ediləndə həmin ad siyahıdan itməsin (sahə səssizcə boş görünərdi) —
     * xəbərdarlıqla saxlanılır ki, menecer onu ya komandaya əlavə etsin, ya da
     * başqasını seçsin.
     *
     * @return array<int, string>
     */
    private function responsibleOptions(?PunchListIssue $record): array
    {
        /** @var Project $project */
        $project = $this->getOwnerRecord();

        $options = PunchListIssue::assignableUsers($project)->pluck('name', 'id')->all();

        $currentId = $record?->responsible_user_id;

        if ($currentId && ! array_key_exists($currentId, $options)) {
            // Əlaqə ayrıca sorğu ilə — dev mühitində lazy loading qadağandır.
            $current = User::query()->find($currentId);

            if ($current) {
                $options[$current->id] = $current->name.' (layihəyə girişi yoxdur)';
            }
        }

        return $options;
    }

    /** @return array<int, string> Layihənin səfər / hesabat qeydləri. */
    private function diaryOptions(): array
    {
        return DiaryEntry::query()
            ->where('project_id', $this->getOwnerRecord()->getKey())
            ->latest('id')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (DiaryEntry $entry) => [
                $entry->id => $entry->kind->label().' · '
                    .($entry->visited_on ?? $entry->created_at)?->format('d.m.Y').' — '
                    .str($entry->body)->limit(60),
            ])
            ->all();
    }
}
