<?php

namespace App\Filament\Resources;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\TaskResource\Pages;
use App\Models\Project;
use App\Models\Stage;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Layihələr';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Tapşırıqlar';

    protected static ?string $modelLabel = 'Tapşırıq';

    protected static ?string $pluralModelLabel = 'Tapşırıqlar';

    protected static ?string $recordTitleAttribute = 'title';

    /** Own-project scoping for non-manager roles. */
    public static function getEloquentQuery(): Builder
    {
        // Table closures read project/stage/assignee — eager load against the N+1 guard.
        $query = parent::getEloquentQuery()
            ->with(['project', 'stage', 'assignee'])
            // Layihə soft-delete olunur, tapşırıq isə yox — `whereHas('project')`
            // layihənin SoftDeletes skopunu işə salır. Bu qoruma MyTasksWidget,
            // TaskPlanner, Attention və CalendarController-də vardı, resursun
            // özündə isə yox idi: silinmiş layihənin tapşırıqları siyahıda
            // qalırdı, layihə sütunu boş («—») görünürdü və sətri açan adam
            // konteksti olmayan tapşırığı redaktə edirdi. Sətir bazadan silinmir,
            // yalnız gizlənir — layihə bərpa olunsa iş də geri qayıdır.
            ->whereHas('project');

        return static::scopeToVisibleProjects($query, auth()->user());
    }

    /**
     * Tapşırıq sorğusunu istifadəçinin görə bildiyi LAYİHƏLƏRLƏ məhdudlaşdırır.
     *
     * Burada əvvəl `assignee_user_id = me` filtri vardı; o filtr iki tərəfdən də
     * səhv nəticə verirdi — layihə meneceri matrisdə Mərhələ/Tapşırıq = Tam
     * olduğu halda öz layihəsində dizaynerə verdiyi tapşırığı görmürdü, əvəzində
     * üzvü olmadığı layihənin tapşırığı ona təyin ediləndə görünürdü. Şərt indi
     * ExpenseResource/MeetingResource ilə eynidir: layihənin meneceri ya üzvü.
     *
     * Metod `public static`-dır ki, TaskPlanner və MyTasksWidget eyni şərti
     * təkrar yazmasın — görünürlük qaydası bir yerdə saxlanılır.
     */
    public static function scopeToVisibleProjects(Builder $query, ?User $user): Builder
    {
        if ($user === null || ! AccessMatrix::requiresOwnProject($user)) {
            return $query;
        }

        return $query->whereHas('project', fn (Builder $project) => $project
            ->where('manager_user_id', $user->id)
            ->orWhereHas('members', fn (Builder $member) => $member->whereKey($user->id)));
    }

    /**
     * Tapşırıq yaradarkən/redaktə edərkən seçilə bilən layihələr.
     *
     * Əvvəl hər yerdə sadəcə `Project::orderBy('name')->pluck()` yazılırdı, yəni
     * «yalnız öz layihələri» rolu studiyanın BÜTÜN layihə adlarını (müştəri
     * obyektlərinin adlarını) seçim siyahısında görürdü və `project_id` Livewire
     * payload-ından gəldiyi üçün üzvü OLMADIĞI layihəyə tapşırıq yaza bilirdi.
     * Yazdığı sətri sonra özü görmür — yad layihədə izahsız iş peyda olur.
     * Görünürlük şərti oxu sorğusu ilə eyni yerdən gəlsin deyə seçim siyahısı da
     * burada saxlanılır (TaskPlanner də bunu çağırır).
     *
     * @return array<int, string>
     */
    public static function visibleProjectOptions(): array
    {
        $query = Project::query()->orderBy('name');
        $user = auth()->user();

        if ($user && AccessMatrix::requiresOwnProject($user)) {
            $query->where(fn (Builder $q) => $q
                ->where('manager_user_id', $user->id)
                ->orWhereHas('members', fn (Builder $m) => $m->whereKey($user->id)));
        }

        return $query->pluck('name', 'id')->all();
    }

    /**
     * Seçilmiş layihə istifadəçiyə açıqdırmı — YAZMA yolunun qapısı.
     * UI-nı gizlətmək icazə deyil (TZ §5.20): form payload-u birbaşa da göndərilə
     * bilər, ona görə yazmadan əvvəl id serverdə təsdiqlənir.
     */
    public static function projectIsVisible(?int $projectId): bool
    {
        return $projectId !== null && array_key_exists($projectId, static::visibleProjectOptions());
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make()->columns(2)->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Layihə')
                    // Yalnız istifadəçinin görə bildiyi layihələr — siyahı da,
                    // saxlanılan dəyər də eyni qaydadan keçir.
                    ->options(fn () => static::visibleProjectOptions())
                    ->rule(fn () => fn (string $attribute, $value, \Closure $fail) => static::projectIsVisible((int) $value)
                        ? null
                        : $fail('Bu layihəyə tapşırıq yaratmaq icazəniz yoxdur.'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->native(false)
                    ->afterStateUpdated(fn (Set $set) => $set('stage_id', null)),
                Forms\Components\Select::make('stage_id')
                    ->label('Mərhələ')
                    ->options(fn (Get $get) => $get('project_id')
                        ? Stage::where('project_id', $get('project_id'))->orderBy('position')->pluck('name', 'id')
                        : [])
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('title')
                    ->label('Tapşırıq')
                    ->required()
                    ->maxLength(191)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->label('Təsvir')
                    ->rows(3)
                    ->autosize()
                    ->columnSpanFull(),
                Forms\Components\Select::make('assignee_user_id')
                    ->label('İcraçı')
                    ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('Tapşırıqda icraçı və müddət məcburidir.'),
                Forms\Components\DatePicker::make('deadline')
                    ->label('Son tarix')
                    ->required()
                    ->native(false),
                Forms\Components\Select::make('priority')
                    ->label('Prioritet')
                    ->options(collect(TaskPriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()]))
                    ->default(TaskPriority::Normal->value)
                    ->required()
                    ->native(false),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(collect(TaskStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->default(TaskStatus::Todo->value)
                    ->required()
                    ->native(false),
            ]),

            Section::make('Çek-list')->schema([
                Repeater::make('checklist')
                    ->label('')
                    ->schema([
                        Forms\Components\TextInput::make('text')
                            ->label('Bənd')
                            ->required(),
                        Forms\Components\Checkbox::make('done')
                            ->label('Hazır')
                            ->default(false),
                    ])
                    ->columns(['default' => 1, 'sm' => 4])
                    ->addActionLabel('Bənd əlavə et')
                    ->default([]),
            ])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Tapşırıq')
                    ->searchable()
                    ->description(fn (Task $r) => collect([$r->project?->name, $r->stage?->name])->filter()->implode(' — '))
                    ->wrap(),
                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('İcraçı')
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioritet')
                    ->badge()
                    ->formatStateUsing(fn (TaskPriority $state) => $state->label())
                    ->color(fn (TaskPriority $state) => $state->color()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (TaskStatus $state) => $state->label())
                    ->color(fn (TaskStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('deadline')
                    ->label('Son tarix')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn (Task $r) => $r->isOverdue() ? 'danger' : null)
                    ->weight(fn (Task $r) => $r->isOverdue() ? 'bold' : null),
            ])
            ->defaultSort('deadline')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Layihə')
                    // Filtr siyahısı da görünürlük qaydasına tabedir: əks halda
                    // rol görə bilmədiyi layihələrin adlarını filtr açılışında
                    // oxuyurdu.
                    ->options(fn () => static::visibleProjectOptions()),
                Tables\Filters\SelectFilter::make('assignee_user_id')
                    ->label('İcraçı')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(TaskStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Actions\Action::make('markDone')
                    ->label('Hazırdır')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Task $r) => ! $r->status->isFinal())
                    ->action(fn (Task $r) => $r->update(['status' => TaskStatus::Done])),
                Actions\EditAction::make(),
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
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\CreateTask::route('/create'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
        ];
    }
}
