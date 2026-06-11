<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // Last-seen cosmetic snapshot, populated only from the DDNet HTTP feed — no UDP
            // info payload (0.6 vanilla/extended, 0.7 inf3) carries skins or afk. Null for
            // players only ever seen over UDP. skin/color_body/color_feet hold the 0.6 form;
            // skin_parts holds the 0.7 six-part skin ({body:{name,color}, ...}).
            $table->string('skin')->nullable();
            $table->integer('color_body')->nullable();
            $table->integer('color_feet')->nullable();
            $table->boolean('afk')->nullable();
            $table->json('skin_parts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['skin', 'color_body', 'color_feet', 'afk', 'skin_parts']);
        });
    }
};
