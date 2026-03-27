<?php

namespace App\Http\Controllers;

use App\Http\Requests\GetVideoWatchHistoryRequest;
use App\Http\Requests\UpsertVideoWatchHistoryRequest;
use App\Models\VideoWatchHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class VideoWatchHistoryController extends Controller
{
    public function show(GetVideoWatchHistoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $history = VideoWatchHistory::query()
            ->where('user_id', $user->id)
            ->where('video_key', $validated['video_key'])
            ->first();

        if ($history === null) {
            return response()->json([
                'history' => null,
            ]);
        }

        if (
            isset($validated['video_url'])
            && $validated['video_url'] !== ''
            && $history->video_url !== $validated['video_url']
        ) {
            return response()->json([
                'history' => null,
            ]);
        }

        return response()->json([
            'history' => [
                'video_key' => $history->video_key,
                'video_url' => $history->video_url,
                'current_time' => $history->current_time,
                'duration' => $history->duration,
                'completed' => $history->completed,
                'last_watched_at' => $history->last_watched_at?->toIso8601String(),
            ],
        ]);
    }

    public function upsert(UpsertVideoWatchHistoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $duration = max((int) $validated['duration'], 0);
        $currentTime = min(max((int) $validated['current_time'], 0), $duration > 0 ? $duration : PHP_INT_MAX);
        $completed = (bool) $validated['completed'];

        if ($duration > 0 && $currentTime >= max($duration - 2, 0)) {
            $completed = true;
            $currentTime = $duration;
        }

        $history = VideoWatchHistory::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'video_key' => $validated['video_key'],
            ],
            [
                'video_url' => $validated['video_url'],
                'current_time' => $currentTime,
                'duration' => $duration,
                'completed' => $completed,
                'last_watched_at' => isset($validated['last_watched_at'])
                    ? Carbon::parse($validated['last_watched_at'])
                    : now(),
            ]
        );

        return response()->json([
            'saved' => true,
            'history' => [
                'video_key' => $history->video_key,
                'current_time' => $history->current_time,
                'duration' => $history->duration,
                'completed' => $history->completed,
            ],
        ]);
    }
}
