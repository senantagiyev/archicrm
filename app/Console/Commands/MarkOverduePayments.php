<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Enums\StaffRole;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentOverdue;
use Illuminate\Console\Command;

class MarkOverduePayments extends Command
{
    protected $signature = 'payments:mark-overdue';

    protected $description = 'Plan tarixi keçmiş ödənişləri "Gecikib" statusuna keçirir və mühasib/sahibkara bildiriş göndərir';

    public function handle(): int
    {
        $payments = Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->with('project')
            ->get();

        $recipients = User::where('is_active', true)
            ->whereIn('role', [StaffRole::Owner->value, StaffRole::Accountant->value])
            ->get();

        foreach ($payments as $payment) {
            $payment->update(['status' => PaymentStatus::Overdue]);

            foreach ($recipients as $user) {
                $user->notify(new PaymentOverdue($payment));
            }
        }

        $this->info($payments->count().' ödəniş "Gecikib" statusuna keçirildi.');

        return self::SUCCESS;
    }
}
