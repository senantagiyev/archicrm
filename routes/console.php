<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('stages:mark-overdue')->dailyAt('06:00');

// 12-month activity log retention (TZ §5.20).
Schedule::command('activitylog:clean')->monthly();
