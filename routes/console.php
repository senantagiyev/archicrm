<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('stages:mark-overdue')->dailyAt('06:00');
Schedule::command('tasks:notify-deadlines')->dailyAt('06:10');
Schedule::command('payments:mark-overdue')->dailyAt('06:20');

// Time-based automations (Əlavə B): approval/invoice/meeting/brief/budget reminders.
// Hourly so the meeting 1-hour reminder is timely; each effect is idempotent.
Schedule::command('automation:tick')->hourly();

// 12-month activity log retention (TZ §5.20).
Schedule::command('activitylog:clean')->monthly();
