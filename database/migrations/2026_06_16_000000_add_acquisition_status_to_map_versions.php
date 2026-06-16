<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A version's acquisition situation is now persisted so it isn't re-classified on every pass:
        // pending (recorded, unresolved) → cached / udp_only / unavailable. status_checked_at drives the
        // 30-day mirror recheck for not-yet-mirrored versions. Existing rows default to pending and the
        // filler reconciles them on first touch — no disk scan here.
        Schema::table('map_versions', function (Blueprint $table) {
            $table->string('acquisition_status', 16)->default('pending')->after('size');
            $table->timestamp('status_checked_at')->nullable()->after('acquisition_status');
            $table->index('acquisition_status');
        });
    }

    public function down(): void
    {
        Schema::table('map_versions', function (Blueprint $table) {
            $table->dropIndex(['acquisition_status']);
            $table->dropColumn(['acquisition_status', 'status_checked_at']);
        });
    }
};
