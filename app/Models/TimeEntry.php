<?php

namespace App\Models;

use App\Enums\TimeEntrySource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'user_id', 'project_id', 'stage_id', 'task_id',
        'started_at', 'ended_at', 'duration_minutes',
        'hourly_cost_snapshot', 'comment', 'source',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'hourly_cost_snapshot' => 'decimal:2',
            'source' => TimeEntrySource::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            // Modeldə saxlanılır, yalnız Filament formasında yox: vaxt qeydi
            // rentabellik hesabatına BİRBAŞA maya dəyəri kimi düşür, ona görə
            // istənilən yazı yolu (import, konsol, API, Livewire) eyni qaydadan
            // keçməlidir. `Expense::booted()` də eyni üslubdadır.
            if ($entry->started_at && $entry->ended_at) {
                // Tərs (və sıfır uzunluqlu) interval rədd edilir. Əvvəllər hook
                // yalnız `ended_at > started_at` halında hesablayırdı — yəni
                // 18:00→17:55 intervalına əl ilə yazılmış `duration_minutes = 600`
                // toxunulmadan qalır və 10 saatlıq saxta maya dəyəri yaradırdı.
                if ($entry->ended_at->lessThanOrEqualTo($entry->started_at)) {
                    throw new \RuntimeException('Vaxt qeydinin bitmə anı başlanğıcdan sonra olmalıdır.');
                }

                $entry->duration_minutes = $entry->started_at->diffInMinutes($entry->ended_at);
            }

            // Müddət intervalsız da yazıla bilər (import, konsol, «cəmi 8 saat»
            // tipli yekun qeyd) — o yolda yuxarıdakı yoxlama ümumiyyətlə
            // işləmirdi. Mənfi müddət isə rentabellikdə REAL saatları silir:
            // −600 dəqiqəlik sətir eyni layihədəki +600-ü sıfırlayır, maya
            // dəyəri 250 ₼ əvəzinə 0 çıxır, marja 75% əvəzinə 100% görünür.
            // Sütun `unsignedInteger` olsa da, SQLite bunu məcbur etmir.
            if ($entry->duration_minutes !== null && (int) $entry->duration_minutes < 0) {
                throw new \RuntimeException('Vaxt qeydinin müddəti mənfi ola bilməz.');
            }

            static::assertDoesNotOverlap($entry);

            // Snapshot the user's cost rate at entry time (TZ §7.27).
            if (blank($entry->hourly_cost_snapshot) || (float) $entry->hourly_cost_snapshot === 0.0) {
                $entry->hourly_cost_snapshot = optional($entry->user)->hourly_internal_cost ?? 0;
            }
        });
    }

    /**
     * Eyni işçinin üst-üstə düşən vaxt qeydlərini bloklayır.
     *
     * Niyə: kəsişmə yoxlaması olmadan 09:00–13:00 və 10:00–12:00 qeydləri
     * sərbəst saxlanılırdı və cəmi 360 dəqiqə verirdi — real iş isə 4 saatdır.
     * Bir günlük əmək iki dəfə xərclənir, rentabellik hesabatı isə bunu maya
     * dəyəri kimi toplayır.
     *
     * Yalnız interval yazılmış qeydlər tutuşdurulur: yalnız `duration_minutes`
     * ilə gələn qeydlərin (köhnə məlumat, import, xülasə daxiletmə) vaxt oxu
     * yoxdur, ona görə onlar üçün kəsişmə anlayışı da yoxdur.
     */
    private static function assertDoesNotOverlap(self $entry): void
    {
        if (! $entry->user_id || ! $entry->started_at || ! $entry->ended_at) {
            return;
        }

        $clash = static::query()
            ->where('user_id', $entry->user_id)
            ->when($entry->exists, fn ($query) => $query->whereKeyNot($entry->getKey()))
            ->whereNotNull('started_at')
            ->whereNotNull('ended_at')
            // Klassik interval kəsişməsi: mövcud.başlanğıc < yeni.bitmə
            // VƏ mövcud.bitmə > yeni.başlanğıc. Bitişik intervallar
            // (13:00-da bitən + 13:00-da başlayan) kəsişmə sayılmır.
            ->where('started_at', '<', $entry->ended_at)
            ->where('ended_at', '>', $entry->started_at)
            ->first();

        if ($clash) {
            throw new \RuntimeException(sprintf(
                'Bu işçinin %s–%s aralığında artıq vaxt qeydi var — intervallar üst-üstə düşə bilməz.',
                $clash->started_at->format('d.m.Y H:i'),
                $clash->ended_at->format('H:i'),
            ));
        }
    }

    public function labourCost(): float
    {
        return round($this->duration_minutes / 60 * (float) $this->hourly_cost_snapshot, 2);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
