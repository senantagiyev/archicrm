<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\DiaryEntryKind;
use App\Enums\DiaryEntryStatus;
use App\Enums\PunchIssueStatus;
use App\Models\DiaryEntry;
use App\Models\PunchListIssue;
use App\Rules\SafeUpload;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class DiaryRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'diaryEntries';

    protected static ?string $title = 'Səfərlər və hesabatlar';

    protected static ?string $modelLabel = 'Səfər / hesabat';

    protected static ?string $pluralModelLabel = 'Səfərlər və hesabatlar';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\ToggleButtons::make('kind')
                ->label('Növ')
                ->options(DiaryEntryKind::options())
                ->default(DiaryEntryKind::Visit->value)
                ->inline()
                ->required(),
            Forms\Components\DatePicker::make('visited_on')
                ->label('Səfər / hesabat tarixi')
                ->default(fn () => today())
                ->maxDate(fn () => today()->addDay()),
            Forms\Components\Textarea::make('body')
                ->label('Qeyd')
                ->required()
                ->rows(4)
                ->maxLength(2000)
                ->helperText('Obyektdən qısa qeyd — müştəri portalda bunu görəcək.')
                ->columnSpanFull(),
            Forms\Components\Select::make('status')
                ->label('Status')
                ->options(DiaryEntryStatus::options())
                ->default(DiaryEntryStatus::New->value)
                ->required()
                ->native(false)
                ->helperText('Qeydin icra vəziyyəti. Dərc olunmuş qeydin statusunu müştəri də görür.')
                ->columnSpanFull(),
            Forms\Components\FileUpload::make('photos')
                ->label('Fotolar')
                ->image()
                ->multiple()
                ->reorderable()
                ->maxFiles(10)
                ->maxSize(10240)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                ->rules([SafeUpload::image()])
                ->helperText('SVG və icra olunan fayllar qəbul edilmir.')
                // Disk AÇIQ yazılmalıdır. Təyin edilməsə Filament `FILESYSTEM_DISK`
                // defoltunu götürür — Laravel 12-də bu `local`-dır, yəni fayl
                // `storage/app/private`-a düşür. Portal və heyət tərəfi isə hər
                // yerdə `public` diskindən oxuyur: nəticədə paneldən yüklənən
                // hər fayl müştəridə 404/500 verirdi. Yükləyən və oxuyan eyni
                // diski göstərməlidir.
                ->disk('public')
                ->directory('diary-photos')
                ->columnSpanFull(),
            Forms\Components\DateTimePicker::make('published_at')
                ->label('Dərc tarixi')
                ->native(false)
                ->seconds(false)
                ->helperText('Boş qalsa qeyd qaralamadır və müştəriyə görünmür.')
                ->columnSpanFull(),
        ])->columns(['default' => 1, 'sm' => 2]);
    }

    public function table(Table $table): Table
    {
        return $table
            // Policy hər sətir üçün `$record->project`-i oxuyur. Əvvəlcədən
            // yüklənməsə, lokal mühitdə (preventLazyLoading) iki sətirdən sonra
            // tab qəzaya uğrayırdı, prod-da isə hər sətrə ayrıca sorğu gedirdi.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'author'])->withCount('punchListIssues'))
            ->defaultSort('created_at', 'desc')
            ->description('Obyektə səfərlər və hesabatlar: qısa qeyd və fotolar. Dərc olunan qeydi müştəri portalda görür. Səfərdə aşkar edilən iradı sətirdəki «İrad yarat» ilə açın — səfərin fotoları yenidən yüklənmədən irada keçir.')
            ->columns([
                Tables\Columns\TextColumn::make('kind')
                    ->label('Növ')
                    ->badge()
                    ->formatStateUsing(fn (DiaryEntryKind $state) => $state->label())
                    ->color(fn (DiaryEntryKind $state) => $state->color()),
                Tables\Columns\TextColumn::make('visited_on')
                    ->label('Tarix')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('body')
                    ->label('Qeyd')
                    ->limit(80)
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (DiaryEntryStatus $state) => $state->label())
                    ->color(fn (DiaryEntryStatus $state) => $state->color())
                    ->sortable(),
                Tables\Columns\TextColumn::make('photos')
                    ->label('Foto')
                    ->state(fn ($record) => count($record->photos ?? [])),
                Tables\Columns\TextColumn::make('punch_list_issues_count')
                    ->label('İradlar')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('author.name')
                    ->label('Müəllif'),
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Dərc')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Qaralama')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Yaradılıb')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->label('Növ')
                    ->options(DiaryEntryKind::options()),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(DiaryEntryStatus::options()),
                Tables\Filters\TernaryFilter::make('published_at')
                    ->label('Dərc olunub')
                    ->nullable()
                    ->placeholder('Hamısı')
                    ->trueLabel('Dərc olunub')
                    ->falseLabel('Qaralama'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Səfər / hesabat əlavə et')
                    ->mutateDataUsing(function (array $data): array {
                        // Müəllif həmişə serverdən — formada redaktə olunan sahə deyil.
                        $data['author_user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                $this->createIssueAction(),
                // Statusu tam redaktə formasını açmadan dəyişmək üçün — obyektdə
                // iradın icrası tez-tez yenilənir, hər dəfə formanı açmaq yorucudur.
                Actions\Action::make('changeStatus')
                    ->label('Statusu dəyiş')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn ($record) => auth()->user()?->can('update', $record))
                    ->modalHeading('Qeydin statusunu dəyiş')
                    ->modalSubmitActionLabel('Yadda saxla')
                    ->fillForm(fn ($record) => ['status' => $record->status->value])
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options(DiaryEntryStatus::options())
                            ->required()
                            ->native(false),
                    ])
                    ->action(fn ($record, array $data) => $record->update(['status' => $data['status']]))
                    ->successNotificationTitle('Status yeniləndi'),
                Actions\Action::make('publish')
                    ->label('Dərc et')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    // Dərc müştəriyə yazmaqdır: yalnız Baxış hüququ olan rol
                    // qaralamanı portala çıxara bilməməlidir.
                    ->visible(fn ($record) => $record->published_at === null
                        && auth()->user()?->can('update', $record))
                    ->requiresConfirmation()
                    ->modalHeading('Qeydi müştəriyə dərc et')
                    ->modalDescription('Qeyd və fotolar müştəri portalında görünəcək.')
                    ->action(fn ($record) => $record->update(['published_at' => now()])),
                Actions\Action::make('unpublish')
                    ->label('Dərcdən çıxar')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn ($record) => $record->published_at !== null
                        && auth()->user()?->can('update', $record))
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->update(['published_at' => null])),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->requiresConfirmation(),
            ]);
    }

    /**
     * Səfərdə aşkar edilən iradı birbaşa bu qeyddən açmaq (ssenari 3).
     *
     * Seçilən fotolar irada YOL kimi keçir — fayl kopyalanmır və yenidən
     * yüklənmir; iradın özü «İradlar» siyahısında görünür və səfərə bağlıdır.
     */
    private function createIssueAction(): Actions\Action
    {
        return Actions\Action::make('createIssue')
            ->label('İrad yarat')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->visible(fn (DiaryEntry $record) => auth()->user()?->can('create', PunchListIssue::class)
                && auth()->user()?->can('update', $record->loadMissing('project')->project))
            ->modalHeading('Səfərdən irad yarat')
            ->modalSubmitActionLabel('İrad yarat')
            ->schema(fn (DiaryEntry $record) => [
                Forms\Components\TextInput::make('title')
                    ->label('Nə aşkar edilib?')
                    ->required()
                    ->maxLength(191),
                Forms\Components\CheckboxList::make('photos')
                    ->label('Səfərin fotolarından')
                    ->helperText('Seçilən fotolar irada əlavə olunur — yenidən yükləmək lazım deyil.')
                    ->options(fn () => collect($record->photoPaths())
                        ->mapWithKeys(fn (string $path, int $index) => [$path => new HtmlString(
                            '<img src="'.e(Storage::disk('public')->url($path)).'" alt="Foto '.($index + 1).'" style="height:64px;width:64px;object-fit:cover;border-radius:6px">'
                        )])
                        ->all())
                    ->allowHtml()
                    // Yalnız bu qeydin öz fotoları (yad yol → validasiya xətası).
                    ->in(fn () => $record->photoPaths())
                    ->columns(4)
                    ->visible(fn () => $record->photoPaths() !== []),
                Forms\Components\Select::make('responsible_user_id')
                    ->label('Məsul şəxs')
                    ->options(fn () => PunchListIssue::assignableUsers($record->loadMissing('project')->project)->pluck('name', 'id')->all())
                    ->searchable()
                    ->native(false),
                Forms\Components\DatePicker::make('due_date')
                    ->label('Aradan qaldırılma müddəti'),
            ])
            ->action(function (DiaryEntry $record, array $data): void {
                // Yalnız bu qeydin öz fotoları — brauzerdən gələn yad yol qəbul edilmir.
                $photos = array_values(array_intersect($record->photoPaths(), (array) ($data['photos'] ?? [])));

                $record->loadMissing('project')->project->punchListIssues()->create([
                    'diary_entry_id' => $record->getKey(),
                    'title' => $data['title'],
                    'photos' => $photos,
                    'responsible_user_id' => $data['responsible_user_id'] ?? null,
                    'due_date' => $data['due_date'] ?? null,
                    'status' => filled($data['responsible_user_id'] ?? null)
                        ? PunchIssueStatus::Assigned->value
                        : PunchIssueStatus::Open->value,
                ]);
            })
            ->successNotificationTitle('İrad yaradıldı — «İradlar» siyahısındadır');
    }
}
