<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            // Map archiving moved to inline acquisition during the scrape; the per-map cooldown is now
            // per-version (map_versions.last_acquire_attempt_at).
            $table->dropColumn('last_archive_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->timestamp('last_archive_attempt_at')->nullable();
        });
    }
};
