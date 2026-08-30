<?php

namespace App\Enums;

/**
 * A user's role within a specific project (project_members pivot).
 */
enum ProjectRole: string
{
    case Manager = 'manager';
    case Designer = 'designer';
    case Visualizer = 'visualizer';
    case Procurement = 'procurement';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Layihə meneceri',
            self::Designer => 'Dizayner',
            self::Visualizer => 'Vizualizator',
            self::Procurement => 'Komplektləşdirici',
            self::External => 'Xarici tərəfdaş',
        };
    }
}
