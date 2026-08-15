<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('squads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color')->default('#6B7280');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('squads');
    }
};
