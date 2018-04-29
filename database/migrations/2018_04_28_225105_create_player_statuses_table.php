<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePlayerStatusesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('player_statuses', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('player_id')->unique();
            $table->unsignedInteger('hour_0')->default(0);
            $table->unsignedInteger('hour_1')->default(0);
            $table->unsignedInteger('hour_2')->default(0);
            $table->unsignedInteger('hour_3')->default(0);
            $table->unsignedInteger('hour_4')->default(0);
            $table->unsignedInteger('hour_5')->default(0);
            $table->unsignedInteger('hour_6')->default(0);
            $table->unsignedInteger('hour_7')->default(0);
            $table->unsignedInteger('hour_8')->default(0);
            $table->unsignedInteger('hour_9')->default(0);
            $table->unsignedInteger('hour_10')->default(0);
            $table->unsignedInteger('hour_11')->default(0);
            $table->unsignedInteger('hour_12')->default(0);
            $table->unsignedInteger('hour_13')->default(0);
            $table->unsignedInteger('hour_14')->default(0);
            $table->unsignedInteger('hour_15')->default(0);
            $table->unsignedInteger('hour_16')->default(0);
            $table->unsignedInteger('hour_17')->default(0);
            $table->unsignedInteger('hour_18')->default(0);
            $table->unsignedInteger('hour_19')->default(0);
            $table->unsignedInteger('hour_20')->default(0);
            $table->unsignedInteger('hour_21')->default(0);
            $table->unsignedInteger('hour_22')->default(0);
            $table->unsignedInteger('hour_23')->default(0);
            $table->unsignedInteger('monday')->default(0);
            $table->unsignedInteger('tuesday')->default(0);
            $table->unsignedInteger('wednesday')->default(0);
            $table->unsignedInteger('thursday')->default(0);
            $table->unsignedInteger('friday')->default(0);
            $table->unsignedInteger('saturday')->default(0);
            $table->unsignedInteger('sunday')->default(0);

            $table->foreign('player_id')->references('id')->on('players');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('player_status');
    }
}
