<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateServerHistories extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('server_histories', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
            // as we are selecting online time etc using the weekday mysql function can
            // impact the performance greatly, so add another column for that
            $table->unsignedInteger('weekday');
            $table->unsignedInteger('hour');
            $table->boolean('continuous');

            $table->unsignedInteger('server_id');
            $table->unsignedInteger('map_id');
            $table->unsignedInteger('mod_id');
            $table->unsignedInteger('mod_original_id')->nullable();
            $table->unsignedInteger('minutes');

            $table->foreign('server_id')->references('id')->on('servers');
            $table->foreign('map_id')->references('id')->on('maps');
            $table->foreign('mod_id')->references('id')->on('mods');
            $table->foreign('mod_original_id')->references('id')->on('mods');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('server_histories');
    }
}
