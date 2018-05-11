<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateServerMapRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('server_map_records', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('server_id');
            $table->unsignedInteger('map_id');
            $table->unsignedInteger('minutes')->default(0);

            $table->foreign('server_id')->references('id')->on('servers');
            $table->foreign('map_id')->references('id')->on('maps');
            $table->unique(['server_id', 'map_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('server_map_records');
    }
}
