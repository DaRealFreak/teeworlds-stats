<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePlayerMapRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('player_map_records', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('player_id');
            $table->unsignedInteger('map_id');
            $table->unsignedInteger('minutes')->default(0);

            $table->foreign('player_id')->references('id')->on('players');
            $table->foreign('map_id')->references('id')->on('maps');
            $table->unique(['player_id', 'map_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('player_maps');
    }
}
