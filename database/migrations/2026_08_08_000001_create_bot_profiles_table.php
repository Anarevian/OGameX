<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bot_profiles', function (Blueprint $table) {
            $table->id();
            // The account this profile drives. One profile per user; deleting the user removes it.
            $table->integer('user_id', false, true)->unique();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            // Playstyle. Backed by the OGame\Enums\BotPersona enum.
            $table->string('persona', 32);
            // Behaviour traits, all 0..1. Skill drives error injection, aggression drives how
            // readily the bot attacks, risk tolerance drives how much fleet value it exposes.
            $table->float('skill')->default(0.5);
            $table->float('aggression')->default(0.5);
            $table->float('risk_tolerance')->default(0.5);
            // IANA timezone name. Bots act only inside their local activity window.
            $table->string('timezone', 64)->default('UTC');
            // Session model: wake/sleep hours, sessions per day, actions per session, jitter.
            $table->json('activity_profile');
            // Mutable brain state: current stance, goals, cooldowns. Written every tick.
            $table->json('state')->nullable();
            // Level of detail at which this bot is simulated. See OGame\Enums\BotLod.
            $table->string('lod', 16)->default('full');
            // When this bot is next due to act. The tick command selects on this column.
            $table->timestamp('next_action_at')->nullable();
            // When the bot last completed a tick, for diagnostics and catch-up decisions.
            $table->timestamp('last_tick_at')->nullable();
            // Per-bot kill switch, independent of the global config toggle.
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // The tick command's hot path: "which enabled bots are due right now".
            $table->index(['enabled', 'next_action_at']);
            $table->index('persona');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_profiles');
    }
};
