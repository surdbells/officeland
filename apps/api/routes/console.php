<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule (§13e)
|--------------------------------------------------------------------------
|
| Requires ONE cron entry on the host:
|
|   * * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1
|
| Without it every entry below is inert, and inert in the quietest possible
| way: nothing errors, nothing logs, the monitor simply never runs and the
| dashboards stay green because nothing is writing to them. Phase 13e's own
| heartbeat check exists partly to catch that.
|
*/

/*
 * The lead pipeline canary (§13e).
 *
 * Every 15 minutes, because the thing being protected is a business's inbound
 * enquiries: an hour of silent breakage on a weekday is real money, and the
 * check costs one rolled-back transaction.
 *
 * `withoutOverlapping` because a monitor that stacks up behind a hung database
 * turns one problem into two — and the second one looks like the first.
 */
Schedule::command('monitor:quote')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

/*
 * Backups are NOT scheduled here, deliberately.
 *
 * They have to run where Postgres lives. This container has neither the
 * Postgres client tools nor the scripts mounted, so a `Schedule::exec` of
 * `pg-base-backup.sh` would fail every night — and it would fail in the worst
 * available way, since a backup that silently never runs looks exactly like a
 * backup that runs fine. Host cron owns them: `infra/cron/officeland.cron`.
 *
 * What the application CAN do is notice they stopped, which is the check below
 * and the one that catches the most common backup failure of all: a cron entry
 * that was never installed on the new server.
 */
Schedule::command('monitor:backups')
    ->dailyAt('09:00')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping(30);

/*
 * Media hygiene (Phase 4f). Dry run by default there; --apply here, because a
 * reaper that only ever reports is a disk that only ever fills.
 */
Schedule::command('media:reap --apply')
    ->weeklyOn(0, '05:00')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping(60);
