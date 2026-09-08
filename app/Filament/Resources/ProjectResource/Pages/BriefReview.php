<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Enums\BriefStatus;
use App\Enums\DocumentType;
use App\Filament\Resources\ProjectResource;
use App\Models\Brief;
use App\Models\BriefQuestion;
use App\Models\BriefRoom;
use App\Services\Brief\BriefRiskDetector;
use App\Services\Brief\BriefService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Screen 14 — Designer View (spec Part 12): sticky summary, risks, missing
 * required answers, answer-level priorities, attachments gallery, version
 * history, per-question clarification requests and final approval.
 */
class BriefReview extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.pages.brief-review';

    protected static ?string $title = 'Brif — dizayner baxışı';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth()->user()?->can('view', $this->record), 403);
    }

    public function brief(): Brief
    {
        return app(BriefService::class)->forProject($this->record);
    }

    public function service(): BriefService
    {
        return app(BriefService::class);
    }

    public function summary(): array
    {
        return $this->service()->summaryPanel($this->brief());
    }

    public function risks(): array
    {
        return app(BriefRiskDetector::class)->detect($this->brief());
    }

    public function missing(): Collection
    {
        return $this->service()->missingRequired($this->brief());
    }

    public function priorities(): Collection
    {
        return $this->service()->answerPriorities($this->brief());
    }

    public function attachments(): Collection
    {
        return $this->service()->attachments($this->brief());
    }

    public function versions(): Collection
    {
        return $this->brief()->versions()->get();
    }

    public function openComments(): Collection
    {
        return $this->brief()->openComments()->with(['question', 'room', 'user'])->get();
    }

    public function latestPdfUrl(): ?string
    {
        $doc = $this->record->documents()->where('type', DocumentType::BriefExport->value)->latest()->first();

        return $doc?->file_path ? asset('storage/'.ltrim($doc->file_path, '/')) : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Layihəyə qayıt')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(ProjectResource::getUrl('edit', ['record' => $this->record])),
            Action::make('pdf')
                ->label('PDF yüklə')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn () => $this->latestPdfUrl(), shouldOpenInNewTab: true)
                ->visible(fn () => $this->latestPdfUrl() !== null),
            Action::make('approve')
                ->label('Brifi təsdiqlə')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Brif layihələndirmə üçün baseline olacaq; müştəri üçün cavablar yalnız-oxu rejiminə keçir.')
                ->visible(fn () => $this->brief()->statusEnum() === BriefStatus::Submitted && $this->brief()->openComments()->doesntExist())
                ->action(function () {
                    $this->service()->approve($this->brief(), auth()->user());
                    Notification::make()->success()->title('Brif təsdiqləndi')->send();
                }),
        ];
    }

    /** Per-question «Dəqiqləşdirmə tələb et» — mounted from the view with {question, room}. */
    public function requestClarificationAction(): Action
    {
        return Action::make('requestClarification')
            ->label('Dəqiqləşdirmə tələb et')
            ->modalHeading('Müştəridən dəqiqləşdirmə tələb et')
            ->modalSubmitActionLabel('Göndər')
            ->schema([
                Textarea::make('body')
                    ->label('Sual / şərh')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000),
            ])
            ->action(function (array $data, array $arguments) {
                $question = BriefQuestion::findOrFail((int) ($arguments['question'] ?? 0));
                $room = ! empty($arguments['room']) ? BriefRoom::find((int) $arguments['room']) : null;

                $this->service()->requestClarification($this->brief(), $question, $room, auth()->user(), $data['body']);

                Notification::make()->success()->title('Dəqiqləşdirmə sorğusu müştəriyə göndərildi')->send();
            });
    }
}
