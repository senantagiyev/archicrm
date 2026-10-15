<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Actions\LinkToSubjectAction;
use App\Filament\Pages\ChatCenter;
use App\Filament\Resources\ProjectResource;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\ProjectDecision;
use App\Models\User;
use App\Services\Projects\ProjectOverviewService;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/**
 * Layihə kartı (TZ «Layihə naviqasiyası» §1–2).
 *
 * Birinci tab «İcmal»dır — gündəlik işə başlama ekranı (ProjectOverviewService).
 * Səhifə QƏSDƏN EditRecord olaraq qalır: ViewRecord-da relation manager-lər
 * yalnız oxunur olur, bölmələrdə isə redaktə lazımdır. Layihənin öz forması
 * buradan «Layihə parametrləri» səhifəsinə (ProjectSettings) köçürülüb — o,
 * hər bölmənin yuxarısını tutmamalıdır.
 */
class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecordTitle();
    }

    public function getBreadcrumb(): string
    {
        return 'İcmal';
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'İcmal';
    }

    public function getContentTabIcon(): string|\BackedEnum|Htmlable|null
    {
        return 'heroicon-o-home';
    }

    /** Forma bu səhifədə yoxdur — layihə parametrləri ayrıca səhifədədir. */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * Formasız səhifədə «yadda saxla» heç nə etmir — əl ilə qurulmuş Livewire
     * çağırışı layihəni bu səhifədən dəyişə bilməz. Redaktə: ProjectSettings.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void {}

    public function getContentTabComponent(): Tab
    {
        return $this->getBaseContentTabComponent()
            ->schema([
                View::make('filament.projects.overview')
                    ->viewData(fn (): array => $this->overviewData()),
            ]);
    }

    /**
     * Tab sətri + dar ekran üçün yığcam seçim menyusu (TZ meyar 13). Tab sətri
     * `sm`-dən aşağı CSS ilə gizlədilir (theme.css → `.archi-project-tabs`),
     * əvəzində `<select>` eyni `activeRelationManager` xassəsini dəyişir.
     */
    public function getRelationManagersContentComponent(): Component
    {
        $component = parent::getRelationManagersContentComponent();

        if (! $component instanceof Tabs) {
            return $component;
        }

        return Group::make([
            View::make('filament.projects.tabs-mobile-select')
                ->viewData(fn (): array => [
                    'options' => $this->sectionOptions(),
                    'active' => (string) $this->activeRelationManager,
                ]),
            $component->extraAttributes(['class' => 'archi-project-tabs']),
        ]);
    }

    /**
     * Bölmə seçimləri: '' => İcmal, sonra relation manager açarları
     * (`ProjectResource::getRelations()`-in açarları ilə eyni).
     *
     * @return array<string, string>
     */
    public function sectionOptions(): array
    {
        $options = ['' => (string) $this->getContentTabLabel()];
        $record = $this->getRecord();

        foreach ($this->getCachedRelationManagers() as $key => $manager) {
            if ($manager instanceof RelationGroup) {
                $label = $manager->getLabel();
            } else {
                $class = $manager instanceof RelationManagerConfiguration ? $manager->relationManager : $manager;
                $label = $class::getTitle($record, static::class);
            }

            $options[(string) $key] = strip_tags((string) $label);
        }

        return $options;
    }

    /** @return array<string, mixed> */
    protected function overviewData(): array
    {
        /** @var Project $project */
        $project = $this->getRecord();
        /** @var User $user */
        $user = auth()->user();
        $service = app(ProjectOverviewService::class);

        return [
            'summary' => $service->summary($project),
            'actions' => $service->pendingActions($project, $user),
            'deadlines' => $service->upcomingDeadlines($project, $user),
            'participants' => $service->participants($project, $user),
            'feed' => $service->feed($project, $user),
            'unchecked' => $service->uncheckedRecords($project, $user),
        ];
    }

    /**
     * «Yoxlanmalı qeydlər» blokundakı «Əlaqələndir» düyməsi (TZ §10): obyektə
     * bağlanmamış köhnə dəyişiklik sorğusu / qərar burada əl ilə bağlanır.
     * Qeyd arqumentdən YALNIZ bu layihənin daxilində tapılır.
     */
    public function linkRecordAction(): Actions\Action
    {
        return LinkToSubjectAction::make('linkRecord')
            ->record(fn (array $arguments): ?Model => match ($arguments['type'] ?? null) {
                'change_request' => ChangeRequest::query()
                    ->where('project_id', $this->record->getKey())
                    ->find($arguments['id'] ?? null),
                'decision' => ProjectDecision::query()
                    ->where('project_id', $this->record->getKey())
                    ->find($arguments['id'] ?? null),
                default => null,
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            // Layihənin geniş forması və komanda tərkibi — ayrıca səhifədə.
            // Silmə də oradadır: kritik əməliyyat gündəlik ekranda durmamalıdır.
            Actions\Action::make('settings')
                ->label('Layihə parametrləri')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('update', $this->record))
                ->url(fn (): string => ProjectResource::getUrl('settings', ['record' => $this->record])),

            Actions\Action::make('chat')
                ->label('Çat')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->url(fn () => ChatCenter::getUrl(['project' => $this->record->id])),

            // Roomix «Subprojects»: təmir mərhələsi üçün ayrıca məkan — öz çatı,
            // mərhələləri və faylları olur, amma eyni müştəriyə bağlı qalır.
            // Alt-layihənin özündən yenisini yaratmaq olmur: iki səviyyə kifayətdir,
            // dərin ağac siyahıları oxunmaz edir.
            Actions\Action::make('createSubproject')
                ->label('Alt-layihə yarat')
                ->icon('heroicon-o-squares-plus')
                ->color('gray')
                ->visible(fn () => ! $this->record->isSubproject())
                ->schema([
                    TextInput::make('name')
                        ->label('Alt-layihənin adı')
                        ->default('Təmir')
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data) {
                    /** @var Project $parent */
                    $parent = $this->record;

                    $child = Project::create([
                        'client_id' => $parent->client_id,
                        'parent_project_id' => $parent->id,
                        'name' => $data['name'],
                        // Tip, ünvan və menecer valideyndən götürülür: alt-layihə
                        // eyni obyektin eyni komanda ilə davamıdır.
                        'type' => $parent->type,
                        'address' => $parent->address,
                        'manager_user_id' => $parent->manager_user_id,
                        'client_response_days' => $parent->client_response_days,
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Alt-layihə yaradıldı')
                        ->body($child->name)
                        ->send();

                    $this->redirect(ProjectResource::getUrl('edit', ['record' => $child]));
                }),
        ];
    }
}
