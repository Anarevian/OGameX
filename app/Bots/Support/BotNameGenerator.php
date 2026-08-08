<?php

namespace OGame\Bots\Support;

use OGame\Models\User;

/**
 * Generates believable usernames for bot accounts.
 *
 * A real server's player list is not uniform: some names are compound space words, some are
 * lowercase handles, some end in a birth year, some contain an underscore. Generating every bot
 * with one pattern would make the population obvious at a glance even before the NPC badge is
 * taken into account, so several naming strategies are mixed.
 *
 * Names must satisfy PlayerService::validateUsername(): at least 3 characters, starting with a
 * letter, then letters, digits and spaces, with optional underscore-separated segments.
 */
class BotNameGenerator
{
    /**
     * Word stems used as the first half of a compound name, or on their own.
     *
     * @var array<int, string>
     */
    private array $prefixes = [
        'Nova', 'Orion', 'Vega', 'Titan', 'Comet', 'Quasar', 'Nebula', 'Pulsar', 'Zenith', 'Eclipse',
        'Aurora', 'Helio', 'Lunar', 'Solar', 'Photon', 'Cosmic', 'Stellar', 'Astro', 'Ion', 'Plasma',
        'Iron', 'Obsidian', 'Crimson', 'Silent', 'Rogue', 'Wraith', 'Specter', 'Vortex', 'Onyx', 'Ember',
        'Drift', 'Echo', 'Frost', 'Storm', 'Ash', 'Rift', 'Hollow', 'Umbra', 'Void', 'Halo',
    ];

    /**
     * Word stems used as the second half of a compound name.
     *
     * @var array<int, string>
     */
    private array $suffixes = [
        'walker', 'runner', 'forge', 'reach', 'spire', 'wing', 'fall', 'gate', 'crown', 'watch',
        'strike', 'hunter', 'raider', 'trader', 'miner', 'smith', 'wright', 'keeper', 'warden', 'seeker',
        'storm', 'blade', 'star', 'core', 'drive', 'flux', 'sky', 'dawn', 'dusk', 'tide',
    ];

    /**
     * Standalone handles that read like something a person typed in a hurry.
     *
     * @var array<int, string>
     */
    private array $handles = [
        'kepler', 'tycho', 'galilei', 'hubble', 'sagan', 'herschel', 'cassini', 'huygens', 'kuiper',
        'brahe', 'lovell', 'gagarin', 'armstrong', 'aldrin', 'shepard', 'glenn', 'ride', 'jemison',
        'zarya', 'mir', 'soyuz', 'apollo', 'vostok', 'ares', 'hermes', 'icarus', 'daedalus', 'perseus',
    ];

    /**
     * Second words for two-word names.
     *
     * @var array<int, string>
     */
    private array $titles = [
        'Vega', 'Prime', 'Nine', 'Sector', 'Delta', 'Sigma', 'Omega', 'Kilo', 'Tango', 'Bravo',
        'Rex', 'Nyx', 'Kai', 'Zed', 'Ora', 'Lux', 'Nix', 'Ari', 'Sol', 'Vox',
    ];

    /**
     * Usernames handed out during this run, so a single spawn cannot collide with itself before
     * the accounts are written to the database.
     *
     * @var array<string, true>
     */
    private array $claimed = [];

    /**
     * Generate a unique username that is not already taken.
     *
     * @param int $maxAttempts Number of unique candidates to try before falling back.
     * @return string
     */
    public function generate(int $maxAttempts = 60): string
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $candidate = $this->candidate();

            if (isset($this->claimed[strtolower($candidate)])) {
                continue;
            }

            if (User::where('username', $candidate)->exists()) {
                continue;
            }

            $this->claimed[strtolower($candidate)] = true;

            return $candidate;
        }

        // Extremely unlikely, but a spawn must never fail because of name exhaustion. Append a
        // numeric suffix to the last candidate shape until something free turns up.
        do {
            $candidate = $this->pick($this->prefixes) . random_int(1000, 999999);
        } while (isset($this->claimed[strtolower($candidate)]) || User::where('username', $candidate)->exists());

        $this->claimed[strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * Produce one candidate username using a randomly chosen naming style.
     *
     * The weighting roughly mirrors what a real player list looks like: compound names are the
     * most common, plain lowercase handles next, and the "handle plus year" style is a
     * recognisable minority.
     */
    private function candidate(): string
    {
        return match (random_int(1, 100)) {
            // Compound space word, e.g. NovaWalker.
            1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
            21, 22, 23, 24, 25, 26, 27, 28, 29, 30 => $this->pick($this->prefixes) . $this->pick($this->suffixes),

            // Lowercase handle, e.g. kepler.
            31, 32, 33, 34, 35, 36, 37, 38, 39, 40,
            41, 42, 43, 44, 45 => $this->pick($this->handles),

            // Handle plus a year-like number, e.g. tycho1987.
            46, 47, 48, 49, 50, 51, 52, 53, 54, 55,
            56, 57, 58, 59, 60 => $this->pick($this->handles) . random_int(1970, 2010),

            // Prefix plus a short number, e.g. Nebula42.
            61, 62, 63, 64, 65, 66, 67, 68, 69, 70,
            71, 72, 73, 74, 75 => $this->pick($this->prefixes) . random_int(2, 99),

            // Underscore-separated, e.g. Void_Reach.
            76, 77, 78, 79, 80, 81, 82, 83, 84, 85 => $this->pick($this->prefixes) . '_' . ucfirst($this->pick($this->suffixes)),

            // Two words with a space, e.g. Iron Vega.
            86, 87, 88, 89, 90, 91, 92, 93 => $this->pick($this->prefixes) . ' ' . $this->pick($this->titles),

            // A bare stem on its own, e.g. Obsidian.
            default => $this->pick($this->prefixes),
        };
    }

    /**
     * Pick a random entry from a list.
     *
     * @param array<int, string> $pool
     */
    private function pick(array $pool): string
    {
        return $pool[random_int(0, count($pool) - 1)];
    }
}
