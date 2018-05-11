<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePlayerModRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('player_mod_records', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('player_id');
            $table->unsignedInteger('mod_id');
            $table->unsignedInteger('minutes')->default(0);

            $table->foreign('player_id')->references('id')->on('players');
            $table->foreign('mod_id')->references('id')->on('mods');
            $table->unique(['player_id', 'mod_id']);
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
