<?php

namespace App\Filament\Pages;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Enums\TaskStatus;
use App\Filament\Resources\TaskResource;
use App\Models\Project;
use App\Models\Stage;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessMatrix;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;

/**
 * Bütün studiyanın işini bir ekranda planlaşdırmaq (Roomix `/tasks` qarşılığı,
 * `docs/roomix-funksional-ferqler.md` §6). TaskResource cədvəli layihə-mərkəzlidir
 * — burada isə iş status sütunlarına yığılır ki, nəyin ilişdiyi bir baxışda görünsün.
 */
class TaskPlanner extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Layihələr';

    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'Tapşırıq planı';

    protected static ?string $title = 'Tapşırıq planı';

    protected string $view = 'filament.pages.task-planner';

    /** «mine» — yalnız mənim, «all» — bütün studiya. */
    public string $scope = 'all';

    /** @var array<string, Collection<int, Task>>|null Sorğu nəticəsinin sorğu-daxili keşi. */
    private ?array $groups = null;

    /**
     * Rolun adına yox, matrisə baxılır: sahibkar «koordinator» adlı xüsusi rol
     * quranda Mərhələ/Tapşırıq = Baxış verirsə, ekran onun üçün açılmalıdır.
     * Baxış səviyyəsi kifayətdir — planlaşdırma ekranı oxumaq üçündür, tapşırıq
     * yaratma düyməsi isə ayrıca Redaktə səviyyəsi tələb edir.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && AccessMatrix::allows($user, Domain::StagesTasks, AccessLevel::View);
    }

    public function canCreateTasks(): bool
    {
        $user = auth()->user();

        return $user !== null
            && AccessMatrix::allows($user, Domain::StagesTasks, AccessLevel::Edit);
    }

    /** Ekranda göstərilən sütunlar; «Ləğv edilib» planlaşdırmaya aid deyil. */
    public function statuses(): array
    {
        return [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Review, TaskStatus::Done];
    }

    /**
     * @return array<string, Collection<int, Task>> status dəyəri => tapşırıqlar
     */
    public function getGroupedTasks(): array
    {
        // Sütunlar və ümumi say eyni sorğudan oxunur — view bir neçə dəfə çağırır.
        if ($this->groups !== null) {
            return $this->groups;
        }

        $user = auth()->user();

        $query = Task::query()
            ->with(['project', 'assignee'])
            ->whereIn('status', array_map(fn (TaskStatus $s) => $s->value, $this->statuses()))
            // Tapşırıqlar soft-delete olunmur, ona görə silinmiş layihənin
            // tapşırıqları planda diri qalırdı — `stages:mark-overdue` əmrindəki
            // ilə eyni qoruma: layihəsi qalmayan iş planlaşdırılmır.
            ->whereHas('project');

        // Resursdakı ilə HƏRFƏN eyni məhdudiyyət — görünürlük qaydası
        // TaskResource-da bir yerdə saxlanılır ki, iki ekran ayrılmasın.
        $query = TaskResource::scopeToVisibleProjects($query, $user);

        if ($this->scope === 'mine' && $user) {
            $query->where('assignee_user_id', $user->id);
        }

        // Son tarixi olmayanlar sona düşür — plan tarixlə oxunur.
        $tasks = $query
            ->orderByRaw('deadline is null')
            ->orderBy('deadline')
            ->orderByDesc('id')
            ->get();

        $grouped = [];

        foreach ($this->statuses() as $status) {
            $grouped[$status->value] = $tasks->where('status', $status)->values();
        }

        return $this->groups = $grouped;
    }

    /** Filtr dəyişdikdə keş-edilmiş sorğu nəticəsi köhnəlir. */
    public function updatedScope(): void
    {
        $this->groups = null;
    }

    public function getTotalCount(): int
    {
        return collect($this->getGroupedTasks())->sum(fn (Collection $tasks) => $tasks->count());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTask')
                ->label('Yeni tapşırıq')
                ->icon('heroicon-o-plus')
                ->visible(fn () => $this->canCreateTasks())
                ->modalHeading('Yeni tapşırıq')
                ->modalSubmitActionLabel('Yarat')
                ->schema([
                    TextInput::make('title')
                        ->label('Başlıq')
                        ->required()
                        ->maxLength(191),
                    Textarea::make('description')
                        ->label('Təsvir')
                        ->rows(3)
                        ->maxLength(2000),
                    Select::make('project_id')
                        ->label('Layihə')
                        ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->native(false)
                        // Mərhələ sütunu bazada məcburidir, ona görə layihə
                        // seçiləndə birinci mərhələ avtomatik doldurulur —
                        // istifadəçidən Roomix-də olmayan sahə soruşulmasın.
                        ->afterStateUpdated(fn (Set $set, $state) => $set(
                            'stage_id',
                            Stage::where('project_id', $state)->orderBy('position')->value('id'),
                        )),
                    Select::make('stage_id')
                        ->label('Mərhələ')
                        ->options(fn (Get $get) => $get('project_id')
                            ? Stage::where('project_id', $get('project_id'))->orderBy('position')->pluck('name', 'id')
                            : [])
                        ->required()
                        ->native(false),
                    Select::make('assignee_user_id')
                        ->label('İcraçı')
                        ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->native(false),
                    DatePicker::make('deadline')
                        ->label('Son tarix')
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    // UI-nı gizlətmək icazə deyil: modal Livewire çağırışı ilə
                    // birbaşa da açıla bilər, ona görə yazma burada yoxlanılır.
                    abort_unless($this->canCreateTasks(), 403);

                    $user = auth()->user();

                    // Layihə və mərhələ id-ləri Livewire payload-ından gəlir —
                    // cari studiyanın (tenant scope) qeydləri olduğu təsdiqlənir.
                    $project = Project::findOrFail($data['project_id']);
                    $stage = Stage::where('project_id', $project->id)->findOrFail($data['stage_id']);

                    $assigneeId = $data['assignee_user_id'] ?? null;
                    if ($assigneeId) {
                        $assigneeId = User::whereKey($assigneeId)->value('id');
                    }

                    Task::create([
                        'project_id' => $project->id,
                        'stage_id' => $stage->id,
                        'title' => $data['title'],
                        'description' => $data['description'] ?? null,
                        'assignee_user_id' => $assigneeId,
                        'author_user_id' => $user?->id,
                        'deadline' => $data['deadline'] ?? null,
                        'status' => TaskStatus::Todo->value,
                    ]);

                    Notification::make()->success()->title('Tapşırıq yaradıldı')->send();
                }),
        ];
    }
}
