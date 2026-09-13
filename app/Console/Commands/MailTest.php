<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailTest extends Command
{
    protected $signature = 'mail:test {email : Test mesajının göndəriləcəyi ünvan}
                            {--show-password : Konfiqurasiya cədvəlində parolu açıq göstər}';

    protected $description = 'Mail konfiqurasiyasını yoxlayır: aktiv ayarları göstərir və verilmiş ünvana test mesajı göndərir';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('"'.$email.'" düzgün e-poçt ünvanı deyil.');

            return self::FAILURE;
        }

        $mailer = Config::get('mail.default');

        $this->newLine();
        $this->line('<fg=yellow>Aktiv mail konfiqurasiyası</>');
        $this->table(['Ayar', 'Dəyər'], $this->configRows($mailer));

        if (! Config::get('mail.from.address')) {
            $this->error('MAIL_FROM_ADDRESS boşdur — göndərən ünvanı olmadan mesaj getməyəcək.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($email.' ünvanına test mesajı göndərilir...');

        $sentAt = now()->toDateTimeString();

        try {
            Mail::raw($this->body($mailer, $sentAt), function ($message) use ($email) {
                $message->to($email)->subject('Archi CRM — mail konfiqurasiyası testi');
            });
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Göndərmə alınmadı: '.$e->getMessage());
            $this->line('<fg=gray>'.$e::class.'</>');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Mesaj "'.$mailer.'" mailer-i ilə göndərildi. Qutunu (və spam qovluğunu) yoxlayın.');

        if ($mailer === 'log') {
            $this->line('<fg=yellow>Qeyd:</> "log" mailer-i real mesaj göndərmir — storage/logs/laravel.log faylına yazır.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    protected function configRows(string $mailer): array
    {
        $rows = [
            ['MAIL_MAILER', $mailer],
            ['Transport', (string) Config::get("mail.mailers.{$mailer}.transport", '—')],
            ['Göndərən', Config::get('mail.from.address').' ('.Config::get('mail.from.name').')'],
            ['APP_URL', (string) Config::get('app.url')],
        ];

        if (Config::get("mail.mailers.{$mailer}.transport") === 'smtp') {
            $password = (string) Config::get("mail.mailers.{$mailer}.password");

            $rows = array_merge($rows, [
                ['Host', (string) Config::get("mail.mailers.{$mailer}.host", '—')],
                ['Port', (string) Config::get("mail.mailers.{$mailer}.port", '—')],
                ['Scheme', Config::get("mail.mailers.{$mailer}.scheme") ?: 'avtomatik (STARTTLS)'],
                ['İstifadəçi', (string) Config::get("mail.mailers.{$mailer}.username", '—')],
                ['Parol', $this->maskPassword($password)],
                ['EHLO domeni', (string) Config::get("mail.mailers.{$mailer}.local_domain", '—')],
            ]);
        }

        return $rows;
    }

    protected function maskPassword(string $password): string
    {
        if ($password === '') {
            return '— (boş)';
        }

        if ($this->option('show-password')) {
            return $password;
        }

        return str_repeat('•', min(mb_strlen($password), 12)).' ('.mb_strlen($password).' simvol)';
    }

    protected function body(string $mailer, string $sentAt): string
    {
        return implode(PHP_EOL, [
            'Archi CRM mail konfiqurasiyası testi.',
            '',
            'Bu mesajı aldınızsa, SMTP ayarları düzgün işləyir.',
            '',
            'Mailer: '.$mailer,
            'Mühit: '.app()->environment(),
            'Göndərilmə vaxtı: '.$sentAt,
        ]);
    }
}
