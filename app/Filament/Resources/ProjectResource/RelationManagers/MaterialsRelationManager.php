<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\DeliverableType;
use App\Enums\DeliverableVersionStatus;
use App\Filament\Actions\ChangesAndDecisionsAction;
use App\Filament\Concerns\OptimisticLock;
use App\Filament\Resources\ApprovalResource\ApprovalRequestForm;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\Project;
use App\Models\Stage;
use App\Models\User;
use App\Rules\SafeUpload;
use App\Services\Design\MaterialService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * «Fayllar» — layihə materiallarının kartları (TZ «Layihə naviqasiyası» §5–6).
 *
 * Köhnə «Dizayn (Deliverables)», «Fayllar» və «Razılaşdırmalar» tablarının iş
 * yeri: siyahıda ayrı yükləmələr deyil, MƏNTİQİ material kartları var. Yeni
 * versiya həmin karta əlavə olunur (əvvəlkilər, şərhlər və qərarlar qalır).
 *
 *  - «Yeni material» ilə «Yeni versiya» ayrıdır: birincisi yeni kart, ikincisi
 *    mövcud kartın sətir əməliyyatıdır.
 *  - Mərhələ kontekstdən (Mərhələlər → «Materialları aç» → `?stage=ID`, aktiv
 *    süzgəc) və ya kateqoriyanın standart mərhələsindən avtomatik dolur; dəyər
 *    görünür və dəyişdirilə bilər.
 *  - Dərc ≠ razılaşdırma. Müştəri yalnız dərc edilmiş versiyanı görür.
 *  - Bütün fayllar avtorizasiyalı marşrutla (`materials.download`) verilir.
 */
class MaterialsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'deliverables';

    protected static ?string $title = 'Fayllar';

    protected static ?string $modelLabel = 'Material';

    protected static ?string $pluralModelLabel = 'Materiallar';

    private const ACCEPTED_TYPES = [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg', 'image/png', 'image/webp', 'text/plain', 'text/csv',
    ];

    /** Kartın metadata redaktəsi (fayl yox — fayl yalnız yeni versiya ilə dəyişir). */
    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Ad')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
            $this->categoryField(),
            $this->stageField(),
            Forms\Components\Select::make('responsible_user_id')
                ->label('Məsul')
                ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->native(false),
            Forms\Components\Textarea::make('description')
                ->label('Təsvir')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            // Policy və kart sütunları hər sətir üçün bunları oxuyur — əvvəlcədən
            // yüklənməsə, lokal mühitdə (preventLazyLoading) cədvəl çökür.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['project', 'stage', 'currentVersion', 'publishedVersion', 'approvals'])
                ->withCount('versions')
                ->withExists(['versions as has_immutable_version' => fn (Builder $query) => $query->whereIn('status', [
                    DeliverableVersionStatus::Approved->value,
                    DeliverableVersionStatus::Locked->value,
                ])]))
            ->columns([
                Stack::make([
                    Split::make([
                        Tables\Columns\TextColumn::make('title')
                            ->label('Material')
                            ->weight(FontWeight::Bold)
                            ->searchable()
                            ->grow(),
                        Tables\Columns\TextColumn::make('approval_state')
                            ->label('Razılaşdırma')
                            ->badge()
                            ->state(fn (Deliverable $record) => $record->approvalState()->label())
                            ->color(fn (Deliverable $record) => $record->approvalState()->color())
                            ->grow(false),
                    ]),
                    Tables\Columns\TextColumn::make('type')
                        ->label('Kateqoriya')
                        ->formatStateUsing(fn (Deliverable $record) => collect([
                            $record->type?->label(),
                            $record->stage?->name ?? 'Ümumi (mərhələsiz)',
                        ])->filter()->implode(' · '))
                        ->color('gray'),
                    Split::make([
                        Tables\Columns\TextColumn::make('current_version')
                            ->label('Versiya')
                            ->state(fn (Deliverable $record) => $record->currentVersion
                                ? 'v'.$record->currentVersion->version_number.' · '.$record->versions_count.' versiya'
                                : 'Versiya yoxdur')
                            ->badge()
                            ->color('gray'),
                        Tables\Columns\TextColumn::make('visibility')
                            ->label('Görünürlük')
                            ->badge()
                            ->state(fn (Deliverable $record) => $this->visibilityLabel($record))
                            ->color(fn (Deliverable $record) => $record->isPublished() ? 'info' : 'gray'),
                        Tables\Columns\TextColumn::make('updated_at')
                            ->label('Yenilənib')
                            ->dateTime('d.m.Y H:i')
                            ->color('gray')
                            ->sortable()
                            ->grow(false),
                    ]),
                ])->space(2),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('stage_id')
                    ->label('Mərhələ')
                    ->options(fn () => ['none' => 'Ümumi (mərhələsiz)'] + $this->stageOptions())
                    // `?stage=ID` — Mərhələlər tabının «Materialları aç» keçidi.
                    // Yalnız ilk açılışda oxunur (Livewire yeniləmə sorğusunda URL
                    // parametri yoxdur), ona görə «Sıfırla» süzgəci həqiqətən təmizləyir.
                    ->default(fn () => $this->stageFromUrl())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match (true) {
                            blank($value) => $query,
                            $value === 'none' => $query->whereNull('stage_id'),
                            default => $query->where('stage_id', $value),
                        };
                    }),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Kateqoriya')
                    ->options(DeliverableType::options()),
                Tables\Filters\TernaryFilter::make('published')
                    ->label('Görünürlük')
                    ->placeholder('Hamısı')
                    ->trueLabel('Müştəriyə dərc edilib')
                    ->falseLabel('Daxili')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('published_version_id'),
                        false: fn (Builder $query) => $query->whereNull('published_version_id'),
                    ),
                // `?material=ID` — İcmaldan / hadisə lentindən birbaşa karta keçid.
                Tables\Filters\SelectFilter::make('id')
                    ->label('Material')
                    ->options(fn () => $this->getOwnerRecord()->deliverables()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->default(fn () => $this->materialFromUrl()),
            ])
            ->headerActions([
                $this->createMaterialAction(),
            ])
            ->actions([
                $this->viewAction(),
                $this->newVersionAction(),
                Actions\ActionGroup::make([
                    $this->publishAction(),
                    $this->unpublishAction(),
                    $this->sendForApprovalAction(),
                    $this->commentAction(),
                    // Materiala aid dəyişiklik sorğuları və qərarlar (TZ §9).
                    ChangesAndDecisionsAction::make(),
                    Actions\EditAction::make()
                        ->label('Redaktə et')
                        ->using(OptimisticLock::updateUsing()),
                    // Policy: təsdiqlənmiş versiyası olan material silinmir.
                    Actions\DeleteAction::make()
                        ->requiresConfirmation()
                        ->modalDescription('Material kartı bütün versiyaları ilə gizlədiləcək (tarixçə saxlanılır).'),
                ]),
            ])
            ->emptyStateHeading('Hələ material yoxdur')
            ->emptyStateDescription('Plan, vizualizasiya, çertyoj və ya sənəd yükləmək üçün «Yeni material» düyməsini basın. Eyni materialın düzəlişi kartın «Yeni versiya» əməliyyatı ilə əlavə olunur.');
    }

    // ───────────────────────────── Əməliyyatlar ─────────────────────────────

    private function createMaterialAction(): Actions\CreateAction
    {
        return Actions\CreateAction::make()
            ->label('Yeni material')
            ->icon('heroicon-o-plus')
            ->modalHeading('Yeni material')
            ->modalDescription('Yeni material kartı yaradılır. Mövcud materialın düzəlişini kartın «Yeni versiya» əməliyyatı ilə əlavə edin.')
            ->schema(fn (): array => [
                $this->filesField('files')
                    ->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                        if (blank($get('title')) && filled($name = $this->titleFromUpload($state))) {
                            $set('title', $name);
                        }
                    }),
                Forms\Components\TextInput::make('title')
                    ->label('Ad')
                    ->required()
                    ->maxLength(191)
                    ->helperText('Fayl adından götürülür — dəyişə bilərsiniz.'),
                $this->categoryField()
                    ->default(fn () => $this->contextCategory() ?? DeliverableType::Layout->value)
                    ->live()
                    ->afterStateUpdated(function ($state, $old, Get $get, Set $set): void {
                        // Mərhələ kontekstdən gəlirsə toxunulmur. Əks halda yalnız
                        // istifadəçinin özü seçmədiyi (boş və ya əvvəlki kateqoriyanın
                        // standart mərhələsi olan) dəyər yenilənir.
                        if ($this->contextStage() !== null) {
                            return;
                        }

                        $service = app(MaterialService::class);
                        $current = $get('stage_id');
                        $previousDefault = $service->defaultStageFor($this->project(), $old)?->id;

                        if (blank($current) || (int) $current === (int) $previousDefault) {
                            $set('stage_id', $service->defaultStageFor($this->project(), $state)?->id);
                        }
                    }),
                $this->stageField()
                    ->default(fn () => $this->contextStage()?->id
                        ?? app(MaterialService::class)->defaultStageFor($this->project(), $this->contextCategory() ?? DeliverableType::Layout->value)?->id)
                    ->helperText(fn () => $this->contextStage()
                        ? 'Mərhələ süzgəcdən avtomatik seçildi — dəyişə bilərsiniz.'
                        : 'Kateqoriyanın standart mərhələsi avtomatik seçilir. Ümumi material mərhələsiz qala bilər.'),
                $this->visibilityField(),
                Section::make('Əlavə parametrlər')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('description')->label('Təsvir')->rows(2),
                        Forms\Components\Select::make('responsible_user_id')
                            ->label('Məsul')
                            ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->native(false),
                        Forms\Components\Textarea::make('change_summary')->label('Versiya qeydi')->rows(2),
                    ]),
            ])
            ->using(function (array $data): Model {
                $this->authorizeOnProject('update');

                return app(MaterialService::class)->create($this->project(), $data, auth()->user());
            })
            ->successNotificationTitle('Material yaradıldı');
    }

    private function newVersionAction(): Actions\Action
    {
        return Actions\Action::make('newVersion')
            ->label('Yeni versiya')
            ->icon('heroicon-o-document-plus')
            ->visible(fn (Deliverable $record) => auth()->user()?->can('update', $record))
            ->modalHeading(fn (Deliverable $record) => 'Yeni versiya: '.$record->title)
            ->modalDescription(fn (Deliverable $record) => $record->isPublished()
                ? 'Əvvəlki versiyalar saxlanılır. Müştəri hələ v'.$record->publishedVersion?->version_number.' versiyasını görür — yeni versiya özü-özünə dərc edilmir və əvvəlki təsdiqi miras almır.'
                : 'Əvvəlki versiyalar saxlanılır. Yeni versiya əvvəlki təsdiqi miras almır.')
            ->schema([
                $this->filesField('files'),
                Forms\Components\Textarea::make('change_summary')
                    ->label('Nə dəyişib?')
                    ->rows(2),
                Forms\Components\Toggle::make('publish')
                    ->label('Bu versiyanı müştəriyə dərc et')
                    ->default(false),
            ])
            ->action(function (Deliverable $record, array $data, MaterialService $service): void {
                abort_unless(auth()->user()?->can('update', $record), 403);

                $service->addVersion(
                    $record,
                    $data['files'] ?? [],
                    $data['file_names'] ?? [],
                    auth()->user(),
                    $data['change_summary'] ?? null,
                    (bool) ($data['publish'] ?? false),
                );
            })
            ->successNotificationTitle('Yeni versiya əlavə olundu');
    }

    private function viewAction(): Actions\Action
    {
        return Actions\Action::make('view')
            ->label('Kartı aç')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn (Deliverable $record) => auth()->user()?->can('view', $record))
            ->slideOver()
            ->modalHeading(fn (Deliverable $record) => $record->title)
            ->modalContent(fn (Deliverable $record) => view('filament.materials.card', [
                'material' => $record->load([
                    'stage', 'publishedVersion',
                    'versions.createdBy', 'versions.uploadedBy', 'versions.comments.author',
                ]),
                'history' => $record->approvalHistory(),
                'state' => $record->approvalState(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Bağla');
    }

    private function publishAction(): Actions\Action
    {
        return Actions\Action::make('publish')
            ->label('Müştəriyə dərc et')
            ->icon('heroicon-o-eye')
            ->color('info')
            ->visible(fn (Deliverable $record) => $record->current_version_id
                && auth()->user()?->can('update', $record))
            ->modalDescription('Müştəri YALNIZ seçilmiş versiyanı görəcək. Dərc etmək təsdiq demək deyil — razılaşdırma ayrıca göndərilir.')
            ->schema([
                Forms\Components\Select::make('version_id')
                    ->label('Versiya')
                    ->options(fn (Deliverable $record) => $this->versionOptions($record))
                    ->default(fn (Deliverable $record) => $record->current_version_id)
                    ->required()
                    ->native(false),
            ])
            ->action(function (Deliverable $record, array $data, MaterialService $service): void {
                abort_unless(auth()->user()?->can('update', $record), 403);

                $service->publish($record, $record->versions()->findOrFail($data['version_id']));
            })
            ->successNotificationTitle('Versiya müştəriyə dərc edildi');
    }

    private function unpublishAction(): Actions\Action
    {
        return Actions\Action::make('unpublish')
            ->label('Dərcdən çıxar')
            ->icon('heroicon-o-eye-slash')
            ->color('gray')
            ->visible(fn (Deliverable $record) => $record->isPublished()
                && auth()->user()?->can('update', $record))
            ->requiresConfirmation()
            ->modalDescription('Material müştəri portalından gizlədiləcək. Versiyalar və tarixçə saxlanılır.')
            ->action(function (Deliverable $record, MaterialService $service): void {
                abort_unless(auth()->user()?->can('update', $record), 403);

                $service->unpublish($record);
            })
            ->successNotificationTitle('Material daxili edildi');
    }

    private function sendForApprovalAction(): Actions\Action
    {
        return Actions\Action::make('sendForApproval')
            ->label('Razılaşdırmaya göndər')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            // Cari versiya təsdiqlənib/kilidlənibsə — yeni versiya lazımdır.
            ->visible(fn (Deliverable $record) => $record->currentVersion !== null
                && ! $record->currentVersion->status->isImmutable()
                && $record->currentVersion->status !== DeliverableVersionStatus::Superseded
                && auth()->user()?->can('update', $record))
            ->modalDescription('Seçilmiş versiya müştəriyə təsdiq üçün göndərilir (dərc edilməyibsə, dərc olunur). Müştəri «Təsdiqlə» və ya «Düzəliş tələb et» cavabı verir. Materialın təsdiqi mərhələni tamamlamır.')
            ->schema([
                Forms\Components\Select::make('version_id')
                    ->label('Versiya')
                    ->options(fn (Deliverable $record) => $this->sendableVersionOptions($record))
                    ->default(fn (Deliverable $record) => array_key_first($this->sendableVersionOptions($record)))
                    ->required()
                    ->native(false),
                Forms\Components\Textarea::make('note')
                    ->label('Müştəriyə qeyd')
                    ->placeholder('Nəyə baxmalı, nəyi təsdiqləməlidir?')
                    ->rows(2)
                    ->maxLength(2000),
                Forms\Components\DatePicker::make('respond_by')
                    ->label('Cavab müddəti')
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->minDate(today())
                    ->default(fn () => ApprovalRequestForm::defaultRespondBy($this->project()))
                    ->helperText('Standart olaraq layihənin «müştərinin cavab müddəti» qədər gün sonra.'),
            ])
            ->action(function (Deliverable $record, array $data, MaterialService $service): void {
                abort_unless(auth()->user()?->can('update', $record), 403);
                abort_unless(array_key_exists((int) $data['version_id'], $this->sendableVersionOptions($record)), 403);

                $service->sendForApproval(
                    $record,
                    $record->versions()->findOrFail($data['version_id']),
                    auth()->user(),
                    filled($data['respond_by'] ?? null) ? Carbon::parse($data['respond_by']) : null,
                    $data['note'] ?? null,
                );
            })
            ->successNotificationTitle('Razılaşdırmaya göndərildi');
    }

    private function commentAction(): Actions\Action
    {
        return Actions\Action::make('comment')
            ->label('Şərh yaz')
            ->icon('heroicon-o-chat-bubble-left')
            ->color('gray')
            ->visible(fn (Deliverable $record) => $record->current_version_id
                && auth()->user()?->can('update', $record))
            ->schema([
                Forms\Components\Select::make('version_id')
                    ->label('Versiya')
                    ->options(fn (Deliverable $record) => $this->versionOptions($record))
                    ->default(fn (Deliverable $record) => $record->current_version_id)
                    ->required()
                    ->native(false),
                Forms\Components\Textarea::make('body')
                    ->label('Şərh')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
                Forms\Components\Toggle::make('is_internal')
                    ->label('Daxili şərh (müştəri görmür)')
                    ->default(true),
            ])
            ->action(function (Deliverable $record, array $data, MaterialService $service): void {
                abort_unless(auth()->user()?->can('update', $record), 403);

                $service->addComment(
                    $record->versions()->findOrFail($data['version_id']),
                    auth()->user(),
                    (string) $data['body'],
                    (bool) ($data['is_internal'] ?? true),
                );
            })
            ->successNotificationTitle('Şərh əlavə olundu');
    }

    // ───────────────────────────── Sahələr ─────────────────────────────

    private function filesField(string $name): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make($name)
            ->label('Fayl(lar)')
            ->multiple()
            ->maxFiles(20)
            ->required()
            ->acceptedFileTypes(self::ACCEPTED_TYPES)
            ->maxSize(51200)
            ->rules([SafeUpload::document()])
            ->helperText('Bir materiala bir neçə əlaqəli fayl əlavə etmək olar. PDF, Word, Excel, şəkil. SVG və icra olunan fayllar qəbul edilmir.')
            ->disk(MaterialService::DISK)
            ->directory(MaterialService::DIRECTORY)
            ->storeFileNamesIn('file_names')
            ->columnSpanFull();
    }

    private function categoryField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('type')
            ->label('Kateqoriya')
            ->options(DeliverableType::options())
            ->required()
            ->native(false);
    }

    private function stageField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('stage_id')
            ->label('Mərhələ')
            ->options(fn () => $this->stageOptions())
            ->placeholder('Ümumi (mərhələsiz)')
            ->native(false);
    }

    private function visibilityField(): Forms\Components\ToggleButtons
    {
        return Forms\Components\ToggleButtons::make('visibility')
            ->label('Görünürlük')
            ->options([
                MaterialService::VISIBILITY_INTERNAL => 'Daxili',
                MaterialService::VISIBILITY_PUBLISHED => 'Müştəriyə dərc et',
            ])
            ->default(MaterialService::VISIBILITY_INTERNAL)
            ->inline()
            ->required()
            ->helperText('Dərc etmək təsdiq demək deyil — razılaşdırma ayrıca göndərilir.');
    }

    // ───────────────────────────── Köməkçilər ─────────────────────────────

    private function project(): Project
    {
        /** @var Project $project */
        $project = $this->getOwnerRecord();

        return $project;
    }

    /** @return array<int, string> */
    private function stageOptions(): array
    {
        return $this->project()->stages()->orderBy('position')->pluck('name', 'id')->all();
    }

    /** `?stage=ID` yalnız bu layihənin mərhələsidirsə. */
    private function stageFromUrl(): ?string
    {
        $id = request()->query('stage');

        return is_scalar($id) && ctype_digit((string) $id) && $this->project()->stages()->whereKey($id)->exists()
            ? (string) $id
            : null;
    }

    private function materialFromUrl(): ?string
    {
        $id = request()->query('material');

        return is_scalar($id) && ctype_digit((string) $id) && $this->project()->deliverables()->whereKey($id)->exists()
            ? (string) $id
            : null;
    }

    /** Aktiv mərhələ süzgəci = yükləmə konteksti. */
    private function contextStage(): ?Stage
    {
        $value = $this->getTableFilterState('stage_id')['value'] ?? null;

        if (blank($value) || $value === 'none') {
            return null;
        }

        return $this->project()->stages()->find($value);
    }

    /** Mərhələ kontekstindən məlum kateqoriya (mərhələnin standart kateqoriyası). */
    private function contextCategory(): ?string
    {
        $filtered = $this->getTableFilterState('type')['value'] ?? null;

        return DeliverableType::tryFrom((string) $filtered)?->value
            ?? app(MaterialService::class)->defaultCategoryFor($this->contextStage());
    }

    /** @return array<int, string> */
    private function versionOptions(Deliverable $record): array
    {
        return $record->versions()->get()
            ->mapWithKeys(fn (DeliverableVersion $version) => [$version->id => $this->versionLabel($record, $version)])
            ->all();
    }

    /** Razılaşdırmaya göndərilə bilən versiyalar (təsdiqlənmiş/kilidli/əvəzlənmiş yox). @return array<int, string> */
    private function sendableVersionOptions(Deliverable $record): array
    {
        return $record->versions()->get()
            ->reject(fn (DeliverableVersion $version) => $version->status->isImmutable()
                || $version->status === DeliverableVersionStatus::Superseded)
            ->mapWithKeys(fn (DeliverableVersion $version) => [$version->id => $this->versionLabel($record, $version)])
            ->all();
    }

    private function versionLabel(Deliverable $record, DeliverableVersion $version): string
    {
        return 'v'.$version->version_number.' · '.$version->status->label()
            .((int) $record->published_version_id === (int) $version->id ? ' · müştəriyə dərc edilib' : '')
            .((int) $record->current_version_id === (int) $version->id ? ' · cari' : '');
    }

    private function visibilityLabel(Deliverable $record): string
    {
        if (! $record->isPublished()) {
            return 'Daxili';
        }

        $published = $record->publishedVersion?->version_number;
        $current = $record->currentVersion?->version_number;

        return 'Müştəriyə: v'.$published.($current && $current !== $published ? ' (v'.$current.' daxili)' : '');
    }

    /** Fayl adından materialın adı: genişlənmə atılır, «_» / «-» boşluğa çevrilir. */
    private function titleFromUpload(mixed $state): ?string
    {
        $file = collect(is_array($state) ? $state : [$state])->first();

        $name = match (true) {
            $file instanceof TemporaryUploadedFile => $file->getClientOriginalName(),
            is_string($file) => basename($file),
            default => null,
        };

        if (blank($name)) {
            return null;
        }

        return Str::of(pathinfo($name, PATHINFO_FILENAME))->replace(['_', '-'], ' ')->squish()->limit(191, '')->toString() ?: null;
    }

    /** Layihə üzvlüyü də yoxlanılsın deyə yeni (saxlanmamış) kart üzərində policy. */
    private function authorizeOnProject(string $ability): void
    {
        $probe = new Deliverable(['project_id' => $this->project()->id]);
        $probe->setRelation('project', $this->project());

        Gate::authorize($ability, $probe);
    }
}
