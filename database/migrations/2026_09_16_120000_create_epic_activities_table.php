<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epic_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epic_id')->constrained()->cascadeOnDelete();

            // Who did it. Unlike a comment, what happened to an epic is not
            // the author's to take away, so a deleted account leaves the row
            // behind with the name it carried at the time.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable();

            // created | updated
            $table->string('event', 20);

            // web | mcp | system -- system is a write with nobody signed in,
            // such as the seeder.
            $table->string('source', 10)->default('web');

            // field => {from, to, from_label?, to_label?}. Named diff rather
            // than changes, which Eloquent already uses for its own bookkeeping
            // on every model. Labels carry the
            // status or category name as it was, so a rename or a deleted
            // column later cannot rewrite what the history says.
            $table->json('diff');

            $table->timestamps();

            // Always read "this epic, newest first".
            $table->index(['epic_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epic_activities');
    }
};
