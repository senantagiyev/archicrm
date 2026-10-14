<?php

namespace App\Models;

use App\Enums\DiaryEntryStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Müəllif nəzarəti gündəliyi: obyektdən qısa qeyd + fotolar.
 * Dizayner qaralama yazır, «Dərc et» ilə müştəriyə açır.
 * `status` qeydin (iradın) icra vəziyyətidir — dərcdən asılı deyil.
 */
class DiaryEntry extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'project_id', 'author_user_id', 'body', 'status', 'photos', 'published_at',
    ];

    // Bazadakı defoltun yaddaşdakı əksi: yeni yaradılmış (hələ bazadan
    // oxunmamış) modeldə `status` boş qalmasın — cədvəl və portal enum gözləyir.
    protected $attributes = [
        'status' => 'new',
    ];

    protected function casts(): array
    {
        return [
            'status' => DiaryEntryStatus::class,
            'photos' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** Müştəri portalında yalnız dərc olunmuş qeydlər görünür. */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
