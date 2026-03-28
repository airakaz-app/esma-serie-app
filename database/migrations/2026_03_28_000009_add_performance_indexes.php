<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── Index sur episodes table ─────────────────────────────────────────
        // Utilisés par: EpisodeSyncService (WHERE series_info_id), ScrapeEpisodesCommand (WHERE status)
        Schema::table('episodes', function (Blueprint $table): void {
            // Index simple sur series_info_id (utilisé partout)
            $table->index('series_info_id');

            // Index simple sur status (filtres courants)
            $table->index('status');

            // Index composite: series_info_id + is_new (pour les queries de sync)
            $table->index(['series_info_id', 'is_new']);
        });

        // ── Index sur episode_servers table ──────────────────────────────────
        // Utilisés par: processServer, verifyAndResetBrokenUrls
        Schema::table('episode_servers', function (Blueprint $table): void {
            // Index simple sur status (filtres STATUS_DONE, STATUS_ERROR, STATUS_PENDING)
            $table->index('status');

            // Index composite: episode_id + status (déjà présent en migration 2)
            // Ajout d'un index sur host pour les filtres LOWER(host) IN (...)
            $table->index('host');
        });

        // ── Index sur series_infos table ─────────────────────────────────────
        // Utilisés par: EpisodeSyncService (WHERE series_page_url)
        Schema::table('series_infos', function (Blueprint $table): void {
            // Index sur series_page_url (utilisé en WHERE)
            $table->index('series_page_url');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropIndex(['series_info_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['series_info_id', 'is_new']);
        });

        Schema::table('episode_servers', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['host']);
        });

        Schema::table('series_infos', function (Blueprint $table): void {
            $table->dropIndex(['series_page_url']);
        });
    }
};
