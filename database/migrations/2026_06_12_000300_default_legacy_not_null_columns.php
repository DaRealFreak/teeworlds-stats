<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // These columns were NOT NULL with no default; the scraper relied on MySQL's non-strict
        // implicit defaults. Give them real defaults/nullability so every source's player/clan/
        // history inserts work on strict drivers without per-call workarounds in the command.
        Schema::table('server_histories', function (Blueprint $table) {
            $table->unsignedInteger('minutes')->default(0)->change();
        });
        Schema::table('player_histories', function (Blueprint $table) {
            $table->unsignedInteger('minutes')->default(0)->change();
        });
        Schema::table('clans', function (Blueprint $table) {
            $table->text('introduction')->nullable()->change();
            $table->string('website')->nullable()->change();
        });
        Schema::table('players', function (Blueprint $table) {
            $table->string('country')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('server_histories', function (Blueprint $table) {
            $table->unsignedInteger('minutes')->default(null)->change();
        });
        Schema::table('player_histories', function (Blueprint $table) {
            $table->unsignedInteger('minutes')->default(null)->change();
        });
        Schema::table('clans', function (Blueprint $table) {
            $table->text('introduction')->nullable(false)->change();
            $table->string('website')->nullable(false)->change();
        });
        Schema::table('players', function (Blueprint $table) {
            $table->string('country')->nullable(false)->change();
        });
    }
};
