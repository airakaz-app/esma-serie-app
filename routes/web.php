<?php

use App\Http\Controllers\SeriesInfoController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/series-infos');
Route::get('/series-infos', [SeriesInfoController::class, 'index'])->name('series-infos.index');
Route::get('/series-infos/{seriesInfo}', [SeriesInfoController::class, 'show'])->name('series-infos.show');
Route::delete('/series-infos/{seriesInfo}', [SeriesInfoController::class, 'destroy'])->name('series-infos.destroy');
Route::delete('/series-infos/{seriesInfo}/episodes/{episode}', [SeriesInfoController::class, 'destroyEpisode'])->name('series-infos.episodes.destroy');
Route::delete('/series-infos/{seriesInfo}/episodes', [SeriesInfoController::class, 'bulkDestroyEpisodes'])->name('series-infos.episodes.bulk-destroy');
