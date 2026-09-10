<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per user per team: the order they have dragged the board's
     * columns into. No row means the team order set on /statuses.
     *
     * The order is a list of status ids rather than a sort_order per status,
     * because a status the list has never heard of should simply appear in
     * its team position -- and that fallback is easier to state over a list.
     */
    public function up(): void
    {
        Schema::create('user_column_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->json('status_ids');
            $table->timestamps();

            $table->unique(['user_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_column_orders');
    }
};
