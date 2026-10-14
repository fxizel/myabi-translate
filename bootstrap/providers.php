<?php

use App\Providers\AppServiceProvider;
use App\Providers\BrowserExtensionServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    BrowserExtensionServiceProvider::class,
];
