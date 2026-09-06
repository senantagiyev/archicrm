<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Unified calendar (TZ §8.7): meetings, stage plan-ends, task deadlines, payment
 * and invoice due dates. Events are fetched from route('calendar.events'),
 * already scoped to the user's accessible projects.
 */
class Calendar extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Layihələr';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Təqvim';

    protected static ?string $title = 'Təqvim';

    protected string $view = 'filament.pages.calendar';
}
