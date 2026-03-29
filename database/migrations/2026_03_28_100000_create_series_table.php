<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('titre')->unique()->comment('Series title');
            $table->string('url')->unique()->comment('Series page URL');
            $table->text('image')->nullable()->comment('Series cover image URL');
            $table->string('source')->default('esheaq')->comment('Source website identifier');
            $table->string('status')->default('active')->comment('active, inactive, or duplicate');
            $table->timestamp('last_scraped_at')->nullable()->comment('When series was last found in scrape');
            $table->timestamps();

            // Indexes for query optimization
            $table->index('source');
            $table->index('status');
            $table->index('last_scraped_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('series');
    }
};
