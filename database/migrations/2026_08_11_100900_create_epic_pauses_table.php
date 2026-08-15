<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epic_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epic_id')->constrained()->cascadeOnDelete();

            // *That* an epic paused is derived from the allocation grid.
            // These rows capture the half derivation cannot know: why, and
            // what took the capacity. Written only when someone answers the
            // prompt on the Now page -- never auto-generated, or mid-week grid
            // edits would fill this with noise.
            $table->date('paused_at');
            $table->date('resumed_at')->nullable();
            $table->string('reason')->nullable();

            $table->foreignId('superseded_by_epic_id')->nullable()
                ->constrained('epics')->nullOnDelete();

            $table->timestamps();

            $table->index(['epic_id', 'paused_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epic_pauses');
    }
};
