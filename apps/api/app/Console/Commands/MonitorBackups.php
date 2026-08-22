<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notices when the backups have stopped happening (§13e).
 *
 * ── The failure this exists for ──────────────────────────────────────────
 * Not "the backup errored" — that one is loud. This is for the backup that
 * **silently stopped running**: a host cron entry that was never installed on
 * the new server, a container renamed so `docker compose exec` no longer
 * resolves, a full disk that made the last successful run also the last run.
 *
 * Every one of those leaves the existing backups sitting there looking healthy.
 * Nothing errors, nothing logs, and the estate quietly loses its recovery point
 * one day at a time until somebody needs it.
 *
 * ── Why it checks the archiver separately ────────────────────────────────
 * WAL archiving failing is worse than a missed base backup and much quieter.
 * Postgres retries a failing `archive_command` forever, `pg_wal` grows without
 * bound, and the first symptom anybody sees is the disk filling and the
 * database stopping — by which point the archive has a hole in it and
 * point-in-time recovery is broken for that whole window.
 *
 * ── Not a readiness probe, on purpose ────────────────────────────────────
 * A stale backup must page somebody; it must NOT take the instance out of
 * service. Wiring this into `/health/ready` would mean an old backup pulling
 * the site down, which is a self-inflicted outage in response to a problem
 * that has not caused one.
 */
final class MonitorBackups extends Command
{
    protected $signature = 'monitor:backups
                            {--max-age-hours=36 : Alert when the newest base backup is older than this.}';

    protected $description = 'Alert when base backups have stopped, or WAL archiving is failing';

    public function handle(): int
    {
        $problems = array_merge($this->checkArchiver(), $this->checkBaseBackupAge());

        if ($problems === []) {
            $this->info('Backups look healthy.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            Log::critical('backup.monitor.failed', ['detail' => $problem]);
            $this->error($problem);
        }

        return self::FAILURE;
    }

    /**
     * `pg_stat_archiver` is Postgres reporting on its own archiving.
     *
     * @return list<string>
     */
    private function checkArchiver(): array
    {
        try {
            /** @var object{archived_count: int|string, failed_count: int|string, last_failed_time: ?string, last_archived_time: ?string}|null $stats */
            $stats = DB::connection('pgsql_direct')->selectOne(
                'select archived_count, failed_count, last_failed_time, last_archived_time from pg_stat_archiver'
            );
        } catch (Throwable $e) {
            return ['Could not read pg_stat_archiver: '.$e->getMessage()];
        }

        if ($stats === null) {
            return ['pg_stat_archiver returned nothing — is archive_mode on?'];
        }

        $problems = [];

        if ((int) $stats->failed_count > 0) {
            $problems[] = sprintf(
                'WAL archiving has failed %d time(s), most recently at %s. '.
                'Postgres will retry forever and pg_wal will grow until the disk fills.',
                (int) $stats->failed_count,
                $stats->last_failed_time ?? 'an unknown time',
            );
        }

        /*
         * Nothing archived at all is its own alarm. `archive_timeout` is 300s,
         * so on a live system a segment should appear at least every five
         * minutes whether or not anybody is writing.
         */
        if ((int) $stats->archived_count === 0) {
            $problems[] = 'No WAL segment has ever been archived. Point-in-time recovery does not work.';
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function checkBaseBackupAge(): array
    {
        $root = (string) config('backups.base_path', '/backups/base');

        if (! is_dir($root)) {
            /*
             * The API container does not mount the backup volume, and it does
             * not need to — this check is about the database host. Reporting a
             * missing directory as a backup failure would produce an alert
             * every day in an environment where nothing is wrong, and a daily
             * false alarm is how a real one gets ignored.
             */
            $this->line("No backup directory visible at {$root} — skipping the age check.");
            $this->line('That is expected unless this process runs on the database host.');

            return [];
        }

        $newest = 0;

        foreach ((array) glob($root.'/*', GLOB_ONLYDIR) as $directory) {
            $newest = max($newest, (int) filemtime((string) $directory));
        }

        if ($newest === 0) {
            return ["No base backup exists in {$root}."];
        }

        $ageHours = (int) round((time() - $newest) / 3600);
        $limit = (int) $this->option('max-age-hours');

        if ($ageHours > $limit) {
            return [sprintf(
                'The newest base backup is %dh old (limit %dh). Recovery now depends on replaying that much WAL.',
                $ageHours,
                $limit,
            )];
        }

        return [];
    }
}
