<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('squad_id')->nullable()->constrained()->nullOnDelete();

            // Optional link to a real login. Engineers are managed as roster
            // records so the EM can plan for someone before (or without) them
            // ever accepting an invite.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('title')->nullable();

            // Fallback used when a quarter capacity has not been set explicitly.
            $table->integer('default_weekly_points')->default(0);

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'is_active']);
            $table->index(['squad_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineers');
    }
};
