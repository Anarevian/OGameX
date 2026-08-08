<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * What a bot *believes* about a coordinate, as opposed to what is actually there.
     *
     * Target selection reads this table and never the planets table directly. That is what
     * gives bots a fog of war: they act on stale observations and are sometimes wrong, exactly
     * like a player working from an old espionage report.
     */
    public function up(): void
    {
        Schema::create('bot_intel', function (Blueprint $table) {
            $table->id();
            // The bot that holds this belief.
            $table->integer('bot_user_id', false, true);
            $table->foreign('bot_user_id')->references('id')->on('users')->onDelete('cascade');
            // The observed coordinate.
            $table->integer('galaxy', false, true);
            $table->integer('system', false, true);
            $table->integer('position', false, true);
            // 1 = planet, 3 = moon, matching OGame\Models\Enums\PlanetType.
            $table->integer('planet_type', false, true)->default(1);
            // Where the belief came from: galaxy_view, espionage, battle, phalanx.
            $table->string('source', 24);
            // Owner as observed. Nullable because a galaxy scan of an empty slot is also intel.
            $table->integer('owner_user_id', false, true)->nullable();
            $table->foreign('owner_user_id')->references('id')->on('users')->onDelete('cascade');
            // Observed details: resources, defence, fleet estimates, points, inactive flag.
            $table->json('payload')->nullable();
            // 0..1, decays with age. Espionage starts high, a galaxy scan starts low.
            $table->float('confidence')->default(0.5);
            // When the observation was made. Staleness is derived from this, not from updated_at.
            $table->timestamp('observed_at');
            $table->timestamps();

            // One belief per bot per coordinate; a newer observation overwrites the older one.
            $table->unique(['bot_user_id', 'galaxy', 'system', 'position', 'planet_type'], 'bot_intel_coordinate_unique');
            // Target selection scans a bot's intel by freshness.
            $table->index(['bot_user_id', 'observed_at']);
            $table->index(['galaxy', 'system']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_intel');
    }
};
