<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Episodes\EpisodeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_all_endpoint_returns_sync_result(): void
    {
        $user = User::factory()->create();

        $this->instance(EpisodeSyncService::class, new class extends EpisodeSyncService
        {
            public function __construct()
            {
            }

            public function syncAllSeries(string $trigger): array
            {
                return [
                    'status' => 'completed',
                    'message' => 'Synchronisation terminée: 2 nouvelle(s) épisode(s) importé(s).',
                    'series_total' => 3,
                    'series_processed' => 3,
                    'new_episodes_count' => 2,
                    'errors' => [],
                ];
            }
        });

        $response = $this->actingAs($user)
            ->postJson(route('series-infos.refresh-all'));

        $response->assertOk();
        $response->assertJson([
            'status' => 'completed',
            'new_episodes_count' => 2,
        ]);
    }
}
