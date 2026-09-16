# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum`,
`opcache` → `OPCache`, and `dotenv` → `Env` are existing exceptions the guess
gets wrong).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks all ~40
  `CLAUDE.md` copies as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. `ez-php/mail`'s Mailpit service is the one other module with published host ports: SMTP `1025` and web UI `8025`, mapped through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above), documented in `modules/mail/.env.example`. It isn't a table column because no other module runs Mailpit, so there is nothing to collide with — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/event-store

Append-only event store with stream replay and projections for event
sourcing. Attached to the monorepo as a git submodule
(`git@github.com:ez-php/event-store.git`) — the repository was empty (a bare
README) when attached, so this module's file set and implementation were
scaffolded directly into the submodule's own working tree, by explicit user
decision, rather than left for a follow-up in that repository.

> This file is the module-specific half. The coding guidelines above it are
> generated by `sync_guidelines.php` — run `composer guidelines:sync` from the
> monorepo root to fill them in. Never edit that part by hand.

---

## Source Structure

```
src/
├── DomainEvent.php                — Contract for an event appended to a stream (eventType(), payload())
├── StoredEvent.php                — Value object: an event as read back, with stream/version/occurredAt metadata
├── EventStoreInterface.php        — append(), load(), getVersion() contract
├── PdoEventStore.php              — PDO-backed EventStoreInterface implementation (auto-creates its table)
├── EventStoreException.php        — Base exception (extends RuntimeException; carve-out base per CLAUDE.md §Coding Standards)
├── ConcurrencyException.php       — Thrown by append() on a stale expectedVersion
├── EventStoreServiceProvider.php  — Binds EventStoreInterface to PdoEventStore via DatabaseInterface
├── AppendToStreamListener.php     — ez-php/events ListenerInterface bridge; appends a dispatched DomainEvent to a resolved stream (soft dependency on ez-php/events — require-dev only)
└── Projection/
    ├── ProjectorInterface.php     — project(StoredEvent): void contract for read-model builders
    └── Projectionist.php          — Replays a stream's events into one or more ProjectorInterface instances

tests/
├── TestCase.php                            — Base PHPUnit test case (trivial passthrough)
├── StoredEventTest.php                     — StoredEvent constructor/property behavior
├── PdoEventStoreTest.php                   — append/load/getVersion, stream isolation, concurrency conflicts
├── EventStoreServiceProviderTest.php       — register() binding, resolution wiring to DatabaseInterface
├── AppendToStreamListenerTest.php          — handle() appends DomainEvent+EventInterface fixtures to the resolved stream; ignores events that don't implement DomainEvent
├── Projection/
│   └── ProjectionistTest.php               — replay() ordering, multi-projector fan-out, fromVersion checkpoint
└── Support/
    ├── RecordedEvent.php                   — Minimal DomainEvent stub used across tests
    └── EventStoreFakeContainer.php         — Minimal ContainerInterface stub for EventStoreServiceProviderTest
```

---

## Key Classes and Responsibilities

- **`PdoEventStore`** — the only `EventStoreInterface` implementation. Appends
  are transactional: it reads the stream's current version, compares it to an
  optional `expectedVersion`, and inserts inside the same transaction, relying
  on a `UNIQUE (stream_id, version)` constraint as a last line of defence
  against a genuine race between two transactions that both pass the version
  check. Follows the same `ensureTable()` auto-DDL pattern as
  `ez-php/audit`'s `AuditLogger` — SQLite DDL for tests, MySQL DDL for
  production, created lazily and cached per-instance via a boolean flag.
- **`Projectionist`** — the only mechanism for turning a stored stream into
  read-model state. Stateless and unopinionated about scheduling: callers
  decide when to call `replay()` and where to persist a projector's
  checkpoint (`fromVersion`) between calls.
- **`EventStoreServiceProvider`** — binds `EventStoreInterface` lazily inside
  the binding closure. Unlike `AuditServiceProvider`, it does **not**
  swallow a missing `DatabaseInterface` binding in a try/catch — an event
  store that silently no-ops on every append would corrupt an application's
  event history without any signal, which is a materially worse failure mode
  than audit logging silently being off.
- **`AppendToStreamListener`** — the glue this module's README promised
  ("composes naturally with `ez-php/audit`") but didn't ship. Implements
  `EzPhp\Events\ListenerInterface`; requires the application's event class to
  implement both `EventInterface` (dispatchable) and `DomainEvent`
  (appendable) — events that only implement `EventInterface` are silently
  ignored, mirroring `ez-php/audit`'s `AuditListener`. The application
  registers it explicitly (`$dispatcher->listen(SomeEvent::class, new
  AppendToStreamListener(...))`); it is not auto-wired by
  `EventStoreServiceProvider`, since the stream-id resolution closure is
  necessarily application-specific.

---

## Design Decisions and Constraints

- **No migration file is shipped.** `PdoEventStore::ensureTable()` creates
  `event_store_events` on first use, exactly like `ez-php/audit`'s
  `AuditLogger::ensureTable()`. The class doc on `PdoEventStore` documents the
  production (MySQL) DDL for applications that want a real migration instead.
- **Optimistic concurrency, not pessimistic locking.** `append()` takes an
  optional `expectedVersion` rather than requiring one, because not every
  stream is concurrently written (e.g. a single background worker owns it).
  Streams that *are* contended should always pass it.
- **Events are `array<string, mixed>` payloads, not typed objects, once
  stored.** `StoredEvent` deliberately does not try to reconstruct the
  original `DomainEvent` class — a stream must remain replayable even after
  the PHP class that produced an old event has been renamed or removed
  (schema evolution). Callers that need typed reconstruction do it themselves
  from `eventType` + `payload` (e.g. a `match` in a projector).
- **No event upcasting/versioning mechanism.** Out of scope for this first
  pass — payload shape changes are the calling application's problem for now.
  A dedicated `EventUpcaster` concept is a plausible future addition once a
  real need shows up (YAGNI).
- **No snapshotting.** Long streams are replayed in full on every `load()`.
  Acceptable for a first pass; a snapshot store would be a separate,
  composed concern, not a change to `EventStoreInterface`.

---

## Testing Approach

- Tests run against `PDO('sqlite::memory:')`, exactly like
  `ez-php/audit`'s `AuditLoggerTest` — no MySQL container needed for the unit
  suite. `docker-compose.yml` still provisions MySQL (`ez-php-event-store-db`,
  host port `3311`) for manual/integration use against the production DDL
  path in `ensureTable()`.
- Test classes live in the shared `Tests\` namespace but must be uniquely named
  across the whole monorepo — the root `phpunit.xml` loads every package in one
  process, so a duplicate name is a fatal error, not a test failure. Prefix with
  `EventStore` when the obvious name is already taken.
- **Helper classes need the same uniqueness discipline, not just `*Test`
  classes.** The shared `Tests\` PSR-4 prefix maps to every module's `tests/`
  directory in one array in the root `composer.json`; Composer's autoloader
  resolves a class to the *first* directory in that array containing a
  matching file, regardless of which module's test actually referenced it.
  `tests/Support/EventStoreFakeContainer.php` is named with the module prefix
  for exactly this reason — a `Tests\Support\FakeContainer` here would have
  silently resolved to a different module's `FakeContainer` (different
  constructor signature) in the aggregated root run instead of this one.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Synchronous, non-persisted event dispatch within a single request | `ez-php/events` |
| Flat audit trail of entity CRUD lifecycle events | `ez-php/audit` |
| Async job execution / queued side effects triggered by an event | `ez-php/queue` |
| Real-time push of events to connected clients | `ez-php/broadcast` |
| Multi-channel user notifications (mail, database, push) | `ez-php/notification` |