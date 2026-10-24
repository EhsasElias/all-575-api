<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('orders:expire-unpaid')->everyMinute()->withoutOverlapping();
Schedule::command('slots:generate')->dailyAt('00:10')->withoutOverlapping();
