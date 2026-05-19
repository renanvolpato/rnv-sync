# Cirrus — Product Specification

> Complete specification for implementation. Read this fully before starting work.
> Contribution guidelines live in `CONTRIBUTING.md`.
>
> **Version:** 1.0
> **Status:** Implementation-ready
> **Last updated:** 2026-05-12

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Principles & Constraints](#2-principles--constraints)
3. [Personas & Use Cases](#3-personas--use-cases)
4. [Technical Architecture](#4-technical-architecture)
5. [Technology Stack](#5-technology-stack)
6. [Repository Structure](#6-repository-structure)
7. [Data Model](#7-data-model)
8. [rclone Integration](#8-rclone-integration)
9. [Features by Release](#9-features-by-release)
10. [UX & Design System](#10-ux--design-system)
11. [Installation & Distribution](#11-installation--distribution)
12. [Security](#12-security)
13. [Internationalization](#13-internationalization)
14. [Testing Strategy](#14-testing-strategy)
15. [Documentation Requirements](#15-documentation-requirements)
16. [CI/CD](#16-cicd)
17. [Defaults & Fallbacks](#17-defaults--fallbacks)
18. [v1.0 Acceptance Criteria](#18-v10-acceptance-criteria)
19. [Glossary](#19-glossary)

---

## 1. Project Overview

### What Cirrus Is

Cirrus is a self-hosted web application for Linux that gives users a beautiful, native-feeling interface to manage their OneDrive accounts. It bundles [rclone](https://rclone.org/) as its sync engine and adds:

- Visual configuration (no CLI required)
- Lifecycle management (systemd integration, auto-restart, health monitoring)
- Smart defaults tuned for OneDrive (rate limits, chunk sizes, cache strategy)
- Files-on-Demand UX comparable to OneDrive on Windows
- Multi-account dashboard
- Conflict resolution with visual aids
- Real-time progress and observability

The application runs locally on the user's machine, accessed via web browser at `http://localhost:8080`. It can optionally be exposed beyond localhost with explicit user opt-in and password protection.

### What Cirrus Is Not

- **Not a from-scratch OneDrive client.** Sync, mount, conflict resolution, and protocol handling are delegated to rclone.
- **Not a generic cloud storage manager** (in v1.0). OneDrive is the focused use case. Architecture allows extending to Google Drive, Dropbox, and others later.
- **Not a CLI replacement for rclone.** Power users who script with rclone CLI should keep doing so. Cirrus targets users who want a GUI.
- **Not an antivirus, backup tool, or file editor.** It manages sync; it does not process file contents.
- **Not a desktop GTK/Qt application.** It is a web app accessed in a browser, deployed locally.

### Why Cirrus Exists

The Linux ecosystem lacks a polished OneDrive client. Existing options:

- **abraunegg/onedrive** — robust CLI, no GUI, no Files-on-Demand.
- **onedriver** — has FUSE-based Files-on-Demand but lacks Business/SharePoint robustness and lacks a friendly UI.
- **rclone** — extremely powerful but pure CLI; no UI exists.
- **Insync** — proprietary, paid, closed-source.

Users coming from Windows expect a graphical, "just works" experience. Cirrus fills that gap by combining the best engine available (rclone) with the missing UX layer.

---

## 2. Principles & Constraints

### Design Principles

1. **One-command install.** From a fresh Linux machine to a working app should be a single command. No system dependencies beyond Docker (or PHP for native install).
2. **Sensible defaults; advanced where needed.** First-time users see 3-4 controls. Power users find a collapsible "Advanced" panel exposing rclone's full capability.
3. **Honest about being a wrapper.** README, About page, and credits prominently acknowledge rclone. Cirrus does not hide its dependency.
4. **No data leaves the user's machine.** OAuth tokens stored locally. No telemetry, no analytics, no phone-home in v1.0.
5. **Local-first, cloud-optional.** App runs entirely offline (configuration, viewing local state) except for actual sync operations.
6. **Beautiful but not flashy.** Modern, clean design. No dark patterns, no animation overuse. Tailwind defaults + Flux UI components.
7. **Bilingual from day one.** PT-BR and EN both first-class. Easy to add more languages via translation files.
8. **Self-hostable but single-user.** The app runs for one user at a time. No multi-tenant logic in v1.0.

### Non-Negotiable Constraints

- **SQLite only.** No MySQL, PostgreSQL, Redis, Memcached.
- **Livewire only.** No Inertia, no React, no Vue, no SPA.
- **rclone is bundled.** Pre-compiled binary shipped with the app. Not a system dependency.
- **No root required.** App runs entirely in user space. systemd user services, not system services.
- **Open source.** MIT license. Public repository on GitHub.
- **Linux-first.** Other OSes are not targeted in v1.0. Windows/Mac support not designed against.

### Trade-Offs Accepted

- Tied to rclone's roadmap and bug fixes. Mitigated by pinning version and testing upgrades.
- FUSE has overhead vs. native filesystem. Mitigated by VFS cache tuning.
- PHP runtime size compared to single-binary Go. Mitigated by Docker as primary distribution path.
- No file manager status icons (online vs. offline) in v1.0. Postponed to v1.x.

---

## 3. Personas & Use Cases

### Persona 1 — "Windows Refugee"

**Profile:** Designer/journalist/student who recently switched to Linux (Pop!_OS, Ubuntu, Mint). Used to OneDrive on Windows. Wants the same experience on Linux. Comfortable with Docker but not with editing config files.

**Goal:** Install something that gives them OneDrive sync that works like on Windows.

**Success:** Within 10 minutes of installing, sees their OneDrive folders, picks what to sync, sees files appearing in `~/OneDrive/`.

### Persona 2 — "Self-Hoster"

**Profile:** Linux enthusiast running a home server (Raspberry Pi, NAS, mini PC). Loves the awesome-selfhosted ecosystem. Curates dashboards via Heimdall/Homer/Dashy. Wants pretty self-hosted apps with web UIs.

**Goal:** Add OneDrive management to their self-hosted collection. Access from any device on their LAN.

**Success:** Deploys via Docker Compose, exposes to LAN behind their reverse proxy, manages OneDrive from any browser at home.

### Persona 3 — "Power User Who Wants Less Pain"

**Profile:** Already knows rclone CLI. Has set up rclone manually in the past. Tired of editing TOML files and reading docs for every change. Wants a UI without losing power.

**Goal:** Configure rclone for OneDrive via a UI, but retain the ability to drop into advanced options when needed.

**Success:** Reproduces their current rclone setup in Cirrus in under 15 minutes. Discovers the "raw rclone config" panel for edge cases.

### Persona 4 — "Family IT"

**Profile:** Sole tech-comfortable person in their family. Sets up Linux for parents/spouse. Needs solutions that "just work" without ongoing support.

**Goal:** Install Cirrus for a family member who uses OneDrive for documents. Set it and forget it.

**Success:** Initial setup takes 5 minutes. Family member never needs to touch CLI or config. Sync runs silently. Errors surface as readable messages, not crash logs.

### Key Use Cases

1. **First-time setup.** New user installs Cirrus, adds OneDrive account, picks folders, sees sync working.
2. **Add additional account.** User adds a second OneDrive account (e.g., Business alongside Personal). Manages both from one dashboard.
3. **Selective sync.** User chooses specific folders to sync, leaving the rest cloud-only.
4. **Free up disk space.** User has 5GB cached locally, wants to keep only some files offline. Clicks "Free up space" on specific files or folders.
5. **Keep file always offline.** User pins critical documents to always be available locally, even without internet.
6. **Bandwidth control.** User sets a daily bandwidth limit or schedule (no sync during work hours).
7. **Conflict resolution.** Same file edited locally and remotely. User sees conflict notification, picks which version to keep.
8. **Storage quota awareness.** User sees their OneDrive quota at a glance. Warning before quota is exceeded.
9. **Migration to new machine.** User exports Cirrus config from old machine, imports on new machine, sync resumes.

---

## 4. Technical Architecture

### High-Level Component Diagram

```
┌───────────────────────────────────────────────────────────────┐
│                         BROWSER                                │
│  (User on http://localhost:8080)                              │
└────────────────────────────┬──────────────────────────────────┘
                             │ HTTP + WebSocket
                             ▼
┌───────────────────────────────────────────────────────────────┐
│                    LARAVEL APPLICATION                         │
│                                                                │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐   │
│  │   Livewire   │  │   Reverb     │  │   Background     │   │
│  │  Components  │  │  (WebSocket) │  │   Job Queue      │   │
│  └──────┬───────┘  └──────┬───────┘  └────────┬─────────┘   │
│         │                  │                    │             │
│         └──────────────────┴────────────────────┘             │
│                            │                                   │
│  ┌─────────────────────────▼──────────────────────────────┐  │
│  │              Service Layer (app/Services/)              │  │
│  │  • Accounts/        • Sync/         • Mount/            │  │
│  │  • Rclone/          • Conflicts/    • Cache/            │  │
│  │  • Graph/           • Notifications/                    │  │
│  └─────────────────────┬──────────────┬────────────────────┘  │
│                        │              │                        │
│  ┌─────────────────────▼──┐   ┌──────▼──────────────────┐    │
│  │   SQLite (app state)   │   │  Microsoft Graph API    │    │
│  │   - accounts           │   │  (OAuth flow only)      │    │
│  │   - sync_folders       │   └─────────────────────────┘    │
│  │   - sync_history       │                                   │
│  │   - conflicts          │                                   │
│  │   - settings           │                                   │
│  └────────────────────────┘                                   │
│                                                                │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │       rclone (bundled binary, spawned subprocess)        │ │
│  │  - All sync, mount, and transfer operations             │ │
│  │  - Reads its own config from app-managed location       │ │
│  └─────────────────────────────────────────────────────────┘ │
└───────────────────────────────────────────────────────────────┘
                            │
                            ▼
                ┌─────────────────────────┐
                │ Microsoft OneDrive       │
                │ (via rclone)             │
                └─────────────────────────┘
```

### Layered Responsibilities

| Layer | Responsibility |
|---|---|
| **Browser** | Render UI, handle user input, receive real-time updates via WebSocket |
| **Livewire Components** | Page logic, form handling, optimistic UI updates |
| **Reverb (WebSocket)** | Push progress events, conflict notifications, status changes |
| **Background Jobs** | Schedule sync, refresh OAuth tokens, monitor rclone health |
| **Service Layer** | Business logic; the only layer allowed to invoke rclone or call Microsoft Graph |
| **SQLite** | App state (accounts, settings, history) |
| **rclone subprocess** | All cloud I/O — never bypassed |

### Process Model

- **Main app process:** Laravel via PHP-FPM (Docker) or `php artisan serve` (dev). Serves HTTP and Livewire.
- **Reverb process:** Long-running WebSocket server (`php artisan reverb:start`). Separate process.
- **Queue worker:** Long-running queue worker (`php artisan queue:work`). Separate process.
- **rclone processes:** Spawned on demand. `rclone mount` is long-running; `rclone copy/sync` is short-running. Each tracked by PID in DB.

In Docker, these run in one container managed by `supervisord`. For native install, they are systemd user units.

### Data Flow Examples

**Adding an account:**
1. User clicks "Add Account" → Livewire opens modal.
2. User clicks "Login with Microsoft" → Livewire calls `AccountsService::initiateOAuth()`.
3. Service generates state, opens new tab to Microsoft OAuth URL.
4. Microsoft redirects to `/oauth/callback?code=...`.
5. Callback handler exchanges code for token via rclone (`rclone authorize "onedrive"` for headless flow, or direct OAuth lib).
6. Token saved to `accounts` table, encrypted.
7. rclone config file updated with new remote.
8. Livewire refreshes account list via WebSocket event.

**Starting sync of a folder:**
1. User toggles a folder's "Sync" switch in UI.
2. Livewire calls `SyncService::enableFolderSync(folderId)`.
3. Service writes config to DB, starts background job `StartSyncJob`.
4. Job spawns `rclone mount` (if not running) and dispatches sync command.
5. As rclone produces JSON log events, a watcher process parses them and emits Reverb events.
6. UI receives events via WebSocket, updates progress bar in real time.

---

## 5. Technology Stack

All versions pinned. Upgrade only after testing.

### Backend

| Component | Version | Why |
|---|---|---|
| PHP | 8.3 | Latest stable with strong typing improvements |
| Laravel | 12.x | Long-term support, modern features |
| Livewire | 3.x | Server-rendered reactivity, fits Laravel mental model |
| Reverb | 1.x | First-party Laravel WebSocket server, no Pusher dependency |
| Pest | 3.x | Modern PHP test framework |
| Flux UI | Latest | Official Livewire component library |

### Frontend

| Component | Version | Why |
|---|---|---|
| Tailwind CSS | 4.x | Latest, JIT compiler, design tokens |
| Alpine.js | 3.x | Comes with Livewire, for small interactions |
| Vite | 6.x | Asset bundler, Laravel default |
| Heroicons | 2.x | Icon set |

### Data & Storage

| Component | Version | Why |
|---|---|---|
| SQLite | 3.40+ | File-based, no server required |
| Laravel Cache | file driver | No Redis needed for single-user app |
| Laravel Queue | database driver | Same — uses SQLite for jobs |

### Sync Engine

| Component | Version | Why |
|---|---|---|
| rclone | 1.67.0 (pinned) | Sync engine, bundled |

### Runtime

| Component | Version | Why |
|---|---|---|
| Docker | 24+ | Primary distribution method |
| Docker Compose | v2 | Orchestration |
| supervisord | Latest | Manages PHP-FPM, Reverb, queue workers, mount workers in container |

### CI/CD

| Component | Why |
|---|---|
| GitHub Actions | Automated tests, builds, releases |
| GitHub Container Registry | Docker image distribution (free, integrated) |
| GoReleaser | Not needed (we don't compile Go) — use simple bash scripts for releases |

---

## 6. Repository Structure

```
rnv-sync/
├── SPEC.md                      # This file
├── README.md                    # Public-facing intro
├── LICENSE                      # MIT
├── LICENSES/
│   └── rclone.txt               # MIT license of rclone, required for redistribution
│
├── docker/
│   ├── Dockerfile               # Multi-stage build (PHP + rclone + frontend)
│   ├── docker-compose.yml       # Public-facing compose for end users
│   ├── docker-compose.dev.yml   # Dev compose with hot reload
│   └── supervisord.conf         # Process supervision in container
│
├── install/
│   ├── install.sh               # Native install script (downloads rclone, sets up systemd)
│   ├── uninstall.sh             # Clean removal
│   └── systemd/                 # systemd user unit templates
│       ├── cirrus-web.service
│       ├── cirrus-queue.service
│       └── cirrus-reverb.service
│
├── app/                         # Laravel application code
│   ├── Console/Commands/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Middleware/
│   │   └── Requests/
│   ├── Livewire/
│   │   ├── Pages/               # Full-page components
│   │   ├── Forms/               # Form components
│   │   └── Modals/              # Modal components
│   ├── Models/
│   ├── Services/
│   │   ├── Accounts/            # Account management
│   │   ├── Rclone/              # rclone wrapper (binary path, command builder, output parser)
│   │   ├── Sync/                # Sync operations
│   │   ├── Mount/               # FUSE mount management
│   │   ├── Cache/               # VFS cache management (pin/unpin, free space)
│   │   ├── Conflicts/           # Conflict detection and resolution
│   │   ├── Graph/               # Microsoft Graph (only for OAuth and quota)
│   │   └── Notifications/       # In-app notifications
│   ├── Jobs/                    # Background jobs
│   ├── Events/                  # Reverb events
│   └── Providers/
│
├── bootstrap/
├── config/
│   ├── cirrus.php               # App-specific config (rclone version, paths, etc.)
│   └── ...laravel-default...
│
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
│
├── lang/
│   ├── en/
│   │   ├── accounts.php
│   │   ├── sync.php
│   │   ├── errors.php
│   │   └── ...
│   └── pt-BR/
│       └── ...same structure
│
├── resources/
│   ├── views/
│   │   ├── livewire/
│   │   ├── layouts/
│   │   └── emails/              # (if email notifications later)
│   ├── css/
│   │   └── app.css              # Tailwind entry
│   └── js/
│       └── app.js               # Alpine + small JS
│
├── routes/
│   ├── web.php
│   └── api.php
│
├── public/
├── storage/
├── tests/
│   ├── Feature/
│   ├── Unit/
│   └── Pest.php
│
├── docs/
│   ├── installation.md
│   ├── configuration.md
│   ├── troubleshooting.md
│   ├── architecture.md          # Developer documentation
│   └── images/
│
├── .github/
│   ├── workflows/
│   │   ├── tests.yml
│   │   ├── docker-build.yml
│   │   └── release.yml
│   ├── ISSUE_TEMPLATE/
│   └── PULL_REQUEST_TEMPLATE.md
│
├── .env.example
├── .gitignore
├── .editorconfig
├── composer.json
├── composer.lock
├── package.json
├── package-lock.json
├── vite.config.js
├── tailwind.config.js
├── phpunit.xml
└── artisan
```

### Critical `.gitignore` entries

```
.env
.env.backup
node_modules/
public/build/
storage/app/private/
storage/framework/cache/
storage/framework/sessions/
storage/framework/views/
storage/logs/
vendor/
*.sqlite
*.sqlite-journal
```

---

## 7. Data Model

All tables prefixed with `cirrus_` to avoid conflicts if user later imports another Laravel project's tables.

### `cirrus_accounts`

Represents an authenticated cloud account (OneDrive Personal, OneDrive Business, etc.).

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| name | string | Friendly name set by user (e.g., "My OneDrive") |
| provider | string | Enum: `onedrive_personal`, `onedrive_business`, `sharepoint`. Future: `gdrive`, `dropbox`. |
| remote_name | string | rclone remote identifier (sanitized) |
| email | string | Account email from OAuth response |
| oauth_token | text, encrypted | Full token JSON, Laravel `Crypt::encrypt` |
| status | string | `active`, `disconnected`, `error` |
| quota_total_bytes | bigint, nullable | Updated periodically |
| quota_used_bytes | bigint, nullable | Updated periodically |
| last_synced_at | timestamp, nullable | |
| created_at | timestamp | |
| updated_at | timestamp | |

### `cirrus_sync_folders`

Folders the user has chosen to sync.

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| account_id | bigint, FK → cirrus_accounts | |
| remote_path | string | Path on OneDrive (e.g., `/Documents`) |
| local_path | string | Where it lives locally (computed from mount point + remote_path) |
| sync_mode | string | `bisync`, `download_only`, `upload_only` |
| is_active | boolean | |
| last_synced_at | timestamp, nullable | |
| last_sync_status | string, nullable | `success`, `error`, `partial` |
| created_at | timestamp | |
| updated_at | timestamp | |

### `cirrus_file_policies`

Per-file/folder overrides for "always keep offline" or "online only". Sparse — only files explicitly set.

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| account_id | bigint, FK | |
| path | string | Full path within the account (e.g., `/Documents/important.pdf`) |
| is_directory | boolean | |
| policy | string | `always_offline`, `online_only`, `default` |
| created_at | timestamp | |
| updated_at | timestamp | |

Index: `(account_id, path)` unique.

### `cirrus_sync_history`

Audit log of sync runs.

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| account_id | bigint, FK | |
| sync_folder_id | bigint, FK, nullable | Null = account-wide sync |
| started_at | timestamp | |
| completed_at | timestamp, nullable | |
| status | string | `running`, `success`, `error`, `cancelled` |
| files_transferred | int | |
| bytes_transferred | bigint | |
| errors_count | int | |
| log_path | string, nullable | Path to detailed log file |

### `cirrus_conflicts`

Detected file conflicts awaiting resolution.

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| account_id | bigint, FK | |
| path | string | |
| local_modified_at | timestamp | |
| remote_modified_at | timestamp | |
| local_size_bytes | bigint | |
| remote_size_bytes | bigint | |
| status | string | `pending`, `resolved_local`, `resolved_remote`, `resolved_both`, `ignored` |
| detected_at | timestamp | |
| resolved_at | timestamp, nullable | |

### `cirrus_settings`

App-wide key/value store. Single row, multiple settings, or one row per setting. Implementation choice: one row per setting (easier migration).

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| key | string, unique | E.g., `bandwidth_limit_kbps`, `default_cache_size_gb`, `ui_language` |
| value | text | JSON-encoded value |
| updated_at | timestamp | |

### `cirrus_users`

The panel's login user. Single user in v1.0.

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| email | string, unique | |
| password | string | bcrypt |
| remember_token | string, nullable | |
| created_at | timestamp | |
| updated_at | timestamp | |

### `cirrus_mount_processes`

Track running rclone mount processes.

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| account_id | bigint, FK | |
| mount_point | string | Local path |
| pid | int | rclone PID |
| started_at | timestamp | |
| status | string | `running`, `stopped`, `failed` |
| last_health_check_at | timestamp, nullable | |
| created_at | timestamp | |
| updated_at | timestamp | |

---

## 8. rclone Integration

### Bundling

The rclone binary ships with the application. Version pinned in `config/cirrus.php`:

```php
'rclone' => [
    'version' => '1.67.0',
    'binary_path' => env('RCLONE_BINARY_PATH', base_path('rclone/rclone')),
    'config_path' => env('RCLONE_CONFIG_PATH', storage_path('rclone/rclone.conf')),
    'cache_dir' => env('RCLONE_CACHE_DIR', storage_path('rclone/cache')),
    'mount_base' => env('RCLONE_MOUNT_BASE', $_SERVER['HOME'] . '/Cirrus'),
],
```

### Acquiring the Binary

**Docker build:**
The Dockerfile downloads the pinned rclone version from official releases:

```dockerfile
ARG RCLONE_VERSION=1.67.0
RUN apk add --no-cache curl unzip && \
    ARCH=$(uname -m | sed 's/x86_64/amd64/' | sed 's/aarch64/arm64/') && \
    curl -fL "https://downloads.rclone.org/v${RCLONE_VERSION}/rclone-v${RCLONE_VERSION}-linux-${ARCH}.zip" -o /tmp/rclone.zip && \
    unzip /tmp/rclone.zip -d /tmp && \
    mv /tmp/rclone-*/rclone /usr/local/bin/cirrus-rclone && \
    chmod +x /usr/local/bin/cirrus-rclone && \
    rm -rf /tmp/rclone*
```

**Native install:**
`install.sh` does the same, placing the binary at `/opt/cirrus/rclone`.

### Invocation Pattern

All rclone interaction flows through `App\Services\Rclone\RcloneRunner`:

```php
namespace App\Services\Rclone;

class RcloneRunner
{
    public function run(array $args, array $options = []): RcloneResult { /* ... */ }
    public function runBackground(array $args, array $options = []): int { /* returns PID */ }
    public function killProcess(int $pid): bool { /* ... */ }
    public function isProcessAlive(int $pid): bool { /* ... */ }
}
```

The runner:
- Always uses the bundled binary.
- Always passes `--config={cirrus-rclone.conf}` to isolate from user's own rclone config.
- Always passes `--use-json-log` for structured output.
- Captures stderr line-by-line for real-time progress.
- Returns structured result objects, not raw strings.

### Default Flags

For mounts:

```
--vfs-cache-mode=full
--vfs-cache-max-size={configured}
--vfs-cache-max-age=168h
--vfs-read-ahead=128M
--buffer-size=64M
--dir-cache-time=5m
--poll-interval=15s
--tpslimit=10
--tpslimit-burst=20
--allow-non-empty
--use-json-log
--log-file={cirrus-mount-{account_id}.log}
```

For sync operations:

```
--transfers=4
--checkers=8
--tpslimit=10
--tpslimit-burst=20
--use-json-log
--stats=1s
--stats-one-line
```

Values are overridable per-account via the Advanced settings panel.

### OAuth Flow

OAuth for OneDrive uses the Microsoft Graph endpoints. Two paths:

**Path A — rclone-managed:** rclone has `rclone authorize` which handles the OAuth dance. Easy but headless.

**Path B — In-app OAuth:** Cirrus implements OAuth directly using Laravel Socialite or a custom flow, then writes the resulting token to rclone's config in the right format.

**Decision:** Use **Path B**. Reasons:
- Better UX (no terminal step, no copy-pasting URLs)
- Tighter control over token refresh timing
- Can show user-friendly errors during OAuth
- Tokens stored encrypted in our DB; rclone reads them when needed (we generate the rclone config dynamically)

**Implementation:** Microsoft Graph endpoints used:
- Authorize: `https://login.microsoftonline.com/common/oauth2/v2.0/authorize`
- Token: `https://login.microsoftonline.com/common/oauth2/v2.0/token`
- Scopes: `Files.ReadWrite.All offline_access User.Read`

### rclone Config File Management

Cirrus generates `rclone.conf` from the `accounts` table on each startup and after any account change. Format:

```ini
[onedrive_pessoal]
type = onedrive
client_id = {public_client_id_from_config}
drive_id = {fetched_at_oauth}
drive_type = personal
token = {decrypted_token_json}

[onedrive_trabalho]
type = onedrive
...
```

The user never edits this file directly. The "Advanced" panel can show its current content for transparency.

### Parsing JSON Log Output

rclone with `--use-json-log` emits one JSON object per line:

```json
{"level":"info","msg":"There was nothing to transfer","object":"","objectType":"","source":"sync/sync.go:954","time":"2026-05-12T10:00:00Z"}
```

`App\Services\Rclone\JsonLogParser` reads these line-by-line, classifies them, and dispatches events:

- `transfer.started` → Reverb event
- `transfer.progress` → Reverb event (throttled to 1/s)
- `transfer.completed` → Reverb event + DB update
- `error` → Reverb event + DB log entry

### Process Supervision

Running rclone processes are tracked in `cirrus_mount_processes`. A scheduled task (`php artisan schedule:run` every minute) checks:

- Is each PID still alive? If not, mark as `failed` and try to restart (up to 3 times).
- Has the mount become unresponsive? Periodic `stat` of the mount point. If hangs, kill and remount.

---

## 9. Features by Release

Each feature has acceptance criteria in EARS notation where critical. All releases are tagged in Git and produce a Docker image.

### v0.1.0 — Foundation

**Goal:** A user can install, log in to the panel, add a OneDrive account, and view the file tree.

**Features:**

- **F1.1** Installation via Docker Compose
- **F1.2** First-run setup wizard (panel password, language, mount location)
- **F1.3** Local panel authentication (username + password)
- **F1.4** Add OneDrive Personal account via OAuth
- **F1.5** View remote file tree (read-only listing via rclone lsjson)
- **F1.6** Account dashboard showing quota (used / total)
- **F1.7** PT-BR and EN translations for all UI strings shipped
- **F1.8** Settings page with: change panel password, change language, mount base path

**Acceptance Criteria (EARS):**

- WHEN a user runs `docker compose up -d` for the first time, THE SYSTEM SHALL be accessible at `http://localhost:8080` within 60 seconds.
- WHEN no panel password is set, THE SYSTEM SHALL redirect all routes to the setup wizard.
- WHEN the user clicks "Add OneDrive Account", THE SYSTEM SHALL initiate the Microsoft OAuth flow and redirect to Microsoft's login page.
- WHEN OAuth completes successfully, THE SYSTEM SHALL store the token encrypted and display the account in the dashboard.
- WHEN OAuth fails (user denies, error from Microsoft), THE SYSTEM SHALL display a clear error message in the user's selected language and offer to retry.
- IF the account's quota cannot be fetched, THEN THE SYSTEM SHALL display "Quota unavailable" and retry on next dashboard load.
- THE SYSTEM SHALL refresh OAuth tokens automatically when they are within 10 minutes of expiry.

**Out of scope for this release:**

- Sync (next release)
- Mount (next release)
- Multi-account beyond Personal (next release)
- Conflict resolution (later release)

### v0.2.0 — Sync Basics

**Goal:** A user can select folders and have them sync bidirectionally with OneDrive.

**Features:**

- **F2.1** Folder selection UI (tree view of remote folders with checkboxes)
- **F2.2** Bidirectional sync via `rclone bisync` (or `rclone sync` + reverse if bisync not stable enough)
- **F2.3** Sync history view (when, what folder, files transferred, status)
- **F2.4** Manual "Sync now" button per folder
- **F2.5** Background scheduled sync (default: every 15 minutes when active)
- **F2.6** Pause/resume sync globally
- **F2.7** Real-time progress via WebSocket (current file, percentage, speed)
- **F2.8** Bandwidth limit setting (global, KB/s)

**Acceptance Criteria (EARS):**

- WHEN the user toggles a folder to "active", THE SYSTEM SHALL initiate a sync within 30 seconds.
- WHILE a sync is in progress, THE SYSTEM SHALL emit progress events at least every 2 seconds.
- WHEN a scheduled sync runs successfully, THE SYSTEM SHALL log it in sync history with file count and bytes transferred.
- IF the network connection fails during sync, THEN THE SYSTEM SHALL retry up to 3 times with exponential backoff before marking the sync as failed.
- IF rclone returns a rate-limit error (429), THEN THE SYSTEM SHALL respect the `Retry-After` header and surface a user-friendly message.
- WHERE bandwidth limit is set, THE SYSTEM SHALL pass `--bwlimit` to rclone with the configured value.

**Out of scope:**

- Files-on-Demand mount (next release)
- Conflict resolution UI (later)
- Per-file policies (later)

### v0.3.0 — Files-on-Demand UX

**Goal:** Files appear in the user's filesystem as if local, but download on demand. User can pin specific files offline or free up space.

**Features:**

- **F3.1** Mount the OneDrive account at `~/Cirrus/{account_name}/` via `rclone mount`
- **F3.2** Mount lifecycle: auto-start with app, auto-restart on failure
- **F3.3** Cache size limit setting (default: 10% of disk free space)
- **F3.4** Cache statistics panel (current usage, hit rate, files cached)
- **F3.5** File browser within UI (browse mount, see cache status per file)
- **F3.6** Per-file/folder "Always keep offline" toggle (pin)
- **F3.7** Per-file/folder "Free up space" action (evict from cache, keep placeholder)
- **F3.8** "Free up all cache" button
- **F3.9** Pinned files protected from automatic eviction (touched periodically in cache)

**Acceptance Criteria (EARS):**

- WHEN the app starts, THE SYSTEM SHALL mount each active account at the configured location within 30 seconds.
- WHEN the mount process dies, THE SYSTEM SHALL detect within 60 seconds and attempt to restart up to 3 times.
- WHEN the user clicks "Always keep offline" on a file, THE SYSTEM SHALL download the file immediately and add it to the protected pin list.
- WHEN cache exceeds the configured limit, THE SYSTEM SHALL evict files using LRU policy, excluding pinned files.
- IF a user attempts to pin a file larger than the configured cache size, THEN THE SYSTEM SHALL display a warning and offer to increase the cache limit.
- WHILE the mount is unhealthy, THE SYSTEM SHALL display a red status indicator in the dashboard with a "Reconnect" button.

### v0.4.0 — Multi-Account, Business, Conflicts

**Goal:** Support multiple accounts including OneDrive Business and SharePoint. Detect and resolve conflicts visually.

**Features:**

- **F4.1** Add OneDrive Business account (different OAuth scopes, tenant detection)
- **F4.2** Add SharePoint document library
- **F4.3** Account switcher in UI header
- **F4.4** Conflict detection (rclone bisync produces conflict markers)
- **F4.5** Conflict resolution UI (side-by-side comparison, pick winner)
- **F4.6** Bulk conflict actions (keep all local, keep all remote, keep both)
- **F4.7** Notification system for new conflicts (badge in UI, optional desktop notification via Web Notifications API)

**Acceptance Criteria (EARS):**

- WHEN a user adds a Business account, THE SYSTEM SHALL detect the tenant and configure rclone with the correct drive_id and drive_type.
- WHEN bisync detects a conflict, THE SYSTEM SHALL create a `cirrus_conflicts` record and emit a WebSocket event.
- WHEN the user resolves a conflict, THE SYSTEM SHALL apply the resolution (copy chosen version, update both sides) and mark the conflict as resolved.
- IF more than 10 conflicts exist for one account, THEN THE SYSTEM SHALL pause automatic sync for that account and require user attention.

### v0.5.0 — Polish

**Goal:** Refinement, performance tuning, broader OS support, accessibility.

**Features:**

- **F5.1** Onboarding tour for first-time users (highlights key features)
- **F5.2** Bandwidth scheduler (limit during work hours, unlimited at night)
- **F5.3** Per-folder advanced settings (custom transfer counts, custom chunk size)
- **F5.4** Search across mounted files (uses rclone or filesystem find)
- **F5.5** Storage usage trends (chart of disk + cloud usage over time)
- **F5.6** Accessibility: keyboard navigation, ARIA labels, sufficient contrast
- **F5.7** Mobile-responsive UI (since panel may be accessed from phone on LAN)
- **F5.8** Better error messages with actionable suggestions
- **F5.9** Export/import config (JSON file with accounts settings sans tokens; tokens stay machine-local)

### v1.0.0 — Stable Release

**Goal:** Public release. Production-ready, documented, supported.

**Includes everything from v0.x plus:**

- **F6.1** Complete user documentation in `docs/` (installation, usage, troubleshooting, FAQ)
- **F6.2** Complete developer documentation (architecture, contributing guide)
- **F6.3** Tested install paths: Docker (Ubuntu, Pop!_OS, Fedora, Arch hosts), native (Ubuntu, Pop!_OS)
- **F6.4** Tested rclone version compatibility matrix
- **F6.5** Security audit checklist completed
- **F6.6** Release announcement on r/selfhosted, awesome-selfhosted PR, Hacker News (Show HN)
- **F6.7** Issue templates and PR template in `.github/`
- **F6.8** Code of Conduct
- **F6.9** Translation guide for community contributors

**Acceptance Criteria for v1.0:** see Section 18.

---

## 10. UX & Design System

### Visual Principles

- **Information density that matches context.** Dashboard is summary-level; detail pages can be denser.
- **Whitespace is a feature.** Don't fill every pixel.
- **One primary action per screen.** Make it obvious. Other actions are secondary.
- **Status before action.** Show what's happening before offering controls (e.g., "Sync in progress" header before pause button).

### Color Palette (Tailwind tokens)

| Token | Tailwind | Use |
|---|---|---|
| Primary | `sky-600` (light) / `sky-500` (dark) | Buttons, links, primary actions |
| Success | `emerald-600` | Successful sync, online status |
| Warning | `amber-500` | Quota near limit, slow sync |
| Danger | `rose-600` | Errors, conflicts, failed mount |
| Surface | `white` / `zinc-900` | Cards, modals |
| Background | `zinc-50` / `zinc-950` | Page background |
| Text primary | `zinc-900` / `zinc-100` | |
| Text secondary | `zinc-600` / `zinc-400` | |
| Border | `zinc-200` / `zinc-800` | |

Dark mode supported via Tailwind's `dark:` prefix. Detect from system, respect user override.

### Typography

- Sans-serif: System font stack via Tailwind's `font-sans`
- Mono (for paths, IDs): `font-mono` (Cascadia Code, JetBrains Mono, fallback)

### Component Inventory

All foundation components from Flux UI. Custom Cirrus components built on top.

| Component | Use |
|---|---|
| `<flux:button>` | Buttons (primary, secondary, danger variants) |
| `<flux:input>` | Text, password, number inputs |
| `<flux:select>` | Dropdowns |
| `<flux:checkbox>` | Checkboxes |
| `<flux:switch>` | Toggle switches |
| `<flux:modal>` | Modal dialogs |
| `<flux:dropdown>` | Context menus |
| `<flux:tabs>` | Tabbed interfaces |
| `<flux:badge>` | Status badges, counts |
| `<flux:toast>` | Toast notifications |
| `<x-cirrus.folder-tree>` | Custom: folder tree with checkboxes |
| `<x-cirrus.transfer-progress>` | Custom: transfer progress bar with file name and speed |
| `<x-cirrus.conflict-row>` | Custom: side-by-side conflict comparison row |
| `<x-cirrus.account-card>` | Custom: account dashboard tile |

### Key Screens

**1. Setup Wizard (first run)**

Four steps with progress dots at top:

1. **Welcome** — brief explanation of what Cirrus does
2. **Create panel account** — email + password
3. **Choose language** — PT-BR or EN
4. **Choose mount location** — defaults to `~/Cirrus/`

After completion, redirected to Dashboard.

**2. Dashboard (`/`)**

- Top bar: logo, account switcher, settings cog, user menu
- Hero card: "Add your first account" if none; otherwise account cards
- Each account card: name, email, quota bar, last sync time, status indicator
- Right sidebar (desktop): recent activity feed (last 10 sync events)

**3. Account Detail (`/accounts/{id}`)**

- Account name (editable inline)
- Quota visualization (donut chart, large)
- Tabs: Folders / Files / Activity / Settings
- Folders tab: list of synced folders with toggle, last sync, file count
- Files tab: browse mounted files with cache status icons
- Activity tab: detailed sync history with filters
- Settings tab: per-account overrides (bandwidth, schedule, advanced rclone flags)

**4. Add Account (`/accounts/new`)**

- Provider selection (v1.0: only OneDrive variants — Personal, Business, SharePoint)
- For Personal: single "Login with Microsoft" button
- For Business/SharePoint: additional fields (tenant ID, document library URL)
- Animated state during OAuth roundtrip

**5. Folder Selection (`/accounts/{id}/folders`)**

- Tree view of remote folders (expandable)
- Checkbox per folder
- File count and size next to each
- Save button at bottom, sticky

**6. File Browser (`/accounts/{id}/files`)**

- Breadcrumb path
- File list (table or grid view, user choice)
- Status icon per file: cloud (online only), check (cached), pin (always offline), syncing
- Context menu per file: Open, Copy path, Always keep offline, Free up space, Show on OneDrive web

**7. Conflicts (`/conflicts`)**

- List of pending conflicts
- For each: file path, local modified date, remote modified date, sizes
- Actions: Keep local / Keep remote / Keep both / Ignore
- Bulk actions at top

**8. Settings (`/settings`)**

- Sections: General, Sync, Cache, Network, Advanced, About
- General: language, theme, mount base path
- Sync: default sync interval, retry policy
- Cache: max size, max age, eviction policy
- Network: bandwidth limit (with schedule editor), proxy
- Advanced: raw rclone flags per account, log level
- About: version, rclone version, license, links

### Notifications

In-app via Reverb WebSocket + toast. Categories:

- **Success** (green): sync completed
- **Info** (blue): sync started, account added
- **Warning** (amber): quota at 80%+, slow sync
- **Error** (red): sync failed, mount lost, conflict

Toast position: bottom right. Auto-dismiss after 5s for non-errors; errors persist until clicked.

### Loading States

- Initial page load: skeleton screens, not spinners
- Action-triggered loads: button shows inline spinner + disabled
- Long operations (>3s): toast with progress bar, never block UI

### Empty States

Every list view has a distinct empty state with:

- Friendly illustration or icon
- One-line explanation
- Primary action (e.g., "Add your first account")

---

## 11. Installation & Distribution

### Distribution Channels

| Channel | Status | Audience |
|---|---|---|
| Docker Compose | **Primary** | Most users |
| Install script (native) | **Secondary** | Power users, no-Docker setups |
| .deb / .rpm packages | Post-v1.0 | Distro users |
| AUR (Arch) | Post-v1.0, community-maintained | Arch users |
| Flatpak | Post-v1.0 | Flathub users |

### Docker Compose (Primary Path)

User runs:

```bash
mkdir ~/cirrus && cd ~/cirrus
curl -fsSL https://raw.githubusercontent.com/{owner}/cirrus/main/docker-compose.yml -o docker-compose.yml
docker compose up -d
```

`docker-compose.yml`:

```yaml
services:
  cirrus:
    image: ghcr.io/{owner}/cirrus:latest
    container_name: cirrus
    restart: unless-stopped
    ports:
      - "127.0.0.1:8080:8080"
    volumes:
      - ./data:/app/storage             # SQLite, logs, etc.
      - ${HOME}/Cirrus:/mnt/cirrus:rshared   # Mount point — accessible to user
    devices:
      - /dev/fuse
    cap_add:
      - SYS_ADMIN
    security_opt:
      - apparmor:unconfined
    environment:
      - APP_URL=http://localhost:8080
      - DB_DATABASE=/app/storage/database.sqlite
```

Note: FUSE mounting requires elevated capabilities. Documented clearly. Rootless alternative explored post-v1.0.

After first `up`, user opens `http://localhost:8080` and goes through setup wizard.

### Native Install Script

```bash
curl -fsSL https://raw.githubusercontent.com/{owner}/cirrus/main/install/install.sh | bash
```

Script:

1. Detects distro (Ubuntu, Debian, Fedora, Arch).
2. Checks for PHP 8.3+, installs if missing (via distro package manager).
3. Installs Composer if missing.
4. Clones repo to `~/.local/share/cirrus/`.
5. Runs `composer install --no-dev`.
6. Downloads rclone binary to `~/.local/share/cirrus/rclone/`.
7. Generates `.env` with safe defaults.
8. Runs migrations.
9. Installs systemd user services (`~/.config/systemd/user/cirrus-*.service`).
10. Enables and starts services.
11. Opens browser to `http://localhost:8080`.

### Update Mechanism

**Docker:** `docker compose pull && docker compose up -d`. Image tag `latest` for rolling, version tags for pinning.

**Native:** `~/.local/share/cirrus/update.sh` script that fetches latest release, runs migrations, restarts services.

### Backup/Restore

The user's important data is in:

- `storage/database.sqlite` — app state
- `storage/rclone/rclone.conf` — rclone config (regenerable but useful)
- `~/Cirrus/` — synced files (regenerable from cloud)

Backup script (`storage/backup.sh`) tars these into a single file. Restore script reverses.

---

## 12. Security

### Authentication

- Panel login required. No anonymous access.
- bcrypt password hashing (Laravel default).
- Session-based auth.
- Password reset via CLI command (since there's no email): `php artisan cirrus:reset-password`.
- Optional 2FA (Google Authenticator-style TOTP) — added in v0.5.

### Authorization

- v1.0 is single-user. The authenticated user has access to all accounts and settings.
- Multi-user/role-based access not in scope.

### Token Storage

- OAuth tokens encrypted via Laravel `Crypt` (AES-256-CBC with the `APP_KEY`).
- Decryption only happens when generating rclone config or refreshing the token.
- Tokens never logged. Custom log processor redacts `oauth_token`, `refresh_token`, `client_secret` fields if they appear.

### Network Exposure

- Default bind: `127.0.0.1:8080`. Documented prominently.
- LAN exposure: requires changing bind address explicitly in `docker-compose.yml` or env.
- Internet exposure: strongly discouraged in docs; if user insists, mandatory HTTPS (reverse proxy) + strong password.
- No telemetry, no analytics, no phone-home.

### File System Permissions

- SQLite DB: `0600` (owner read/write only).
- rclone config: `0600`.
- Log files: `0640`.

### Microsoft Graph Scopes

Request only what's needed:

- `Files.ReadWrite.All` — sync, mount
- `offline_access` — refresh tokens
- `User.Read` — for displaying user email/name

No `Sites.FullControl.All` or other broad scopes in v1.0.

### CSRF, XSS

- Laravel CSRF middleware enabled on all state-changing routes (default).
- Content Security Policy headers configured.
- Blade auto-escapes by default; never use `{!! !!}` for user input.

---

## 13. Internationalization

### Supported Languages (v1.0)

- **en** — English (default, fallback)
- **pt-BR** — Brazilian Portuguese

### File Structure

```
lang/
├── en/
│   ├── accounts.php
│   ├── auth.php
│   ├── cache.php
│   ├── common.php
│   ├── dashboard.php
│   ├── errors.php
│   ├── settings.php
│   ├── sync.php
│   ├── validation.php
│   └── wizard.php
└── pt-BR/
    └── (same files, translated)
```

### Conventions

- Keys in English: `'add_account' => 'Add Account'` (en), `'add_account' => 'Adicionar Conta'` (pt-BR).
- Use Laravel's `__('accounts.add_account')` in Blade and PHP.
- Pluralization via Laravel's `trans_choice`.
- Date/time formatting via Carbon, locale-aware.
- Numbers and file sizes formatted per locale.

### Adding a New Language

Documented in `docs/contributing-translations.md`:

1. Copy `lang/en/` to `lang/{new-locale}/`.
2. Translate each file's values, keeping keys identical.
3. Add the locale to `config/cirrus.php`'s `available_locales` array.
4. Submit PR.

### Default Locale Detection

Order:

1. User's saved preference (DB).
2. Browser's `Accept-Language` header.
3. App's configured default (`en`).

---

## 14. Testing Strategy

### Framework

**Pest** as primary test framework. PHPUnit-compatible.

### Test Levels

| Level | Location | Purpose |
|---|---|---|
| Unit | `tests/Unit/` | Service classes, value objects, parsers |
| Feature | `tests/Feature/` | HTTP routes, Livewire components, jobs |
| Integration | `tests/Integration/` | rclone interactions (mocked or real binary in CI) |
| E2E | (post-v1.0) | Browser-driven via Pest Browser Plugin |

### Coverage Targets

- `app/Services/` — 80%+
- `app/Livewire/` — 70%+
- `app/Http/Controllers/` — 70%+
- `app/Jobs/` — 80%+
- `app/Models/` — 50%+ (mostly Eloquent, less to test)

### Mocking rclone

For most tests, mock `App\Services\Rclone\RcloneRunner` to return canned responses. For integration tests in CI, the actual rclone binary runs against a fake remote (rclone has a `memory` backend for testing).

### Test Data

- Use factories for all models.
- Avoid hard-coded IDs.
- Reset DB between tests (Pest's `RefreshDatabase`).

### CI

Tests run on every push and PR via GitHub Actions:

```
- PHP setup (8.3)
- Composer install
- Copy .env.testing
- Run migrations (SQLite in memory)
- Run Pest
- Report coverage to Codecov (optional)
```

---

## 15. Documentation Requirements

### User-Facing Documentation (`docs/`)

Required pages for v1.0:

- `installation.md` — Docker and native install, step by step
- `configuration.md` — settings reference
- `usage.md` — common workflows (add account, sync folders, free space)
- `troubleshooting.md` — common errors and solutions
- `faq.md` — frequently asked questions
- `security.md` — what's encrypted, where data goes
- `contributing.md` — for contributors
- `contributing-translations.md` — for translators
- `architecture.md` — high-level architecture for contributors

All in Markdown, hosted in repo. Future: GitHub Pages or VitePress for nicer browsing.

### README.md

Required sections:

- Hero (project name, tagline, screenshot)
- What is Cirrus (1 paragraph)
- Why Cirrus (3 bullets)
- Built on rclone (acknowledgment)
- Quick install (Docker one-liner)
- Features (bulleted list)
- Screenshots (3-5 images)
- Documentation link
- Comparison table (Cirrus vs raw rclone vs other OneDrive Linux clients)
- License
- Contributing
- Acknowledgments (rclone, Laravel, Livewire, Flux UI)

### In-Code Documentation

- Public methods of service classes: PHPDoc with summary, params, return.
- Complex algorithms: inline explanation comments.
- TODO/FIXME with reason and date.

---

## 16. CI/CD

### GitHub Actions Workflows

**`.github/workflows/tests.yml`**

Runs on every push and PR:

```
- Checkout
- Setup PHP 8.3
- Setup Node 20
- Composer install
- npm install
- Build assets (npm run build)
- Run migrations
- Run Pest (php artisan test)
- Run Pint code style (php artisan pint --test)
- Upload coverage
```

**`.github/workflows/docker-build.yml`**

Runs on push to main and on tags:

```
- Checkout
- Login to GHCR
- Build multi-arch image (amd64, arm64)
- Push as :latest (main) or :v{version} (tags)
```

**`.github/workflows/release.yml`**

Runs on tags `v*`:

```
- Generate changelog from commits
- Create GitHub Release
- Attach binaries/zip if any
- Publish announcement (optional Discord webhook, etc.)
```

### Versioning

Semantic versioning. Tags in format `v0.1.0`, `v1.0.0`. Pre-1.0 releases may break APIs.

### Branching

- `main` — stable, deployable
- `develop` — integration branch (optional, if releases get complex)
- Feature branches: `feat/{short-name}`
- Hotfix branches: `fix/{short-name}`

PRs squash-merged to main.

---

## 17. Defaults & Fallbacks

This section gives contributors explicit defaults when the spec is silent.

### General

- **Charset:** UTF-8 everywhere.
- **Timezone:** User's local timezone (detected from server) for display; UTC for storage.
- **Date format:** ISO 8601 in storage; locale-aware in display.

### PHP / Laravel

- **Strict types:** `declare(strict_types=1);` in service classes and value objects. Not required in controllers and Livewire components (Laravel convention).
- **Exception handling:** Bubble up to Laravel's handler. Custom exceptions for domain errors (e.g., `RcloneException`, `OAuthException`).
- **Logging:** Laravel's `Log` facade. Channels: `cirrus-app`, `cirrus-rclone`. Daily rotation. Max retention 14 days.
- **Validation:** Form Request classes. Translate all messages.
- **Authorization:** Policies for any multi-record entity.
- **API responses:** JSON. Standard structure: `{ "data": {...}, "errors": [...] }`.

### Livewire

- **Loading indicators:** Use `wire:loading` everywhere user-initiated actions occur.
- **Error display:** Inline below the input that errored. Form-level errors at top.
- **Notifications:** `Toast` events dispatched from components.
- **Pagination:** Laravel pagination, 25 per page default.

### Frontend

- **CSS:** Tailwind only. Components from Flux UI. No custom CSS files except `app.css` for Tailwind directives.
- **JS:** Alpine.js only (no jQuery, no other libraries). Reserved for: dropdowns, modals open/close, transient UI state.
- **Images:** Store in `public/images/`. Use SVG where possible. PNG only for screenshots.
- **Favicon:** Cirrus logo at multiple sizes in `public/`.

### rclone

- **Config location:** Inside app's `storage/rclone/`. Never user's `~/.config/rclone/`.
- **Cache location:** Inside app's `storage/rclone/cache/`. Configurable.
- **Mount location:** Default `~/Cirrus/`. Configurable.
- **Log location:** `storage/logs/rclone-{account}-{operation}.log`.

### Security

- **Session lifetime:** 2 hours. "Remember me" extends to 30 days.
- **Password requirements:** Minimum 12 characters. No complexity rules (length matters more).
- **Rate limiting:** Login endpoint limited to 5 attempts per 5 minutes per IP.

### UI Defaults

- **Theme:** Auto (follow system).
- **Language:** Detect from browser, fallback to English.
- **Mount base:** `~/Cirrus/`.
- **Cache size:** 10% of free disk space, max 20 GB, min 1 GB.
- **Sync interval:** 15 minutes.
- **Bandwidth limit:** None (unlimited).

### Error Recovery

- **rclone process crash:** Restart up to 3 times. Backoff: 5s, 30s, 5min. After 3 failures, mark account as `error` and notify user.
- **OAuth token expired and refresh failed:** Mark account as `disconnected`, prompt user to re-authenticate.
- **Database locked (SQLite):** Retry up to 5 times with 100ms backoff.
- **Mount point unresponsive:** Unmount with `--force`, remount.

---

## 18. v1.0 Acceptance Criteria

Before tagging v1.0, all of the following must be true:

### Functionality

- [ ] All v0.1.0 through v0.5.0 features implemented and tested.
- [ ] All EARS criteria from each release pass.
- [ ] Tested with at least 5 real OneDrive Personal accounts.
- [ ] Tested with at least 3 OneDrive Business accounts (different tenants).
- [ ] Tested with at least 1 SharePoint document library.
- [ ] Tested on Ubuntu 22.04, Pop!_OS 22.04, Fedora 40, Arch (latest).
- [ ] Docker image builds for amd64 and arm64.

### Quality

- [ ] Test coverage: services 80%+, livewire 70%+, controllers 70%+, jobs 80%+.
- [ ] No critical bugs open in GitHub Issues.
- [ ] All UI strings translated for en and pt-BR.
- [ ] No console errors in browser during normal use.
- [ ] Lighthouse accessibility score >= 90 on key pages.

### Documentation

- [ ] All required docs in `docs/` present and reviewed.
- [ ] README complete with screenshots.
- [ ] Architecture doc for contributors complete.
- [ ] Demo video (5 min) showing install and basic usage.

### Distribution

- [ ] Docker Compose path works from `curl | docker compose up -d`.
- [ ] Native install script works on Ubuntu, Pop!_OS, Fedora.
- [ ] GitHub Release with changelog generated.
- [ ] Docker image published to GitHub Container Registry.

### Community

- [ ] Issue templates configured.
- [ ] PR template configured.
- [ ] CODE_OF_CONDUCT.md present.
- [ ] CONTRIBUTING.md present.
- [ ] LICENSE (MIT) present.
- [ ] LICENSES/rclone.txt present.

---

## 19. Glossary

- **Bisync** — rclone's bidirectional synchronization mode (`rclone bisync`).
- **Bottle** — In the Wine ecosystem; unrelated to Cirrus.
- **Cache** — Local copy of cloud files maintained by rclone's VFS cache mode.
- **Conflict** — A file modified both locally and remotely between syncs.
- **Delta sync** — Sync that only fetches changes since last sync, via Microsoft Graph's delta API.
- **FUSE** — Filesystem in Userspace; Linux kernel feature allowing user-space filesystem implementations.
- **Files-on-Demand** — Microsoft's branding for cloud-first storage where files appear locally but download on access. Cirrus replicates this UX via rclone VFS cache.
- **Flux UI** — Official Livewire component library.
- **Livewire** — Server-driven reactivity framework for Laravel.
- **Mount** — rclone command (`rclone mount`) that exposes a cloud remote as a local filesystem.
- **Pin (a file)** — Mark a file as "always keep offline"; protected from cache eviction.
- **Provider** — A cloud storage service (OneDrive, Google Drive, Dropbox). Cirrus v1.0 supports only OneDrive variants.
- **Remote** — rclone's term for a configured cloud account.
- **SharePoint document library** — A folder structure within a SharePoint site, accessible via Microsoft Graph.
- **VFS cache** — rclone's virtual filesystem cache layer, configurable via `--vfs-cache-mode`.

---

**End of Specification**

This document is the project's specification. Any deviation should be explicitly noted in a commit message or pull request description with justification.
