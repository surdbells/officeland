<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\MailServiceProvider;

return [
    AppServiceProvider::class,
    MailServiceProvider::class,
    AdminPanelProvider::class,
];
