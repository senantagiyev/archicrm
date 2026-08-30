<?php

namespace App\Support;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Enums\StaffRole;

/**
 * The TZ §5.4 access matrix, encoded as code. Policies consult this for the
 * level and separately enforce project membership where the TZ says "Öz"
 * (own projects only) — see requiresOwnProject().
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
     * Roles whose project-scoped access applies to their OWN projects only
     * (project membership or being the responsible manager). Owner and
     * Accountant see across all projects at their matrix level.
     */
    private const OWN_PROJECTS_ONLY = [
        StaffRole::ProjectManager->value,
        StaffRole::Designer->value,
        StaffRole::Visualizer->value,
        StaffRole::Procurement->value,
    ];

    public static function level(StaffRole $role, Domain $domain): AccessLevel
    {
        return self::LEVELS[$role->value][$domain->value] ?? AccessLevel::None;
    }

    public static function allows(StaffRole $role, Domain $domain, AccessLevel $minimum): bool
    {
        return self::level($role, $domain)->atLeast($minimum);
    }

    public static function requiresOwnProject(StaffRole $role): bool
    {
        return in_array($role->value, self::OWN_PROJECTS_ONLY, true);
    }
}
