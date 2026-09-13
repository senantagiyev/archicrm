<?php

namespace App\Support;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Enums\StaffRole;
use App\Models\Role;
use App\Models\User;

/**
 * The TZ §5.4 access matrix. The const below is the canonical built-in matrix; it
 * is seeded into the `roles` table (RoleSeeder) so owners can edit it and add
 * custom roles (TZ V1.2 constructor). At runtime a user's access resolves from
 * their DB role (custom role_id, else the seeded system role keyed by the enum),
 * falling back to the const when no DB row exists (fresh installs / tests).
 *
 * Enforced at the policy/API level, not just by hiding UI (TZ §5.20).
 */
class AccessMatrix
{
    /** @var array<string, array<string, AccessLevel>> */
    private const LEVELS = [
        StaffRole::Owner->value => [
            Domain::Clients->value => AccessLevel::Full,
            Domain::Projects->value => AccessLevel::Full,
            Domain::Brief->value => AccessLevel::Full,
            Domain::StagesTasks->value => AccessLevel::Full,
            Domain::FilesDocuments->value => AccessLevel::Full,
            Domain::Budget->value => AccessLevel::Full,
            Domain::Procurement->value => AccessLevel::Full,
            Domain::Payments->value => AccessLevel::Full,
            Domain::OwnerDashboard->value => AccessLevel::Full,
            Domain::Analytics->value => AccessLevel::Full,
        ],
        StaffRole::ProjectManager->value => [
            Domain::Clients->value => AccessLevel::Edit,
            Domain::Projects->value => AccessLevel::Full,      // own projects
            Domain::Brief->value => AccessLevel::Full,
            Domain::StagesTasks->value => AccessLevel::Full,
            Domain::FilesDocuments->value => AccessLevel::Full,
            Domain::Budget->value => AccessLevel::Edit,
            Domain::Procurement->value => AccessLevel::Edit,
            Domain::Payments->value => AccessLevel::View,
            Domain::OwnerDashboard->value => AccessLevel::None,
            Domain::Analytics->value => AccessLevel::View,     // own projects
        ],
        StaffRole::Designer->value => [
            Domain::Clients->value => AccessLevel::View,
            Domain::Projects->value => AccessLevel::Edit,      // own projects
            Domain::Brief->value => AccessLevel::Full,
            Domain::StagesTasks->value => AccessLevel::Edit,   // own
            Domain::FilesDocuments->value => AccessLevel::Edit,
            Domain::Budget->value => AccessLevel::View,
            Domain::Procurement->value => AccessLevel::View,
            Domain::Payments->value => AccessLevel::None,
            Domain::OwnerDashboard->value => AccessLevel::None,
            Domain::Analytics->value => AccessLevel::None,
        ],
        StaffRole::Visualizer->value => [
            Domain::Clients->value => AccessLevel::None,
            Domain::Projects->value => AccessLevel::Edit,      // own projects
            Domain::Brief->value => AccessLevel::View,
            Domain::StagesTasks->value => AccessLevel::Edit,   // own
            Domain::FilesDocuments->value => AccessLevel::Edit, // visuals
            Domain::Budget->value => AccessLevel::None,
            Domain::Procurement->value => AccessLevel::None,
            Domain::Payments->value => AccessLevel::None,
            Domain::OwnerDashboard->value => AccessLevel::None,
            Domain::Analytics->value => AccessLevel::None,
        ],
        StaffRole::Procurement->value => [
            Domain::Clients->value => AccessLevel::None,
            Domain::Projects->value => AccessLevel::Edit,      // own projects
            Domain::Brief->value => AccessLevel::View,
            Domain::StagesTasks->value => AccessLevel::Edit,   // own
            Domain::FilesDocuments->value => AccessLevel::View,
            Domain::Budget->value => AccessLevel::View,
            Domain::Procurement->value => AccessLevel::Full,
            Domain::Payments->value => AccessLevel::None,
            Domain::OwnerDashboard->value => AccessLevel::None,
            Domain::Analytics->value => AccessLevel::None,
        ],
        StaffRole::Accountant->value => [
            Domain::Clients->value => AccessLevel::View,
            Domain::Projects->value => AccessLevel::View,
            Domain::Brief->value => AccessLevel::None,
            Domain::StagesTasks->value => AccessLevel::View,
            Domain::FilesDocuments->value => AccessLevel::View, // contracts/acts
            Domain::Budget->value => AccessLevel::Full,
            Domain::Procurement->value => AccessLevel::View,
            Domain::Payments->value => AccessLevel::Full,
            Domain::OwnerDashboard->value => AccessLevel::View, // limited (finance)
            Domain::Analytics->value => AccessLevel::View,      // finance
        ],
    ];

    /**
     * Built-in roles whose project-scoped access applies to their OWN projects only.
     * Owner and Accountant see across all projects at their matrix level.
     */
    private const OWN_PROJECTS_ONLY = [
        StaffRole::ProjectManager->value,
        StaffRole::Designer->value,
        StaffRole::Visualizer->value,
        StaffRole::Procurement->value,
    ];

    /** @var array<string, array{levels: array<string,int>, own: bool}> Per-request resolve cache. */
    private static array $cache = [];

    public static function level(User $user, Domain $domain): AccessLevel
    {
        $levels = self::resolve($user)['levels'];

        return AccessLevel::from((int) ($levels[$domain->value] ?? 0));
    }

    public static function allows(User $user, Domain $domain, AccessLevel $minimum): bool
    {
        return self::level($user, $domain)->atLeast($minimum);
    }

    public static function requiresOwnProject(User $user): bool
    {
        return self::resolve($user)['own'];
    }

    /** Clear the per-request cache (call after a role/assignment change in the same request). */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /**
     * The built-in matrix in seed-ready form: key => [name, levels(domain=>int), own].
     *
     * @return array<string, array{name: string, levels: array<string,int>, own: bool}>
     */
    public static function systemRoles(): array
    {
        $roles = [];

        foreach (self::LEVELS as $key => $domains) {
            $roles[$key] = [
                'name' => StaffRole::from($key)->label(),
                'levels' => array_map(fn (AccessLevel $l) => $l->value, $domains),
                'own' => in_array($key, self::OWN_PROJECTS_ONLY, true),
            ];
        }

        return $roles;
    }

    /**
     * Resolve a user's effective matrix: custom role → seeded system role → const.
     *
     * @return array{levels: array<string,int>, own: bool}
     */
    private static function resolve(User $user): array
    {
        // The tenant is part of the key: roles resolve per studio (a studio's own
        // copy shadows the platform-wide row), so caching on the role alone would
        // serve studio A's matrix to studio B inside one process — which the
        // automation tick does routinely, looping every studio in a single run.
        $key = 't:'.($user->tenant_id ?? 0).'|'
            .($user->role_id ? 'id:'.$user->role_id : 'enum:'.($user->role?->value ?? 'none'));

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        // `active` is honoured: the RoleResource toggle used to change nothing,
        // so deactivating a role left every grant it carried fully live.
        // The custom role must belong to this user's studio (or be a platform-wide
        // row). A role_id pointing at ANOTHER studio's custom role used to grant
        // its levels outright — the shortest path to cross-studio privileges.
        $role = null;
        if ($user->role_id) {
            $role = Role::forTenant($user->tenant_id)->where('id', $user->role_id)->first();
        }
        if (! $role && $user->role) {
            $role = Role::forTenant($user->tenant_id)->where('key', $user->role->value)->first();
        }

        // Deactivating a role REVOKES it. Filtering `active` inside the lookup
        // instead let resolution fall through to the platform default (or the
        // const matrix), so the "Aktiv" switch looked like an off button and
        // silently meant "revert to default" — the user kept working.
        if ($role && ! $role->active) {
            return self::$cache[$key] = ['levels' => [], 'own' => true];
        }

        if ($role) {
            return self::$cache[$key] = ['levels' => $role->levels, 'own' => (bool) $role->own_projects_only];
        }

        // Const fallback (no DB roles seeded yet, or unknown role).
        $enumKey = $user->role?->value;
        $levels = isset(self::LEVELS[$enumKey])
            ? array_map(fn (AccessLevel $l) => $l->value, self::LEVELS[$enumKey])
            : [];

        return self::$cache[$key] = [
            'levels' => $levels,
            'own' => in_array($enumKey, self::OWN_PROJECTS_ONLY, true),
        ];
    }
}
