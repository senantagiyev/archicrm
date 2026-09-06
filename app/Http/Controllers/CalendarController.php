<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Models\Invoice;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Stage;
use App\Models\Task;
use App\Support\AccessMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Unified staff calendar feed (TZ §8.7): meetings, stage plan-end dates, task
 * deadlines, payment and invoice due dates — one colour per source. Scoped to the
 * projects the user may see (owner = all; other roles = own projects, §5.4) and
 * limited to FullCalendar's requested window so payloads stay small.
 */
class CalendarController extends Controller
{
    public function events(Request $request): JsonResponse
    {
        $user = $request->user();
        $start = $request->date('start') ?? now()->startOfMonth();
        $end = $request->date('end') ?? now()->endOfMonth();

        $projectIds = $this->accessibleProjectIds($user);
        // Never leak other projects: an empty set means an empty calendar.
        if ($projectIds !== null && $projectIds->isEmpty()) {
            return response()->json([]);
        }

        $scope = fn ($query) => $projectIds === null ? $query : $query->whereIn('project_id', $projectIds);
        $adminPath = config('app.admin_path');
        $events = [];

        $meetings = $scope(Meeting::query())
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$start, $end])
            ->with('project')
            ->get();
        foreach ($meetings as $m) {
            $events[] = [
                'title' => '📅 '.$m->title,
                'start' => $m->starts_at->toIso8601String(),
                'end' => $m->ends_at?->toIso8601String(),
                'color' => '#2563eb',
                'url' => url("{$adminPath}/meetings/{$m->id}/edit"),
            ];
        }

        $tasks = $scope(Task::query())
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [$start, $end])
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->get();
        foreach ($tasks as $t) {
            $overdue = $t->deadline->isPast();
            $events[] = [
                'title' => '✓ '.$t->title,
                'start' => $t->deadline->toDateString(),
                'allDay' => true,
                'color' => $overdue ? '#dc2626' : '#f59e0b',
                'url' => url("{$adminPath}/tasks/{$t->id}/edit"),
            ];
        }

        $stages = $scope(Stage::query())
            ->whereNotNull('date_plan_end')
            ->whereBetween('date_plan_end', [$start, $end])
            ->where('status', '!=', StageStatus::Done->value)
            ->get();
        foreach ($stages as $s) {
            $events[] = [
                'title' => '▪ '.$s->name,
                'start' => $s->date_plan_end->toDateString(),
                'allDay' => true,
                'color' => '#0d9488',
                'url' => url("{$adminPath}/projects/{$s->project_id}/edit"),
            ];
        }

        $payments = $scope(Payment::query())
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$start, $end])
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Overdue->value])
            ->get();
        foreach ($payments as $p) {
            $events[] = [
                'title' => '₼ '.rtrim(rtrim(number_format((float) $p->amount, 2), '0'), '.').' — '.$p->title,
                'start' => $p->due_date->toDateString(),
                'allDay' => true,
                'color' => '#7c3aed',
                'url' => url("{$adminPath}/projects/{$p->project_id}/edit"),
            ];
        }

        $invoices = $scope(Invoice::query())
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$start, $end])
            ->whereNotIn('status', ['paid', 'cancelled', 'draft'])
            ->get();
        foreach ($invoices as $i) {
            $events[] = [
                'title' => '🧾 № '.$i->number,
                'start' => $i->due_date->toDateString(),
                'allDay' => true,
                'color' => '#be123c',
                'url' => url("{$adminPath}/invoices/{$i->id}/edit"),
            ];
        }

        return response()->json($events);
    }

    /**
     * Project ids the user may see, or null for "all projects" (no restriction).
     *
     * @return Collection<int,int>|null
     */
    private function accessibleProjectIds($user)
    {
        if (! $user || ! AccessMatrix::requiresOwnProject($user->role)) {
            return null;
        }

        return Project::query()
            ->where(fn ($q) => $q
                ->where('manager_user_id', $user->id)
                ->orWhereHas('members', fn ($m) => $m->whereKey($user->id)))
            ->pluck('id');
    }
}
