<?php

namespace App\Filament\Resources;

use App\Enums\AccessLevel;
use App\Enums\DocumentType;
use App\Filament\Concerns\ScopesToOwnProjects;
use App\Filament\Resources\ContractDocumentResource\Pages;
use App\Models\Document;
use App\Policies\DocumentPolicy;
use App\Rules\SafeUpload;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Mühasibatlıq → Müqavilələr və sənədlər (TZ «Layihə naviqasiyası» §9:
 * müqavilələr və mühasibat sənədləri mühasibatlığa köçürülür).
 *
 * Ayrı cədvəl YOXDUR — eyni `documents` sətirləri, yalnız mühasibat tipləri
 * (`DocumentType::accounting()`: müqavilə, qəbul aktı). Qalan tiplər layihə
 * materiallarıdır və bu resursda görünmür (birbaşa URL ilə də — sorğu
 * süzgəci route binding-ə də tətbiq olunur). Sahələr köhnə
 * `DocumentsRelationManager`-dəki ilə eynidir. Hüquq: «Ödənişlər» domeni
 * (+ öz layihəsi) — mühasib işləyə bilsin, dizayner maliyyə sənədini görməsin.
 */
class ContractDocumentResource extends Resource
{
    use ScopesToOwnProjects;

    protected static ?string $model = Document::class;

    protected static ?string $slug = 'contract-documents';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Maliyyə';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Müqavilələr və sənədlər';

    protected static ?string $modelLabel = 'Müqavilə / sənəd';

    protected static ?string $pluralModelLabel = 'Müqavilələr və sənədlər';

    // Başlıq olduğu kimi qalsın — Filament «və»-ni «Və» edirdi.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'title';

    /** Siyahı və yaratma — «Ödənişlər» domeni (sətir hüququ DocumentPolicy-dədir). */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && DocumentPolicy::allowsAccounting($user, AccessLevel::View);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user !== null && DocumentPolicy::allowsAccounting($user, AccessLevel::Edit);
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return collect(DocumentType::accounting())->mapWithKeys(fn (DocumentType $t) => [$t->value => $t->label()])->all();
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\Select::make('project_id')
                ->label('Layihə')
                ->options(fn () => static::projectOptions())
                ->in(fn () => array_keys(static::projectOptions()))
                ->searchable()
                ->required()
                ->native(false)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('title')
                ->label('Ad')
                ->required()
                ->maxLength(191),
            Forms\Components\Select::make('type')
                ->label('Tip')
                ->options(static::typeOptions())
                ->in(array_keys(static::typeOptions()))
                ->default(DocumentType::Contract->value)
                ->required()
                ->native(false),
            Forms\Components\FileUpload::make('file_path')
                ->label('Fayl')
                ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'image/jpeg', 'image/png', 'image/webp', 'text/plain', 'text/csv'])
                ->maxSize(20480)
                ->rules([SafeUpload::document()])
                ->helperText('İcazə verilən formatlar: PDF, Word, Excel, şəkil (JPG/PNG). SVG və icra olunan fayllar qəbul edilmir.')
                ->disk('public')
                ->directory('documents')
                ->required()
                ->columnSpanFull(),
            Forms\Components\Toggle::make('visible_to_client')
                ->label('Sifarişçiyə görünür')
                ->inline(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project' => fn ($q) => $q->withTrashed()]))
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Layihə')
                    ->description(fn ($record) => $record->project?->trashed() ? 'Layihə silinib' : null)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Sənəd')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (DocumentType $state) => $state->label()),
                Tables\Columns\IconColumn::make('visible_to_client')
                    ->label('Sifarişçiyə görünür')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Yüklənmə tarixi')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => static::projectOptions())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tip')
                    ->options(static::typeOptions()),
            ])
            ->actions([
                Actions\Action::make('download')
                    ->label('Yüklə')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Document $record) => auth()->user()?->can('view', $record))
                    ->url(fn (Document $record) => Storage::disk('public')->url($record->file_path))
                    ->openUrlInNewTab(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('Sənəd silinəcək. Bu əməliyyat əməliyyat jurnalında qeyd olunur.'),
            ])
            ->bulkActions([]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'project.name'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContractDocuments::route('/'),
            'create' => Pages\CreateContractDocument::route('/create'),
            'edit' => Pages\EditContractDocument::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->whereIn('type', array_map(fn (DocumentType $t) => $t->value, DocumentType::accounting()));

        return static::scopeToOwnProjects($query);
    }
}
