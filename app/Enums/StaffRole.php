<?php

namespace App\Enums;

enum StaffRole: string
{
    case Owner = 'owner';
    case ProjectManager = 'project_manager';
    case Designer = 'designer';
    case Visualizer = 'visualizer';
    case Procurement = 'procurement';
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Sahibkar / Büro rəhbəri',
            self::ProjectManager => 'Layihə meneceri',
            self::Designer => 'Baş dizayner / Memar',
            self::Visualizer => 'Vizualizator',
            self::Procurement => 'Komplektləşdirici',
            self::Accountant => 'Mühasib / Maliyyə meneceri',
        };
    }
}
