<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Notifications\ApprovalRequested;
use App\Services\Approvals\ApprovalService;
use App\Services\Design\MaterialService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Smoke test (2026-10-15): Mailtrap «550 Too many emails per second» qaytaranda
 * «Razılaşdırmaya göndər» bütünlüklə geri qaytarılırdı — bildirişlər sinxron
 * göndərilir (prod-da da QUEUE_CONNECTION=sync). Poçt xətası razılaşdırmanı
 * dayandırmamalıdır; panel/portal bildirişi (database kanalı) yenə yazılır.
 */
class ApprovalMailFailureTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->studio = StudioWorld::make('mailfail');

        Mail::extend('broken', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new RuntimeException('550 5.7.0 Too many emails per second.');
            }

            public function __toString(): string
            {
                return 'broken://';
            }
        });
        config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'broken']]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_a_failing_mail_server_does_not_block_sending_or_deciding(): void
    {
        $owner = $this->studio->user('owner');
        $this->actingAs($owner);
        app(TenantContext::class)->set($this->studio->tenant->id);

        $path = UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')->store('materials', 'public');
        $materials = app(MaterialService::class);
        $material = $materials->create($this->studio->project, ['title' => 'Plan həlli', 'files' => [$path]], $owner);

        $approval = $materials->sendForApproval($material, $material->currentVersion, $owner);

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
        $this->assertNotNull($material->fresh()->published_version_id);
        $this->assertTrue(
            $this->studio->portalUser->notifications()->where('type', ApprovalRequested::class)->exists(),
            'Portal bildirişi (database kanalı) poçt xətasına baxmayaraq yazılmalıdır.',
        );

        // Müştərinin qərarı da poçt xətası ilə 500-ə çevrilmir.
        app(ApprovalService::class)->decide(Approval::findOrFail($approval->id), true, null, $this->studio->portalUser);

        $this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status);
    }
}
