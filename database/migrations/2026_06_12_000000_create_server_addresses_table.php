<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A logical server is reachable through one or more protocol-tagged endpoints
        // (a DDNet "sixup" server answers both 0.6 and 0.7). The address set is the
        // server's identity, mirroring how the DDNet master groups a server's addresses[].
        Schema::create('server_addresses', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('server_id');
            $table->string('ip');
            $table->unsignedInteger('port');
            $table->unsignedTinyInteger('protocol'); // 6 or 7
            $table->boolean('is_canonical')->default(false);

            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
            $table->unique(['ip', 'port', 'protocol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_addresses');
    }
};
