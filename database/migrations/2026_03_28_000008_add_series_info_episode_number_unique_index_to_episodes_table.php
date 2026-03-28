<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->unique(['series_info_id', 'episode_number'], 'episodes_series_info_episode_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropUnique('episodes_series_info_episode_number_unique');
        });
    }
};
