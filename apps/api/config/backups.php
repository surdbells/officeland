<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Where base backups land (Phase 13d/13e)
    |--------------------------------------------------------------------------
    |
    | Read by `monitor:backups` to check how old the newest one is. The path is
    | inside the POSTGRES container, so the check only does anything when this
    | process can see that filesystem — on a single-host deployment it can, and
    | on a split one the age check is skipped rather than alerting falsely.
    |
    */
    'base_path' => env('BACKUP_BASE_PATH', '/backups/base'),
];
