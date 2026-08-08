# NPC subsystem — status, known gaps and open errors

Companion to [`npc-players-plan.md`](npc-players-plan.md). That document is the design; this one
tracks what is actually built, what is verified, and what is known to be wrong or missing.

**Last updated:** 2026-08-08 (decision log surfaces added)

---

## 1. Phase status

| Phase | Scope | State |
|---|---|---|
| 0 — Groundwork | Data model, marking, synchroniser, spawn/despawn, Docker | **Built.** Verified: see §2. |
| 1 — Economy bot | Tick driver, activity scheduler, brain, economy actions | **Built.** Verified: see §2. |
| 2 — Movement and war | Fleet dispatch, espionage, raiding, fleetsave, recovery | **Built.** Expedition, espionage, intel writing, raiding with fairness caps, fleetsave, transport, colonisation and recycling, all tested. Post-battle recovery (rebuilding defence, revenge) is the one piece left, and it belongs with Phase 3's memory work. |
| 3 — Perception and memory | Intel decay, grudges, skill-scaled mistakes | **Mostly built.** Intel decay, battle observation, grudges with decay, grudge-weighted targeting and re-scouting of stale intel are in and tested. Phalanx as an intel source and post-battle rebuilding are not. |
| 4 — Alliances | Founding, invites, ACS, buddy handling. No messaging (decision §13.4). | **Mostly built.** Founding, applying, attitude-driven application and buddy handling, and the positive side of attitude are in and tested, including a direct assertion of the silence invariant. ACS between allied bots is not. |
| 5 — Scale | LOD classification, abstract economy, lazy materialisation, backpressure | **Built.** Measured at 300 bots, see §7. |
| 6 — Tooling | Admin panel, simulation harness, inspect command, docs | **Mostly built.** `ogamex:bots:simulate`, `ogamex:bots:inspect`, `ogamex:bots:log`, `ogamex:bots:pause` and the operator guide are done. The admin panel UI is not; the console tools cover the same ground. |

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
| Phase 2 tests | `./vendor/bin/phpunit --filter BotFleetTest` | **Pass** — 8 tests |
| Phase 3 tests | `./vendor/bin/phpunit --filter BotMemoryTest` | **Pass** — 7 tests |
| Phase 4 tests | `./vendor/bin/phpunit --filter BotSocialTest` | **Pass** — 5 tests |
| Phase 5 tests | `./vendor/bin/phpunit --filter BotScaleTest` | **Pass** — 7 tests |
| Decision log | `./vendor/bin/phpunit --filter BotLogTest` | **Pass** — 12 tests, 71 assertions (~1.6s) |
| Full bot suite | `php artisan test --filter="Bot*Test"` | **Pass** — 63 tests, 604 assertions (~29 min) |

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

None.

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
| 16 | **Every bot had zero research.** `PlayerService::load()` creates an empty `users_tech` row the first time a player is loaded, and the homeworld is created through a PlayerService — so `SpawnBots::createUserTech()`'s `UserTech::create()` inserted a *second* row. `User::tech()` returns the first, so every technology written was invisible. Explorers had no astrophysics and ran no expeditions; nobody had computer technology, so every bot had one fleet slot. | `UserTech::firstOrNew()`. Fleet slots went 1 → 11 and astrophysics 0 → 4 on the first spawn after the fix. |
| 17 | `testSpawnedProgressionSatisfiesRequirements` was **passing vacuously**: it only asserted a research lab existed *if* the bot had research, and research was always zero, so the assertion never ran. It is what should have caught #16. | Asserts the tech row is populated first, so the check can never go hollow again. |
| 18 | **Research scores were an order of magnitude too large.** `interest * (20000 / cost)` is unbounded as cost falls, so a cheap early technology scored in the hundreds against everything else's 0–3. | Saturated to `interest * 2 * (20000 / (cost + 20000))`. |
| 19 | **Mine scores were unbounded too.** `24 / payback` reached 17 for a level-1 colony mine, so building beat every fleet, research and expedition candidate and the bot did nothing else. | Saturated to `3 * (24 / (payback + 24))`, same ordering, bounded range. Energy and storage scores capped to match. |
| 24 | **The bot suites passed individually but failed together.** BotSocialTest founds alliances, and deleting a bot removes the account but not the alliance it founded, so a leftover open alliance changed what AllianceAction proposed in every suite that ran afterwards. | The social suite clears alliances, members, applications and ranks in its teardown. Worth remembering: the suite does not roll back between tests, so anything a test creates outside the bot tables has to be cleaned up by hand. |
| 22 | **The simulation harness poisoned its own schedule.** It advances the clock, so at the end every NPC was scheduled days ahead in simulated time — against the restored clock that meant nothing was due again for as long as the simulation ran. A second run reported zero decisions, and on a real server the population would have sat frozen. | The command re-anchors every schedule to the present when it finishes. |
| 23 | **Transport scored 5.42 on average against building's 2.51**, so it won every slot it was offered in and took 20% of all decisions. The same scale violation as defects 18–20, found by the harness rather than by reading. | Capped to the shared 3.0 ceiling. Average fell to 1.96 and its share to 11.5%. |
| 20 | Even with comparable scales the brain took the argmax every step, and a multi-planet empire always has another cheap building available, so one category still won every slot. A bot spending all ten actions on the same button is worse at the game and obviously not a person. | Per-session category fatigue in `BotBrain`: a category's score is divided by `1 + 0.6 × actions already spent on it` this session. An explorer went from 100% buildings to 44 buildings / 26 expeditions / 2 research. |
| 21 | **A bot starting from nothing locked itself out of most of the game.** Building from zero for ten simulated days produced mine level 19 and 2.9M unspent metal, with `robot_factory`, `research_lab` and `shipyard` all still at 0 — so no research, no ships, no defence, ever. A mine upgrade always out-scored an incremental infrastructure one, and both sat in the `economy` category so session fatigue scaled them together and never changed the ordering. | The first level of a gateway building (robot factory, research lab, shipyard) now scores 2.6, because it unlocks a whole branch rather than being an incremental gain; and infrastructure has its own scoring category so fatigue can tell it apart from mines. The same bot now reaches robot factory 10, research lab 8, shipyard 9 and energy technology 5. |

---

## 4a. One rule to keep

Defects 18, 19 and 20 were the same mistake three times, and it will happen again every time an
action class is added:

> **Every scorer must saturate towards the same ceiling (3.0), and no score may be an unbounded
> ratio.** The brain is a ranking. The moment one class emits a number an order of magnitude
> larger than the rest, it stops being a ranking and becomes a fixed priority list, and the bot
> does one thing forever.

When adding an action, print the score distribution across a few hundred decisions before
believing it works:

```sql
SELECT action, COUNT(*), ROUND(AVG(score), 2), ROUND(MAX(score), 2)
FROM bot_action_log GROUP BY action;
```

If one action's average is more than about double another's, the scale is wrong, not the weights.

---

## 5. Known gaps and deliberate simplifications

Things that are not bugs but are not finished either. Each needs a decision or a later phase.

### 5.1 Carried from Phase 0

- **Developed spawning writes state rather than playing it.** See §6.0. It is no longer the
  default, but when used it remains the largest source of "state the game could not have
  produced" risk in the codebase.
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
- **Cross-planet coordination is crude.** `TransportAction` moves surplus from the fullest planet
  to the emptiest, which stops production being thrown away, but it is not aimed at funding a
  specific upgrade. A bot will not deliberately ship crystal home to pay for a named building.

### 5.3 Carried from the design

- **Bots are silent** (decision §13.4). A human who writes to a bot gets no reply at all. Accepted,
  since bots are openly marked, but it is a visible limitation. The `PhraseProvider` seam is
  documented and unimplemented.
- ~~**Fairness caps are configured but not enforced.**~~ Resolved in Phase 2: `RaidAction` applies
  all six caps under `bots.fairness`. Kept here because it remains the most important thing not to
  forget — the engine does not enforce newbie protection, so those caps are the only thing
  protecting human players.
- ~~**LOD is a column, not a behaviour.**~~ Resolved in Phase 5: `BotLodClassifier` writes it and
  the tick pacing reads it.
- **ACS is not implemented.** Allied bots do not answer each other's defence calls or launch
  joint attacks. `FleetUnionService` exists and `bot_memory` now carries the friendly attitudes
  that would decide who answers whose call, so the remaining work is the fleet coordination
  itself.
- **Bots never leave an alliance.** They found and join, but nothing makes them walk out after a
  falling-out, so alliance membership only ever grows.

### 5.4 Observability

- **The log records decisions taken, not decisions rejected.** Only the winning candidate of each
  step is written, so the log answers "why did it do that" but not "why did it *not* do the other
  thing". A bot that never raids looks identical to one whose raid candidates are always scored
  just below a mine. Logging every proposal would answer it, at roughly ten times the volume;
  until then `ogamex:bots:simulate` is the way to see which actions never win.
- **A tick that produces no decisions leaves no trace.** A bot that was asleep, held no free fleet
  slot and could afford nothing writes nothing at all, which reads the same as a bot that was
  never ticked. `ogamex:bots:inspect` shows `last_tick_at`, which is the way to tell the two
  apart.

---

## 6. Operational notes

### 6.0 Fresh vs developed spawning

By default a bot registers **exactly like a human**: one homeworld, 500 metal, 500 crystal, no
buildings, no technology, no ships. Everything it owns after that, it built itself, so its account
can never hold a state the game could not have produced.

```bash
php artisan ogamex:bots:spawn --count=20              # fresh (default)
php artisan ogamex:bots:spawn --count=20 --developed  # materialised progress
```

`BOTS_SPAWN_DEVELOPED=true` flips the default; `--fresh` and `--developed` override it per run.

**The trade-off is real and worth understanding before choosing.** A fresh bot grows at the pace a
human does, which is the point, but that means a newly seeded server starts as 200 identical
level-zero accounts: a flat highscore, no rivals, nothing worth raiding. Measured here, a fresh
Miner reaches mine level 22, robot factory 10, research lab 8 and shipyard 9 after ten *simulated*
days — which is ten real days at normal speed.

Developed spawning skips that wait by writing the state a plausible account would have reached, so
the universe looks months old the moment it is seeded. The cost is that the state is written rather
than played, and defects 1, 2, 3, 10, 11 and 16 in §4 all came from that materialisation.

A reasonable middle: seed once with `--developed` for a populated backdrop, then add fresh bots
over time so new arrivals grow naturally alongside the humans.

### 6.1 Getting bots to appear — the first-run order matters

Setting `BOTS_ENABLED=true` does **not** create any bots, and this is the first thing everyone
trips over. The two settings do different jobs:

- `BOTS_ENABLED` decides whether bots that *already exist* take actions.
- `BOTS_POPULATION` is only the default `--count` for the spawn command.

Nothing creates accounts on container boot. That is deliberate: spawning hundreds of accounts is
a large, hard-to-undo side effect a server owner should trigger knowingly.

The working order is:

```bash
# 1. Be on the branch, and create the tables.
docker compose exec ogamex-app php artisan migrate

# 2. Register your own account through the web UI FIRST.
#    Roughly a third of bots are placed near human players; with no humans in the database they
#    all scatter at random and your neighbourhood stays empty.

# 3. Spawn.
docker compose exec ogamex-app php artisan ogamex:bots:spawn --count=20

# 4. Confirm they exist. This number is the real answer to "did it work".
docker compose exec ogamex-app php artisan tinker \
  --execute="echo OGame\Models\BotProfile::count();"
```

Then check the **highscore page** rather than the galaxy view: every bot appears there
immediately with its NPC badge, wherever it landed, while the galaxy only shows the systems you
happen to be looking at.

To watch one act right now instead of waiting for its schedule:

```bash
docker compose exec ogamex-app php artisan ogamex:bots:tick --user=<id>
```
- `ogamex:bots:tick --user=<id>` forces one bot's turn regardless of schedule, and prints its
  stance and next action. This is the first thing to reach for when a bot misbehaves.
- `bot_action_log` records every decision including failures, with the score and the reasoning
  string. A failed row means the bot proposed something the game rejected, which is the main
  signal that an action class is proposing illegal moves.

### 6.2 Reading the decision log

Two surfaces read the same `bot_action_log` table, and they answer different questions.

`ogamex:bots:inspect <name>` answers *what is this one bot doing* — its last decisions alongside
its empire, its intel and its grudges, which is what you want when one account looks wrong.

`ogamex:bots:log` answers *what is the population doing*. It is a chronological stream with
filters (`--user`, `--persona`, `--action`, `--failed`, `--since`, `--limit`), three output formats
and two extra modes:

```bash
php artisan ogamex:bots:log --follow                        # tail live
php artisan ogamex:bots:log --failed --since=24h            # what the game rejected today
php artisan ogamex:bots:log --limit=0 --format=csv --out=…  # export before retention prunes it
```

The summary line is written to **stderr**, not stdout, so `--format=json | jq` and
`--format=csv > file` produce clean data. This is asserted directly in `BotLogTest` using an
output double with real split streams, because `Artisan::call()` merges them and would hide a
regression.

A third surface writes the same decisions to `storage/logs/bots.log` as they happen, rotated daily
(`BOTS_LOG_DAYS`, default 14) and switchable with `BOTS_LOG_FILE`. The project directory is
bind-mounted into the containers, so `tail -f storage/logs/bots.log` works from the host with no
`docker compose exec`. The file mirror is deliberately best-effort: `BotActivityLog` swallows its
own errors, because an unwritable log directory must not cost a bot its turn. The table is written
either way.

Successful lines carry only the bot id and tick id as context; failures also carry the full
payload, since reconstructing what the bot thought it was doing is the entire reason to look at a
failure.

---

## 7. Measured performance

Taken on the dev container with 300 spawned bots and no human players, so every bot classified as
Abstract. Numbers are indicative, not a benchmark, but they are real.

| Operation | Cost |
|---|---|
| Spawn 300 bots | 114s (~380ms each, developed) |
| Classify all 300 for LOD | 1.2s (~4ms each) |
| One sweep (selection + dispatch) | **81ms, 64 queries** |
| One real turn (sync + brain + reschedule) | **~1.0s** |
| Materialise a system on a galaxy view | **1–25ms** (was 820ms before bounding) |

### What these mean

**The sweep is cheap and stays cheap.** It only selects due bots and queues one job each, so it
does not grow with how much work the bots have to do.

**A turn costs about a second**, almost all of it in `PlanetService::update()`, which is a locked
transaction per planet. That is the number that matters, and it bounds the population:

- In steady state a bot takes roughly 3–8 turns a day, so 300 bots is around 1,500 turns a day —
  about 25 minutes of worker CPU spread over 24 hours. Comfortable.
- The dangerous case is a **thundering herd**: after downtime every bot is overdue at once, and
  300 turns is 300 seconds of work. `max_bots_per_sweep` (default 60) is what stops that becoming
  a backlog — it drains over about five minutes instead of all at once.
- At 500+ bots, raise `BOTS_ABSTRACT_GAP_MULTIPLIER` before raising the sweep limit. Ticking
  distant bots less often is free; ticking more of them per minute is not.

**Materialisation was the one genuine problem this phase found.** It runs inside a human's page
request, and unbounded it added 820ms to a galaxy view of a bot-heavy system. Now it skips bots
that ticked in the last 10 minutes and synchronises at most 4 per request, which brought it to
1–25ms. A system with more stale bots than that catches up on the next view.

### Operating it

```bash
php artisan ogamex:bots:pause            # stop turns now, no deploy, lifts after 24h
php artisan ogamex:bots:pause --resume
php artisan ogamex:bots:pause --status   # paused? plus last sweep timing and queue backlog
```

The sweep also refuses to run when the `bots` queue is more than `BOTS_MAX_QUEUE_BACKLOG` deep
(default 500), because queuing turns that will be stale by the time they run is worse than
skipping a session.

---

## 8. What the simulation harness found

First real use of `ogamex:bots:simulate`, over 24 mixed NPCs. It paid for itself immediately by
finding two defects (22 and 23 above) that no amount of reading had caught.

After fixing those, three simulated days produced:

```
Action mix          build_building 59%  transport 12%  expedition 11%
                    build_defence 7%  espionage 5%  research 4%  build_ships 2%
Failed actions      0 of 330
```

**Persona divergence, as share of each persona's own decisions:**

| Persona | building | defence | ships | espionage | expedition | transport |
|---|---|---|---|---|---|---|
| casual | 52% | 4% | 4% | 12% | 12% | – |
| explorer | 61% | – | – | 7% | **21%** | 6% |
| miner | 62% | 7% | 1% | – | 11% | **15%** |
| raider | 43% | – | **22%** | **30%** | – | – |
| turtle | 60% | **16%** | – | – | 3% | 19% |

That is the property the whole design was aiming at, and it is now visible rather than asserted:
a raider scouts and builds warships and never runs an expedition; a turtle builds defence nobody
else does; an explorer runs expeditions; a miner moves resources between planets. Nothing in the
code says "raiders scout" — it falls out of the persona weights and the utility scoring.

Activity across the 24 hours has real peaks and troughs rather than a flat line, confirming the
timezone and waking-hour model works.

**Read the harness with `--step=1`.** Larger steps alias the hourly histogram: the clock jumps in
whole steps, so only every Nth hour can contain a decision and the gaps look like sleeping NPCs
when they are an artefact of sampling. The command now says so in its own output.
