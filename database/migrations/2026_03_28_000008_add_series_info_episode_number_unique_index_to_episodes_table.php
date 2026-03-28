<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── Étape 1 : supprimer les doublons (series_info_id, episode_number) ─────────
        // On garde l'épisode avec le plus petit id pour chaque doublon.
        // Sans ce nettoyage, l'ajout de l'index UNIQUE échouerait sur les DBs existantes.
        DB::statement('
            DELETE e1
            FROM episodes e1
            INNER JOIN episodes e2
                ON  e1.series_info_id  = e2.series_info_id
                AND e1.episode_number  = e2.episode_number
                AND e1.episode_number IS NOT NULL
                AND e1.id > e2.id
        ');

        // ── Étape 2 : ajouter l'index unique ─────────────────────────────────────────
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
