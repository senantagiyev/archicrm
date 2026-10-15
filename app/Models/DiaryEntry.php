<?php

namespace App\Models;

use App\Enums\DiaryEntryKind;
use App\Enums\DiaryEntryStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Müəllif nəzarəti → «Səfərlər və hesabatlar»: obyektə səfər və ya hesabat,
 * qısa qeyd + fotolar. Dizayner qaralama yazır, «Dərc et» ilə müştəriyə açır.
 * `status` qeydin icra vəziyyətidir — dərcdən asılı deyil.
 *
 * Səfərdə aşkar edilən irad «İradlar»da (`PunchListIssue::diary_entry_id`)
 * açılır; səfərin fotoları ora yenidən yüklənmədən keçir.
 */
class DiaryEntry extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'project_id', 'author_user_id', 'kind', 'visited_on', 'body', 'status', 'photos', 'published_at',
    ];

    // Bazadakı defoltun yaddaşdakı əksi: yeni yaradılmış (hələ bazadan
    // oxunmamış) modeldə `status` boş qalmasın — cədvəl və portal enum gözləyir.
    protected $attributes = [
        'status' => 'new',
        'kind' => 'visit',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DiaryEntryKind::class,
            'status' => DiaryEntryStatus::class,
            'visited_on' => 'date',
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

    /** Bu səfərdə / hesabatda qeydə alınmış iradlar. */
    public function punchListIssues(): HasMany
    {
        return $this->hasMany(PunchListIssue::class);
    }

    /** @return list<string> Boş yolları atılmış foto siyahısı. */
    public function photoPaths(): array
    {
        return collect($this->photos ?? [])->filter(fn ($path) => is_string($path) && $path !== '')->values()->all();
    }
}
