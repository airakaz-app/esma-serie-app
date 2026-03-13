<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->unsignedInteger('episode_number')->nullable()->after('page_url');
            $table->string('image_url')->nullable()->after('episode_number');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropColumn(['episode_number', 'image_url']);
        });
    }
};
