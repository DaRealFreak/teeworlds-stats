<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-(host, map) UDP backoff: a host-health download failure parks that pair for an escalating
        // span, so a flaky/dead host stops eating connect timeouts for the same map while staying
        // acquirable from other hosts. Keyed on (server, map) — a host cycling to a different map is a
        // different key, so it earns a fresh attempt with no explicit reset. cooldown_until is indexed
        // because it is both the candidate filter and the prune predicate.
        Schema::create('server_map_backoffs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('map_id');
            $table->unsignedSmallInteger('failure_streak')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'map_id']);
            $table->index('cooldown_until');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('map_id')->references('id')->on('maps')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_map_backoffs');
    }
};
