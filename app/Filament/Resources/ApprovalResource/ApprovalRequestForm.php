<?php

namespace App\Filament\Resources\ApprovalResource;

use App\Enums\DeliverableVersionStatus;
use App\Enums\DocumentType;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource;
use App\Models\Approval;
use App\Models\Deliverable;
use App\Models\Document;
use App\Models\Project;
use App\Models\Stage;
use App\Models\User;
use App\Rules\SafeUpload;
use App\Services\Approvals\ApprovalService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * «Razılaşdırma yarat» — həm «Razılaşdırmalar» siyahısında, həm layihənin
 * «Razılaşdırmalar» tabında EYNİ forma.
 *
 * Müştəri (studiya) razılaşdırmanı harada yaradacağını tapa bilmirdi: siyahıda
 * düymə yox idi, sorğu yalnız smeta / komplektasiya / dizayn cədvəllərinin
 * sətir düymələrindən gedirdi. Forma iki yerdə ayrı yazılsaydı, qaydalar
 * (icazə, layihə süzgəci, cavab müddəti) tezliklə ayrılardı — ona görə bir sinif.
 * Yaratmanın özü həmişə {@see ApprovalService::requestFromPanel()}-dən keçir.
 */
class ApprovalRequestForm
{
    public const HELP = 'Razılaşdırma — müştəridən rəsmi «təsdiq» və ya «rədd» cavabı almaqdır. '
        .'Müştəri sorğunu portalda görür, faylı açır və cavab verir; rədd edərsə səbəbini yazmalıdır. '
        .'Cavab müddəti keçəndə sorğu «gecikən» kimi işarələnir.';

    /**
     * @param  Project|null  $project  layihənin tabından açılanda layihə sabitdir
     *                                 və formada seçilmir
     */
    public static function action(?Project $project = null): Action
    {
        return Action::make('createApproval')
            ->label('Razılaşdırma yarat')
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading('Yeni razılaşdırma')
            ->modalDescription(self::HELP)
            ->modalSubmitActionLabel('Müştəriyə göndər')
            ->visible(fn (): bool => self::canCreate($project))
            ->schema(fn (): array => self::schema($project))
            ->action(function (array $data, ApprovalService $service) use ($project): void {
                /** @var User $user */
                $user = auth()->user();
                $target = $project ?? self::selectableProjects()->findOrFail($data['project_id'] ?? null);

                abort_unless($user->can('createFor', [Approval::class, $target]), 403);
                abort_unless(self::canUseSubject($user, $target, $data), 403);

                $approval = $service->requestFromPanel($target, $user, $data);

                Notification::make()
                    ->success()
                    ->title('Razılaşdırma müştəriyə göndərildi')
                    ->body($approval->subjectLabel())
                    ->send();
            });
    }

    /** @return array<int, Component> */
    public static function schema(?Project $project = null): array
    {
        $resolve = fn (Get $get): ?Project => $project
            ?? (filled($get('project_id')) ? self::selectableProjects()->find($get('project_id')) : null);

        return [
            Forms\Components\Select::make('project_id')
                ->label('Layihə')
                ->options(fn () => self::selectableProjects()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, $state): void {
                    $set('subject_id', null);
                    $set('respond_by', self::defaultRespondBy(self::selectableProjects()->find($state)));
                })
                ->hidden($project !== null)
                ->columnSpanFull(),

            Forms\Components\Radio::make('subject_type')
                ->label('Nə razılaşdırılır?')
                ->options([
                    ApprovalService::SUBJECT_UPLOAD => 'Yeni fayl yükləyirəm',
                    ApprovalService::SUBJECT_DOCUMENT => 'Layihənin mövcud sənədi',
                    ApprovalService::SUBJECT_DELIVERABLE => 'Dizayn materialı (cari versiya)',
                    ApprovalService::SUBJECT_STAGE => 'Mərhələnin nəticəsi',
                ])
                ->descriptions([
                    ApprovalService::SUBJECT_UPLOAD => 'Çertyoj, plan, kollaj və s. — fayl «Sənədlər»ə əlavə olunur və müştəriyə açılır.',
                    ApprovalService::SUBJECT_DOCUMENT => '«Sənədlər» tabındakı fayl; daxili idisə, müştəriyə açılır.',
                    ApprovalService::SUBJECT_DELIVERABLE => 'Smeta və komplektasiya sətirləri öz cədvəllərindən göndərilir.',
                ])
                ->default(ApprovalService::SUBJECT_UPLOAD)
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('subject_id', null))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('title')
                ->label('Ad')
                ->placeholder('Məs.: Qonaq otağının planı')
                ->required()
                ->maxLength(191)
                ->visible(fn (Get $get): bool => $get('subject_type') === ApprovalService::SUBJECT_UPLOAD),

            Forms\Components\Select::make('document_type')
                ->label('Sənədin tipi')
                ->options(collect(DocumentType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                ->default(DocumentType::Other->value)
                ->required()
                ->native(false)
                ->visible(fn (Get $get): bool => $get('subject_type') === ApprovalService::SUBJECT_UPLOAD),

            // Qaydalar «Sənədlər» tabının yükləməsi ilə eynidir: eyni diskə, eyni
            // qovluğa düşür və portal onu eyni marşrutla verir.
            Forms\Components\FileUpload::make('file_path')
                ->label('Fayl')
                ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'image/jpeg', 'image/png', 'image/webp', 'text/plain', 'text/csv'])
                ->maxSize(20480)
                ->rules([SafeUpload::document()])
                ->helperText('PDF, Word, Excel və ya şəkil (JPG/PNG). Bir neçə variant göstərmək lazımdırsa, onları bir PDF-də birləşdirin.')
                ->disk('public')
                ->directory('documents')
                ->required()
                ->visible(fn (Get $get): bool => $get('subject_type') === ApprovalService::SUBJECT_UPLOAD)
                ->columnSpanFull(),

            Forms\Components\Select::make('subject_id')
                ->label(fn (Get $get): string => match ($get('subject_type')) {
                    ApprovalService::SUBJECT_DELIVERABLE => 'Dizayn materialı',
                    ApprovalService::SUBJECT_STAGE => 'Mərhələ',
                    default => 'Sənəd',
                })
                ->options(fn (Get $get): array => self::subjectOptions($resolve($get), (string) $get('subject_type')))
                ->searchable()
                ->required()
                ->helperText(fn (Get $get): ?string => $resolve($get) === null ? 'Əvvəlcə layihəni seçin.' : null)
                ->visible(fn (Get $get): bool => in_array($get('subject_type'), [
                    ApprovalService::SUBJECT_DOCUMENT,
                    ApprovalService::SUBJECT_DELIVERABLE,
                    ApprovalService::SUBJECT_STAGE,
                ], true))
                ->columnSpanFull(),

            Forms\Components\Textarea::make('note')
                ->label('Müştəriyə qeyd')
                ->placeholder('Nəyə baxmalı, nəyi təsdiqləməlidir?')
                ->rows(3)
                ->maxLength(2000)
                ->columnSpanFull(),

            Forms\Components\DatePicker::make('respond_by')
                ->label('Cavab müddəti')
                ->native(false)
                ->displayFormat('d.m.Y')
                ->minDate(today())
                ->default(fn (): ?string => self::defaultRespondBy($project))
                ->helperText('Standart olaraq layihənin «müştərinin cavab müddəti» qədər gün sonra.'),
        ];
    }

    /** Siyahıda göstərilən layihələr: istifadəçinin gördüyü, arxivdə olmayan. */
    public static function selectableProjects()
    {
        return ProjectResource::getEloquentQuery()
            ->where('status', '!=', ProjectStatus::Archived->value);
    }

    public static function defaultRespondBy(?Project $project): ?string
    {
        return $project?->client_response_days
            ? now()->addDays($project->client_response_days)->toDateString()
            : null;
    }

    /** @return array<int|string, string> */
    public static function subjectOptions(?Project $project, string $type): array
    {
        $user = auth()->user();

        if (! $project || ! $user) {
            return [];
        }

        return match ($type) {
            ApprovalService::SUBJECT_DOCUMENT => $project->documents()->latest()->get()
                ->filter(fn (Document $d) => $user->can('update', $d))
                ->mapWithKeys(fn (Document $d) => [$d->id => $d->title.' · '.$d->type->label().($d->visible_to_client ? '' : ' · daxili')])
                ->all(),
            // Dizayn cədvəlinin «Razılaşdırmaya göndər» düyməsinin şərtləri ilə
            // eyni: cari versiyası olan və hələ təsdiqlənməmiş/kilidlənməmiş.
            ApprovalService::SUBJECT_DELIVERABLE => $project->deliverables()->with('currentVersion')
                ->whereNotNull('current_version_id')->orderBy('title')->get()
                ->filter(fn (Deliverable $d) => self::deliverableIsSendable($d) && $user->can('update', $d))
                ->mapWithKeys(fn (Deliverable $d) => [$d->id => $d->title])
                ->all(),
            ApprovalService::SUBJECT_STAGE => $project->stages()->get()
                ->filter(fn (Stage $s) => $user->can('update', $s))
                ->mapWithKeys(fn (Stage $s) => [$s->id => $s->name])
                ->all(),
            default => [],
        };
    }

    private static function deliverableIsSendable(Deliverable $deliverable): bool
    {
        return $deliverable->current_version_id
            && ! in_array($deliverable->currentVersion?->status, [DeliverableVersionStatus::Approved, DeliverableVersionStatus::Locked], true);
    }

    private static function canCreate(?Project $project): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $project
            ? $user->can('createFor', [Approval::class, $project])
            : $user->can('create', Approval::class);
    }

    /**
     * Seçim siyahısı icazəsiz obyekti onsuz da göstərmir; bu yoxlama birbaşa
     * Livewire sorğusu ilə yad id göndərilən hal üçündür.
     *
     * @param  array<string, mixed>  $data
     */
    private static function canUseSubject(User $user, Project $project, array $data): bool
    {
        $type = (string) ($data['subject_type'] ?? '');

        if ($type === ApprovalService::SUBJECT_UPLOAD) {
            return $user->can('create', Document::class);
        }

        return array_key_exists($data['subject_id'] ?? '', self::subjectOptions($project, $type));
    }
}
