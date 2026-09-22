<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Project extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'client_id', 'parent_project_id', 'name', 'type', 'address', 'area',
        'budget_plan', 'budget_fact', 'deadline', 'status',
        'readiness', 'debt', 'manager_user_id', 'client_response_days',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'status' => ProjectStatus::class,
            'area' => 'decimal:2',
            'budget_plan' => 'decimal:2',
            'budget_fact' => 'decimal:2',
            'debt' => 'decimal:2',
            'deadline' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'deadline', 'budget_plan', 'manager_user_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Roomix «Subproject» — layihənin təmir/tikinti mərhələsi.
     *
     * Alt-layihə də tam hüquqlu layihədir (öz çatı, mərhələləri, faylları və
     * iştirakçıları var), sadəcə valideyninə bağlıdır.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_project_id');
    }

    public function subprojects(): HasMany
    {
        return $this->hasMany(self::class, 'parent_project_id');
    }

    public function isSubproject(): bool
    {
        return $this->parent_project_id !== null;
    }

    public function client(): BelongsTo
    {
        // Clients soft-delete; their projects must keep showing who they belonged to.
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot('project_role')
            ->withTimestamps();
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('position');
    }

    public function procurementItems(): HasMany
    {
        return $this->hasMany(ProcurementItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class);
    }

    public function diaryEntries(): HasMany
    {
        return $this->hasMany(DiaryEntry::class);
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class);
    }

    public function specificationItems(): HasMany
    {
        return $this->hasMany(SpecificationItem::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** What the studio orders from suppliers for this project — its purchase cost. */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ProjectDecision::class)->latest('decided_at');
    }

    public function punchListIssues(): HasMany
    {
        return $this->hasMany(PunchListIssue::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * Müştərinin qərarını gözləyən razılaşdırmaların sayı — portalda
     * «Sizin növbəniz» göstəricisini qidalandırır.
     *
     * Nəticə sorğu başına bir dəfə hesablanır: göstərici hər səhifənin
     * başlığındadır, yoxsa hər açılışda eyni sorğu təkrarlanardı.
     */
    public function pendingClientApprovalsCount(): int
    {
        return once(fn () => $this->approvals()
            ->where('status', ApprovalStatus::Pending->value)
            ->count());
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function brief(): HasOne
    {
        return $this->hasOne(Brief::class);
    }

    public function briefAnswers(): HasManyThrough
    {
        return $this->hasManyThrough(BriefAnswer::class, Brief::class);
    }

    /** Is the user the manager or a member of this project? */
    public function hasMember(User $user): bool
    {
        if ($this->manager_user_id === $user->id) {
            return true;
        }

        return $this->members()->whereKey($user->id)->exists();
    }
}
