# NPC subsystem — status, known gaps and open errors

Companion to [`npc-players-plan.md`](npc-players-plan.md). That document is the design; this one
tracks what is actually built, what is verified, and what is known to be wrong or missing.

**Last updated:** 2026-08-08

---

## 1. Phase status

| Phase | Scope | State |
|---|---|---|
| 0 — Groundwork | Data model, marking, synchroniser, spawn/despawn, Docker | **Built.** Verified: see §2. |
| 1 — Economy bot | Tick driver, activity scheduler, brain, economy actions | **Built.** Verified: see §2. |
| 2 — Movement and war | Fleet dispatch, espionage, raiding, fleetsave, recovery | **Partly built.** Expedition, espionage, intel writing and raiding with fairness caps are in and tested (4/5). Fleetsave, transport, colonisation, recycling and post-battle recovery are not. One open error, §3.1. |
| 3 — Perception and memory | Intel decay, grudges, skill-scaled mistakes | Not started (tables exist since Phase 0). |
| 4 — Alliances | Founding, invites, ACS, buddy handling. No messaging (decision §13.4). | Not started. |
| 5 — Scale | LOD classification, abstract economy, lazy materialisation, backpressure | Not started. |
| 6 — Tooling | Admin panel, simulation harness, inspect command, docs | Not started. |

---

## 2. Verification state

The dev container this was built in initially could not run the suite: PHP 8.4 against a project
requiring 8.5, no database, and `composer install` blocked. All three are now resolved and the
checks below were actually executed.

| Check | Command | Result |
|---|---|---|
| Static analysis | `./vendor/bin/phpstan analyse --memory-limit=1G` | **Pass** — level 8, no errors |
| Code style | `./vendor/bin/pint --test` | **Pass** |
| Migrations | `php artisan migrate` | **Pass** — all four bot tables created |
| Phase 0 tests | `./vendor/bin/phpunit --filter BotSpawnTest` | **Pass** — 12 tests, 118 assertions (~60s) |
| Phase 1 tests | `./vendor/bin/phpunit --filter BotBrainTest` | **Pass** — 11 tests, 219 assertions (~6min) |
| Regression | `./vendor/bin/phpunit --filter "GalaxyTest\|BootstrapTest\|AdminTest"` | **Pass** — no existing test affected |
| Phase 2 tests | `./vendor/bin/phpunit --filter BotFleetTest` | **4 pass, 1 skipped** — the skip is the open error in §3.1 |

### How the environment was made to work

Recorded because it will be needed again, and because none of it is a change to the project:

1. **PHP 8.5.** The `ondrej/php` PPA was already configured. `apt-get install php8.5-cli` plus the
   extensions, then `update-alternatives --set php /usr/bin/php8.5`. The project targets 8.5
   (`Dockerfile: FROM php:8.5-fpm`), so lowering `composer.json` would have been wrong.
2. **Composer.** The agent proxy returns 403 for `api.github.com` and `codeload.github.com`, but
   plain `git clone` to github.com works. `--prefer-source` therefore installs everything except
   `phpstan/phpstan`, which is the one locked package with no `source` entry. Composer keys its
   dist cache by `sha1(download url)`, so the fix is to build the same zip from a git clone and
   drop it at `/root/.cache/composer/files/phpstan/phpstan/<sha1>.zip`, then run
   `composer install --prefer-source`.
3. **Database.** `apt-get install mariadb-server`, create the `ogamex` database and user, copy
   `.env.example` to `.env` with `DB_HOST=127.0.0.1`, `php artisan key:generate`.

---

## 3. Open errors

### 3.1 Explorer spawns with astrophysics 0, so it never runs an expedition

**Status:** open. Test `BotFleetTest::testBotsDispatchFleetMissions` is skipped because of it.

An Explorer is defined by running expeditions, and `ExpeditionAction` requires
`astrophysics >= 1`. A spawned Explorer has astrophysics 0, so the action never proposes and the
bot sends no fleets at all.

A `tech_floor` of `['astrophysics' => 1]` was added to the explorer persona and applied in
`BotProgression::techConfig()`, but a freshly spawned Explorer still reports
`getResearchLevel('astrophysics') === 0` after `php artisan config:clear`. The floor is present
in `config/bots.php` and the code path looks right, so the fault is somewhere between
`techConfig()` and the `users_tech` row.

**Reproduction**

```
php artisan ogamex:bots:despawn --all --force
php artisan ogamex:bots:spawn --count=1 --persona=explorer --near-humans=0
php artisan tinker
>>> $p = OGame\Models\BotProfile::first();
>>> app(OGame\Factories\PlayerServiceFactory::class)->make($p->user_id, true)->getResearchLevel('astrophysics');
=> 0   // expected >= 1
```

**Next things to check, in order**

1. Whether `users_tech` actually has an `astrophysics` column, and whether
   `SpawnBots::createUserTech()`'s dynamic `$userTech->{$machineName} = $level` writes it. A
   column that does not exist would be silently dropped rather than throwing.
2. Whether `expandTechRequirements()` is discarding the floored entry — astrophysics requires
   espionage technology 4 and impulse drive 3, and the expansion runs *after* the floor is applied.
3. Whether `PlayerService::getResearchLevel()` reads the value by a different name.

**Impact:** Explorers are inert — no expeditions, and with no other fleet action reachable at
their tech level, no fleet missions at all. Raiders and Fleeters are unaffected (their tests
pass), so this is one persona, not the fleet layer as a whole.

---

## 4. Fixed during development

Kept because each was a genuine defect that the design invited, and the same trap will recur in
later phases.

| # | Problem | Fix |
|---|---|---|
| 1 | `array_merge` of the persona's planet config over the buildings required by its technologies let a modest research-lab level silently overwrite the higher level a technology required, producing an account the game could not have built. | `BotProgression::mergeHighest()`, applied in both directions. |
| 2 | Requirements only ran one way. Buildings need research too — a nano factory needs computer technology 10 — so a spawned bot could own a building it had no way to unlock. | `researchRequirementsForBuildings()`, reconciled both ways with a second building pass. |
| 3 | Trimming building levels to fit a planet's fields scaled everything proportionally, which could drop a prerequisite below what depended on it. | `fitToFields()` now shrinks only the seven buildings nothing depends on (mines, solar, stores). |
| 4 | Bot jobs were dispatched to a `bots` queue, but `docker/entrypoint.sh` ran plain `queue:work`, which consumes only `default`. Bot turns would never have run in Docker. | `queue:work --queue=default,bots`, priority order so bots cannot delay game jobs. |
| 5 | `PlayerService::update()` writes `request()->ip()` and stamps `user.time` unconditionally. Under a queue worker the first is meaningless and clusters every bot on one IP in the admin panel; the second makes every bot look permanently online. | `BotSynchroniser` reuses only `updateResearchQueue()` and handles both side effects itself. |
| 6 | Spawning a population would flood the admin bot-detection panel and bury real suspects. | NPCs excluded from the shared-IP grouping and the activity signals. |
| 7 | `isset($x) && $x !== null` — the null check is dead after `isset`. | Removed. |
| 8 | Test helpers chained `->assertSuccessful()` off `$this->artisan()`, whose `PendingCommand\|int` union is not narrowable and fails static analysis. No existing test in the repo does this. | `Artisan::call()` with explicit exit-code assertions. |
| 9 | **Every command option was ignored when invoked programmatically.** Options typed on a command line are strings, but `Artisan::call()` passes PHP values through untouched, so `['--count' => 1]` arrives as an `int` and the `is_string()` guard rejected it. Each command silently fell back to its default — a test asking for one bot spawned the configured population of **200**, which is why the suite appeared to hang. | `ReadsScalarOptions` trait (`scalarOption`/`intOption`/`floatOption`), used by all four bot commands. |
| 10 | Spawned bots started **above their storage capacity**, so production was clamped at the ceiling and the planet earned nothing until something was spent. A freshly spawned bot looked frozen. | `clampResourcesToStorage()` fills to 35–95% of capacity depending on skill: a careless player sits near the top of their stores, a careful one keeps room. |
| 11 | **Spawned bots started in a permanent energy deficit** (measured −1699 on a miner). Mine energy consumption grows faster than solar output at the same level, so scaling both from one progression factor left every planet starved. The brain then correctly spent nearly every decision on solar plants and the account never developed. | `balanceEnergy()` raises the solar plant using the game's own production maths until the deficit clears, respecting the field limit. Low-skill bots are left slightly under-powered on purpose. |
| 12 | A 500-hour payback cutoff on mine upgrades meant a developed account refused every remaining upgrade once its cheap colony mines were done, plateauing with over a million unspent metal on the planet. | Cutoff raised to 2000h. Ranking already orders by payback, so the cutoff only needs to discard the absurd. |
| 13 | `assertDatabaseHas('user_tech', ...)` — the table is `users_tech`. | Corrected. |
| 14 | Miner defence focus of 0.7 sat too close to a turtle's 1.0 for the two playstyles to read differently; the miner's larger economy let it out-build the turtle in absolute defence. | Miner defence focus lowered to 0.45, and the test now compares the *share* of decisions spent on defence rather than the raw count, which is what playstyle actually means. |
| 15 | The log-pruning test never created an old row: `created_at` is not in `BotActionLog`'s `Fillable` list, so mass assignment dropped it and Laravel stamped "now". | Backdate after insert. |

---

## 5. Known gaps and deliberate simplifications

Things that are not bugs but are not finished either. Each needs a decision or a later phase.

### 5.1 Carried from Phase 0

- **Spawn cost is ~1.6s per bot**, almost all CPU in recursive requirement expansion. A default
  200-bot population therefore takes around five minutes to spawn. Acceptable for a one-off
  command, but worth memoising `ObjectService::getRecursiveRequirements()` if it ever runs on a
  hot path.
- **Planet timestamps are not backdated.** A spawned account's registration date is backdated but
  its planets' `time_last_update` is "now", because the planet state is materialised to match the
  account's age and replaying production from the registration date would double-count it. The
  visible consequence is that a bot's planets all show as freshly updated.
- **Colony progression is a rough model.** A colony is treated as a fraction of the account's age
  (`0.6 / index`), which produces the right shape — big homeworld, progressively smaller colonies —
  but is not derived from anything.
- **Moons are never created at spawn.** Bots can only get moons through Phase 2 combat.
- **`updated_at` is not backdated**, because Laravel rewrites it on save. Only `created_at` is.

### 5.2 Carried from Phase 1

- **No fleet activity at all.** Bots do not transport, expedition, colonise, spy or attack. Their
  ships sit on the planet. This is the single most visible gap for a player watching the galaxy,
  and it is Phase 2.
- **Stance machine is minimal.** Only the three economic stances are ever chosen. Defending,
  Raiding, Recovering and Expanding exist in the enum and in the modifier table but are never
  entered, because nothing yet can trigger them.
- **`BotTickJob::remainingBudget()` always returns 0.** A whole session runs inside one job, so a
  session always ends when the job does. The hook is there for Phase 5, where long sessions may
  need splitting across jobs.
- **Planet moves are not processed on the bot path.** `PlanetMoveService::processDueMoves()` is
  global rather than player-scoped, so running it per bot would repeat the whole server's work
  every tick. Bots do not move planets before Phase 2; when they do it belongs in the sweep, once.
- **Research is started from the best lab, not the best plan.** A bot picks the planet with the
  highest research lab and researches from there, which is right, but it never decides to *build*
  a lab somewhere better.
- **Resource value ratios (1 : 2 : 3) are hardcoded** in three action classes. They are the
  community rule of thumb rather than anything the game defines. If they need tuning they should
  move to `config/bots.php` first.
- **No cross-planet coordination.** Each planet is scored independently; a bot will not ship
  crystal from a rich colony to fund a homeworld upgrade. That needs Phase 2 transports.

### 5.3 Carried from the design

- **Bots are silent** (decision §13.4). A human who writes to a bot gets no reply at all. Accepted,
  since bots are openly marked, but it is a visible limitation. The `PhraseProvider` seam is
  documented and unimplemented.
- **Fairness caps are configured but not enforced**, because nothing can attack yet. Every value
  under `bots.fairness` is dead configuration until Phase 2. This is the most important thing not
  to forget: the engine does not enforce newbie protection, so those caps are the only thing that
  will protect human players.
- **LOD is a column, not a behaviour.** `bot_profiles.lod` is written at spawn and never read.
- **`bot_intel` and `bot_memory` are empty tables.** Nothing writes them until Phase 3.

---

## 6. Operational notes

- `BOTS_ENABLED=false` is the default. Existing bots stay visible in the galaxy and highscores but
  take no actions.
- Spawning is deliberately manual. Nothing creates accounts on container boot.
- `ogamex:bots:tick --user=<id>` forces one bot's turn regardless of schedule, and prints its
  stance and next action. This is the first thing to reach for when a bot misbehaves.
- `bot_action_log` records every decision including failures, with the score and the reasoning
  string. A failed row means the bot proposed something the game rejected, which is the main
  signal that an action class is proposing illegal moves.
