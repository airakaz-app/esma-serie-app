<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_watch_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('video_key', 191);
            $table->text('video_url');
            $table->unsignedInteger('current_time')->default(0);
            $table->unsignedInteger('duration')->default(0);
            $table->boolean('completed')->default(false);
            $table->timestamp('last_watched_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'video_key']);
            $table->index(['user_id', 'last_watched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_watch_histories');
    }
};
