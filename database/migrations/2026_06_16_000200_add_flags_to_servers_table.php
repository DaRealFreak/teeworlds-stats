<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // The Teeworlds server-info flags bitfield (SERVER_FLAG_PASSWORD = 1, SERVER_FLAG_TIMESCORE = 2).
            // Parsed each scrape but previously discarded; the password bit gates UDP map-download
            // candidacy (an empty-password connect can't authenticate). Defaults to 0 so legacy rows
            // read as open/non-timescore until the next scrape backfills them.
            $table->unsignedSmallInteger('flags')->default(0)->after('flavor');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('flags');
        });
    }
};
