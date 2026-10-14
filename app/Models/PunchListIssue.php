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
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Punch List — obyektdə iş gedərkən və ya təhvil-təslimdə aşkar edilən
 * qüsur/iradların siyahısı. Hər qüsur məsul şəxsə son tarixlə təyin olunur,
 * düzəldiləndə bağlanır.
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

    protected $fillable = [
        'project_id', 'room', 'title', 'description', 'photo_url', 'priority',
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
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'room', 'priority', 'status', 'responsible_user_id', 'due_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
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
}
