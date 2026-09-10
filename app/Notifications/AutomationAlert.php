<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic in-app + mail alert emitted by the Automation Engine's scheduled rules
 * (TZ Əlavə B reminders/escalations). Each rule supplies its own title/body/link
 * and a data bag for the database payload, so one class covers every reminder.
 */
class AutomationAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
        public array $data = [],
        public string $ruleCode = '',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->line($this->body);

        if ($this->url !== null) {
            $mail->action('Bax', $this->url);
        }

        return $mail;
    }

    public function toDatabase(object $notifiable): array
    {
        return array_merge([
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'rule' => $this->ruleCode,
        ], $this->data);
    }
}
