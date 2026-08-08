# Running NPC players

Practical guide for server owners. For the design see [`npc-players-plan.md`](npc-players-plan.md);
for what is built and what is not, [`npc-status.md`](npc-status.md).

NPCs are computer-controlled accounts that play through the same services a human does. They are
**openly marked** with an `NPC` badge in the galaxy view and the highscores.

---

## Quick start

```bash
# 1. In .env
BOTS_ENABLED=true
BOTS_POPULATION=200

# 2. Apply the migrations (creates the four bot_* tables)
docker compose exec ogamex-app php artisan migrate

# 3. Register your own account in the browser first - see the note below

# 4. Create the population
docker compose exec ogamex-app php artisan ogamex:bots:spawn --count=20
```

### Three things that trip people up

**Enabling is not spawning.** `BOTS_ENABLED=true` only lets existing NPCs act. `BOTS_POPULATION`
is just the default count *for the spawn command*. Until you run `ogamex:bots:spawn`, there are no
NPC accounts and nothing will appear anywhere.

**Register your own account before spawning.** 35% of NPCs are placed near human players. With no
humans in the database yet they all scatter at random and your own neighbourhood stays empty.

**Check the highscore page first, not the galaxy.** Every NPC appears in the rankings immediately
regardless of where it landed. The galaxy view only shows the system you are looking at.

To confirm the spawn worked:

```bash
docker compose exec ogamex-app php artisan ogamex:bots:inspect
```

---

## Fresh or developed

By default an NPC registers **exactly like a human**: one homeworld, 500 metal, 500 crystal, no
buildings, no technology, no ships. Everything it owns after that, it built itself.

```bash
php artisan ogamex:bots:spawn --count=20              # fresh (default)
php artisan ogamex:bots:spawn --count=20 --developed  # start with materialised progress
```

Fresh is the honest option and rules out a whole class of bugs, but it is slow: NPCs grow at the
same pace a human does, so a newly seeded server is 200 identical level-zero accounts with a flat
highscore and nothing worth raiding. Measured here, a fresh Miner reaches mine level 22 and a
research lab after ten days.

`--developed` writes the state a plausible account of that age would have reached, so the universe
looks months old immediately. A reasonable middle is to seed once with `--developed` for a
backdrop, then add fresh NPCs over time.

`BOTS_SPAWN_DEVELOPED=true` flips the default.

---

## Day to day

```bash
php artisan ogamex:bots:inspect              # list every NPC: persona, skill, stance, next turn
php artisan ogamex:bots:inspect Nebula42     # one NPC in full: empire, decisions, intel, grudges
php artisan ogamex:bots:log --follow         # every decision the population takes, as it happens
php artisan ogamex:bots:tick --user=123      # force a turn now, ignoring its schedule

php artisan ogamex:bots:pause                # stop all turns immediately, no deploy needed
php artisan ogamex:bots:pause --resume
php artisan ogamex:bots:pause --status       # paused? plus last sweep timing and queue depth

php artisan ogamex:bots:despawn --all --force        # remove everything
php artisan ogamex:bots:despawn --persona=raider     # remove one playstyle
```

`pause` lifts itself after 24 hours, so a pause set during an incident cannot silently freeze the
universe forever.

The scheduler and queue containers run the NPCs automatically; there is nothing to start.

### When an NPC does something strange

`ogamex:bots:inspect <name>` is the first stop. It shows the last decisions with the score and the
reasoning that produced them, what the NPC currently believes about its neighbours and how stale
that belief is, and how it feels about everyone it has met. Between those three, most odd behaviour
explains itself.

---

## The decision log

Every decision an NPC takes is recorded — what it did, how strongly it wanted to, why, and whether
the game accepted it. `inspect` answers *what is this one NPC doing*; the log answers *what is the
population doing*, which is usually the question you actually have.

```bash
php artisan ogamex:bots:log                       # the last 200 decisions, oldest first
php artisan ogamex:bots:log --follow              # watch the universe play itself
php artisan ogamex:bots:log --failed --since=24h  # what the game rejected today, and why
```

```
2026-08-08 22:33:37  hubble1989  casual  build_building  ok    3.92  solar_plant 9, energy deficit -164
2026-08-08 22:33:38  hubble1989  casual  espionage       ok    2.00  scout 6:111:7 with 2 probes
2026-08-08 22:33:38  Storm_Fall  miner   build_defence   ok    0.20  2x rocket_launcher
```

Filters combine: `--user=Nebula42` (id or name), `--persona=raider`, `--action=raid --action=espionage`,
`--failed`, `--since=90m|12h|7d|<date>`, `--limit=N` (`0` for everything).

**Failures are the interesting rows.** A `FAIL` line means an NPC proposed something the game
refused, and the line carries the exception instead of the reason. A handful is normal — a fleet
slot filled between deciding and acting. A steady stream of the same one is a bug.

### Exporting

```bash
php artisan ogamex:bots:log --limit=0 --format=csv --out=storage/app/bots-week.csv
php artisan ogamex:bots:log --format=json | jq 'select(.action == "raid")'
```

`--format=json` emits one object per line, `--format=csv` a header plus rows. The summary line goes
to stderr, so piping to `jq` or redirecting to a file gives clean data. `--out` appends rather than
overwrites, so exporting twice does not destroy the first export.

Retention on the table is `BOTS_ACTION_LOG_RETENTION_DAYS` (14 by default) — export before that
window closes if you want to keep a record.

### The log file

The same decisions are also written to `storage/logs/bots.log` as they happen, rotated daily and
kept for `BOTS_LOG_DAYS`. The project directory is bind-mounted into the containers, so this works
from the host with no `docker compose exec`:

```bash
tail -f storage/logs/bots.log
grep raid storage/logs/bots.log
```

Set `BOTS_LOG_FILE=false` to turn it off. Around 200 NPCs produce roughly a thousand lines a day.
The table is written either way — turning the file off costs you `tail`, not the log.

---

## Protecting your players

The game engine does **not** enforce newbie protection — `isNewbie()` and `isStrong()` only affect
how the galaxy view is drawn. Everything that stops NPCs farming a human out of the game lives in
`config/bots.php` under `fairness`, and is the only protection your players have:

| Setting | Default | Effect |
|---|---|---|
| `BOTS_NEW_PLAYER_GRACE_DAYS` | 7 | Newly registered humans are never attacked |
| `BOTS_MIN_TARGET_POINTS` | 5000 | Humans below this are never attacked |
| `BOTS_MAX_ATTACKS_PER_HUMAN_PER_DAY` | 3 | Across all NPCs combined |
| `BOTS_RAID_COOLDOWN_HOURS` | 8 | Between one NPC hitting the same human twice |
| `BOTS_MAX_SIMULTANEOUS_ATTACKS_PER_HUMAN` | 2 | NPCs cannot gang up |

NPC-on-NPC raiding is deliberately unrestricted.

---

## Tuning the population

`ogamex:bots:simulate` runs the universe forward in simulated time — a week passes in a few
minutes — and reports how the population behaved. It is the only practical way to see properties
that emerge over weeks rather than ticks.

```bash
php artisan ogamex:bots:simulate --days=7 --step=1
```

> **Development copies only.** It advances the application clock, which writes future timestamps
> onto every planet it touches. On a live server those planets sit frozen until real time catches
> up. The command refuses to run outside `local` and `testing` for that reason.

What to look for in the output:

- **Activity by hour** should have visible peaks and quiet periods. A flat line means the timezone
  spread is not working and every NPC is effectively always online. Use `--step=1`; larger steps
  alias the histogram and leave empty hours that are an artefact, not a finding.
- **Decision share by persona** is the headline. If two personas' rows look alike they are the same
  playstyle wearing different names — pull their `focus` weights apart in `config/bots.php`.
- **Failed actions** should be zero. Every one is an NPC proposing something the game rejected.
- **Average score** across actions should be within roughly a factor of two. If one action's
  average is far above the rest, its scoring is on the wrong scale and it will win every slot it
  is offered — see section 4a of the status document.

---

## Scale

The default of 200 is comfortable. A turn costs about a second, and in steady state each NPC takes
3–8 turns a day, so 300 NPCs is roughly 25 minutes of worker CPU spread across a day.

Going much larger: raise `BOTS_ABSTRACT_GAP_MULTIPLIER` before `BOTS_MAX_PER_SWEEP`. Ticking
distant NPCs less often is free; ticking more of them per minute is not. `--status` shows whether
sweeps are keeping up.
