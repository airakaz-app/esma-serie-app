<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\SeriesInfoController;
use App\Http\Controllers\VideoWatchHistoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::redirect('/', '/series-infos');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/series-infos', [SeriesInfoController::class, 'index'])->name('series-infos.index');
    Route::get('/series-infos/search-external', [SeriesInfoController::class, 'searchExternal'])->name('series-infos.search-external');
    Route::post('/series-infos/scrape-preview', [SeriesInfoController::class, 'scrapePreview'])->name('series-infos.scrape-preview');
    Route::post('/series-infos/scrape', [SeriesInfoController::class, 'scrape'])->name('series-infos.scrape');
    Route::post('/series-infos/refresh-all', [SeriesInfoController::class, 'refreshAllEpisodes'])->name('series-infos.refresh-all');
    Route::get('/series-infos/{seriesInfo}', [SeriesInfoController::class, 'show'])->name('series-infos.show');
    Route::post('/series-infos/{seriesInfo}/retry-errors', [SeriesInfoController::class, 'retryErrors'])->name('series-infos.retry-errors');
    Route::get('/series-infos/scrape-status/{trackingKey}', [SeriesInfoController::class, 'scrapeStatus'])->name('series-infos.scrape-status');
    Route::delete('/series-infos/{seriesInfo}', [SeriesInfoController::class, 'destroy'])->name('series-infos.destroy');
    Route::delete('/series-infos/{seriesInfo}/episodes/{episode}', [SeriesInfoController::class, 'destroyEpisode'])->name('series-infos.episodes.destroy');
    Route::delete('/series-infos/{seriesInfo}/episodes', [SeriesInfoController::class, 'bulkDestroyEpisodes'])->name('series-infos.episodes.bulk-destroy');
    Route::get('/series-infos/{seriesInfo}/episodes/{episode}/download', [SeriesInfoController::class, 'downloadEpisode'])->name('series-infos.episodes.download');

    Route::get('/video-watch-histories', [VideoWatchHistoryController::class, 'show'])->name('video-watch-histories.show');
    Route::put('/video-watch-histories', [VideoWatchHistoryController::class, 'upsert'])->name('video-watch-histories.upsert');
});
