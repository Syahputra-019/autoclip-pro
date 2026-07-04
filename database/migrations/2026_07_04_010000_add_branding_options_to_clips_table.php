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
        Schema::table('clips', function (Blueprint $table): void {
            $table->boolean('watermark_enabled')->default(true)->after('crop_mode');
            $table->string('watermark_position', 24)->default('top-right')->after('watermark_enabled');
            $table->unsignedTinyInteger('watermark_opacity')->default(55)->after('watermark_position');
            $table->boolean('signature_enabled')->default(true)->after('watermark_opacity');
            $table->boolean('polish_enabled')->default(true)->after('signature_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table): void {
            $table->dropColumn([
                'watermark_enabled',
                'watermark_position',
                'watermark_opacity',
                'signature_enabled',
                'polish_enabled',
            ]);
        });
    }
};
