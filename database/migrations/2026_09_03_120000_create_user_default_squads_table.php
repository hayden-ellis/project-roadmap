<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per user per team: the squad their pages start filtered by.
     *
     * A row with a null squad is an explicit "no default" -- it stops the
     * app inferring one from the engineer record linked to the login.
     * Deleting the squad deletes the row, so inference quietly resumes.
     */
    public function up(): void
    {
        Schema::create('user_default_squads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('squad_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_default_squads');
    }
};
