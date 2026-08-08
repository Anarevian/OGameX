<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Every decision a bot makes, including the ones that failed. This is the debugging surface,
     * the tuning input for the simulation harness, and what the behavioural tests assert against.
     * Pruned on a schedule using bots.action_log_retention_days.
     */
    public function up(): void
    {
        Schema::create('bot_action_log', function (Blueprint $table) {
            $table->id();
            $table->integer('bot_user_id', false, true);
            $table->foreign('bot_user_id')->references('id')->on('users')->onDelete('cascade');
            // Groups every decision taken within a single tick.
            $table->uuid('tick_id')->nullable();
            // Machine name of the action, e.g. build_building, raid, fleetsave, espionage.
            $table->string('action', 48);
            // Whether the action was executed successfully. A failure is information, not a bug:
            // it usually means the bot mis-estimated, which is behaviour worth keeping.
            $table->boolean('succeeded')->default(true);
            // Utility score that won the selection, for tuning.
            $table->float('score')->nullable();
            // Short human-readable justification, e.g. "metal mine 14, payback 9h".
            $table->string('reason', 255)->nullable();
            // Action-specific detail, plus the exception message when it failed.
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['bot_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            // Retention pruning scans on this column alone.
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_action_log');
    }
};
