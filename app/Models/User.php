<?php

namespace App\Models;

use App\Enums\StaffRole;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'role', 'role_id', 'is_active', 'locale', 'avatar_path',
        'hourly_internal_cost', 'weekly_capacity_hours', 'is_platform_admin',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => StaffRole::class,
            'is_active' => 'boolean',
            'is_platform_admin' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active
            && ($this->tenant_id === null || $this->tenant?->active === true);
    }

    protected static function booted(): void
    {
        static::updating(function (self $user): void {
            // The last active owner keeps the lights on: deactivating them leaves
            // a studio with nobody who can reach Komanda or Rollar, so nobody can
            // reactivate anyone. There is no way back without database access.
            $losingAccess = ($user->isDirty('is_active') && ! $user->is_active)
                || ($user->isDirty('role') && $user->getRawOriginal('role') === StaffRole::Owner->value);

            if ($losingAccess && $user->isLastActiveOwner()) {
                throw new \RuntimeException('Studiyanın son aktiv sahibkarını deaktiv etmək və ya rolunu dəyişmək olmaz.');
            }
        });

        static::deleting(function (self $user): void {
            if ($user->isLastActiveOwner()) {
                throw new \RuntimeException('Studiyanın son aktiv sahibkarını silmək olmaz.');
            }
        });
    }

    public function isLastActiveOwner(): bool
    {
        if ($this->getRawOriginal('role') !== StaffRole::Owner->value || ! $this->getRawOriginal('is_active')) {
            return false;
        }

        return static::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('role', StaffRole::Owner->value)
            ->where('is_active', true)
            ->whereKeyNot($this->getKey())
            ->doesntExist();
    }

    public function isOwner(): bool
    {
        return $this->role === StaffRole::Owner;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->isOwner() && $this->is_platform_admin;
    }

    /** Custom (DB) role that overrides the StaffRole enum matrix when assigned. */
    public function customRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function responsibleClients(): HasMany
    {
        return $this->hasMany(Client::class, 'responsible_user_id');
    }

    public function managedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'manager_user_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withPivot('project_role')
            ->withTimestamps();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_user_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
