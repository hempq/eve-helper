# EVE Helper

A self-hosted companion app for a solo EVE Online pilot: it pulls your characters through
[ESI](https://developers.eveonline.com/) and turns the data into decisions — what to train,
where to sell, where to farm, and what deserves an alert.

Built with **Laravel 12 + Livewire 3 + SQLite**, shipped as an always-on **Docker Compose**
stack with HTTPS (Caddy + mkcert). ~170 PHPUnit tests and a Playwright E2E smoke suite.

## Features

| Page | What it does |
|---|---|
| **Dashboard** | Character overview, net-worth trend (daily snapshots, net of your sales tax), income/spending breakdown, daily earnings chart, multi-character account overview, ship-loss history (zKillboard), in-app alert bell |
| **Skills / Planner / Remap** | Skill browser, prerequisite-resolving plan builder, EVEMon-style remap optimizer (14 641 candidates), **multi-remap segmentation** (queue and plans), injector/extractor ROI |
| **Market** | Loot appraisal (paste or assets) with real tax/broker fees, standing-aware broker rates, order-book-depth fill times, 30-day price sparklines, contract-ask fallback for deadspace loot, hub comparison, multi-stop sell-trip planner, undercut monitor (citadels included), contracts, LP-store ISK/LP ranking (sell vs instant), saved-fitting replacement costs, realized trading profit (FIFO over actual fills) |
| **Farm** | Region- or **pirate-faction-wide** system scoring from activity proxies (score v7: 2h availability EWMA vs 3-day competition average, backlog/surge trends, dead-end bonuses, live-kill veto), GRASP orienteering tour with waypoint push to the client, probe-scan signature journal with escalation timers and per-constellation yield stats, ratting ISK/h from bounty ticks |
| **Trade** | Hub-to-hub hauling flips from full order-book scans (parallel, every 4h) and a **station-trading** scanner (bid/ask spreads net of both broker fees + tax, scam walls filtered) |
| **Warzone** | Live incursions and faction-warfare occupancy — informational; routes detour around them via hazard-aware routing (toggleable) |
| **Agents** | Standings-checked mission-agent finder (Connections included) and R&D datacore income |
| **Industry** | Industry jobs (ready-to-deliver alerts), blueprint library (ME/TE), PI colonies with extractor-expiry alerts, mining ledger |

Every ISK figure in the app is **net of your actual sales tax and broker fees**
(skills + standings toward the station owner).

### Data sources

ESI (compat-date client with ETag caching, error-limit and 2025 token-bucket handling) ·
Fuzzwork (SDE CSVs, market aggregates) · EVE Ref (public-contracts snapshots) ·
zKillboard (losses) · EVE-Scout (Thera/Turnur wormholes).

## Requirements

- Docker with the Compose plugin
- [mkcert](https://github.com/FiloSottile/mkcert) for the local HTTPS certificate
- An EVE developer application (free): <https://developers.eveonline.com>
- Node 18+ only if you want to run the Playwright E2E suite

## Installation

### 1. Clone and configure

```bash
git clone https://github.com/hempq/eve-helper.git
cd eve-helper
cp .env.example deploy/.env.production
```

Edit `deploy/.env.production`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://eve-helper.local:8443
DB_CONNECTION=sqlite

EVE_CLIENT_ID=...          # from step 2
EVE_CLIENT_SECRET=...      # from step 2
EVE_ESI_USER_AGENT="EveHelper/1.0 (your-email@example.com)"  # ESI requires a contact
```

### 2. Register the EVE application

On <https://developers.eveonline.com> create an application:

- type: **Authentication & API Access**
- callback URL: `https://eve-helper.local:8443/auth/eve/callback` (must match exactly)
- scopes: everything listed in `config/eve.php` under `sso.scopes`

Copy the client ID/secret into `deploy/.env.production`.

### 3. HTTPS certificate and hostname

EVE SSO only accepts plain-http callbacks on localhost, so the app runs behind HTTPS:

```bash
mkcert -install
mkcert -cert-file deploy/certs/eve-helper.local.pem \
       -key-file  deploy/certs/eve-helper.local-key.pem \
       eve-helper.local localhost
```

Add to your hosts file (`/etc/hosts`, and on WSL2 also `C:\Windows\System32\drivers\etc\hosts`):

```
127.0.0.1 eve-helper.local
```

### 4. Start the stack

```bash
deploy/dc up -d --build
```

This starts four containers: **app** (migrations run automatically on boot), **caddy**
(HTTPS on :8443), **scheduler** and **queue**. The app is at **<https://eve-helper.local:8443>**.

Optional boot autostart (systemd): `sudo bash deploy/install-autostart.sh`

### 5. Import static data

```bash
deploy/dc artisan eve:sde-import            # Fuzzwork SDE: types, skills, universe, agents (~min)
deploy/dc artisan eve:import-contract-prices # public-contract asking prices
deploy/dc artisan eve:scan-hubs              # hub order books (~1 min, parallel)
```

### 6. Log in

Open the app and sign in through EVE SSO. The scheduler keeps everything fresh from
then on:

| Job | Cadence |
|---|---|
| `eve:sync-characters` | every 15 min |
| `eve:check-alerts` | every 30 min |
| `eve:record-activity` (farm scoring history) | hourly |
| `eve:scan-hubs` (order books) | every 4 h |
| `eve:import-contract-prices` | twice daily |
| `eve:record-net-worth` | daily |

> **Note:** farm backlog/surge trends need ~5 days of hourly activity history before
> they activate; the net-worth trend line appears after a few daily snapshots.

## Development

```bash
deploy/dc artisan <cmd>     # artisan in the app container
deploy/dc composer <cmd>    # composer
deploy/dc test              # PHPUnit suite
npm install && npm run test:e2e   # Playwright E2E against https://localhost:8443
```

The E2E suite authenticates through `/dev/login/{characterId}`, which only works with
`EVE_ALLOW_DEV_LOGIN=true` in the environment and an already-synced character. Never
enable it on a publicly reachable host.

On WSL2, if npm/npx hangs on downloads: `NODE_OPTIONS=--dns-result-order=ipv4first`.

## Notes

- Single-user by design: anyone who can open the app sees every linked character.
- ESI etiquette is enforced in the client (User-Agent with contact, `X-Compatibility-Date`,
  ETag/Expires caching, error-limit and token-bucket backoff). Don't strip it.
- ESI cannot see anomalies/signatures — the farm module works from activity proxies and
  your own probe-scan journal. That's a game limitation, not a missing feature.
