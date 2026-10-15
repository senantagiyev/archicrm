<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\DocumentType;
use App\Filament\Resources\BudgetLineResource;
use App\Filament\Resources\BudgetLineResource\Pages\ListBudgetLines;
use App\Filament\Resources\ContractDocumentResource;
use App\Filament\Resources\ContractDocumentResource\Pages\ListContractDocuments;
use App\Filament\Resources\PaymentResource\Pages\CreatePayment;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Models\Document;
use App\Models\Payment;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * «Maliyyə» qrupunun yeni resursları — Ödənişlər, Smeta, Müqavilələr və
 * sənədlər (TZ «Layihə naviqasiyası» §9, qəbul meyarı 11): layihə əlaqəsi,
 * layihə süzgəci, studiya izolyasiyası və köhnə tablarla eyni hüquqlar.
 */
class FinanceResourcesTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alpha;

    private StudioWorld $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = StudioWorld::make('alpha');
        $this->beta = StudioWorld::make('beta');
    }

    private function asStaff(string $role, ?StudioWorld $world = null): User
    {
        $world ??= $this->alpha;
        $user = $world->user($role);

        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($world->tenant->id);
        AccessMatrix::flushCache();

        return $user;
    }

    private function inAlpha(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->alpha->tenant->id, $callback);
    }

    public function test_payments_list_only_the_own_studio_and_filter_by_project(): void
    {
        $other = $this->inAlpha(fn () => $this->alpha->otherProject->payments()->create([
            'title' => 'İkinci layihə avansı', 'amount' => 300, 'status' => 'pending',
        ]));

        $this->asStaff('accountant');

        Livewire::test(ListPayments::class)
            ->assertCanSeeTableRecords([$this->alpha->payment, $other])
            ->assertCanNotSeeTableRecords([$this->beta->payment])
            ->assertSee($this->alpha->project->name)
            ->filterTable('project_id', $this->alpha->otherProject->id)
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$this->alpha->payment]);
    }

    public function test_smeta_lists_only_the_own_studio_filters_by_project_and_keeps_the_approval_flow(): void
    {
        Notification::fake();

        $other = $this->inAlpha(fn () => $this->alpha->otherProject->budgetLines()->create([
            'work_type' => 'İkinci layihə işi', 'qty' => 1, 'work_price' => 10, 'material_price' => 0,
        ]));

        $this->asStaff('owner');

        Livewire::test(ListBudgetLines::class)
            ->assertCanSeeTableRecords([$this->alpha->budgetLine, $other])
            ->assertCanNotSeeTableRecords([$this->beta->budgetLine])
            ->filterTable('project_id', $this->alpha->project->id)
            ->assertCanSeeTableRecords([$this->alpha->budgetLine])
            ->assertCanNotSeeTableRecords([$other])
            ->callTableAction('requestApproval', $this->alpha->budgetLine)
            ->assertHasNoTableActionErrors();

        $this->assertSame(ApprovalStatus::Pending, $this->alpha->budgetLine->fresh()->approval_status);
    }

    public function test_own_project_roles_see_only_their_projects_in_the_smeta(): void
    {
        $foreign = $this->inAlpha(fn () => $this->alpha->otherProject->budgetLines()->create([
            'work_type' => 'Yad layihənin işi', 'qty' => 1, 'work_price' => 10, 'material_price' => 0,
        ]));

        // Dizayner: Smeta = Baxış, yalnız öz layihələri.
        $this->asStaff('designer');

        Livewire::test(ListBudgetLines::class)
            ->assertCanSeeTableRecords([$this->alpha->budgetLine])
            ->assertCanNotSeeTableRecords([$foreign]);

        $this->get(BudgetLineResource::getUrl('edit', ['record' => $foreign]))->assertNotFound();
    }

    public function test_the_contracts_resource_holds_accounting_documents_only(): void
    {
        $act = $this->inAlpha(fn () => Document::create([
            'project_id' => $this->alpha->otherProject->id,
            'type' => DocumentType::Act->value,
            'title' => 'Qəbul aktı №1',
            'file_path' => 'docs/akt.pdf',
        ]));
        $drawing = $this->inAlpha(fn () => Document::create([
            'project_id' => $this->alpha->project->id,
            'type' => DocumentType::Drawing->value,
            'title' => 'Çertyoj',
            'file_path' => 'docs/cizgi.pdf',
        ]));

        $this->asStaff('accountant');

        Livewire::test(ListContractDocuments::class)
            ->assertCanSeeTableRecords([$this->alpha->clientDocument, $act])
            ->assertCanNotSeeTableRecords([$this->alpha->internalDocument, $drawing, $this->beta->clientDocument])
            ->filterTable('project_id', $this->alpha->otherProject->id)
            ->assertCanSeeTableRecords([$act])
            ->assertCanNotSeeTableRecords([$this->alpha->clientDocument]);

        // Material sənədi bu resursun birbaşa URL-i ilə də açılmır.
        $this->get(ContractDocumentResource::getUrl('edit', ['record' => $drawing]))->assertNotFound();
    }

    public function test_roles_without_the_domain_are_refused(): void
    {
        // Dizayner: Ödənişlər = Yoxdur.
        $this->asStaff('designer');
        $this->get(route('filament.app.resources.payments.index'))->assertForbidden();

        // Vizualizator: Smeta = Yoxdur.
        $this->flushSession();
        $this->asStaff('visualizer');
        $this->get(route('filament.app.resources.budget-lines.index'))->assertForbidden();

        // Layihə meneceri ödənişlərə baxır, amma yarada bilmir (Ödənişlər = Baxış).
        $this->flushSession();
        $this->asStaff('project_manager');
        $this->get(route('filament.app.resources.payments.index'))->assertOk();
        $this->get(route('filament.app.resources.payments.create'))->assertForbidden();
    }

    public function test_another_studios_record_is_not_reachable_by_direct_url(): void
    {
        $this->asStaff('owner');

        $this->get(route('filament.app.resources.payments.edit', ['record' => $this->beta->payment]))->assertNotFound();
        $this->get(route('filament.app.resources.contract-documents.edit', ['record' => $this->beta->clientDocument]))->assertNotFound();
    }

    public function test_a_payment_can_be_linked_only_to_its_own_projects_procurement_item(): void
    {
        $this->asStaff('accountant');

        Livewire::test(CreatePayment::class)
            ->fillForm([
                'project_id' => $this->alpha->otherProject->id,
                'title' => 'Yanlış pozisiya',
                'amount' => 100,
                'status' => 'pending',
                'procurement_item_id' => $this->alpha->procurementItem->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['procurement_item_id']);

        Livewire::test(CreatePayment::class)
            ->fillForm([
                'project_id' => $this->alpha->project->id,
                'title' => 'Divan ödənişi',
                'amount' => 100,
                'status' => 'pending',
                'procurement_item_id' => $this->alpha->procurementItem->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $this->alpha->procurementItem->id,
            $this->inAlpha(fn () => Payment::where('title', 'Divan ödənişi')->value('procurement_item_id')),
        );
    }
}
