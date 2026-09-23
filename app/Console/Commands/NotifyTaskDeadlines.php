<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Tenant;
use App\Notifications\TaskDeadlineSoon;
use App\Notifications\TaskOverdue;
use App\Services\Automation\AutomationEngine;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class NotifyTaskDeadlines extends Command
{
    protected $signature = 'tasks:notify-deadlines';

    protected $description = 'Son tarixi yaxınlaşan tapşırıqların icraçılarına, gecikənlərdə isə icraçı + menecerə bildiriş göndərir';

    public function handle(AutomationEngine $engine, TenantContext $tenants): int
    {
        // HƏR STUDİYA ÜÇÜN AYRICA keçid — etalon: RunAutomationTick::handle().
        // Əmr planlaşdırıcıdan CLI-da işləyir, orada tenant konteksti boşdur və
        // `AutomationEngine::isEnabled()` yalnız `tenant_id IS NULL` (platforma)
        // sətirlərini oxuyur. Nəticədə beta studiyası rule-9-u söndürsə belə
        // beta-nın işçisinə gecikmə bildirişi gedirdi — studiya səviyyəli
        // idarəetmə tamamilə nəzərə alınmırdı.
        //
        // Tək studiyalı quraşdırma (və tenancy-dən əvvəlki, `tenant_id`-siz
        // sətirlər) üçün scope-suz keçid saxlanılır.
        $tenantIds = Tenant::query()->where('active', true)->pluck('id');

        if ($tenantIds->count() < 2) {
            [$soon, $overdue] = $this->notifyForCurrentTenant($engine);

            return $this->report($soon, $overdue);
        }

        $soon = 0;
        $overdue = 0;

        foreach ($tenantIds as $tenantId) {
            [$tenantSoon, $tenantOverdue] = $tenants->actingAs($tenantId, function () use ($engine): array {
                // Qayda xəritəsi studiyaya bağlıdır — hər keçiddə təzələnməlidir.
                $engine->flush();

                return $this->notifyForCurrentTenant($engine);
            });

            $soon += $tenantSoon;
            $overdue += $tenantOverdue;
        }

        return $this->report($soon, $overdue);
    }

    private function report(int $soon, int $overdue): int
    {
        $this->info(sprintf('%d yaxınlaşan, %d gecikən tapşırıq üzrə bildiriş göndərildi.', $soon, $overdue));

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} [yaxınlaşan, gecikən] tapşırıq sayı
     */
    private function notifyForCurrentTenant(AutomationEngine $engine): array
    {
        $days = (int) setting('notifications.deadline_days', 3);

        $soon = Task::query()
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->whereDate('deadline', today()->addDays($days))
            ->whereNotNull('assignee_user_id')
            // A departed employee is soft-deleted, which does NOT clear
            // assignee_user_id — so the row still matches while the relation
            // resolves to null. Without this the whole command dies on it.
            // Deactivated accounts are skipped too: they cannot act on the task
            // and the mail carries project and client names.
            ->whereHas('assignee', fn ($query) => $query->where('is_active', true))
            ->whereHas('project')
            ->with(['assignee', 'project'])
            ->get();

        foreach ($soon as $task) {
            $task->assignee?->notify(new TaskDeadlineSoon($task, $days));
        }

        // Newly overdue (deadline was yesterday) — assignee + project manager (TZ §5.13).
        // Gated by Əlavə B rule 9 so it can be switched off from Admin → Avtomatlaşdırmalar.
        $overdue = collect();

        if ($engine->isEnabled('rule-9')) {
            $overdue = Task::query()
                ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
                ->whereDate('deadline', today()->subDay())
                ->whereHas('project')
                ->with(['assignee', 'project.manager'])
                ->get();

            foreach ($overdue as $task) {
                $task->assignee?->notify(new TaskOverdue($task));

                if ($task->project?->manager && ! $task->project->manager->is($task->assignee)) {
                    $task->project->manager->notify(new TaskOverdue($task));
                }
            }
        }

        return [$soon->count(), $overdue->count()];
    }
}
