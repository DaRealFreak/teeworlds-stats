<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            // sha256 + size identify a DDNet map for HTTP download from maps.ddnet.org;
            // crc identifies a 0.6/0.7 map for the UDP download path (populated in Phase E).
            $table->char('sha256', 64)->nullable()->after('name');
            $table->unsignedInteger('size')->nullable()->after('sha256');
            $table->string('crc', 8)->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn(['sha256', 'size', 'crc']);
        });
    }
};
