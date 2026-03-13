<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('page_url')->unique();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
