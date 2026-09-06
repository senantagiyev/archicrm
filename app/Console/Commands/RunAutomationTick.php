<?php

namespace App\Console\Commands;

use App\Enums\ApprovalStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LeadStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StaffRole;
use App\Models\Approval;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Notifications\AutomationAlert;
use App\Services\Automation\AutomationEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Time-based automations from Əlavə B (approval/invoice/meeting/brief/budget
 * reminders and escalations). Every effect is gated by the rule's Admin toggle
 * (AutomationEngine::isEnabled) and de-duplicated (once) so an hourly cadence
 * never sends the same reminder twice. Run hourly (routes/console.php).
 */
class RunAutomationTick extends Command
{
    protected $signature = 'automation:tick';

    protected $description = 'Əlavə B üzrə vaxt-əsaslı avtomatlaşdırmaları icra edir (approval/invoice/meeting/brief/büdcə xatırlatmaları)';

    public function handle(AutomationEngine $engine): int
    {
        $sent = 0;
        $sent += $this->approvalReminders($engine);       // rules 16, 17
        $sent += $this->invoiceReminders($engine);        // rules 26, 27
        $sent += $this->meetingReminders($engine);        // rule 39
        $sent += $this->briefReminders($engine);          // rule 12
        $sent += $this->budgetOverrunAlerts($engine);     // rule 30
        $sent += $this->leadSlaAlerts($engine);           // rule 2
        $sent += $this->deliveryLateAlerts($engine);      // rule 24

        $this->info("Avtomatlaşdırma tick: {$sent} bildiriş göndərildi.");

        return self::SUCCESS;
    }

    /** Rule 16: approval overdue ≥2 days → remind client. Rule 17: >5 days → escalate PM. */
    private function approvalReminders(AutomationEngine $engine): int
    {
        $r16 = $engine->isEnabled('rule-16');
        $r17 = $engine->isEnabled('rule-17');
        if (! $r16 && ! $r17) {
            return 0;
        }

        $today = today()->toDateString();
        $count = 0;

        $pending = Approval::query()
            ->where('status', ApprovalStatus::Pending->value)
            ->whereNotNull('respond_by')
            ->whereDate('respond_by', '<', today())
            ->with(['clientUser', 'project.manager'])
            ->get();

        foreach ($pending as $approval) {
            // Carbon 3 diffInDays is signed; respond_by is in the past → take the magnitude.
            $daysOverdue = (int) $approval->respond_by->diffInDays(today(), true);

            if ($r16 && $daysOverdue >= 2 && $approval->clientUser) {
                $engine->once('rule-16', "approval:{$approval->id}:{$today}", function () use ($approval, &$count) {
                    $approval->clientUser->notify(new AutomationAlert(
                        'Razılaşdırma gözləyir',
                        'Sizdən gözlənilən razılaşdırma gecikir. Zəhmət olmasa nəzərdən keçirin.',
                        null,
                        ['approval_id' => $approval->id, 'project_id' => $approval->project_id],
                        'rule-16',
                    ));
                    $count++;
                });
            }

            if ($r17 && $daysOverdue > 5 && $approval->project?->manager) {
                $engine->once('rule-17', "approval:{$approval->id}:{$today}", function () use ($approval, &$count) {
                    $approval->project->manager->notify(new AutomationAlert(
                        'Razılaşdırma 5 gündən çox cavabsızdır',
                        "Layihə: {$approval->project->name} — müştəri {$approval->respond_by->format('d.m.Y')} tarixindən cavab verməyib.",
                        null,
                        ['approval_id' => $approval->id, 'project_id' => $approval->project_id],
                        'rule-17',
                    ));
                    $count++;
                });
            }
        }

        return $count;
    }

    /** Rule 26: invoice due today → remind client. Rule 27: overdue → alert accountant + PM. */
    private function invoiceReminders(AutomationEngine $engine): int
    {
        $r26 = $engine->isEnabled('rule-26');
        $r27 = $engine->isEnabled('rule-27');
        if (! $r26 && ! $r27) {
            return 0;
        }

        $today = today()->toDateString();
        $count = 0;
        $unpaid = [InvoiceStatus::Issued->value, InvoiceStatus::Sent->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Overdue->value];

        $invoices = Invoice::query()
            ->whereIn('status', $unpaid)
            ->whereNotNull('due_date')
            ->with(['client.clientUsers', 'project.manager'])
            ->get();

        foreach ($invoices as $invoice) {
            $due = $invoice->due_date;

            if ($r26 && $due->isToday() && $invoice->client) {
                foreach ($invoice->client->clientUsers as $clientUser) {
                    $engine->once('rule-26', "invoice:{$invoice->id}:user:{$clientUser->id}:{$today}", function () use ($clientUser, $invoice, &$count) {
                        $clientUser->notify(new AutomationAlert(
                            'Hesab-faktura ödəniş günüdür',
                            "№ {$invoice->number} hesab-fakturasının bugün ödəniş tarixidir.",
                            null,
                            ['invoice_id' => $invoice->id, 'project_id' => $invoice->project_id],
                            'rule-26',
                        ));
                        $count++;
                    });
                }
            }

            if ($r27 && $due->isPast() && ! $due->isToday()) {
                foreach ($this->financeStaff() as $user) {
                    $engine->once('rule-27', "invoice:{$invoice->id}:staff:{$user->id}:{$today}", function () use ($user, $invoice, &$count) {
                        $user->notify(new AutomationAlert(
                            'Hesab-faktura gecikib',
                            "№ {$invoice->number} — ödəniş tarixi keçib ({$invoice->due_date->format('d.m.Y')}).",
                            null,
                            ['invoice_id' => $invoice->id, 'project_id' => $invoice->project_id],
                            'rule-27',
                        ));
                        $count++;
                    });
                }
            }
        }

        return $count;
    }

    /** Rule 39: meeting within 24h and within 1h → remind the project manager. */
    private function meetingReminders(AutomationEngine $engine): int
    {
        if (! $engine->isEnabled('rule-39')) {
            return 0;
        }

        $count = 0;
        $windows = [
            ['24h', now()->addDay()],
            ['1h', now()->addHour()],
        ];

        $upcoming = Meeting::query()
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addDay())
            ->with('project.manager')
            ->get();

        foreach ($upcoming as $meeting) {
            foreach ($windows as [$label, $edge]) {
                if ($meeting->starts_at->lte($edge) && $meeting->project?->manager) {
                    $engine->once('rule-39', "meeting:{$meeting->id}:{$label}", function () use ($meeting, $label, &$count) {
                        $when = $label === '1h' ? '1 saat' : '24 saat';
                        $meeting->project->manager->notify(new AutomationAlert(
                            'Yaxınlaşan görüş',
                            "\"{$meeting->title}\" görüşünə {$when} qalıb ({$meeting->starts_at->format('d.m.Y H:i')}).",
                            null,
                            ['meeting_id' => $meeting->id, 'project_id' => $meeting->project_id],
                            'rule-39',
                        ));
                        $count++;
                    });
                }
            }
        }

        return $count;
    }

    /** Rule 12: brief not started > N days after project creation → remind client. */
    private function briefReminders(AutomationEngine $engine): int
    {
        if (! $engine->isEnabled('rule-12')) {
            return 0;
        }

        $days = (int) setting('brief.reminder_days', 3);
        $today = today()->toDateString();
        $count = 0;

        $projects = Project::query()
            ->whereDate('created_at', '<=', today()->subDays($days))
            ->whereHas('brief', fn ($q) => $q->where('status', 'not_started'))
            ->with('client.clientUsers')
            ->get();

        foreach ($projects as $project) {
            foreach ($project->client?->clientUsers ?? [] as $clientUser) {
                $engine->once('rule-12', "brief:{$project->id}:user:{$clientUser->id}:{$today}", function () use ($clientUser, $project, &$count) {
                    $clientUser->notify(new AutomationAlert(
                        'Brif gözləyir',
                        "\"{$project->name}\" layihəsi üçün brif hələ doldurulmayıb. Zəhmət olmasa başlayın.",
                        null,
                        ['project_id' => $project->id],
                        'rule-12',
                    ));
                    $count++;
                });
            }
        }

        return $count;
    }

    /** Rule 30: budget_fact > budget_plan → alert management (once per project per month). */
    private function budgetOverrunAlerts(AutomationEngine $engine): int
    {
        if (! $engine->isEnabled('rule-30')) {
            return 0;
        }

        $period = today()->format('Y-m');
        $count = 0;

        $projects = Project::query()
            ->whereNotNull('budget_plan')
            ->where('budget_plan', '>', 0)
            ->whereColumn('budget_fact', '>', 'budget_plan')
            ->get();

        foreach ($projects as $project) {
            foreach ($this->owners() as $user) {
                $engine->once('rule-30', "project:{$project->id}:user:{$user->id}:{$period}", function () use ($user, $project, &$count) {
                    $user->notify(new AutomationAlert(
                        'Büdcə aşımı',
                        "\"{$project->name}\" layihəsinin faktiki büdcəsi planı aşıb.",
                        null,
                        ['project_id' => $project->id],
                        'rule-30',
                    ));
                    $count++;
                });
            }
        }

        return $count;
    }

    /** Rule 2: lead with no first contact past the SLA → alert responsible (or owners). */
    private function leadSlaAlerts(AutomationEngine $engine): int
    {
        if (! $engine->isEnabled('rule-2')) {
            return 0;
        }

        $days = (int) setting('lead.first_contact_sla_days', 2);
        $today = today()->toDateString();
        $count = 0;

        $leads = Lead::query()
            ->whereNull('first_contact_date')
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value, LeadStatus::Archived->value])
            ->whereDate('created_at', '<=', today()->subDays($days))
            ->with('responsible')
            ->get();

        foreach ($leads as $lead) {
            $recipients = $lead->responsible ? collect([$lead->responsible]) : $this->owners();

            foreach ($recipients as $user) {
                $engine->once('rule-2', "lead:{$lead->id}:user:{$user->id}:{$today}", function () use ($user, $lead, &$count) {
                    $user->notify(new AutomationAlert(
                        'Lidlə əlaqə gecikir (SLA)',
                        trim("\"{$lead->first_name} {$lead->last_name}\"").' lidi ilə hələ ilk əlaqə saxlanılmayıb.',
                        null,
                        ['lead_id' => $lead->id],
                        'rule-2',
                    ));
                    $count++;
                });
            }
        }

        return $count;
    }

    /** Rule 24: purchase order past its expected delivery and not received → procurement alert. */
    private function deliveryLateAlerts(AutomationEngine $engine): int
    {
        if (! $engine->isEnabled('rule-24')) {
            return 0;
        }

        $today = today()->toDateString();
        $count = 0;

        $orders = PurchaseOrder::query()
            ->whereNotNull('expected_delivery')
            ->whereDate('expected_delivery', '<', today())
            ->whereNotIn('status', [PurchaseOrderStatus::Received->value, PurchaseOrderStatus::Cancelled->value])
            ->with('supplier')
            ->get();

        foreach ($orders as $order) {
            foreach ($this->procurementStaff() as $user) {
                $engine->once('rule-24', "po:{$order->id}:user:{$user->id}:{$today}", function () use ($user, $order, &$count) {
                    $user->notify(new AutomationAlert(
                        'Çatdırılma gecikib',
                        'Satınalma sifarişi #'.$order->id.' — gözlənilən çatdırılma tarixi keçib ('.$order->expected_delivery->format('d.m.Y').').',
                        null,
                        ['purchase_order_id' => $order->id],
                        'rule-24',
                    ));
                    $count++;
                });
            }
        }

        return $count;
    }

    /** @return Collection<int,User> */
    private function procurementStaff()
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', [StaffRole::Owner->value, StaffRole::Procurement->value])
            ->get();
    }

    /** @return Collection<int,User> */
    private function financeStaff()
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', [StaffRole::Owner->value, StaffRole::Accountant->value, StaffRole::ProjectManager->value])
            ->get();
    }

    /** @return Collection<int,User> */
    private function owners()
    {
        return User::query()
            ->where('is_active', true)
            ->where('role', StaffRole::Owner->value)
            ->get();
    }
}
