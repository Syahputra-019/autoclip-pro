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
        Schema::create('clips', function (Blueprint $table): void {
            $table->id();
            $table->text('youtube_url');
            $table->string('source_title')->nullable();
            $table->unsignedInteger('source_duration')->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('start_time')->default(10);
            $table->unsignedSmallInteger('duration')->default(60);
            $table->unsignedSmallInteger('quality_height')->default(1080);
            $table->string('crop_mode', 24)->default('center');
            $table->string('output_disk')->default('public');
            $table->string('output_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clips');
    }
};
