<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only telemetry: one row per acquisition attempt against one channel, so the admin
        // dashboard can show which versions downloaded, which failed, and which servers fail to serve.
        // map_version_id/server_id are nullable: the HTTP-mirror channel has no server, and the legacy
        // fetch(Map) / no-host cases have no version. created_at only — rows are never updated.
        Schema::create('map_acquisition_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('map_version_id')->nullable();
            $table->unsignedInteger('server_id')->nullable();
            $table->string('source', 16);   // http_mirror | udp | http_fastdl
            $table->string('outcome', 8);   // success | failure
            $table->string('reason', 32)->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['server_id', 'outcome']);
            $table->index(['outcome', 'created_at']);
            $table->index('source');
            $table->foreign('map_version_id')->references('id')->on('map_versions')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_acquisition_attempts');
    }
};
