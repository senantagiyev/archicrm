<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Invoice extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

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
