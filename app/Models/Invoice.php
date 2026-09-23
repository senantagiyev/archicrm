<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOptimisticLock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Invoice extends Model
{
    use BelongsToTenant, HasFactory, HasOptimisticLock, LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id', 'client_id', 'number', 'issue_date', 'due_date',
        'currency', 'subtotal', 'tax', 'total', 'paid_amount', 'status', 'notes',
    ];

    protected $attributes = [
        'currency' => 'AZN',
        'paid_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Bütün maliyyə invariantları BİR yerdə — modeldə. Səbəb: Filament
        // formasındakı `minValue()` yalnız admin panelini qoruyur; idxal,
        // konsol əmri, seeder və API yolu ilə yazılan sətir heç bir yoxlamadan
        // keçmirdi və birbaşa «Debitor borc» kartına düşürdü.
        // Etalon: `PurchaseOrder::booted()` (cəmi yenidən hesablayır) və
        // `Expense::booted()` (mənfi məbləği istisna ilə bloklayır).
        static::saving(function (self $invoice): void {
            $tax = round((float) $invoice->tax, 2);

            // --- 1) `total` = `subtotal` + `tax` -------------------------------
            // Hansı tərəfin doğru olduğunu bu saxlanmada NƏYİN dəyişdiyi həll edir:
            //  • yalnız `total` dəyişibsə — operator ümumi məbləği əl ilə yazıb
            //    (məsələn sətirsiz, «yekun» faktura), ona görə `subtotal` ondan
            //    geri hesablanır: subtotal = total − tax;
            //  • `subtotal`/`tax` dəyişibsə (və ya heç nə dəyişməyibsə) —
            //    komponentlər doğrudur, `total` onlardan yenidən qurulur.
            // Beləliklə hər iki iş üsulu işləyir, amma DB-də invariant HƏMİŞƏ
            // qorunur: əvvəl üç sahə də bir-birindən asılı olmayan sərbəst
            // input idi və `subtotal 1000 + tax 180` yazılmış sətirdə `total`
            // 500 qala bilirdi.
            if ($invoice->isDirty('total') && ! $invoice->isDirty('subtotal') && ! $invoice->isDirty('tax')) {
                $total = round((float) $invoice->total, 2);
                $subtotal = round($total - $tax, 2);
            } else {
                $subtotal = round((float) $invoice->subtotal, 2);
                $total = round($subtotal + $tax, 2);
            }

            $paid = round((float) $invoice->paid_amount, 2);

            // --- 2) Mənfi məbləğ qadağandır ------------------------------------
            // `Expense.php:41` üslubu: kəsmək yox, istisna atmaq — mənfi faktura
            // debitor borcu «yeyir» (5 000 borc + (−4 000) faktura = 1 000 görünür),
            // yəni bu, səssiz düzəldilməli yox, dərhal dayandırılmalı səhvdir.
            // Sıra vacibdir: operator ən çox «Ümumi məbləğ»i yazır, ona görə
            // mesaj da əvvəlcə onu göstərsin (geri hesablanmış `subtotal`-ı yox).
            foreach ([
                'Ümumi məbləğ' => $total,
                'Vergi' => $tax,
                'Ara cəm' => $subtotal,
                'Ödənilmiş məbləğ' => $paid,
            ] as $label => $value) {
                if ($value < 0) {
                    throw new \RuntimeException("Hesab-fakturada «{$label}» mənfi ola bilməz.");
                }
            }

            // --- 3) Artıq ödəniş qadağandır ------------------------------------
            // KƏSMƏK YOX, İSTİSNA seçildi: `portfolio()` debitor borcu
            // `SUM(total - paid_amount)` kimi yığır, ona görə artıq ödəniş mənfi
            // qalıq verir və BAŞQA fakturanın borcunu görünməz edir (1 000-lik
            // fakturaya 3 000 yazılanda 5 000-lik borc kartda 3 000 kimi çıxırdı).
            // Məbləği səssizcə `total`-a kəssəydik, kassaya real daxil olmuş
            // artıq pul heç yerdə qeydə alınmazdı — operator səhvini görməli və
            // ya məbləği düzəltməli, ya da ayrıca faktura kəsməlidir.
            if ($paid > $total) {
                throw new \RuntimeException(
                    "Ödənilmiş məbləğ ({$paid}) hesab-fakturanın ümumi məbləğindən ({$total}) çox ola bilməz."
                );
            }

            $invoice->subtotal = $subtotal;
            $invoice->tax = $tax;
            $invoice->total = $total;
            $invoice->paid_amount = $paid;

            // --- 4) Status ödənişi izləyir (unpaid → partial → paid) -----------
            // `draft` və `cancelled` ödəniş axınından KƏNARDIR: qaralama hələ
            // kəsilməyib, ləğv edilmiş faktura isə şüurlu qərardır (Filament-dəki
            // «Ləğv et» əməliyyatı) — avtomatik keçid onları əzməməlidir.
            $flow = [
                InvoiceStatus::Issued,
                InvoiceStatus::Sent,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
                InvoiceStatus::Overdue,
            ];

            if (! in_array($invoice->status, $flow, true)) {
                return;
            }

            if ($total > 0 && $paid >= $total) {
                // Tam ödənilib — «Vaxtı keçib» də bağlanır, çünki borc qalmayıb.
                $invoice->status = InvoiceStatus::Paid;
            } elseif ($paid > 0) {
                // Qismən ödənilib. «Vaxtı keçib» statusu SAXLANILIR: o, ödəniş
                // tarixinin keçməsi barədə daha kritik məlumatı daşıyır və qismən
                // ödəniş gecikməni aradan qaldırmır.
                if ($invoice->status !== InvoiceStatus::Overdue) {
                    $invoice->status = InvoiceStatus::PartiallyPaid;
                }
            } elseif ($total > 0 && in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::PartiallyPaid], true)) {
                // Ödəniş geri götürülüb (səhv yazılış düzəldilib) — faktura
                // yenidən ödənilməmiş vəziyyətə qayıdır, əks halda tam ödənilmiş
                // kimi siyahıda qalardı.
                $invoice->status = InvoiceStatus::Sent;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'total', 'paid_amount', 'due_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        // Clients soft-delete; invoices must keep showing whom they belonged to.
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** Overdue when the due date has passed and it is not fully paid. */
    public function isOverdue(): bool
    {
        if ($this->due_date === null) {
            return false;
        }

        if (in_array($this->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)) {
            return false;
        }

        return $this->due_date->isPast()
            && (float) $this->paid_amount < (float) $this->total;
    }
}
