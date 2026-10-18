# Runbook — Deploy on aaPanel

A sequential, copy-paste runbook for standing the OfficeLand storefront up on a
Linux box managed by **aaPanel** (Debian 13 tested), and for deploying changes
to it afterwards.

This is the *checklist*. The **why** behind the proxy-not-port and
bring-your-own-Postgres decisions is in [DEPLOYMENT.md](DEPLOYMENT.md); day-2
operations (backup/restore, email, lead queue, monitoring) are in
[RUNBOOK.md](RUNBOOK.md). Where this runbook and DEPLOYMENT.md disagree on a
fact about the catalogue, this one is newer: the catalogue is now **~5,050 real
products with images**, imported from the legacy WooCommerce site, not the 10
demo products DEPLOYMENT.md was written against.

---

## The shape of it

```
Internet ─▶ aaPanel Nginx :443 ─▶ 127.0.0.1:8095 ─▶ Caddy / FrankenPHP (container)
            (TLS, domain, WAF)                       ├─ static SPA shell            → humans
                                                      ├─ crawler UA → render:4000    → bots (SSR)
                                                      ├─ /api   → Octane
                                                      └─ /admin → Filament
                                                           │  PgBouncer :6432 ─▶ Postgres 16
                                                           │  Redis :6379
```

**aaPanel's Nginx only does TLS, the domain and the firewall.** Everything else
— CSP with per-build hashes, the 181 legacy redirects, the cache split, the SPA
fallback, the staging `noindex`, and the Dynamic-Rendering user-agent routing
that sends crawlers to the SSR renderer — already lives, gate-verified, in the
container's Caddy. The vhost is a thin reverse proxy
([infra/nginx/officeland.conf](../infra/nginx/officeland.conf)); do not
reimplement any of it in Nginx.

**The stack is self-contained.** It brings its own Postgres 16, PgBouncer and
Redis. Do not point it at aaPanel's Postgres/Redis — PgBouncer in transaction
mode with prepared statements disabled is load-bearing (there is a test that
fails if it regresses), and the schema is tested against Postgres 16
specifically.

**Everything binds loopback.** `BIND_ADDR=127.0.0.1` publishes the app
(`:8095`) and Postgres/PgBouncer/Redis (`:5432/:6432/:6379`) on localhost only.
The vhost is the single way in.

---

## 0. Prerequisites

- A server with aaPanel installed, root/sudo, and a domain's DNS A record
  pointed at it.
- Docker Engine + Compose v2 (`docker compose version`). Install via aaPanel's
  **Docker** app, or the official script — see
  [DEPLOYMENT.md §2](DEPLOYMENT.md).
- Disk: budget **20 GB+**. The media volume (product imagery + WebP/AVIF/JPEG
  derivatives for ~5,050 products) is the bulk of it.
- Decide the first-bring-up environment: **`staging` first** on a new box
  (`APP_ENV=staging` forces `Disallow: /` and `X-Robots-Tag: noindex` at the
  edge, so a half-configured site can never be indexed). Flip to `production`
  only when §6 is satisfied.

---

## 1. Get the code and configure the two env files

```bash
mkdir -p /www/officeland && cd /www/officeland
git clone <repo-url> .
cp .env.production.example .env
```

This stack reads **two** env files, by design — don't put everything in one:

**Root `.env`** — read by Docker Compose. Supplies the container's app/DB/Redis
settings and the host port mappings. Fill in:

| Key | Value |
| --- | --- |
| `APP_ENV` | `staging` for first bring-up, later `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | exact public URL incl. scheme, e.g. `https://officelandng.com` |
| `DB_PASSWORD` | a password — `openssl rand -hex 24` (hex avoids quoting/CRLF traps) |
| `BIND_ADDR` | `127.0.0.1` |
| `HTTP_PORT` | `8095` (match the vhost upstream) |
| `BUILD_TARGET` | **`prod`** — `deploy.sh` refuses anything else |
| `OCTANE_WORKERS` | e.g. `4` (≈ CPU cores) |

> **`APP_KEY` does NOT go in the root `.env`** — compose does not forward it,
> so a value here is silently ignored. It is generated for you (next file).

**`apps/api/.env`** — Laravel's own config, bind-mounted into the container at
runtime and **never baked into the image**. You do not create it by hand: on
first start the api entrypoint copies `apps/api/.env.example` to it and runs
`key:generate`, so `APP_KEY` is set automatically. The only thing to add is
third-party mail, so quote emails send — edit `apps/api/.env.example` *before*
the first run, or `apps/api/.env` after it: `MAIL_MAILER`, `ZEPTOMAIL_ENDPOINT`,
`ZEPTOMAIL_TOKEN`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`,
`QUOTE_DEFAULT_RECIPIENTS` (`ICECAT_*` may stay blank). **Quote any value with a
space**, or Laravel's dotenv parser rejects the whole file:

```ini
ZEPTOMAIL_TOKEN="Zoho-enczapikey <your-token>"
MAIL_FROM_NAME="OfficeLand Limited"
```

### Host port collisions (you WILL hit this on aaPanel)

aaPanel already runs PostgreSQL on `5432` and Redis on `6379`, which collide
with this stack's loopback-published ports. Remap them in the **root `.env`** —
these only move the host-side admin ports; the app reaches Postgres/Redis over
the compose network regardless:

```ini
DB_DIRECT_PORT=5433
DB_POOLED_PORT=6432
REDIS_PORT=6380
```

Check they're free first: `ss -ltnp | grep -E ':(5433|6432|6380)\b'` (no output
= free; bump any that are taken). Don't open these in the firewall — they stay
on `127.0.0.1`.

> **PgBouncer password:** a custom `DB_PASSWORD` is now synced into the
> PgBouncer userlist automatically — `serve.sh`/`deploy.sh` regenerate
> `infra/pgbouncer/userlist.txt` from `.env` before the stack starts. (If you
> change the password *after* the database volume already exists, also reset the
> role: `docker compose exec postgres psql -U officeland -c "ALTER ROLE
> officeland PASSWORD '…'"`.)

---

## 2. First run

```bash
sh scripts/serve.sh --rebuild --detach
```

Starts the containers, waits on every healthcheck, migrates, and publishes the
SPA shell. The Angular build is seconds (CSR, nothing prerendered).

**Seed the taxonomy and SEO copy** (categories must exist before any catalogue
import can map products onto them):

```bash
docker compose exec api php artisan db:seed --class=TaxonomySeeder
docker compose exec api php artisan db:seed --class=CategoryIntroSeeder
docker compose exec api php artisan db:seed --class=CategorySeoSeeder
```

**Populate the catalogue.** Two ways — pick one:

- **A. Restore a backup (recommended for production).** Reproduces the exact
  data that was tested on dev/staging, and skips re-downloading all media.
  Restore a `pg_dump -Fc` and the media volume per
  [RUNBOOK.md §12](RUNBOOK.md). Fastest, and production matches what you QA'd.

- **B. Import from the live WooCommerce site.** Pulls ~5,050 products and their
  images straight from `officelandng.com`'s Store API and builds all image
  derivatives. Slow (downloads + image processing — budget a few hours) and
  depends on the source site being up:

  ```bash
  docker compose exec api php artisan woo:import          # full catalogue
  # if a prior demo catalogue is present, clear it first:
  # docker compose exec api php artisan demo:purge --apply
  ```

**Create an admin user** for `/admin` (Filament):

```bash
docker compose exec api php artisan make:filament-user
```

---

## 3. Smoke-test locally, before exposing it

```bash
curl -I  http://127.0.0.1:8095/
curl -s  http://127.0.0.1:8095/robots.txt        # staging MUST say: Disallow: /
```

Confirm Dynamic Rendering serves both audiences from the same URL:

```bash
# Human → small CSR shell (~11 KB):
curl -s -A "Mozilla/5.0" http://127.0.0.1:8095/c/it-products/computers/laptops/ | wc -c

# Crawler → full SSR HTML with the title in the <head>:
curl -s -A "Googlebot/2.1" http://127.0.0.1:8095/c/it-products/computers/laptops/ \
  | grep -o '<title>[^<]*</title>'
```

A leaf category's crawler HTML should also carry a direct `<a>` to every product
in it (the 3-click / crawl-depth guarantee):

```bash
curl -s -A "Googlebot/2.1" http://127.0.0.1:8095/c/it-products/computers/laptops/ \
  | grep -oE 'href="/p/[^"]+"' | sort -u | wc -l     # hundreds, not ~24
```

---

## 4. Point aaPanel at it

1. **Website → Add site** → enter the domain. "Static" or PHP, does not matter —
   the vhost is replaced by a proxy.
2. **SSL → Let's Encrypt** → issue, then enable **Force HTTPS**.
3. **Config → Configuration File** → paste the `location` / `client_max_body_size`
   / `proxy_intercept_errors` directives from
   [infra/nginx/officeland.conf](../infra/nginx/officeland.conf) **inside** the
   `server { }` block aaPanel generated, replacing the default `location /`.
   Leave aaPanel's own `listen` / `ssl_*` / `access_log` lines alone (it renews
   the cert).
4. Save — aaPanel reloads Nginx and reports any syntax error.

Verify the proxy passes scheme and headers through cleanly:

```bash
curl -sI https://your-domain/ | grep -i 'content-security-policy\|x-robots-tag'
```

Each header must appear **exactly once**. Two CSP headers = Nginx is adding its
own; the browser intersects them and the effective policy is no longer the one
that was tested. (The vhost deliberately does **not** `add_header` anything.)

---

## 5. Firewall

aaPanel → **Security**: open **80** and **443** only. Do **not** open `8095`,
`5432`, `6432` or `6379` — they bind to loopback, and opening them is the one
way to make that binding pointless.

---

## 6. Flip staging → production

Only when the catalogue is real (it now is), images are present, and the policy
pages have had whatever review they need. Then:

```bash
# in .env:
#   APP_ENV=production
#   APP_URL=https://officelandng.com
sh scripts/deploy.sh
```

Then confirm the edge opened up and submit the sitemap:

```bash
curl -s  https://officelandng.com/robots.txt            # no longer Disallow: /
curl -sI https://officelandng.com/ | grep -i x-robots   # X-Robots-Tag gone
# Search Console → submit https://officelandng.com/sitemap.xml
```

> **Known open item:** the storefront hardcodes `https://officelandng.com` as
> its canonical/JSON-LD origin (`seo.service.ts`, `structured-data.ts`). Harmless
> while staging is `noindex`; on a *non-noindex* staging subdomain it would
> canonicalise to production. Keep staging `noindex` until launch.

---

## 7. Deploy a change

```bash
cd /www/officeland && git pull && sh scripts/deploy.sh
```

[scripts/deploy.sh](../scripts/deploy.sh) does, in this order: build images →
up → **migrate** → cache config/routes/views → build the storefront → regenerate
the CSP → **run the gates against the build** → publish to the static root →
publish redirects → reload the edge → regenerate sitemaps → verify redirects. A
build that fails a gate is **never published**; the running site is untouched.

`--no-build` skips the storefront build for a config-only change.

> ⏱ **Budget ~75+ minutes for the gate step.** `npm run gate` crawls the entire
> running catalogue (home + ~740 category/pagination pages + ~5,050 product
> pages) through the SSR renderer, then runs the raw-HTML, crawl-depth, link and
> a11y gates over the result. At this catalogue size that crawl alone is over an
> hour. **Do not interrupt it** — a killed crawl leaves a partial snapshot tree
> and the gate fails spuriously. If you need a faster path, build + gate on a
> staging box and restore the verified data to production, or run with the stack
> fully up and walk away. (It is safe to run `deploy.sh` in `tmux`/`screen`.)

**Rollback:** `git checkout <previous-sha> && sh scripts/deploy.sh`. Migrations
are **forward-only** and are not rolled back (which is why they run before the
build). Restore the database from backup if a migration must be undone.

---

## 8. When something is wrong

```bash
docker compose ps
docker compose logs -n 100 api
docker compose exec api php artisan about
curl -s http://127.0.0.1:8095/health/ready
```

| Symptom | Likely cause / fix |
| --- | --- |
| Blank or unstyled page, HTML looks fine in view-source | Stale CSP hash blocking the stylesheet. `docker compose exec render node tools/csp-hashes.mjs --check`, then redeploy. |
| Crawlers get `502`, humans fine | The `render` container is down. It must stay up (`restart: unless-stopped`); a renderer outage is an **SEO incident**, not a visible site outage. Alert on `:4000`. |
| Two `Content-Security-Policy` headers | Nginx is adding one. Remove any `add_header` from the vhost — the upstream owns security headers. |
| aaPanel shows its own 404 | `proxy_intercept_errors` is on. The app serves a real 404 (and a real 404 status for crawlers); the vhost sets it `off`. |
| `/media/*` 404s | Caddy must use `handle_path` (strips the prefix), not `handle`. See [RUNBOOK.md §5](RUNBOOK.md). |
| Slow for ~1 min after deploy | Octane workers cold-starting. If it does not settle, check `docker compose logs api` for workers dying. |

Backup, restore, email, the lead queue and monitoring are in
[RUNBOOK.md](RUNBOOK.md).
