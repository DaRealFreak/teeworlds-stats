<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A map NAME is not unique: different servers host byte-different files under it. Each distinct
        // file is a map_versions row, identified by sha256 (DDNet) and/or crc (0.6/0.7). Both columns
        // live on one row so a file seen under two partial identities is one version, not two.
        Schema::create('map_versions', function (Blueprint $table) {
            $table->increments('id'); // matches maps.id (increments) so FKs line up
            $table->unsignedInteger('map_id');
            $table->char('sha256', 64)->nullable();
            $table->string('crc', 8)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_acquire_attempt_at')->nullable();
            $table->timestamps();

            // One row per content identity per map. NULLs are distinct on both MySQL and SQLite, so
            // crc-only and sha256-only rows still coexist; only a repeated non-null key is rejected.
            $table->unique(['map_id', 'sha256']);
            $table->unique(['map_id', 'crc']);
            $table->foreign('map_id')->references('id')->on('maps')->cascadeOnDelete();
        });

        // Which version a host was serving at this sighting — enables version-targeted host lookup and
        // the per-version "N servers" count. Stats still group by map_id; this does not split them.
        Schema::table('server_histories', function (Blueprint $table) {
            $table->unsignedInteger('map_version_id')->nullable()->after('map_id');
            $table->foreign('map_version_id')->references('id')->on('map_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('server_histories', function (Blueprint $table) {
            $table->dropForeign(['map_version_id']);
            $table->dropColumn('map_version_id');
        });
        Schema::dropIfExists('map_versions');
    }
};
