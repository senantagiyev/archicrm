<?php

namespace App\Models;

use App\Enums\PunchIssuePriority;
use App\Enums\PunchIssueStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Observers\PunchListIssueObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Müəllif nəzarəti → «İradlar» (köhnə Punch List, TZ §8): obyektdə iş
 * gedərkən və ya təhvil-təslimdə aşkar edilən qüsur/iradlar. Hər irad məsul
 * şəxsə son tarixlə təyin olunur; məsul şəxs düzəlişin nəticəsini (qeyd +
 * foto) yazır, yoxlayan bağlayır və ya yenidən işə qaytarır. Müzakirə
 * `comments`-də, dəyişiklik tarixçəsi activity log-dadır.
 *
 * Portalda iradlar YOXDUR — müzakirə və nəticə qeydləri daxilidir.
 */
#[ObservedBy(PunchListIssueObserver::class)]
class PunchListIssue extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity;

    /**
     * İşi bitmiş sayılan statuslar (cədvəldəki gecikmə rəngi də bunları istisna edir).
     * «Rədd edilib» QƏSDƏN burada deyil: düzəliş qəbul olunmayıb, yenidən görülməlidir.
     */
    public const RESOLVED_STATUSES = [PunchIssueStatus::Resolved, PunchIssueStatus::Closed];

    /** Yoxlayanın bağlaya və ya yenidən işə qaytara biləcəyi statuslar. */
    public const REVIEWABLE_STATUSES = [PunchIssueStatus::ReadyForReview, PunchIssueStatus::Resolved];

    /**
     * `resolved_*` / `closed_*` / `created_by_user_id` QƏSDƏN burada deyil:
     * onları yalnız `recordResolution()`, `close()` və yaradılış hook-u yazır —
     * formadan və ya sorğudan «kim, nə vaxt həll etdi» saxtalaşdırılmasın.
     * `photo_url` / `resolved_photo_url` köhnə tək-foto sütunlarıdır (geriyə uyğunluq).
     */
    protected $fillable = [
        'project_id', 'diary_entry_id', 'room', 'title', 'description', 'photo_url', 'photos', 'priority',
        'responsible_user_id', 'due_date', 'status', 'resolved_photo_url',
    ];

    protected $attributes = [
        'status' => 'open',
        'priority' => 'normal',
    ];

    protected function casts(): array
    {
        return [
            'status' => PunchIssueStatus::class,
            'priority' => PunchIssuePriority::class,
            'due_date' => 'date',
            'photos' => 'array',
            'resolved_photos' => 'array',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $issue): void {
            // Müəllif həmişə serverdən — formada redaktə olunan sahə deyil.
            if ($issue->created_by_user_id === null && auth()->guard('web')->id() !== null) {
                $issue->created_by_user_id = auth()->guard('web')->id();
            }
        });
    }

    /**
     * Dəyişiklik tarixçəsi (müzakirə pəncərəsində göstərilir). Fotolar və
     * nəticə qeydi də daxildir — «kim nəyi nə vaxt dəyişdi» sualı açıq qalmasın.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'title', 'room', 'description', 'priority', 'status', 'responsible_user_id', 'due_date',
                'photos', 'resolved_photos', 'resolution_note', 'resolved_by_user_id', 'closed_by_user_id',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ─────────────── İş axını ───────────────

    /**
     * Məsul şəxs düzəlişin nəticəsini qeyd edir: qeyd + (istəyə görə) fotolar.
     * İrad «Həll edilib» olur və yoxlayanın siyahısına keçir.
     *
     * @param  list<string>  $photos  `public` diskindəki yollar
     */
    public function recordResolution(User $by, string $note, array $photos = []): void
    {
        if ($this->status === PunchIssueStatus::Closed) {
            throw new RuntimeException('Bağlanmış iradın nəticəsi dəyişdirilə bilməz — əvvəlcə yenidən işə qaytarın.');
        }

        $this->forceFill([
            'status' => PunchIssueStatus::Resolved,
            'resolution_note' => $note,
            'resolved_photos' => array_values(array_filter($photos, fn ($path) => is_string($path) && $path !== '')),
            'resolved_at' => now(),
            'resolved_by_user_id' => $by->getKey(),
            'closed_at' => null,
            'closed_by_user_id' => null,
        ])->save();
    }

    /** Yoxlayan düzəlişi qəbul edir — irad bağlanır. */
    public function close(User $by, ?string $comment = null): void
    {
        if (! in_array($this->status, self::REVIEWABLE_STATUSES, true)) {
            throw new RuntimeException('Yalnız həll edilmiş irad bağlana bilər.');
        }

        $this->forceFill([
            'status' => PunchIssueStatus::Closed,
            'closed_at' => now(),
            'closed_by_user_id' => $by->getKey(),
        ])->save();

        if (filled($comment)) {
            $this->addComment($by, $comment);
        }
    }

    /** Yoxlayan düzəlişi qəbul etmir — səbəb müzakirəyə yazılır (MƏCBURİ). */
    public function returnForRework(User $by, string $reason): void
    {
        if (! in_array($this->status, [...self::REVIEWABLE_STATUSES, PunchIssueStatus::Closed], true)) {
            throw new RuntimeException('İrad hələ həll edilməyib — yenidən işə qaytarmaq olmaz.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Səbəb yazılmalıdır.');
        }

        $this->forceFill([
            'status' => PunchIssueStatus::Rejected,
            'closed_at' => null,
            'closed_by_user_id' => null,
        ])->save();

        $this->addComment($by, 'Yenidən işə qaytarıldı: '.$reason);
    }

    public function addComment(User $author, string $body): Comment
    {
        return $this->comments()->create([
            'author_type' => $author->getMorphClass(),
            'author_id' => $author->getKey(),
            'body' => $body,
        ]);
    }

    // ─────────────── Fotolar ───────────────

    /**
     * İradın fotoları: yeni `photos` massivi + köhnə tək `photo_url` (köçürmə
     * işlədilməmiş sətirlər üçün). Eyni fayl bir dəfə göstərilir.
     *
     * @return list<string>
     */
    public function photoPaths(): array
    {
        return self::cleanPaths([...($this->photos ?? []), $this->photo_url]);
    }

    /** @return list<string> Düzəlişdən sonrakı fotolar (köhnə `resolved_photo_url` daxil). */
    public function resolvedPhotoPaths(): array
    {
        return self::cleanPaths([...($this->resolved_photos ?? []), $this->resolved_photo_url]);
    }

    /**
     * @param  array<int, mixed>  $paths
     * @return list<string>
     */
    private static function cleanPaths(array $paths): array
    {
        return collect($paths)
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** Həll edilməmiş (məsul şəxsin hələ işi olan) qüsurlar. */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNotIn(
            $query->qualifyColumn('status'),
            array_map(fn (PunchIssueStatus $s) => $s->value, self::RESOLVED_STATUSES),
        );
    }

    /**
     * Bu layihədə qüsuru kimə təyin etmək olar: layihəni AÇA bilən və Punch
     * List tabını GÖRƏ bilən aktiv işçilər.
     *
     * Əvvəl seçim siyahısı studiyanın BÜTÜN aktiv işçiləri idi. «Yalnız öz
     * layihələri» rolu (dizayner, vizualizator, təchizat) üzvü olmadığı
     * layihənin qüsuruna təyin edilə bilirdi — amma həmin layihəni açmaq ona
     * qadağandır, yəni qüsur ona təyin olunsa da onu heç yerdə görə bilmirdi.
     * Qayda policy-lərdən gəlir ki, rol konstruktorundakı xüsusi rollar da
     * düzgün hesablansın.
     *
     * @return Collection<int, User>
     */
    public static function assignableUsers(Project $project): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => self::canBeAssignedOn($user, $project))
            ->values();
    }

    public static function canBeAssignedOn(User $user, Project $project): bool
    {
        $gate = Gate::forUser($user);

        return $user->is_active !== false
            && $gate->allows('update', $project)
            && $gate->allows('viewAny', self::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** İrad hansı səfərdə / hesabatda aşkar edilib. */
    public function diaryEntry(): BelongsTo
    {
        return $this->belongsTo(DiaryEntry::class);
    }

    /** Daxili müzakirə (işçilər arası) — portala çıxmır. */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
