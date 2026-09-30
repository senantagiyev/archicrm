<?php

namespace App\Notifications;

use App\Models\Brief;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Studiya brifi müştəriyə təqdim etdi — portalda doldurmaq üçün açıldı.
 * Həm məktub, həm portal bildirişi: müştəri məktubu görməsə də, portala
 * girəndə zəng işarəsində görür.
 */
class BriefPresented extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Brief $brief) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->brief->project;
        $title = $this->brief->template?->getTranslation('name', 'az') ?? 'Brif';

        return (new MailMessage)
            ->subject('Sizin üçün brif hazırlanıb — '.$project?->name)
            ->greeting('Salam, '.$notifiable->name.'!')
            ->line('«'.$title.'» brifi portalda doldurmaq üçün açıldı. Cavablarınız dizaynerə layihənin əsasını qurmaqda kömək edəcək.')
            ->line('İstənilən vaxt dayandırıb sonra davam edə bilərsiniz — cavablar avtomatik saxlanılır.')
            ->action('Brifi doldur', route('portal.brief', [$project, 'brief' => $this->brief->id]));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Brif təqdim edildi',
            'body' => ($this->brief->template?->getTranslation('name', 'az') ?? 'Brif').' · '.$this->brief->project?->name,
            'url' => route('portal.brief', [$this->brief->project, 'brief' => $this->brief->id]),
            'project_id' => $this->brief->project_id,
        ];
    }
}
