<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateServerModRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('server_mod_records', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('server_id');
            $table->unsignedInteger('mod_id');
            $table->unsignedInteger('minutes')->default(0);

            $table->foreign('server_id')->references('id')->on('servers');
            $table->foreign('mod_id')->references('id')->on('mods');
            $table->unique(['server_id', 'mod_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('server_mod_records');
    }
}
