<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // player_histories only stores aggregated minutes per weekday/hour/map/mod, so
        // it cannot answer "when was this tee online, and for how long". This table
        // records discrete play sessions (a contiguous presence on one server) that the
        // UpdateData collector extends while a player keeps being seen.
        Schema::create('player_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('player_id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('map_id');
            $table->unsignedInteger('mod_id');
            $table->unsignedInteger('minutes')->default(0);

            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            // null while the session is still open (player currently being seen)
            $table->timestamp('ended_at')->nullable();

            $table->foreign('player_id')->references('id')->on('players');
            $table->foreign('server_id')->references('id')->on('servers');
            $table->foreign('map_id')->references('id')->on('maps');
            $table->foreign('mod_id')->references('id')->on('mods');

            $table->index(['player_id', 'started_at']);
            $table->index(['player_id', 'ended_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_sessions');
    }
};
