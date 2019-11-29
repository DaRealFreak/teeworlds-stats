<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDailySummaries extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('daily_summaries', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->date('date')->useCurrent();
            $table->unsignedInteger('players_online_peak');
            $table->unsignedInteger('players_online');

            $table->unsignedInteger('clans_online_peak');
            $table->unsignedInteger('clans_online');

            $table->unsignedInteger('servers_online_peak');
            $table->unsignedInteger('servers_online');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('daily_summaries');
    }
}
