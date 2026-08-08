# Player-like NPCs ("Bot Players") — Design & Implementation Plan

**Status:** Proposal / plan. No code written yet. Scope decisions settled — see §13.
**Goal:** Let a server with a handful of real players feel like a populated universe, by adding
persistent AI accounts that build, research, expand, scout, trade and fight using the *same*
game rules as humans — with distinct, recognisable playstyles.

Bots are **openly marked in-game** (§13.1) and **do not send messages or chat** (§13.4). Believability
therefore does not mean concealment: it means that an account a player *knows* is a bot still behaves
like a person when they look closely at it.

---

## 1. Success criteria

A bot is "done" when all of these hold:

| Criterion | Test |
|---|---|
| **Plausible under scrutiny** | A human who inspects a bot's growth curve, login rhythm, fleet movements and battle history sees nothing that reads as scripted — no clockwork intervals, no perfect play, no 24/7 activity. |
| **Plays by the rules** | Every bot action goes through the same services a controller calls. No resource injection, no omniscient targeting, no illegal builds. |
| **Has a memory and a fog of war** | A bot only acts on information it could have obtained in-game (espionage reports, galaxy view, battle reports). It makes decisions on stale intel and is sometimes wrong. |
| **Has a schedule** | Bots sleep, take breaks, go on holiday, and some quit forever. Actions are bursty, not uniform. |
| **Distinct playstyles** | Given two bots of different personas, their build order, fleet composition, aggression and mistakes are visibly different over a week. |
| **Fair** | A new human player is never farmed into quitting. Aggression toward humans is capped and configurable. |
| **Scales** | The default population (200 bots, configurable) costs well under a second of CPU per minute on the dev docker stack, and 500+ remains viable. |
| **Reversible** | One server setting disables the whole system; one command removes all bots cleanly. |

---

## 2. What the codebase already gives us

Findings from reading the current `main`:

### 2.1 There is already an `NPC*` namespace — and it is not what we want

`app/Services/NPCPlayerService.php`, `NPCPlanetService.php` and `NPCFleetGeneratorService.php` exist,
but they are **ephemeral expedition combatants** (pirates/aliens) with hardcoded IDs `-1`/`-2`, no
database record and no behaviour outside a single battle.

> **Decision:** the new system must not reuse the `NPC` prefix. Proposed namespace `OGame\Bots\`,
> table prefix `bot_`, command prefix `ogamex:bots:`. This avoids a confusing collision and keeps
> the expedition code untouched.

### 2.2 Game rules live in the service layer, not in controllers

This is the single most important enabler. Validation is enforced *below* the HTTP layer:

- `BuildingQueueService::add()` — throws on queue full, requirements not met, wrong planet type, no free fields.
- `ResearchQueueService::add()`, `UnitQueueService::add()` — same pattern.
- `GameMission::startMissionSanityChecks()` — checks resources, units on planet, **fleet slot limits**,
  then delegates to per-mission `isMissionPossible()` (vacation mode, attack block, target exists,
  own-planet check, admin protection).
- `PlanetService::deductResourcesAndUnitsAtomic()` — atomic, race-safe.

**Consequence:** a bot driver that calls these services is playing the real game. We do not need to
re-implement or duplicate any rules, and any future rule change automatically applies to bots.

### 2.3 The game has no global tick — this is the hard problem

`app/Http/Middleware/GlobalGame.php` advances state **only for the currently browsing player**:

```php
$player->update();                    // research queue, last_ip, time
$player->planets->current()->update(); // building queue, resources, unit queue, production, storage
$player->updateFleetMissions();        // arrived missions (in AND out)
$planetMoveService->processDueMoves(...);
```

Nothing advances a player who is not making HTTP requests. Bots therefore need their own driver.

Two useful details:

- `FleetMissionService::getArrivedMissionsByPlanetIds()` matches on **both** `planet_id_from` **and**
  `planet_id_to`. So whichever side ticks first resolves the mission — a bot ticking on schedule will
  resolve an attack it launched even if the victim never logs in, and a human's page load resolves
  their attack on a sleeping bot.
- `PlayerService::update()` writes `request()->ip()` and sets `user->time = now()`. In console
  context this is wrong for us on both counts (see §9.2 and §7.1) and needs a bot-aware path.

### 2.4 Scheduler and queue worker already run

`docker/entrypoint.sh` runs `php artisan schedule:run` on a loop **and** `php artisan queue:work`.
`QUEUE_CONNECTION=database` by default. `routes/console.php` is the existing place to register
scheduled commands. So the infrastructure for a tick driver already exists — we only add to it.

### 2.5 Reusable building blocks

| Need | Existing code |
|---|---|
| Create account + homeworld | `Actions/Fortify/CreateNewUser.php`, `PlanetServiceFactory::createInitialPlanetForPlayer()` |
| Believable usernames | The `firstNames`/`lastNames` arrays in `CreateNewUser.php` |
| Bulk-create players with tech/buildings/ships | `Console/Commands/Dev/PreviewSeedUsers.php` (excellent template, incl. recursive requirement expansion) |
| Colonise / add planets | `PlanetServiceFactory::determineNewPlanetPosition()`, `createAdditionalPlanetForPlayer()` |
| Combat | `AttackMission`, Rust FFI battle engine + PHP fallback |
| Espionage | `EspionageMission`, `EspionageReport` model, `CounterEspionageService` |
| Social | `AllianceService`, `MessageService`, `ChatService`, `BuddyService`, `FleetUnionService` (ACS) |
| Economy extras | `MerchantService`, `DebrisFieldService`, `WreckFieldService`, `JumpGateService`, `PhalanxService` |
| Presence in rankings | `GenerateHighscores` scheduled command — bots appear automatically, no work needed |
| Class-based playstyle | `CharacterClass` enum (Collector / General / Discoverer) + `CharacterClassService` |

### 2.6 Two things that will bite us

1. **Bot detection panel.** `Admin/ServerAdministrationController` flags accounts by shared IP groups
   and by mission cadence (`bot_detection_missions_per_slot_per_day`, `expedition_gap_seconds`,
   `attack_reaction_seconds`, default 10s). Our bots will share an IP and act on a cadence — they
   would flood this panel. They must be excluded from it (§10.3), and their cadence should stay
   human-plausible anyway.
2. **Noob protection is not enforced.** `PlayerService::isNewbie()` / `isStrong()` are only used for
   *display* in `GalaxyController` — there is no engine-level block on attacking a much weaker
   player. All fairness limits must therefore be implemented in the bot targeting layer (§8).

---

## 3. Architecture overview

```
routes/console.php
  └─ Schedule::command(BotTickCommand)->everyMinute()->withoutOverlapping()
        │
        ├─ selects bots where next_action_at <= now()  (indexed)
        └─ dispatches BotTickJob per bot onto the existing database queue
              │
              ├─ Cache::lock('bot:{id}')            ← no concurrent ticks per bot
              ├─ BotSynchroniser  → player/planet/fleet catch-up (the GlobalGame equivalent)
              ├─ BotPerception    → what can this bot see right now? writes BotIntel
              ├─ BotBrain         → generate candidates → score → pick → execute
              └─ schedules next_action_at from the bot's ActivityProfile
```

### 3.1 New namespaces

```
app/Bots/
  Personas/            persona definitions (PHP config objects, one per playstyle)
  Brain/               BotBrain, ActionCandidate, scoring
  Actions/             one class per action type (BuildAction, ResearchAction, RaidAction, …)
  Perception/          BotPerception, IntelStore, target discovery
  Activity/            ActivityProfile (sleep/wake/holiday/quit), jitter
  Social/              alliance behaviour only (no messaging — see §13.4)
  Support/             BotSynchroniser, BotLock, decision logging
app/Console/Commands/Bots/    tick, spawn, despawn, simulate, inspect, pause
app/Models/Bot*.php           BotProfile, BotIntel, BotMemory, BotActionLog
config/bots.php               global tunables + persona registry
```

### 3.2 Database

| Table | Purpose |
|---|---|
| `bot_profiles` | `user_id` (FK, unique), `persona`, `skill` (0–1), `aggression`, `risk_tolerance`, `timezone`, `activity_profile` (json), `state` (json: stance, goals, cooldowns), `next_action_at` (**indexed**), `enabled`, `lod` (full/abstract/dormant), `created_at` |
| `bot_intel` | What a bot *believes* about a coordinate: `bot_user_id`, `galaxy/system/position/type`, `source` (galaxy_view / espionage / battle / phalanx), `observed_at`, `payload` (json: resources, defence, fleet estimates), `confidence` |
| `bot_memory` | Relationship state: `bot_user_id`, `other_user_id`, `attitude` (−100…100), `attacked_us_count`, `we_farmed_count`, `last_interaction_at`, `notes` |
| `bot_action_log` | `bot_user_id`, `tick_id`, `action`, `score`, `reason`, `payload`, `created_at`. Debug + tuning + test assertions. Pruned by a scheduled command. |

`bot_profiles.user_id` is the only link into core tables — deleting a bot is `PlayerService::delete()`
plus one row. No core migrations required except optional indexes.

### 3.3 Key design rule

> **The bot never reads the database for anything a human could not see.**

Target selection reads `bot_intel`, not `planets`. Galaxy scanning is an explicit action that writes
intel. Espionage is a real `EspionageMission` whose report the bot then parses. This single rule is
what produces believable behaviour for free: stale intel → the bot walks into a defended planet and
loses ships, exactly like a player.

The one deliberate exception is *discovery* — knowing which coordinates exist at all, which the galaxy
view gives a human cheaply. Bots may run a cheap "galaxy scan" action that writes low-confidence
intel (player name, points, inactive flag, moon presence) for a system, mirroring what the galaxy
page shows.

---

## 4. Decision making: utility scoring

Rejected alternatives: hardcoded build order (rigid, identical bots), behaviour trees (verbose,
hard to give personality), LLM-per-decision (latency, cost, non-determinism, unusable in tests).

**Chosen: utility AI with a light stance machine.**

Each tick:

1. **Stance** — a small state machine sets the bot's current posture:
   `Expanding | Economising | Militarising | Raiding | Defending | Recovering | Idle`.
   Stance changes are slow (hours/days) and driven by triggers (was attacked, hit a resource ceiling,
   fleet destroyed, new colony slot available).
2. **Candidate generation** — each `Action` class proposes zero or more concrete candidates it could
   legally execute right now (it may cheaply pre-check feasibility).
3. **Scoring** — `score = base_utility(candidate) × persona_weight[action_type] × stance_modifier × urgency × noise`.
   `noise` is drawn per-bot per-tick and scaled by `(1 - skill)`, so low-skill bots make visibly
   worse choices.
4. **Selection** — take the highest scoring feasible candidates until the tick's *action budget* is
   spent (a human clicking around does 1–15 meaningful actions per session, not 200).
5. **Execution** — call the real service. Catch exceptions, log them as failed decisions, never crash
   the tick. A thrown exception is *information*: it means the bot mis-estimated, which is fine and
   should be recorded in `bot_action_log`.

Scoring inputs worth having from day one: resource ratios vs. targets, production per hour vs. cost,
storage overflow risk, time-to-payback for a mine upgrade, defence value vs. nearest threat, fleet
value at risk, distance/deuterium cost, expected loot from intel, cooldowns.

**Why this shape:** personas become a weight vector plus a few thresholds — a data file, not code.
Adding a new playstyle is a config entry. Tuning is a spreadsheet exercise driven by the simulation
harness (§11.2).

---

## 5. Playstyle catalogue

Each persona is a config object: class preference, weight vector, build-order bias, activity profile,
skill, aggression, risk tolerance, alliance sociability, and a naming flavour.

| Persona | Class | Behaviour | Role in the universe |
|---|---|---|---|
| **Miner** | Collector | Maxes mines/energy early, storages before overflow, solid defence, almost never attacks, transports between own planets. | Fat, defended target; drives the top of the economy ranking. |
| **Raider** | General | Cheap economy, heavy cargo + light/heavy fighters, constant espionage sweeps, farms inactives and weak targets, recycles debris. | Makes the universe feel dangerous; creates debris and battle reports. |
| **Explorer** | Discoverer | Astrophysics rush, permanent expeditions, many small colonies, mid economy. | Fills the galaxy map with colonies; occasional expedition-fuelled spikes. |
| **Turtle** | any | Enormous defence, minimal fleet, never attacks, rebuilds defence obsessively. | Punishes careless attackers; a "do not touch" landmark. |
| **Fleeter** | General | Saves resources into fleet, fleetsaves reliably, hunts high-value targets, uses ACS with alliance mates. | The scary top-10 account; the endgame rival. |
| **Casual** | any/none | Logs in 1–2× a day, builds suboptimally, forgets to fleetsave, leaves resources unspent. | **The farm the solo player wants.** Deliberately beatable. |
| **Trader** | Collector | Heavy merchant use, moves resources for allies, low military. | Economy texture; alliance glue. |
| **Ghost** | — | Registered, played briefly, then stopped forever. Ticks never run; drifts into `(i)` then `(I)`. | Realistic dead-account texture and free farms. |
| **Vacationer** | any | Periodically enters real vacation mode for days. | Explains gaps; teaches the vacation-mode UI. |

A spawn command takes a **universe mix** (e.g. `--mix=default` → 25% Casual, 20% Miner, 15% Raider,
15% Explorer, 10% Ghost, 8% Turtle, 5% Fleeter, 2% Fleeter-elite), so the population feels like a
real server rather than 500 clones.

---

## 6. Believability checklist

This is the list that separates "a script that builds mines" from "an account you'd swear is a person".

### 6.1 Identity
- [ ] Names from the existing `CreateNewUser` generator, plus persona-flavoured pools; some all-lowercase, some with numbers, some clan-tagged. Never a detectable pattern.
- [ ] `created_at` spread over weeks/months, not all at spawn time. Older accounts get proportionally more progress.
- [ ] Planet names renamed from the default (`Colony`, `Mine 2`, persona-flavoured names); a few bots never rename anything (that is realistic too).
- [ ] Character class chosen to match persona; a few bots leave it unset (like real new players).
- [ ] Language/locale varies across bots.
- [ ] Placement: seed across galaxies via `determineNewPlanetPosition()`, **plus** a configurable bias toward systems near real players so a solo player's neighbourhood is not empty.

### 6.2 Rhythm — the biggest single believability lever
- [ ] Per-bot timezone + wake/sleep window (e.g. 07:00–00:30 local); no actions while asleep.
- [ ] Sessions, not polling: 2–8 sessions/day, each a burst of 1–15 actions over a few minutes, then hours of silence.
- [ ] Weekday/weekend differences; occasional day-long absences; rare multi-day vacation mode.
- [ ] Every timestamp jittered — never act on exact minute boundaries or fixed intervals.
- [ ] Reaction latency to events is drawn from a human distribution (minutes to hours), and is sometimes **infinite** (the bot was asleep and just lost its fleet). Never react faster than the bot-detection `attack_reaction_seconds` threshold.
- [ ] Long-running queues continue while "offline" — which is exactly how a real absent player looks.
- [ ] A slow drift: some bots gradually reduce activity and eventually become Ghosts.

### 6.3 Knowledge and mistakes
- [ ] Espionage before attacking, always. Attack decisions read `bot_intel`, never live planet rows.
- [ ] Intel decays: confidence drops with age; bots act on stale data and get surprised.
- [ ] Skill-scaled errors: mis-sized attack fleets, forgetting to fleetsave, over-building defence, letting storage overflow, colonising a bad slot, sending too few cargos to carry the loot.
- [ ] Bots occasionally cancel or re-order their own queue (visible as human indecision).
- [ ] Bots lose fleets and *recover* — rebuild, harvest their own debris, repair from the wreck field.

### 6.4 Social presence — actions only, no words

Bots are **silent** (§13.4): they never send messages and never post in chat, in either direction.
A human who writes to a bot gets no reply. Since bots are openly marked, this is an accepted and
visible limitation rather than a tell to be hidden. Everything social is therefore expressed through
*behaviour* instead of text:

- [ ] Alliances: bots found alliances, invite each other, accept or reject human applications, and leave alliances after conflicts — all through `AllianceService`, with no accompanying messages.
- [ ] ACS defends and joint attacks between allied bots via `FleetUnionService`.
- [ ] `bot_memory` gives grudges and friendships continuity: a bot that was farmed remembers who did it and expresses that by attacking back, refusing an alliance application, or joining an ally's ACS against them.
- [ ] Buddy requests from humans are accepted or ignored according to persona and attitude (`BuddyService`), which is a social signal that needs no prose.
- [ ] Whatever notification messages the *engine* generates on a bot's behalf (fleet arrival, battle reports, alliance events) still flow normally — those are system messages, not bot-authored text.

> If you later want bots to talk, the hook is a `PhraseProvider` interface plus phrase banks in
> `resources/lang/*/bots.php`. Not building it now; noted so the seam is left in the right place.

### 6.5 Military behaviour
- [ ] Targeting from intel: expected loot, distance/deut cost, defence estimate, grudges, cooldowns.
- [ ] Fleetsave when a hostile fleet is detected (bots can see incoming fleets exactly as players do, via `getActiveMissionsByPlanetIds()` / `currentPlayerUnderAttack()`) — with persona- and skill-dependent reliability.
- [ ] Recyclers dispatched to debris fields after battles (their own and, opportunistically, others').
- [ ] Defence rebuilding, wreck-field repair, revenge attacks, or relocating after repeated losses.
- [ ] Moon usage: phalanx scans as an intel source; jump gate for fast fleet redeployment.
- [ ] ACS attacks and defends between allied bots.
- [ ] Expedition slots used continuously by Explorer/Discoverer personas.

---

## 7. Fairness and anti-frustration

Because the engine does not enforce noob protection (§2.6), the bot layer owns it. All values are
server settings, editable in the admin panel:

- `bots_min_target_points` — never attack a human below N points.
- `bots_max_attacks_per_human_per_day` and `bots_raid_cooldown_hours` per (bot, target) pair.
- `bots_max_loot_fraction` — cap how much of a human's stock a single raid takes.
- `bots_respect_vacation` — already enforced by `isMissionPossible`, but assert it in tests.
- `bots_farm_quota` — guarantee that at least N low-defence, farmable bots exist within X systems of
  each human player, so a solo player always has targets.
- Escalation control: bots do not gang up — a global limit on simultaneous hostile fleets aimed at a
  single human.
- A "grace period" after a human registers during which no bot attacks them at all.

---

## 8. Scale and performance

Population is a config value (`bots.population`), **defaulting to 200**, so a server owner can scale
from a quiet 50 to a full 500+ without code changes. The LOD work below is built regardless of the
starting number, so raising the setting later needs no rework.

Measured costs: each `PlanetService::update()` is a `lockForUpdate` transaction touching the building
queue, resources, unit queue, production and storage. At 200 bots × ~3 planets that is ~600 such
transactions per full sweep, and 1500 at 500 bots — too much to do every minute, and pointless when
nobody is watching.

**Level of detail (LOD), mirroring the game's own lazy-update philosophy:**

| LOD | Who | Cost |
|---|---|---|
| **Full** | Bots inside a human's observation radius (same/adjacent systems, recently scanned, recently interacted with, in a shared alliance) or with an active fleet mission. | Full tick as described. |
| **Abstract** | Distant bots. Economy advanced analytically at a coarse interval (e.g. hourly), decisions simplified, no per-planet locking. | ~an order of magnitude cheaper. |
| **Dormant** | Ghosts and sleeping bots. | One indexed row read, nothing else. |

**Lazy materialisation:** when a human observes a bot (galaxy view, espionage, incoming attack,
highscore drill-down), the bot is promoted to Full and a catch-up tick runs first, so the human always
sees a consistent, fully-simulated account. This is precisely the pattern `GlobalGame` already uses
for humans, applied from the observer's side.

Other measures: index `bot_profiles.next_action_at`; per-tick action budget; `--limit` on the tick
command; `withoutOverlapping()`; queue-depth backpressure that skips a sweep if the worker is behind;
a `ogamex:bots:pause` kill switch; metrics logged per sweep (bots ticked, actions taken, ms spent).

---

## 9. Correctness details that need explicit handling

1. **Console context.** `PlayerService::update()` calls `request()->ip()`. Under a queue worker this
   must not write nonsense into `last_ip`. Add a bot-aware synchroniser (`BotSynchroniser`) that
   performs the same three steps as `GlobalGame` but with bot-appropriate values — including a stable
   synthetic per-bot IP so the admin's shared-IP grouping is not polluted with one giant cluster.
2. **`user->time` is the activity flag.** `isInactive()` (7d) and `isLongInactive()` (28d) derive from
   it, and drive the `(i)`/`(I)` markers in galaxy view. Active bots must bump it *only during their
   awake window*; Ghosts must never bump it. Getting this wrong makes every bot look permanently
   online, which is the fastest possible giveaway.
3. **Player-scoped services.** `FleetMissionService` is constructed with a `PlayerService`
   (`resolve(FleetMissionService::class, ['player' => $bot])`). Resolving the container singleton
   inside a job would silently use the wrong player. Every bot action must build its services against
   the bot's own player service.
4. **Locking.** Bot ticks and human page loads can touch the same rows (a human attacking a bot while
   the bot ticks). Existing `lockForUpdate` transactions cover the core, but the tick itself needs an
   advisory lock per bot, short transactions, and deadlock-retry.
5. **Time travel in tests.** `Carbon::setTestNow()` plus the action log makes multi-day behaviour
   testable in milliseconds.
6. **Bots must be excluded** from: the admin bot-detection panel, "real player count" statistics, and
   any registration/multi-account checks. They must be **included** in: highscores, galaxy view,
   alliance rankings, search.
7. **Marking (§13.1).** A `User::isBot()` helper plus an NPC icon/badge in galaxy view and the
   highscore table. This is the one place the feature touches core views rather than only adding
   files — see `GalaxyController::…` player payload (which already assembles `isNewbie`/`isStrong`
   flags for display, so the pattern exists) and the highscore views. Keep it to a single shared
   partial so it is easy to find and revert.

---

## 10. Implementation plan

Phases are independently shippable; each ends with a server that still works.

### Phase 0 — Groundwork
1. `config/bots.php` with global tunables and the persona registry.
2. Migrations: `bot_profiles`, `bot_intel`, `bot_memory`, `bot_action_log` (+ index on `next_action_at`).
3. Models `BotProfile`, `BotIntel`, `BotMemory`, `BotActionLog` with a `User::botProfile()` relation and a `User::isBot()` helper.
3a. **Marking:** NPC badge in galaxy view and highscores, driven by `User::isBot()`, as a single shared
    view partial (§9.7). Ship this in Phase 0 so no bot is ever visible to a player unmarked.
4. `BotSynchroniser` — the console-safe equivalent of `GlobalGame` (player update, per-planet update, fleet missions, planet moves), with correct `time`/`last_ip` handling.
5. `ogamex:bots:spawn` — creates accounts + homeworlds via `PlanetServiceFactory`, backdated `created_at`, persona assignment from a `--mix`, name generation. Modelled on `PreviewSeedUsers`.
6. `ogamex:bots:despawn` — clean removal (`PlayerService::delete()` + profile rows), with `--persona` / `--all` filters.
7. Feature test: spawn 10 bots, assert accounts, planets, tech rows and profiles exist and the game still renders (`Http200Test` style).

### Phase 1 — A living economy bot
8. `BotTickCommand` (`ogamex:bots:tick`) + `BotTickJob` + per-bot cache lock; register in `routes/console.php` as `->everyMinute()->withoutOverlapping()`.
9. `ActivityProfile`: timezone, wake/sleep, session model, jitter, `next_action_at` scheduling.
10. `BotBrain` skeleton: stance machine, candidate generation, scoring, action budget, decision logging.
11. Economy actions: `BuildBuildingAction`, `ResearchAction`, `BuildDefenceAction`, `BuildShipAction` — each calling the existing queue services and handling their exceptions.
12. Utility functions: mine payback time, energy deficit, storage overflow risk, tech prerequisites, field limits.
13. Personas **Miner**, **Casual**, **Ghost**.
14. Tests: with `setTestNow`, run a Miner for 7 simulated days and assert mine levels rise, resources never overflow for long, and no illegal actions were logged.

### Phase 2 — Movement and war
15. `BotFleetService` wrapper: build a `UnitCollection`, pick speed, respect fleet slots, call `FleetMissionService::createNewFromPlanet()`.
16. Actions: `TransportAction` (between own planets), `ExpeditionAction`, `ColoniseAction` (astrophysics-gated), `RecycleAction`.
17. `EspionageAction` + report parsing into `bot_intel`.
18. `RaidAction`: target selection from intel, expected-loot model, cargo sizing (with skill-scaled errors), cooldowns, fairness caps.
19. `FleetsaveAction`: detect inbound hostiles, react with persona/skill-dependent reliability and human latency.
20. `DefendAction` / `RebuildAction`: post-battle defence rebuild, wreck-field repair, own-debris harvest.
21. Personas **Raider**, **Explorer**, **Turtle**, **Fleeter**.
22. Tests: a Raider farms a seeded inactive account; a Fleeter successfully fleetsaves against an incoming human attack; fairness caps are respected; a bot loses a battle and recovers.

### Phase 3 — Perception, memory, mistakes
23. `BotPerception`: galaxy scanning as an action writing low-confidence intel; phalanx as an intel source for moon-owning bots.
24. Intel confidence decay; explicit "act on stale data" paths.
25. `bot_memory`: attitudes, grudges, friendships; grudge-driven revenge attacks.
26. Skill model: per-persona error injection across all action types.
27. Tests: assert a bot with only stale intel attacks into a defence it did not know about, and that a high-skill bot re-scouts first.

### Phase 4 — Alliances (no messaging)
Substantially smaller than originally scoped, because bots are silent (§13.4).
28. Alliance behaviour: found, invite other bots, accept/reject human applications, leave after conflict — via `AllianceService`, attitude-driven from `bot_memory`.
29. ACS defend and joint attack between allied bots via `FleetUnionService`.
30. Buddy request handling (`BuddyService`): accept or ignore by persona and attitude.
31. Assert the silence invariant in code and tests: no code path lets a bot create a `Message` or `ChatMessage`. Leave the `PhraseProvider` seam unimplemented but documented.
32. Tests: alliance lifecycle works end to end; a grudge in `bot_memory` causes an application rejection and an ACS join against the offender; a human message to a bot produces no reply and no error.
33. *(Freed capacity)* Steps saved here are best spent on Phase 3 tuning and the simulation harness (step 41), which is where believability is actually won.

### Phase 5 — Scale
34. LOD classification (Full/Abstract/Dormant) with an observation-radius calculation.
35. Abstract economy advancement for distant bots.
36. Lazy materialisation on human observation (galaxy view, espionage, incoming mission, search).
37. Backpressure, `--limit`, metrics, `ogamex:bots:pause`.
38. Load test: 500 bots on the dev docker stack; record sweep duration and query counts; set defaults from the measurements.

### Phase 6 — Tooling, admin, tuning
39. Admin panel section: list bots, filter by persona, spawn/despawn, edit persona, force tick, inspect the decision log.
40. Exclude bots from the bot-detection panel and real-player statistics.
41. `ogamex:bots:simulate` — headless universe simulation over N simulated days, reporting points distribution, activity histograms, attack counts, fairness metrics, and per-persona divergence. This is the tuning instrument.
42. `ogamex:bots:inspect {user}` — dump a bot's stance, goals, intel and recent decisions.
43. Scheduled `bot_action_log` pruning.
44. Documentation: server-owner guide (how to spawn a universe, tune personas, safety switches) and a developer guide (how to add a persona or an action).
45. PHPStan level compliance, Pint formatting, and CI green — the repo enforces all three.

---

## 11. Testing and tuning

- **Unit:** scoring functions, intel decay, activity scheduling, persona config validation.
- **Feature:** each phase's behavioural assertions above, using `AccountTestCase` patterns and `Carbon::setTestNow()`.
- **Invariant tests (run across every persona):** a bot never performs an action a human could not;
  never acts while asleep; never exceeds fleet slots; never breaches fairness caps; never throws out
  of a tick.
- **Simulation harness (step 41):** the only practical way to judge "believable". Run 30 simulated
  days, then look at the distributions — if every bot has the same points curve, the personas are not
  distinct enough; if activity is uniform across the clock, the rhythm model is wrong.
- **Human test:** show a colleague the galaxy view and highscores and ask them to pick the bots.

---

## 12. Risks

| Risk | Mitigation |
|---|---|
| Bots feel robotic despite the effort | Rhythm (§6.2) and mistakes (§6.3) matter more than smart play. Prioritise them over optimisation quality. |
| Performance collapse at scale | LOD + lazy materialisation from Phase 5; measure before setting defaults. |
| Bots bully the humans the server exists for | Fairness caps (§7) implemented in Phase 2, not deferred. |
| Merge conflicts pulling from upstream OGameX | Private fork, so core files *may* be touched — but keep it additive under `app/Bots/` wherever that costs nothing, and confine unavoidable core edits (the NPC badge, bot-detection exclusion) to small, clearly-marked partials so `git pull` conflicts stay trivial. |
| Naming/concept confusion with expedition NPCs | Distinct `Bots` namespace and vocabulary from day one. |
| Silence is noticeable | A human writing to a bot gets nothing back. Accepted: bots are marked, so this reads as "it's an NPC" rather than as a broken player. Revisit via the `PhraseProvider` seam if it grates. |
| Marked bots get treated as scenery | Uncapped highscores plus real fog-of-war behaviour (Phase 3) keep them worth engaging with rather than just farming. |

---

## 13. Decisions taken

1. **Labelling — bots are marked in-game.** An NPC badge next to bot names in galaxy view and the
   highscore table (§9.7, step 3a). Shipped in Phase 0 so a bot is never visible to a player
   unmarked. Consequence: the goal is *plausible*, not *undetectable* — which is why §6.2 (rhythm)
   and §6.3 (fog of war and mistakes) still carry the whole feature.
2. **Highscores — uncapped.** Bots progress freely and may hold #1. Exposed as a server setting so
   it can be revisited without code changes.
3. **Population — configurable, default 200.** `bots.population` in `config/bots.php`, with the
   default persona mix from §5. The LOD work in Phase 5 is built regardless of the starting number,
   so scaling to 500+ later is a settings change.
4. **Bots are silent.** No bot-authored messages and no chat, in either direction; a human writing to
   a bot gets no reply. Phase 4 shrinks to alliance and buddy mechanics (§6.4). The `PhraseProvider`
   seam is documented but not implemented, so this is reversible later.
5. **No LLM integration.** Moot given (4) — there is no dialogue to generate. All behaviour stays
   deterministic and testable, with no external API dependency.
6. **Private fork, upstream-quality standards.** Core files may be edited where that is genuinely
   simpler, but the code still targets the project's CI bar: PHPStan clean, Pint-formatted, and
   covered by tests (step 45). Additive-under-`app/Bots/` remains the default wherever it costs
   nothing, and unavoidable core edits are kept to small marked partials to keep upstream pulls cheap.
7. **Full behavioural realism retained.** Phase 3 (perception, intel decay, memory, skill-scaled
   mistakes) is built in full despite the marking decision. Marking tells a player *that* an account
   is a bot; it does not make an omniscient one interesting to play against.
