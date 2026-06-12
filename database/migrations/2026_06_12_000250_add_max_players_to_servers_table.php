<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Capacity the server reports each scrape (parsed into DiscoveredServer but
            // previously dropped). Nullable: legacy rows carry no value until the next
            // scrape backfills them, and a source may report an unknown (0) capacity.
            $table->unsignedSmallInteger('max_clients')->nullable()->after('flavor');
            $table->unsignedSmallInteger('max_players')->nullable()->after('max_clients');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['max_clients', 'max_players']);
        });
    }
};
