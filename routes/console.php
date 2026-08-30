<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('stages:mark-overdue')->dailyAt('06:00');
Schedule::command('tasks:notify-deadlines')->dailyAt('06:10');

// 12-month activity log retention (TZ §5.20).
Schedule::command('activitylog:clean')->monthly();
