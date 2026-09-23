<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Meeting extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity;

    protected $fillable = [
        'project_id', 'title', 'starts_at', 'ends_at', 'participants',
        'location', 'online_link', 'notes', 'recording_link',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'participants' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // İştirakçılar və protokol keçidi artıq admin paneldən dəyişdirilir,
            // ona görə audit jurnalına da düşür.
            ->logOnly(['project_id', 'title', 'starts_at', 'ends_at', 'participants', 'location', 'online_link', 'recording_link'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        // Bitmə vaxtı başlanğıcdan əvvəl olan görüş təqvimə MƏNFİ uzunluqlu
        // hadisə kimi düşür. Formadakı `->after('starts_at')` yalnız admin
        // panelini qoruyur — import, avtomatlaşdırma və gələcək API üçün son
        // sipər buradadır.
        //
        // Yalnız «əvvəl» bloklanır, «bərabər» yox: sıfır uzunluqlu köhnə qeydlər
        // (proqramla yazılmışlar) redaktə edilə bilməli qalır, formada isə
        // `after` onsuz da daha ciddidir.
        static::saving(function (self $meeting): void {
            if ($meeting->ends_at && $meeting->starts_at && $meeting->ends_at->lt($meeting->starts_at)) {
                throw new \RuntimeException(
                    'Görüşün bitmə vaxtı başlama vaxtından əvvəl ola bilməz.'
                );
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * İştirakçıların oxunaqlı adları.
     *
     * Forma indi `users.id` saxlayır (ad dəyişəndə iştirakçı itmir), köhnə
     * qeydlərdə isə sərbəst mətn var — cədvəldə arxiv görüşlər boş görünməsin
     * deyə hər iki format oxunur. Silinmiş işçi `#id` kimi qalır ki, sətir
     * səssizcə yox olmasın.
     *
     * @return array<int, string>
     */
    public function participantNames(): array
    {
        $values = array_values(array_filter((array) ($this->participants ?? []), fn ($value) => filled($value)));

        if ($values === []) {
            return [];
        }

        $ids = array_filter($values, fn ($value) => is_int($value) || ctype_digit((string) $value));

        $names = $ids === []
            ? []
            : User::query()->whereKey($ids)->pluck('name', 'id')->all();

        return array_map(function ($value) use ($names) {
            if (is_int($value) || ctype_digit((string) $value)) {
                return $names[(int) $value] ?? '#'.$value;
            }

            return (string) $value;
        }, $values);
    }
}
