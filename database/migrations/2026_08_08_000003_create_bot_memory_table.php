<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * How a bot feels about another player. Because bots are silent, this is the table that
     * carries all social continuity: grudges, friendships and alliance decisions are expressed
     * through behaviour rather than messages.
     */
    public function up(): void
    {
        Schema::create('bot_memory', function (Blueprint $table) {
            $table->id();
            $table->integer('bot_user_id', false, true);
            $table->foreign('bot_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->integer('other_user_id', false, true);
            $table->foreign('other_user_id')->references('id')->on('users')->onDelete('cascade');
            // -100 (blood feud) .. 100 (trusted ally). Drives revenge attacks, alliance
            // application decisions, ACS participation and buddy request handling.
            $table->integer('attitude')->default(0);
            // Interaction tallies, used both for scoring and for the fairness caps.
            $table->integer('attacked_us_count')->default(0);
            $table->integer('we_attacked_count')->default(0);
            $table->integer('spied_us_count')->default(0);
            // Cumulative resources this player has taken from the bot, and vice versa.
            $table->double('resources_lost_to')->default(0);
            $table->double('resources_taken_from')->default(0);
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->unique(['bot_user_id', 'other_user_id']);
            $table->index(['bot_user_id', 'attitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_memory');
    }
};
