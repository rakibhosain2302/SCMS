<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Needs the scheduler in cron on the server: * * * * * cd /path/to/scms && php artisan schedule:run
 * nit-master schedules its own clean-ups (trash, unverified sign-ups, digests, webhooks).
 * The activity log is not pruned: it is part of the audit trail.
 */
Schedule::command('nit:backup', ['--keep' => (int) config('nit.backups.keep', 10)])->dailyAt('02:00')->withoutOverlapping();
Schedule::command('finance:loan-reminders')->dailyAt('09:00')->withoutOverlapping();
// Reports any cached savings / loan balance that differs from the ledger (changes nothing without --fix).
Schedule::command('finance:rebuild-balances')->weeklyOn(5, '03:00');
