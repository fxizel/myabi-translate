<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('referentiel:work')->everyMinute()->withoutOverlapping(35);
Schedule::command('notifications:daily')->dailyAt('07:00')->timezone('Europe/Zurich')->withoutOverlapping();
