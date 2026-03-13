<?php

use App\Http\Controllers\SeriesInfoController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/series-infos');
Route::get('/series-infos', [SeriesInfoController::class, 'index'])->name('series-infos.index');
Route::get('/series-infos/{seriesInfo}', [SeriesInfoController::class, 'show'])->name('series-infos.show');
