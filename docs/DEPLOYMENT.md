# Deployment — Debian 13 + aaPanel

Target: Debian 13, aaPanel, Nginx 1.30.2, Apache 2.4.68, Redis 7.2.12,
PostgreSQL already installed.

---

## 0. Read this before you start

**Deploy to a staging subdomain first.** Two reasons, and the second is the
one that costs money.

1. Nothing here has ever run outside a development machine.
2. The catalogue is **10 products against 170 categories**, there are **no
   images**, and the policy pages have not been through legal review.
   Publishing that on `officelandng.com` teaches Google that the domain is a
   near-empty site, and a first impression to a crawler is expensive to
   correct. Rankings are the primary objective of this whole rebuild; a
   premature launch works directly against it.

Staging is safe by construction: with `APP_ENV=staging`, Caddy serves
`Disallow: /` in robots.txt and `X-Robots-Tag: noindex, nofollow, noarchive`
on every response. That is enforced at the edge, not in a template, so no
page can leak past it.

**Two things were wrong and are now fixed** — both found while writing this,
neither previously deployed:

- Postgres, PgBouncer, Redis and HTTP were published on `0.0.0.0`. On a public
  server that is the database open to the internet. All four now bind
  `127.0.0.1` via `BIND_ADDR`.
- `scripts/deploy.sh` refuses to run unless `BUILD_TARGET=prod`. The dev image
  bind-mounts source and runs with debug on.

**One thing is still wrong and needs a decision** — the storefront hardcodes
`https://officelandng.com` as its origin for canonical tags and JSON-LD
(`seo.service.ts`, `structured-data.ts`). On staging, pages will canonicalise
to production. Harmless while staging is `noindex`, wrong the moment it is
not. Logged in [BACKLOG.md](BACKLOG.md).

---

## 1. The shape of it

```
Internet ──▶ aaPanel Nginx :443 ──▶ 127.0.0.1:8095 ──▶ Caddy/FrankenPHP
             (TLS, domain)                              ├─ static storefront
                                                        ├─ /api  → Octane
                                                        └─ /admin → Filament
                                                             │
                                              PgBouncer ─▶ Postgres 16
                                              Redis 7
```

**Nginx proxies; it does not reimplement.** Everything a web server does for
this application already exists and is gate-verified inside the container: the
CSP with per-build script hashes, 181 legacy redirects, the
immutable-vs-revalidate cache split, a real 404, the staging noindex. All of
it is *generated* — `csp-hashes.mjs` and `redirects:publish` emit Caddy
syntax — and the gates read Caddy's output.

Porting that to Nginx would mean a second copy of rules whose entire purpose
is correctness, regenerated on every deploy, and every gate rewritten to
verify the copy instead of the original. The proxy keeps one copy. Nginx does
TLS, the domain and the firewall, which is what aaPanel is good at.

**The containers bring their own Postgres and Redis.** You already have both,
and reusing them would save a few hundred MB. It is not worth it: PgBouncer in
transaction mode with `PGSQL_ATTR_DISABLE_PREPARES` is load-bearing (D-024,
and there is a test that fails if it regresses), the schema is tested against
Postgres 16 specifically, and the failure mode of a version mismatch is subtle
rather than loud. Keep the host's copies for whatever else the box runs.

Apache is not involved. If aaPanel has it fronting Nginx on this box, put the
site on Nginx.

---

## 2. Install Docker

aaPanel's App Store has a Docker manager, or:

```bash
apt-get update
apt-get install -y ca-certificates curl
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Verify: `docker compose version`.

---

## 3. Get the code and configure

```bash
mkdir -p /www/officeland && cd /www/officeland
git clone <repo-url> .

cp .env.production.example .env
```

Fill in every `FILL-ME`:

```bash
# APP_KEY — generate it here, never reuse the development one
docker compose run --rm api php artisan key:generate --show

# DB_PASSWORD
openssl rand -base64 32
```

Set `APP_URL` to the exact public URL including `https://`, and leave
`APP_ENV=staging` until you have read §7.

---

## 4. First run

```bash
sh scripts/serve.sh --rebuild --detach
```

About three minutes, most of it the Angular build. Then seed the taxonomy and
the copy:

```bash
docker compose exec api php artisan db:seed --class=TaxonomySeeder
docker compose exec api php artisan db:seed --class=CategoryIntroSeeder
docker compose exec api php artisan db:seed --class=CategorySeoSeeder
```

Create an admin user:

```bash
docker compose exec api php artisan make:filament-user
```

Check it locally before exposing it:

```bash
curl -I http://127.0.0.1:8095/
curl -s http://127.0.0.1:8095/robots.txt      # must say Disallow: / on staging
```

---

## 5. Point aaPanel at it

1. **Website → Add site**, enter the domain. Choose "Static" or PHP — it does
   not matter, the vhost is replaced by a proxy.
2. **SSL → Let's Encrypt**, issue and enable **Force HTTPS**.
3. **Config → Configuration File**, and paste the contents of
   [`infra/nginx/officeland.conf`](../infra/nginx/officeland.conf) inside the
   `server { }` block, replacing the default `location /`.
4. Save. aaPanel reloads Nginx and reports a syntax error if there is one.

Then check that the proxy is passing the scheme through, because this is the
one that breaks quietly:

```bash
curl -sI https://your-domain/ | grep -i 'content-security-policy\|x-robots-tag'
```

Both headers must be present and each must appear **once**. Two CSP headers
means something is adding one in Nginx as well, and the browser intersects
them — the effective policy stops being the one that was tested.

---

## 6. Firewall

aaPanel → Security. Open 80 and 443. **Do not open 8095, 5432, 6432 or
6379** — they bind to loopback, and opening them would be the only way to make
that binding useless.

---

## 7. Going to production

Only when the catalogue is real. Then:

1. `APP_ENV=production` and `APP_URL=https://officelandng.com` in `.env`
2. `sh scripts/deploy.sh`
3. Confirm `robots.txt` no longer says `Disallow: /`
4. Confirm `X-Robots-Tag` is gone
5. Submit `https://officelandng.com/sitemap.xml` in Search Console

Before that switch, these are open: no images anywhere, 124 categories without
intro copy (they stay `noindex` by design), policy pages not legally reviewed,
and the catalogue at 10 products. See [BACKLOG.md](BACKLOG.md).

---

## 8. Deploying a change

```bash
cd /www/officeland && git pull && sh scripts/deploy.sh
```

The order is deliberate: migrate, build, run the gates against the build, and
only publish if they pass. A build that fails a gate never reaches the static
root, and the running site is untouched.

`--no-build` skips the storefront build for a config-only change.

---

## 9. When something is wrong

```bash
docker compose ps
docker compose logs -n 100 api
docker compose exec api php artisan about
curl -s http://127.0.0.1:8095/health/ready
```

**Site loads but is unstyled or blank.** Almost always the CSP: a stale hash
blocks the stylesheet and the HTML still looks correct in view-source. Check
the browser console, then `docker compose exec render node tools/csp-hashes.mjs --check`.

**A page 404s that should not.** The storefront is static files. If it was not
prerendered it does not exist — check the route count in the build output.

**Everything is slow after a deploy.** Octane workers cold-start. If it does
not settle within a minute, check `docker compose logs api` for workers dying
and restarting.

**Rollback:** `git checkout <previous-sha> && sh scripts/deploy.sh`. The
database is not rolled back — migrations are forward-only, which is why they
run before the build.

Backup and restore are in [RUNBOOK.md](RUNBOOK.md) §9.
