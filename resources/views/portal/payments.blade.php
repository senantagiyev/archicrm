<x-portal.shell :title="t('portal.nav_payments')" :project="$project" active="payments">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-heading font-semibold">{{ t('portal.nav_payments') }}</h1>
        <div class="flex items-baseline gap-3 rounded-ds-lg border border-black/8 bg-white px-5 py-3">
            <span class="text-helper font-medium text-black/60">{{ t('portal.debt') }}</span>
            <span class="text-title font-semibold {{ (float) $project->debt > 0 ? 'text-error' : 'text-ok' }}">
                {{ number_format((float) $project->debt, 2, '.', ' ') }} ₼
            </span>
        </div>
    </div>

    <div class="overflow-x-auto rounded-ds-xl border border-black/8 bg-white">
        <table class="w-full text-body">
            <thead>
                <tr class="border-b border-black/8 text-left text-helper font-semibold text-black/60">
                    <th class="px-5 py-3.5">{{ t('portal.payment_title') }}</th>
                    <th class="px-5 py-3.5">{{ t('portal.amount') }}</th>
                    <th class="px-5 py-3.5">{{ t('portal.plan_date') }}</th>
                    <th class="px-5 py-3.5">{{ t('portal.paid_at') }}</th>
                    <th class="px-5 py-3.5">{{ t('portal.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($payments as $payment)
                    <tr class="border-b border-black/5 last:border-0">
                        <td class="px-5 py-4 font-medium">{{ $payment->title }}</td>
                        <td class="px-5 py-4 font-semibold">{{ number_format((float) $payment->amount, 2, '.', ' ') }} ₼</td>
                        <td class="px-5 py-4 text-black/70">{{ $payment->due_date?->format('d.m.Y') ?? '—' }}</td>
                        <td class="px-5 py-4 text-black/70">{{ $payment->paid_at?->format('d.m.Y') ?? '—' }}</td>
                        <td class="px-5 py-4">
                            <span @class([
                                'rounded-pill px-3 py-1 text-helper font-medium',
                                'bg-ok-soft text-ok' => $payment->status === \App\Enums\PaymentStatus::Paid,
                                'bg-warn-soft text-warn' => $payment->status === \App\Enums\PaymentStatus::Pending,
                                'bg-error-soft text-error' => $payment->status === \App\Enums\PaymentStatus::Overdue,
                            ])>{{ $payment->status->translatedLabel() }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-12 text-center text-black/55">{{ t('portal.no_payments') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-portal.shell>
