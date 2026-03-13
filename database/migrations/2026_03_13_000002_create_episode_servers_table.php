<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('episode_servers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('episode_id')->constrained()->cascadeOnDelete();
            $table->string('server_name')->nullable();
            $table->string('host')->nullable();
            $table->string('server_page_url')->unique();
            $table->string('iframe_url')->nullable();
            $table->boolean('click_success')->default(false);
            $table->string('final_url')->nullable();
            $table->string('result_title')->nullable();
            $table->string('result_h1')->nullable();
            $table->text('result_preview')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('retry_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamps();

            $table->index(['episode_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_servers');
    }
};
