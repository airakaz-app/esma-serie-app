<?php

use App\Http\Controllers\SeriesInfoController;
use Illuminate\Support\Facades\Route;

Route::post('/series-infos/scrape', [SeriesInfoController::class, 'scrape'])->name('series-infos.scrape');
Route::get('/series-infos/scrape-status/{trackingKey}', [SeriesInfoController::class, 'scrapeStatus'])->name('series-infos.scrape-status');
