<?php

namespace App\Notifications;

use App\Filament\Resources\ProjectResource\RelationManagers\PunchListRelationManager;
use App\Models\PunchListIssue;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Punch list qüsuru işçiyə təyin edildi.
 *
 * Əvvəl qüsura məsul şəxs seçiləndə ona HEÇ NƏ getmirdi: adam qüsuru yalnız
 * layihəni özü açıb «Punch List» tabına baxsaydı görərdi. İndi həm zəng
 * (panelin bildiriş siyahısı), həm də məktub gedir.
 */
class PunchIssueAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public PunchListIssue $issue) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Sizə qüsur təyin edildi: '.$this->issue->title)
            ->line("Punch List-də sizə qüsur təyin edildi: {$this->issue->title}")
            ->line('Layihə: '.$this->issue->project?->name);

        if (filled($this->issue->room)) {
            $mail->line('Otaq / zona: '.$this->issue->room);
        }

        if ($this->issue->due_date) {
            $mail->line('Son tarix: '.$this->issue->due_date->format('d.m.Y'));
        }

        return $mail->action('Qüsura bax', $this->url());
    }

    /**
     * Filament formatında yazılır: panelin bildiriş zəngi yalnız
     * `format = filament` olan sətirləri göstərir — sadə massiv bazaya düşsə
     * də işçi onu panelin heç bir yerində görməzdi.
     */
    public function toDatabase(object $notifiable): array
    {
        $body = collect([
            $this->issue->project?->name,
            $this->issue->room,
            $this->issue->due_date ? 'son tarix '.$this->issue->due_date->format('d.m.Y') : null,
        ])->filter()->implode(' · ');

        return array_merge(
            FilamentNotification::make()
                ->title('Sizə qüsur təyin edildi: '.$this->issue->title)
                ->body($body)
                ->icon('heroicon-o-wrench-screwdriver')
                ->iconColor('warning')
                ->actions([
                    Action::make('open')
                        ->label('Qüsura bax')
                        ->url($this->url())
                        ->markAsRead(),
                ])
                ->getDatabaseMessage(),
            [
                'punch_list_issue_id' => $this->issue->id,
                'project_id' => $this->issue->project_id,
            ],
        );
    }

    private function url(): ?string
    {
        return $this->issue->project
            ? PunchListRelationManager::urlFor($this->issue->project)
            : null;
    }
}
