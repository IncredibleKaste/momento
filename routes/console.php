<?php

use App\Actions\ManagePhotoPosts;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('photo-posts:reconcile', function (ManagePhotoPosts $posts): void {
    $this->info('Reconciled '.$posts->reconcile().' photo post operations.');
})->purpose('Retry cleanup for bounded photo post operations');

Schedule::command('photo-posts:reconcile')->everyMinute()->withoutOverlapping();
