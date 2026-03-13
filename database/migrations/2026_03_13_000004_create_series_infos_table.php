<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('series_infos', function (Blueprint $table): void {
            $table->id();
            $table->string('source_episode_page_url')->unique();
            $table->string('series_page_url');
            $table->string('title')->nullable();
            $table->string('title_url')->nullable();
            $table->string('cover_image_url')->nullable();
            $table->text('story')->nullable();
            $table->json('categories')->nullable();
            $table->json('actors')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_infos');
    }
};
