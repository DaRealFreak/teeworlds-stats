<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableModRules extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mod_rules', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->string('decider');
            $table->string('rule');
            $table->unsignedInteger('mod_id');
            $table->unsignedInteger('priority')->default(0);

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
        Schema::dropIfExists('mod_rules');
    }
}
