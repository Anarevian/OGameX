<?php

use OGame\Enums\CharacterClass;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When disabled, no bot is ever ticked. Existing bot accounts stay in the
    | database and remain visible in the galaxy and highscores, they simply stop
    | taking actions. This is the kill switch referenced in the plan.
    |
    */

    'enabled' => env('BOTS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Population
    |--------------------------------------------------------------------------
    |
    | The number of bot accounts `ogamex:bots:spawn` creates when no explicit
    | --count is given. The level-of-detail machinery (Phase 5) is built
    | regardless of this number, so raising it later is a settings change.
    |
    */

    'population' => (int) env('BOTS_POPULATION', 200),

    /*
    |--------------------------------------------------------------------------
    | Account identity
    |--------------------------------------------------------------------------
    |
    | Bot accounts need an email address because the users table requires one.
    | The default domain uses the RFC 2606 reserved ".invalid" TLD so the
    | addresses can never resolve or receive mail.
    |
    | Bots are also given a stable synthetic IP derived from their user id. Real
    | players on a public server do not have RFC 1918 addresses, so this keeps
    | bots out of the admin panel's shared-IP grouping without inventing
    | addresses that could collide with a real player.
    |
    */

    'email_domain' => env('BOTS_EMAIL_DOMAIN', 'npc.invalid'),

    'ip_prefix' => env('BOTS_IP_PREFIX', '10.99'),

    /*
    |--------------------------------------------------------------------------
    | Starting state
    |--------------------------------------------------------------------------
    |
    | By default a bot registers exactly like a human does: one homeworld, 500
    | metal, 500 crystal, no buildings, no technology, no ships. Everything it
    | owns from then on, it built itself, which means its account can never be
    | in a state the game could not have produced.
    |
    | Set BOTS_SPAWN_DEVELOPED=true instead to materialise a plausible amount of
    | progress from a backdated registration date, so the universe looks like it
    | has been running for months the moment it is seeded. That is much faster to
    | get an interesting server, but the state is written rather than played, and
    | every bug in the spawn path so far has come from that materialisation.
    |
    | The two can be mixed: spawn a developed population once for the backdrop,
    | then add fresh bots over time with --fresh.
    |
    */

    'spawn' => [
        'developed' => (bool) env('BOTS_SPAWN_DEVELOPED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account age
    |--------------------------------------------------------------------------
    |
    | Only used when spawning developed bots: registration is backdated within
    | this range and starting progress is scaled from it, so an "old" account is
    | further along. Fresh bots are always registered just now.
    |
    */

    'account_age_days' => [
        'min' => (int) env('BOTS_MIN_AGE_DAYS', 2),
        'max' => (int) env('BOTS_MAX_AGE_DAYS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Placement
    |--------------------------------------------------------------------------
    |
    | Bots are spread across the universe by the normal planet position logic.
    | A share of them is instead placed near existing human players so a solo
    | player's neighbourhood is not empty. Set human_proximity_share to 0 to
    | distribute bots purely at random.
    |
    */

    'placement' => [
        'human_proximity_share' => (float) env('BOTS_HUMAN_PROXIMITY_SHARE', 0.35),
        'human_proximity_systems' => (int) env('BOTS_HUMAN_PROXIMITY_SYSTEMS', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fairness guardrails
    |--------------------------------------------------------------------------
    |
    | The battle engine does not enforce newbie protection (PlayerService::isNewbie()
    | and isStrong() are display-only), so all protection of human players lives
    | here. Enforced from Phase 2 onwards, when bots gain the ability to attack.
    |
    */

    'fairness' => [
        'min_target_points' => (int) env('BOTS_MIN_TARGET_POINTS', 5000),
        'max_attacks_per_human_per_day' => (int) env('BOTS_MAX_ATTACKS_PER_HUMAN_PER_DAY', 3),
        'raid_cooldown_hours' => (int) env('BOTS_RAID_COOLDOWN_HOURS', 8),
        'max_loot_fraction' => (float) env('BOTS_MAX_LOOT_FRACTION', 0.5),
        'new_player_grace_days' => (int) env('BOTS_NEW_PLAYER_GRACE_DAYS', 7),
        'max_simultaneous_attacks_per_human' => (int) env('BOTS_MAX_SIMULTANEOUS_ATTACKS_PER_HUMAN', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tick pacing
    |--------------------------------------------------------------------------
    |
    | Used from Phase 1. Kept here so the whole feature has one config surface.
    |
    */

    'tick' => [
        'max_bots_per_sweep' => (int) env('BOTS_MAX_PER_SWEEP', 60),
        'queue' => env('BOTS_QUEUE', 'bots'),
        'lock_seconds' => 120,

        // How much less often a bot outside any human's observation radius takes a turn.
        // Cadence only: its account is still brought fully up to date whenever it does run, or
        // the instant a human looks at it.
        'abstract_gap_multiplier' => (float) env('BOTS_ABSTRACT_GAP_MULTIPLIER', 6.0),

        // Skip a sweep entirely when the bots queue is already this far behind, so a slow worker
        // cannot accumulate an unbounded backlog of turns that are stale by the time they run.
        'max_queue_backlog' => (int) env('BOTS_MAX_QUEUE_BACKLOG', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Action log retention
    |--------------------------------------------------------------------------
    */

    'action_log_retention_days' => (int) env('BOTS_ACTION_LOG_RETENTION_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Decision log
    |--------------------------------------------------------------------------
    |
    | Every decision is always written to the bot_action_log table, which is what
    | `ogamex:bots:log` and `ogamex:bots:inspect` read. Setting BOTS_LOG_FILE
    | additionally mirrors each decision to storage/logs as it happens, so the
    | population can be watched from outside the game and the activity outlives
    | the table's retention window. Rotation is daily and kept for BOTS_LOG_DAYS,
    | which means the file is bots-YYYY-MM-DD.log rather than bots.log.
    |
    | Turn the file off on a large population if disk is tight: 200 bots produce
    | roughly a thousand lines a day.
    |
    */

    'log' => [
        'file' => (bool) env('BOTS_LOG_FILE', true),
        'channel' => env('BOTS_LOG_CHANNEL', 'bots'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default universe mix
    |--------------------------------------------------------------------------
    |
    | Relative shares used when spawning a population. Values are weights, not
    | strict percentages: they are normalised, so they do not have to sum to 100.
    | The default deliberately over-represents Casual and Ghost accounts, because
    | a real server is mostly made up of people who play badly or stopped playing.
    |
    */

    'mix' => [
        'casual' => 24,
        'miner' => 19,
        'raider' => 15,
        'explorer' => 14,
        'ghost' => 10,
        'turtle' => 8,
        'fleeter' => 5,
        'trader' => 3,
        'vacationer' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Personas
    |--------------------------------------------------------------------------
    |
    | Each persona describes one playstyle.
    |
    |   character_class  CharacterClass value, or null for "never picked one".
    |   skill            0..1 range. Drives error injection: a low-skill bot
    |                    mis-sizes fleets, forgets to fleetsave and over-builds.
    |   aggression       0..1 range. How readily the bot attacks (Phase 2).
    |   risk_tolerance   0..1 range. How much fleet value it will expose.
    |   growth           Multiplier on age-scaled starting progression.
    |   focus            Relative emphasis used both by the spawn progression
    |                    seeder and, later, by the utility weights.
    |   activity         Session model. Hours are local to the bot's timezone.
    |   planets          Planet count range, scaled by account age.
    |
    */

    'personas' => [

        'miner' => [
            'character_class' => CharacterClass::COLLECTOR->value,
            'skill' => [0.55, 0.85],
            'aggression' => [0.0, 0.1],
            'risk_tolerance' => [0.1, 0.3],
            'growth' => 1.25,
            'focus' => ['economy' => 1.0, 'research' => 0.6, 'fleet' => 0.15, 'defence' => 0.45],
            'activity' => [
                'sessions_per_day' => [2, 5],
                'session_actions' => [3, 10],
                'awake_hours' => [7, 23],
            ],
            'planets' => [1, 6],
        ],

        'raider' => [
            'character_class' => CharacterClass::GENERAL->value,
            'skill' => [0.45, 0.8],
            'aggression' => [0.7, 1.0],
            'risk_tolerance' => [0.5, 0.85],
            'growth' => 1.0,
            'focus' => ['economy' => 0.5, 'research' => 0.6, 'fleet' => 1.0, 'defence' => 0.25],
            'activity' => [
                'sessions_per_day' => [3, 8],
                'session_actions' => [4, 14],
                'awake_hours' => [9, 25], // wraps past midnight
            ],
            'planets' => [1, 5],
        ],

        'explorer' => [
            'character_class' => CharacterClass::DISCOVERER->value,
            'skill' => [0.5, 0.8],
            'aggression' => [0.1, 0.35],
            'risk_tolerance' => [0.4, 0.7],
            'growth' => 1.05,
            'focus' => ['economy' => 0.7, 'research' => 1.0, 'fleet' => 0.5, 'defence' => 0.35],
            'activity' => [
                'sessions_per_day' => [3, 6],
                'session_actions' => [4, 12],
                'awake_hours' => [8, 24],
            ],
            'planets' => [2, 9],
            // An Explorer without astrophysics cannot run a single expedition, which is the
            // whole persona. Scaling alone rounds it to zero on a young account, so the
            // defining technology gets a floor.
            'tech_floor' => ['astrophysics' => 1],
        ],

        'turtle' => [
            'character_class' => CharacterClass::COLLECTOR->value,
            'skill' => [0.4, 0.7],
            'aggression' => [0.0, 0.05],
            'risk_tolerance' => [0.0, 0.15],
            'growth' => 0.95,
            'focus' => ['economy' => 0.8, 'research' => 0.5, 'fleet' => 0.05, 'defence' => 1.0],
            'activity' => [
                'sessions_per_day' => [1, 3],
                'session_actions' => [2, 7],
                'awake_hours' => [10, 23],
            ],
            'planets' => [1, 4],
        ],

        'fleeter' => [
            'character_class' => CharacterClass::GENERAL->value,
            'skill' => [0.75, 1.0],
            'aggression' => [0.55, 0.9],
            'risk_tolerance' => [0.35, 0.6],
            'growth' => 1.35,
            'focus' => ['economy' => 0.7, 'research' => 0.85, 'fleet' => 1.0, 'defence' => 0.3],
            'activity' => [
                'sessions_per_day' => [4, 9],
                'session_actions' => [5, 15],
                'awake_hours' => [8, 25],
            ],
            'planets' => [2, 8],
        ],

        'casual' => [
            'character_class' => null,
            'skill' => [0.1, 0.4],
            'aggression' => [0.05, 0.3],
            'risk_tolerance' => [0.2, 0.6],
            'growth' => 0.55,
            'focus' => ['economy' => 0.7, 'research' => 0.4, 'fleet' => 0.4, 'defence' => 0.3],
            'activity' => [
                'sessions_per_day' => [1, 2],
                'session_actions' => [1, 5],
                'awake_hours' => [17, 23],
            ],
            'planets' => [1, 3],
        ],

        'trader' => [
            'character_class' => CharacterClass::COLLECTOR->value,
            'skill' => [0.5, 0.8],
            'aggression' => [0.0, 0.15],
            'risk_tolerance' => [0.2, 0.45],
            'growth' => 1.1,
            'focus' => ['economy' => 1.0, 'research' => 0.55, 'fleet' => 0.35, 'defence' => 0.5],
            'activity' => [
                'sessions_per_day' => [2, 6],
                'session_actions' => [3, 9],
                'awake_hours' => [8, 22],
            ],
            'planets' => [2, 6],
        ],

        'ghost' => [
            'character_class' => null,
            'skill' => [0.1, 0.5],
            'aggression' => [0.0, 0.0],
            'risk_tolerance' => [0.0, 0.0],
            'growth' => 0.3,
            'focus' => ['economy' => 0.6, 'research' => 0.3, 'fleet' => 0.2, 'defence' => 0.2],
            'activity' => [
                'sessions_per_day' => [0, 0],
                'session_actions' => [0, 0],
                'awake_hours' => [0, 0],
            ],
            'planets' => [1, 2],
            // How long ago the account stopped playing, as a fraction of its age.
            // 0.6 means it went quiet after 40% of its lifetime.
            'abandoned_after' => [0.15, 0.7],
        ],

        'vacationer' => [
            'character_class' => CharacterClass::COLLECTOR->value,
            'skill' => [0.4, 0.75],
            'aggression' => [0.05, 0.25],
            'risk_tolerance' => [0.1, 0.35],
            'growth' => 0.85,
            'focus' => ['economy' => 0.9, 'research' => 0.6, 'fleet' => 0.25, 'defence' => 0.6],
            'activity' => [
                'sessions_per_day' => [1, 4],
                'session_actions' => [2, 8],
                'awake_hours' => [9, 22],
            ],
            'planets' => [1, 4],
            // Chance the account is currently in vacation mode at spawn time.
            'vacation_chance' => 0.4,
        ],
    ],
];
