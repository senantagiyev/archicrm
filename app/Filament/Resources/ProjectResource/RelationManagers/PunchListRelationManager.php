<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\PunchIssuePriority;
use App\Enums\PunchIssueStatus;
use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use App\Models\PunchListIssue;
use App\Models\User;
use App\Rules\SafeUpload;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PunchListRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'punchListIssues';

    protected static ?string $title = 'Punch List (qüsurlar)';

    protected static ?string $modelLabel = 'Qüsur';

    /**
     * Layihə səhifəsində bu tabın birbaşa linki (bildiriş və dashboard üçün).
     *
     * Tab açarı `ProjectResource::getRelations()`-dakı mövqedir — sabit rəqəm
     * yazılmır ki, siyahıya yeni tab əlavə olunanda link başqa taba düşməsin.
     */
    public static function urlFor(Project $project): string
    {
        $key = array_search(static::class, ProjectResource::getRelations(), true);

        return ProjectResource::getUrl('edit', array_filter([
            'record' => $project,
            'relation' => $key === false ? null : (string) $key,
        ], fn ($value) => $value !== null));
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Başlıq')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
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
            Forms\Components\Select::make('responsible_user_id')
                ->label('Məsul şəxs')
                ->options(fn (?PunchListIssue $record) => $this->responsibleOptions($record))
                ->searchable()
                ->native(false)
                ->helperText('Siyahıda yalnız bu layihəni aça bilən işçilər var. Başqasını təyin etmək üçün onu əvvəlcə «Komanda» tabından layihəyə əlavə edin. Məsul şəxs bildiriş alır.'),
            Forms\Components\DatePicker::make('due_date')
                ->label('Son tarix')
                ->native(false),
            Forms\Components\Select::make('status')
                ->label('Status')
                ->options(collect(PunchIssueStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                ->default(PunchIssueStatus::Open->value)
                ->required()
                ->native(false),
            Forms\Components\FileUpload::make('photo_url')
                ->label('Foto')
                ->image()
                ->rules([SafeUpload::image()])
                ->disk('public')
                ->directory('punchlist')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            // Policy hər sətir üçün `$record->project`-i oxuyur. Əvvəlcədən
            // yüklənməsə, lokal mühitdə (preventLazyLoading) iki sətirdən sonra
            // tab qəzaya uğrayırdı, prod-da isə hər sətrə ayrıca sorğu gedirdi.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('project'))
            // Müştərinin sualı: «bu nə üçündür?» — tabın özü izah etsin.
            ->description('Obyektdə iş gedərkən və ya təhvil-təslim zamanı aşkar edilən qüsur və iradların siyahısı. Hər qüsur məsul şəxsə son tarixlə təyin olunur — o, bildiriş alır və qüsuru «Mənə təyin olunanlar» süzgəci ilə tapır. Qüsur düzəldiləndə statusu «Həll edilib», yoxlamadan sonra «Bağlanıb» edilir.')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Başlıq')
                    ->searchable(),
                Tables\Columns\TextColumn::make('room')
                    ->label('Otaq')
                    ->searchable(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioritet')
                    ->badge()
                    ->formatStateUsing(fn (PunchIssuePriority $state) => $state->label())
                    ->color(fn (PunchIssuePriority $state) => $state->color()),
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
                    ->label('Son tarix')
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
                Actions\CreateAction::make()->label('Qüsur əlavə et'),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->requiresConfirmation(),
            ]);
    }

    /**
     * Məsul şəxs seçimləri: layihəyə girişi olan işçilər (`assignableUsers`).
     *
     * Köhnə qaydayla girişi OLMAYAN birinə artıq təyin edilmiş qüsur redaktə
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
}
