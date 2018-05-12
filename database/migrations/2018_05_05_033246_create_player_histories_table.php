<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePlayerHistoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('player_histories', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('server_id');
            $table->unsignedInteger('player_id');
            $table->unsignedInteger('map_id');
            $table->unsignedInteger('mod_id');
            $table->unsignedInteger('minutes');

            $table->foreign('server_id')->references('id')->on('servers');
            $table->foreign('player_id')->references('id')->on('players');
            $table->foreign('map_id')->references('id')->on('maps');
            $table->foreign('mod_id')->references('id')->on('mods');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('player_histories');
    }
}
