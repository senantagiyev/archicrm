<?php

namespace App\Filament\Resources;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Filament\Resources\BriefTemplateResource\Pages;
use App\Filament\Resources\BriefTemplateResource\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\BriefTemplateResource\RelationManagers\SectionsRelationManager;
use App\Models\BriefTemplate;
use App\Models\Project;
use App\Models\User;
use App\Services\Brief\BriefBuilderService;
use App\Services\Brief\BriefService;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Briflər — studiyanın öz brifini qurduğu və müştəriyə göndərdiyi yer.
 *
 * Siyahıda üç növ sətir var: studiyanın FƏRDİ brifləri, platformanın SİSTEM
 * şablonları (Quick, Yaşayış, Kommersiya) və sistem şablonunun studiya
 * NÜSXƏLƏRİ. Sistem şablonu ortaq olduğu üçün yerində dəyişmir — «Redaktə et»
 * studiyaya tam nüsxə verir və nüsxə siyahıda orijinalın yerini tutur
 * (copy-on-write). Suallar və bölmələr redaktə səhifəsində qurulur.
 */
class BriefTemplateResource extends Resource
{
    protected static ?string $model = BriefTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Layihələr';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Briflər';

    protected static ?string $modelLabel = 'Brif';

    protected static ?string $pluralModelLabel = 'Briflər';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Sistem şablonları + yalnız BU studiyanın brifləri; fərdilər üstdə.
     * Studiyanın nüsxəsi olan sistem şablonu siyahıdan düşür (`catalogFor`).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->catalogFor(auth()->user()?->tenant_id)
            ->withCount('questions')
            ->orderByRaw('tenant_id is null')
            ->orderBy('position')
            ->orderBy('id');
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Brif haqqında')
                ->description('Ad müştəriyə portalda görünür — məsələn «Sənan bəy üçün hazırlanmış brif».')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Brifin adı')
                        ->required()
                        ->maxLength(191)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('summary')
                        ->label('Qısa təsvir (istəyə görə)')
                        ->rows(2)
                        ->maxLength(1000)
                        ->helperText('Müştəri brifi açanda bu mətni başlığın altında görür.')
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('active')
                        ->label('Aktiv')
                        ->helperText('Deaktiv brif göndərmə siyahısında görünmür; artıq göndərilmiş nüsxələrə təsir etmir.')
                        ->default(true)
                        ->visibleOn('edit'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Ad')
                    ->formatStateUsing(fn ($state) => is_array($state) ? ($state['az'] ?? reset($state)) : $state)
                    ->description(fn (BriefTemplate $record) => match (true) {
                        $record->isFork() => 'Sistem şablonunun sizin versiyanız · '.$record->levelLabel(),
                        $record->isSystem() => $record->levelLabel(),
                        default => null,
                    })
                    ->searchable()
                    ->weight('semibold'),
                Tables\Columns\TextColumn::make('origin')
                    ->label('Mənşə')
                    ->state(fn (BriefTemplate $record) => match (true) {
                        $record->isFork() => 'Redaktə edilmiş şablon',
                        $record->isCustom() => 'Fərdi brif',
                        default => 'Sistem şablonu',
                    })
                    ->badge()
                    ->color(fn (BriefTemplate $record) => match (true) {
                        $record->isFork() => 'info',
                        $record->isCustom() => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('questions_count')
                    ->label('Sual')
                    ->numeric()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Hazırlayan')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('active')
                    ->label('Aktiv')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Yaradılıb')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('custom')
                    ->label('Mənşə')
                    ->trueLabel('Yalnız fərdi briflər')
                    ->falseLabel('Yalnız sistem şablonları')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('tenant_id'),
                        false: fn (Builder $q) => $q->whereNull('tenant_id'),
                    ),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->label('Redaktə et')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (BriefTemplate $record) => auth()->user()?->can('update', $record)),
                static::customizeAction(),
                static::sendToProjectAction(),
                Actions\DeleteAction::make()
                    ->label(fn (BriefTemplate $record) => $record->isFork() ? 'Sistem versiyasına qayıt' : 'Sil')
                    ->icon(fn (BriefTemplate $record) => $record->isFork() ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-trash')
                    ->requiresConfirmation()
                    ->modalDescription(fn (BriefTemplate $record) => static::deleteDescription($record))
                    ->visible(fn (BriefTemplate $record) => auth()->user()?->can('delete', $record)),
            ])
            ->emptyStateHeading('Hələ brif yoxdur')
            ->emptyStateDescription('«Yeni brif» ilə öz sual dəstinizi qurun və ya sistem şablonlarından birini layihəyə göndərin.');
    }

    /**
     * Sistem şablonunun «Redaktə et» düyməsi — copy-on-write.
     *
     * Ortaq sətir dəyişdirilmir: studiyaya bütün bölmələri, sualları, şərti
     * məntiqi və şəkilləri ilə TAM nüsxə yaradılır, sonra nüsxənin redaktə
     * səhifəsi açılır. Nüsxə bu studiyanın siyahısında orijinalın yerini tutur
     * və yeni layihələr onunla açılır; digər studiyalar heç nə hiss etmir.
     */
    public static function customizeAction(): Actions\Action
    {
        return Actions\Action::make('customize')
            ->label('Redaktə et')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (BriefTemplate $record) => auth()->user()?->can('customize', $record) ?? false)
            ->authorize(fn (BriefTemplate $record) => auth()->user()?->can('customize', $record) ?? false)
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-document-duplicate')
            ->modalHeading(fn (BriefTemplate $record) => '«'.$record->getTranslation('name', 'az').'» şablonunu redaktə et')
            ->modalDescription('Sistem şablonu bütün studiyalar üçün ortaqdır, ona görə studiyanız üçün onun tam nüsxəsi yaradılacaq: bütün bölmələr, suallar, variantlar və şəkillər. Nüsxəni istədiyiniz kimi dəyişirsiniz; siyahıda və yeni layihələrdə orijinalın yerini o tutur. Artıq göndərilmiş briflər köhnə versiyada qalır. Nüsxəni silsəniz, sistem versiyası geri qayıdır.')
            ->modalSubmitActionLabel('Nüsxə yarat və redaktə et')
            ->action(function (BriefTemplate $record, Component $livewire): void {
                $user = auth()->user();
                abort_unless($user instanceof User && $user->can('customize', $record), 403);

                $fork = app(BriefBuilderService::class)->forkTemplate($record, $user);

                Notification::make()
                    ->success()
                    ->title('Şablonun nüsxəsi yaradıldı')
                    ->body('İndi sualları və bölmələri dəyişə bilərsiniz. Orijinal şablon dəyişməyib.')
                    ->send();

                $livewire->redirect(static::getUrl('edit', ['record' => $fork]));
            });
    }

    public static function deleteDescription(BriefTemplate $record): string
    {
        return $record->isFork()
            ? 'Sizin versiyanız silinəcək və siyahıda yenidən orijinal sistem şablonu görünəcək. Bu, yalnız versiyanız heç bir layihəyə göndərilməyibsə mümkündür.'
            : 'Brif və onun bütün sualları silinəcək. Bu brif heç bir layihəyə göndərilməyibsə mümkündür.';
    }

    /**
     * Brifi layihəyə göndərmək — həm siyahıdan, həm redaktə səhifəsindən.
     *
     * Göndərmə = müştəriyə TƏQDİMAT: layihənin brifi bu şablona bağlanır,
     * `presented_at` vurulur, müştərinin portal hesablarına bildiriş gedir və
     * portalda brif tabı açılır. Ona qədər müştəri heç bir brif görmür.
     */
    public static function sendToProjectAction(): Actions\Action
    {
        return Actions\Action::make('sendToProject')
            ->label('Layihəyə göndər')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->visible(fn () => static::maySend())
            ->authorize(fn () => static::maySend())
            ->modalHeading(fn (BriefTemplate $record) => '«'.$record->getTranslation('name', 'az').'» brifini müştəriyə göndər')
            ->modalDescription('Seçdiyiniz layihənin müştərisi bildiriş alacaq və brif portalda açılacaq. Layihədə başqa briflər varsa, onlar yerində qalır — bu brif onların yanına ayrıca əlavə olunur. Bu brif həmin layihəyə artıq göndərilibsə, yenisi yaranmır.')
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => TaskResource::visibleProjectOptions())
                    ->searchable()
                    ->required()
                    ->native(false)
                    // Siyahını gizlətmək icazə deyil: id payload-dan gəlir, ona görə
                    // «yalnız öz layihələri» rolu üçün eyni qayda serverdə də yoxlanılır.
                    ->rule(fn () => fn (string $attribute, mixed $value, \Closure $fail) => TaskResource::projectIsVisible((int) $value)
                        ? null
                        : $fail('Bu layihəyə çıxışınız yoxdur.')),
            ])
            ->action(function (BriefTemplate $record, array $data): void {
                abort_unless(static::maySend(), 403);
                abort_unless($record->active, 422, 'Deaktiv brif göndərilə bilməz.');

                $project = Project::query()->findOrFail($data['project_id']);
                abort_unless(TaskResource::projectIsVisible($project->id), 403);

                app(BriefService::class)->present($project, $record, auth()->user());

                Notification::make()
                    ->success()
                    ->title('Brif göndərildi')
                    ->body('«'.$project->name.'» layihəsinin müştərisi bildiriş aldı; brif portalda açıqdır.')
                    ->send();
            });
    }

    /** Göndərmək brifi müştəriyə açır — oxu deyil, tam səlahiyyət tələb edir. */
    public static function maySend(): bool
    {
        $user = auth()->user();

        // Yalnız İŞÇİ: matris müştəri hesabını tanımır, ona görə tip yoxlanılır.
        return $user instanceof User && AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full);
    }

    public static function getRelations(): array
    {
        return [QuestionsRelationManager::class, SectionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBriefTemplates::route('/'),
            'create' => Pages\CreateBriefTemplate::route('/create'),
            'edit' => Pages\EditBriefTemplate::route('/{record}/edit'),
        ];
    }
}
