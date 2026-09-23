<x-portal.shell :title="t('portal.nav_payments')" :project="$project" active="payments">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-heading font-semibold">{{ t('portal.nav_payments') }}</h1>
        @php
            // `projects.debt` işarəli kəmiyyətdir: razılaşdırılmış smeta və
            // komplektasiya MƏNFİ təsdiqlənmiş ödənişlər. Mənfi nəticə borc
            // deyil — müştəri hələ rəsmiləşdirilməmiş işin qabağına pul verib.
            // «Qalıq borc −222 ₼» yazmaq müştərini çaşdırırdı: etiket artıq
            // rəqəmin işarəsinə görə seçilir, ekrana isə həmişə müsbət məbləğ
            // çıxır.
            $debt = round((float) $project->debt, 2);
            [$debtLabel, $debtTone] = match (true) {
                $debt > 0 => [t('portal.debt'), 'text-error'],
                $debt < 0 => [t('portal.debt_credit'), 'text-ok'],
                default => [t('portal.debt_settled'), 'text-ok'],
            };
        @endphp
        <div class="flex items-baseline gap-3 rounded-ds-lg border border-black/8 bg-card px-5 py-3">
            <span class="text-helper font-medium text-black/60">{{ $debtLabel }}</span>
            <span class="text-title font-semibold {{ $debtTone }}">
                {{ number_format(abs($debt), 2, '.', ' ') }} ₼
            </span>
        </div>
    </div>

    <div class="overflow-x-auto rounded-ds-xl border border-black/8 bg-card">
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
