<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who a comment names with an @mention. Resolved from the body when the
     * comment is saved, so the body stays plain text and rendering can
     * highlight real people without guessing again.
     */
    public function up(): void
    {
        Schema::create('epic_comment_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epic_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['epic_comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epic_comment_mentions');
    }
};
