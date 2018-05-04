<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePlayerModsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('player_mods', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('player_id');
            $table->string('mod');
            $table->unsignedInteger('times')->default(0);

            $table->foreign('player_id')->references('id')->on('players');
            $table->unique(['player_id', 'mod']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('player_mods');
    }
}
