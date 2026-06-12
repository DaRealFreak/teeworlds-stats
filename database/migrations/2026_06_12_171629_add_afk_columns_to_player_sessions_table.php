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
        Schema::table('player_sessions', function (Blueprint $table) {
            // afk_minutes: minutes observed idle; afk_tracked_minutes: minutes the source reported
            // idle status at all (afk != null). afk_minutes <= afk_tracked_minutes <= minutes.
            $table->unsignedInteger('afk_minutes')->default(0)->after('minutes');
            $table->unsignedInteger('afk_tracked_minutes')->default(0)->after('afk_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player_sessions', function (Blueprint $table) {
            $table->dropColumn(['afk_minutes', 'afk_tracked_minutes']);
        });
    }
};
