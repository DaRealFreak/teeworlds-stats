<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTeesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tees', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->string('name');

            $table->unsignedInteger('country_id')->nullable()->default(null);
            $table->unsignedInteger('clan_id')->nullable()->default(null);

            // ToDo: add index for country model which doesn't exist yet
            $table->foreign('clan_id')->references('id')->on('clans');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tees');
    }
}
