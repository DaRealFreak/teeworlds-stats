<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Identity now lives in server_addresses. ip/port remain on servers only as the
            // denormalised canonical pointer, so the old (ip,port) uniqueness no longer holds
            // (a sixup server has several protocol-tagged addresses at the same ip/port).
            $table->dropUnique(['ip', 'port']);
            // derived server-type label for display/filtering: ddnet | vanilla_06 | vanilla_07
            $table->string('flavor')->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('flavor');
            // re-adds the (ip,port) uniqueness; this rollback fails on MySQL if multi-address
            // data with duplicate (ip,port) rows has already been written. Safe for local/CI.
            $table->unique(['ip', 'port']);
        });
    }
};
