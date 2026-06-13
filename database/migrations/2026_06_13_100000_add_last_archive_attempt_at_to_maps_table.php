<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            // When archiving last attempted to fetch this map's .map file. Drives the re-enqueue
            // cooldown and the Queued-vs-Retrying badge; the cached file on disk (not this column)
            // is the source of truth for "is it archived".
            $table->timestamp('last_archive_attempt_at')->nullable()->after('crc');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn('last_archive_attempt_at');
        });
    }
};
