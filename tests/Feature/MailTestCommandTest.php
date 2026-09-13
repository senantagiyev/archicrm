<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTestCommandTest extends TestCase
{
    public function test_it_sends_a_test_message_to_the_given_address(): void
    {
        config()->set('mail.default', 'array');

        $this->artisan('mail:test', ['email' => 'teleb@archi.az'])
            ->assertSuccessful();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);

        $sent = $messages[0]->getOriginalMessage();

        $this->assertSame('teleb@archi.az', $sent->getTo()[0]->getAddress());
        $this->assertSame('Archi CRM — mail konfiqurasiyası testi', $sent->getSubject());
        $this->assertStringContainsString('SMTP ayarları düzgün işləyir', $sent->getTextBody());
    }

    public function test_it_rejects_an_invalid_address_without_sending(): void
    {
        Mail::fake();

        $this->artisan('mail:test', ['email' => 'yanlis-unvan'])
            ->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_it_fails_when_the_from_address_is_missing(): void
    {
        Mail::fake();
        config()->set('mail.from.address', null);

        $this->artisan('mail:test', ['email' => 'teleb@archi.az'])
            ->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_it_masks_the_smtp_password_in_the_output(): void
    {
        Mail::fake();
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.transport', 'smtp');
        config()->set('mail.mailers.smtp.password', 'super-gizli-parol');

        $this->artisan('mail:test', ['email' => 'teleb@archi.az'])
            ->doesntExpectOutputToContain('super-gizli-parol')
            ->assertSuccessful();
    }
}
