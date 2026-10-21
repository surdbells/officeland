# Runbook

Operational procedures for the OfficeLand storefront. Written for whoever is on call, not for whoever built it.

The storefront is **static files** (D-009). There is no application between Caddy and the HTML, and there is no TTL. That single fact explains most of what follows: a page that is not regenerated serves stale content **forever**, and nothing will notice on its own.

---

## 0. Running it locally

```bash
sh scripts/serve.sh
```

That is the whole thing. It starts the containers, waits for every
healthcheck, runs migrations, publishes the storefront if nothing is
published yet, and prints the URLs. Safe to run repeatedly — containers that
are already up are left alone.

| Flag | What it does |
| --- | --- |
| *(none)* | Start. Build only if nothing is published yet. |
| `--rebuild` | Start and rebuild the storefront from scratch (~3 min). |
| `--no-build` | Start only. Serve whatever is already published. |
| `--detach` | Do not tail logs; return to the prompt. |

| Surface | URL |
| --- | --- |
| Storefront | `http://localhost:8095/` |
| Admin | `http://localhost:8095/admin` |
| API | `http://localhost:8095/api/v1/navigation` |
| Health | `http://localhost:8095/health/ready` |

Stop everything with `docker compose down`. Ctrl-C only detaches the log tail;
the stack keeps running.

**Why a script rather than `docker compose up`.** Under D-009 nothing is
rendered at request time. The site Caddy serves is a directory of static files
that only exists after an Angular build has been *published* into it, and the
build output and the served directory are deliberately different volumes so
the swap is atomic. `docker compose up` gets you containers and an empty or
stale storefront; the missing step is the one people forget, and the symptom
is a page that appears not to exist when it was simply never built.

**Cold-start timings**, measured on the development machine:

| | Wall clock |
| --- | --- |
| `serve.sh --no-build` from `docker compose down` | 33 s |
| `serve.sh --rebuild` from `docker compose down` | 2 min 56 s |

**Editor integration.** `.claude/launch.json` *attaches* to the running stack
rather than starting one — it has a `url` and no command. The port is not the
preview harness's to choose: Caddy binds 8095 from `compose.yaml`, and
`.env`, the generated redirect snippet and the CSP all assume it, so
`autoPort` is `false`. Start the stack with the script first; the preview then
connects to it. Trying to have the harness start it produces "Port 8095 is in
use by com.docker.backend.exe", which is Docker correctly holding the port
this application asked for.

in-app browser can start the stack itself.

## 1. How a change reaches production

There are two paths, and they cover different kinds of change.

| Change | Path | Typical latency |
|---|---|---|
| A product, category or brand is edited or published | Incremental: model event → `RouteResolver` → Redis queue → `render:queue` | 60 s debounce, then ~50 ms per route |
| A template, component, design token or global SEO rule changes | **Full build** — nothing in the database moved, so no event fires | See §3 |

**This is the most important operational fact in the system.** The incremental renderer cannot see a template change. If you edit the header, the footer, `SeoService`, or any component, and you do not run a full build, every existing page keeps the old markup indefinitely and no alarm sounds.

### 1.1 Incremental render

```bash
docker compose exec api php artisan render:queue --once
```

Runs continuously in production. `--once` drains what is due and stops.

Routes are **debounced by 60 seconds** (`RenderQueue::DEBOUNCE_SECONDS`) so that a bulk import of 500 products does not enqueue 500 × N renders immediately. A route claimed before its window elapses is not returned — an empty result immediately after an edit is correct, not a fault.

Check queue depth:

```bash
docker compose exec api php artisan tinker --execute 'echo json_encode(app(App\Rendering\RenderQueue::class)->depth());'
```

### 1.2 Full build

```bash
docker compose exec api php artisan render:full
```

Renders every route in the manifest through the running render worker, then regenerates the sitemaps and drops the queue (everything in it has just been rendered from current data).

`--dry-run` lists the routes and stops. `--chunk=N` changes how many are rendered per pass.

A **failed** full build leaves the site in a mixed state — some pages new, some old — so it exits non-zero and names every failed route. That is better than an empty site and worse than a complete one; do not let a deploy script continue past it.

---

## 2. Deploy procedure

1. Deploy the API and run migrations on the **direct** connection (never through PgBouncer):
   ```bash
   docker compose exec api php artisan migrate --force --database=pgsql_direct
   ```
2. Build the storefront bundles. This also prerenders every route:
   ```bash
   docker compose exec render npx ng build
   ```
3. **Publish the build.** `ng build` writes to `dist/`; the static root is a
   separate volume, and only the render container's entrypoint rsyncs one to
   the other. Restarting it is what publishes:
   ```bash
   docker compose restart render
   ```
4. If step 2 was skipped because only data changed, run a full render instead:
   ```bash
   docker compose exec api php artisan render:full
   ```
5. Regenerate the CSP if anything about the build changed (§11.1), then restart
   the edge so Caddy re-reads it:
   ```bash
   cd apps/web && node tools/csp-hashes.mjs --write && docker compose restart api
   ```
6. Verify before considering it done:
   ```bash
   docker compose exec render sh -lc 'cd /repo/apps/web && npm run gate'
   ```

> **Any template change requires step 2 or step 4.** See §1.

> **Step 3 is easy to miss and fails confusingly.** Skipping it leaves the new
> `dist/` unpublished, so the site keeps serving the previous CSS and JS while
> the source, the build and every local check all show the new version. It
> looks like a caching problem and is not one — it was found exactly that way
> during browser testing, chasing a colour change that had built correctly and
> simply never been published.
>
> `render:full` does **not** cover it: that renders HTML through the render
> worker and writes pages, but the worker republishes assets only at start-up.

---

## 3. Measured timings

Measured on the development stack (Docker Desktop, Windows, 20 cores), against a 10-product demo catalogue and the full 124-category taxonomy.

| Operation | Routes | Wall clock | Per route |
|---|---|---|---|
| `render:full` (bundle already built) | 145 | **7.6 s** | **52 ms** |
| `ng build` (compile + prerender) | 145 | ~200 s | — |
| Incremental render after one product edit | 5 | 0.33 s | 66 ms |
| Derivative generation (Phase 4) | 1 image → 15 files | 3.6 s | — |

### 3.1 Extrapolation to the launch catalogue

At 15,000 products the manifest is roughly **15,150 routes** (products, 124 categories, their paginated pages, brand hubs, static pages).

| Operation | Estimate | Basis |
|---|---|---|
| `render:full`, single worker | **~13 minutes** | 15,150 × 52 ms |
| `ng build` full prerender | **~1.5–3 hours** | extrapolated from 145 routes; dominated by per-route bootstrap |

**This is the number that matters for planning:** a template fix takes ~13 minutes to reach every page via `render:full`, and considerably longer if the bundle must be recompiled. It is the floor on time-to-recover for any markup or global-SEO defect.

> These are development-machine figures and should be re-measured on the production host once **Q7** (server spec) is settled. See §6 for why this machine in particular under-reports.

---

## 4. Sitemaps

```bash
docker compose exec api php artisan render:sitemaps
```

Regenerated by the full build, and enqueued incrementally whenever an indexable record changes.

**The invariant is one-directional: sitemap ⊆ manifest.** The manifest decides what *exists*; the sitemap decides what is *offered to Google*, and it filters on `is_indexable` where the manifest deliberately does not. A URL in the sitemap with no file behind it is a 404 reported straight to Search Console.

A category with no intro copy is `is_indexable = false`, so it is rendered and linked but not sitemapped. **An empty `sitemap-categories.xml` is expected until Q-B (content) is resolved** — it is not a fault.

---

## 5. Common faults

### Every image 404s
Check that Caddy uses `handle_path` and not `handle` for `/media/*`. `handle` does not strip the URL prefix, so it looks for `<root>/media/ab/cd/...` — a path that never exists — while the files sit correctly on disk.

### An unknown URL returns the homepage
Two separate causes, and the second is the one that actually bit.

`handle_errors` must rewrite to `/404/index.html`, not `/index.html`. The 404 template is prerendered from an explicit `404` route; a wildcard route cannot be prerendered, because Angular has no parameter to render it under.

**But `handle_errors` only fires if something 404s in the first place.** If the static handler ends its `try_files` with `/index.html`, Caddy finds the homepage, answers **200**, and the error handler is never reached. See §14.5 — this is a soft 404 on every unknown URL and it looks exactly like the site working.

### A page is stale and the queue is empty
Almost always a template change with no full build. See §1.

### The render worker returns 403 with "Header host is not allowed"
`angular.json` → `security.allowedHosts`. This is SSRF protection and is deliberately not a wildcard; see the comment in `apps/web/src/server.ts` before adding to it.

### Everything is slow, including `/health/live`
See §6.

### The site flaps 502 ("connection refused" to 127.0.0.1:8095)
The edge (aaPanel nginx) proxies to the `api` container on `127.0.0.1:8095`. A
`502 connection refused` means nothing was listening *at that instant* — almost
always the brief window while the `api` container restarts during a deploy
(`restart: unless-stopped` brings it back in ~15–20s). It is NOT normally memory:
check first with `free -h` and `dmesg -T | grep -i oom` — the box is 62 GB and an
OOM would show there. A flood of successful `200`s in `docker compose logs api`
either side of the gap confirms it was a restart window, not an outage.

Two mitigations:
- **Run routine deploys with `--no-gate`.** The gate crawls the whole catalogue
  through the SSR renderer (every page fires many API calls); it is an hour of
  self-inflicted load and is pure verification — run it deliberately, off-peak.
- **Let the edge retry the upstream** so a restart is invisible. In the aaPanel
  site's proxy `location` block (the one pointing at `127.0.0.1:8095`):

  ```nginx
  proxy_connect_timeout 3s;
  proxy_next_upstream error timeout http_502 http_503 http_504;
  proxy_next_upstream_tries 3;
  proxy_next_upstream_timeout 15s;
  ```

---

## 6. Development-environment performance (Docker Desktop for Windows)

A bind-mounted file read costs about **29 ms** on this platform. Measured: 500 `vendor/` files took **14.5 s** from the bind mount against **0.01 s** from container-local storage — a factor of ~1,450.

Laravel reads hundreds of files to boot, so a cold Octane worker could not finish booting inside the 30-second request limit: it timed out, died, and its replacement cold-booted into the same wall. The symptom is that *every* endpoint becomes slow, including ones that touch nothing, and the errors appear at random framework locations (`AliasLoader`, `Connector`, `Application`) because the timeout lands wherever the boot happened to be.

**Mitigation, already applied:** `vendor/` is a named volume (`api_vendor`), not part of the bind mount. It is generated by composer and never hand-edited, so nothing is lost. Same reasoning as `web_node_modules` on the render service.

After changing `composer.json`:

```bash
docker compose exec api composer install
```

Application code (`app/`, `config/`, `routes/`) stays on the bind mount because it is edited live. That still costs ~2 s per cold request on this platform. **None of this applies to Linux hosts**, where bind mounts are native.

**Do not take performance measurements on this machine and treat them as representative.**

---

## 7. Database

PgBouncer runs in **transaction pooling** mode. Two settings are load-bearing and are explained at length in `infra/pgbouncer/pgbouncer.ini` and D-024:

- `max_prepared_statements = 0` — raising it breaks transactions.
- `PDO::PGSQL_ATTR_DISABLE_PREPARES => true` — without it, statements fail under concurrency with `prepared statement "pdo_stmt_00000001" does not exist`.

Neither is a tuning knob. `PgBouncerConcurrencyTest` fails if either changes.

Migrations use `pgsql_direct` and bypass the pooler entirely: DDL and long transactions do not behave correctly through a transaction pooler.

---

## 8. Email

Transactional email goes through **ZeptoMail's HTTP API** (§13.2), not SMTP. Every message is queued through Redis; nothing is sent inline in a web request.

### 8.1 GO-LIVE BLOCKER: SPF, DKIM and DMARC

**The site must not go live until all three are published and verified.** This is not a hardening task to schedule afterwards.

Without them, quote confirmations land in spam or are rejected outright. The customer concludes we never replied, the salesperson concludes the customer went quiet, and **nobody involved learns that the email was the problem**. It is the single most damaging failure available to this system, and it is invisible from every side.

| Record | Where | What |
|---|---|---|
| **SPF** | `TXT` on the sending domain | Must include ZeptoMail's sender. One SPF record only — two is a permanent fail, not a merge. |
| **DKIM** | `TXT` on the selector ZeptoMail issues | Published exactly as given; a re-wrapped or truncated key fails silently. |
| **DMARC** | `TXT` on `_dmarc.<domain>` | Start at `p=none` with `rua=` reporting, and only tighten once the reports are clean. |

**Verify before launch, not after:**

```bash
dig +short TXT officelandng.com | grep spf
dig +short TXT <selector>._domainkey.officelandng.com
dig +short TXT _dmarc.officelandng.com
```

Then send to a real Gmail address and inspect **Show original**: SPF, DKIM and DMARC must all read PASS. A test send that arrives is not evidence — it may have arrived *despite* a failure, and the next one may not.

> **Do not start at `p=reject`.** The legacy site sends through `hostinger-reach`, and **Q18 asks for an inventory of what that currently sends**. Any of it still in use — order notifications, contact forms, password resets — starts failing the moment DMARC is enforced, and the failure will look like a ZeptoMail problem.

### 8.2 Configuration

Set on the client's own server. **The development team never holds these values.**

| Variable | Notes |
|---|---|
| `MAIL_MAILER=zeptomail` | |
| `ZEPTOMAIL_TOKEN` | The *send* token, from the Mail Agent's SMTP/API tab. |
| `ZEPTOMAIL_ENDPOINT` | Defaults to `https://api.zeptomail.com/v1.1/email`. **EU tenants must use `https://api.zeptomail.eu/v1.1/email`** — see below. |
| `ZEPTOMAIL_WEBHOOK_SECRET` | The webhook Authentication Key. A **different** secret from the send token. |
| `MAIL_SALES_ADDRESS` | Where quote notifications land. |
| `MAIL_FROM_ADDRESS` | Must be a verified sender on the ZeptoMail domain. |

**Region (Q18).** A EU-hosted ZeptoMail tenant answers only on `api.zeptomail.eu`. Sending a EU token to the `.com` endpoint returns a **401** that reads exactly like a bad key, and the natural response — reissuing the token — does not help. Confirm the region before touching the token.

**Two different secrets.** Using the send token as the webhook secret verifies nothing: every webhook is rejected, bounces never reach the suppression list, and the only symptom is a slowly worsening sender reputation.

### 8.3 What gets sent

| Template | To | Reply-to | Trigger |
|---|---|---|---|
| `quote.confirmation` | the customer | sales | quote submitted |
| `quote.received` | sales | **the customer** | quote submitted |

The customer receipt carries the **full quote detail in the HTML body** — D-006 removed the PDF, so the email *is* the record the customer keeps.

The internal notification's reply-to is the customer, so a salesperson answers a lead by hitting reply rather than copying an address out of the body.

### 8.4 Retries and failures

Five attempts, backing off `10s → 60s → 5m → 15m`. The later delays are deliberately long: a provider blip clears in seconds, but a DNS or reputation problem takes minutes, and five fast retries burn every attempt before the problem can clear.

```bash
docker compose exec api php artisan queue:failed          # exhausted sends
docker compose exec api php artisan queue:retry <id>      # after fixing the cause
```

**Check the cause before retrying.** A send that failed five times because DKIM is wrong will fail a sixth time, and each attempt adds to the reputation damage.

Every send is logged as `mail.sent` with the request id, recipient, subject, quote reference and provider status. `client_reference` carries the quote reference into ZeptoMail's own logs, so *"was `OL-Q-2026-000042` ever sent"* is answerable from their dashboard alone.

### 8.5 Bounces and the suppression list

`POST /api/v1/webhooks/mail`, configured in ZeptoMail's webhook settings.

Verified by HMAC-SHA256 over the raw body, from the `producer-signature` header. The endpoint is unauthenticated by necessity — the caller cannot hold our credentials — so **the signature is the entire security boundary**: an unverified webhook could suppress any address an attacker names, which is a denial of service against our own customers delivered through our own bounce handling.

It **fails closed**: with no secret configured, every webhook is rejected.

| Event | Action |
|---|---|
| hard bounce | suppressed permanently |
| spam complaint | suppressed permanently |
| soft bounce | logged only |
| opens, clicks | ignored |

A **soft** bounce is not suppressed. A full mailbox clears on its own, and suppressing on the first one would permanently stop writing to a customer whose inbox was briefly full — losing orders to fix a problem that fixed itself.

Suppressions are visible and releasable at **Quotes → Email suppressions**. Releasing is deliberate and records who did it. Only release when you know the mailbox works: sending to a dead address re-earns the bounce, and repeated bounces to the same address are what damage the sending domain.

**A suppressed customer still generates a sales notification.** They get no receipt — recorded on the quote as `confirmation_suppressed` — so sales phones instead. A bad email address must not become a lost order.

### 8.6 Verifying a real send

The Phase 10 gate — *real send to real inboxes, failure and retry path proven, bounce webhook verified* — **requires Q18 credentials and cannot be completed without them.** Once they exist:

1. Publish SPF/DKIM/DMARC (§8.1) and confirm all three PASS in Gmail's *Show original*.
2. Submit a real quote and confirm both emails arrive.
3. Send to a known-invalid address on the sending domain, confirm the webhook fires and the address appears in the suppression list.
4. Break the token deliberately, confirm the job retries and lands in `queue:failed`, then fix it and `queue:retry`.
5. Render both templates in Gmail, Outlook and Apple Mail, in light and dark mode.

---

## 9. The lead queue

Filament → **Quotes → Lead queue**. Visible to Sales and Super Admin only: a quote carries a customer's contact details and buying intent, which is not catalogue data.

D-005 put pricing and the reply itself outside this system. So the queue's only job is making sure every lead is **seen, owned and answered** — and everything below serves that.

### 9.1 The four tabs

| Tab | Shows | Use it for |
|---|---|---|
| **Waiting** | No first reply yet, not closed | The working view. Oldest first. |
| **Late** | Waiting and past 4 working hours | Triage when the backlog is deep |
| **Mine** | Assigned to you | Your own list |
| **Everything** | All leads ever | Finding one from last week |

The filters underneath compose with whichever tab is open.

Oldest-first is deliberate and is the opposite of most admin tables. Newest-first buries the lead that has waited longest under every new submission.

### 9.2 The four hour clock

The **Waiting** column counts **working hours** — 08:00–17:00, Monday to Friday, Lagos time — not elapsed hours (D-044). A quote submitted at 16:30 on Friday and answered at 09:30 on Monday reads as **one working hour**, which is the honest number.

**Public holidays are not modelled.** A lead over a holiday reads as roughly one day of overrun; read it accordingly. Making this exact needs a maintained holiday table, not a code change.

The navigation badge shows how many leads are waiting, and turns **red** as soon as any one of them is past four working hours.

### 9.3 Press "I have replied"

**Nothing detects that you answered a lead.** You reply from your own mail client, outside this system, so the clock is stopped by the button and by nothing else (D-045).

If it is not pressed the lead stays in **Waiting** forever, the badge keeps counting it, and the SLA figure stops meaning anything. Pressing it also assigns the lead to you if nobody had taken it.

### 9.4 What each action records

Everything lands on the timeline at the bottom of the lead, including the events nobody performed — a suppressed confirmation, an email that failed to queue. Those are the ones that explain a customer who says they never heard from us.

| Action | Effect |
|---|---|
| **I have replied** | Stops the clock; assigns to you if unowned; `new` → `contacted` |
| **Assign** | Changes the owner |
| **Add a note** | Internal only — the customer never sees it |
| **Change status** | `won`/`lost`/`spam` stamp a closing time; anything else clears it |

Use the **Why** box when marking a lead **Lost**. It is the only record of the reason that will ever exist.

### 9.5 Routing notifications

Filament → **Quotes → Notification routing**.

Leads without a matching rule go to `MAIL_SALES_ADDRESS`. A rule sends one category — **and everything beneath it** — somewhere else. The most specific matching rule wins, so `/it-products/printers/` can be routed separately from `/it-products/` regardless of the order the rules were added.

Matching is on the primary category of the **first line** of the quote. A quote spanning departments has to land somewhere, and the first line is what the buyer reached for first.

**Routing can narrow but never drop.** No rule, a paused rule, a deleted category, a product with no category — all of them fall back to the default inbox. There is deliberately no "send nowhere".

Prefer a **shared team inbox** over a personal address: a personal address survives neither a holiday nor a resignation, and the failure mode is silent.

**After adding or editing a rule, press "Send a test".** It sends the real notification template through the real transport, so a typo in the address bounces at you instead of quietly swallowing a lead. The test uses a made-up sample quote — never a real customer's details — and creates nothing in the queue.

### 9.6 Fault: leads are not arriving

In order:

1. **Notification routing** — is there a rule sending them somewhere unexpected? Press *Send a test* against the address you expect.
2. **Quotes → Email suppressions** — is the recipient suppressed? A suppressed *sales* address stops the notification entirely.
3. `php artisan queue:failed` — did the job fail rather than never exist?
4. The lead itself — open it and read the History section. `email_queued` means it reached the queue; `email_queue_failed` means Redis was unreachable and **nothing was enqueued to retry**.

---

## 10. Content

### 10.1 Why a page will not go indexable

`is_indexable` is refused — with an exception, not a silent correction — while a page is below the SEO_SPEC §7 floor (D-048). The guard is on the **model**, so it applies to the CSV importer and any future Icecat sync as well as to the admin form.

| Record | Must have |
|---|---|
| **Product** | 120+ words of original description · 3+ specifications · an "in the box" list · a warranty line · a lead time |
| **Category** | a 150+ word intro |

Two further product rules catch the failures a word count cannot:

- **The supplier's text verbatim is not original copy** (D-015). Compared on the text, not the markup, so reformatting through the editor does not launder it.
- **A generated description needs a person's approval** (D-049), given *after* the machine last wrote it. Regenerating invalidates a prior approval automatically.

The full checklist for one product is on the **Description** tab of the product form, live. The whole catalogue is at **Catalogue → Content readiness**.

### 10.2 Drafting descriptions

```bash
docker compose exec api php artisan content:draft
```

Dry run by default. The report is worth reading on its own — *"not enough structured data to describe"* counts products that need **data**, not copy, which is a different job for a different person.

Add `--apply` to write. Every draft is stamped and none of them can be indexed until approved one at a time on the product page.

It never overwrites an approved description, so re-running after a week of editing is safe.

### 10.3 "Reads like another product"

The similarity gate compares a description against others **in the same family** — same brand, same primary category — after stripping the name, SKU, MPN and model code from both (D-050).

That stripping is the point: left in, those are the one part guaranteed to differ, so they would drag the score down exactly where the boilerplate is worst.

Flagged is **not** rejected. Genuine variants exist — four colours of one cartridge legitimately share most of their text. Read it, and either rewrite it or press **Approve this description**. Use **Re-check for duplication** after a rewrite to clear the flag immediately.

### 10.4 What to write first

**Catalogue → Content readiness** orders the queue by demand from our own search log, weighting queries that returned **nothing** three times higher — that is demand we are visibly failing to meet (D-051).

This is a stand-in for keyword value, and it says so on the screen. Replace it once Search Console has 28 days of data.

**Categories before products.** 124 intros are a week and unlock whole subtrees; 15,000 descriptions are a quarter.

---

## 11. Security

Full review: `docs/SECURITY_REVIEW.md`. This section is the operational half — what breaks, and what to change.

### 11.1 The Content-Security-Policy is generated, not hand-written

`infra/caddy/csp.caddy` contains SHA-256 hashes of the inline scripts Angular emits into every prerendered page. **A stale hash blanks the site**: the browser refuses to run the hydration bootstrap, the page renders as static HTML with nothing interactive, and the only symptom is a console message no user will report.

After any Angular upgrade or build-configuration change:

```bash
docker compose exec render sh -lc 'cd /repo/apps/web && npx ng build' && cd apps/web && node tools/csp-hashes.mjs --write
```

Then commit `infra/caddy/csp.caddy` and restart the api container. CI runs `npm run gate:csp` and fails the build when the two disagree, so this cannot ship silently — but it *can* be missed locally.

If the tool reports more than 24 distinct hashes, something page-specific is being inlined. Do not raise the ceiling: find what changed.

### 11.2 Rate limits, and the one way to get them badly wrong

Limits are per client IP, per surface, configured in `.env` (see `API_RATE_*`). Search is deliberately the tightest — it is the most expensive query in the system.

**`API_TRUSTED_PREFIXES` is the dangerous setting.** Addresses matching it are exempt entirely, so the render worker can walk 15,000+ routes during a full build. This is only safe because PHP runs *inside* Caddy under FrankenPHP: there is no proxy hop, and a client on the internet cannot present a private source address.

> **Before putting a load balancer or CDN in front of Caddy**, re-read F1 in `SECURITY_REVIEW.md`. If the upstream's address matches a trusted prefix, every request on the internet becomes exempt and the limits stop existing.

Symptom of getting it wrong in the other direction: `render:full` reporting failed routes with 429. It exits non-zero and names them, so it fails loudly.

### 11.3 Reading the authentication log

Failed logins, lockouts and password resets are logged at `warning`; successful logins and logouts at `info`.

```bash
docker compose exec api sh -lc 'grep auth. storage/logs/laravel.log | tail -50'
```

| Pattern | What it usually means |
|---|---|
| Many `auth.failed`, one email, `user_exists: true` | Somebody is attacking a real account. Tell the account holder; consider forcing a reset. |
| Many `auth.failed`, many emails, `user_exists: false` | A scanner working a leaked address list. Noise, unless it persists. |
| Any `auth.lockout` | Five attempts in a minute. No one mistypes their own password five times in a minute. |
| `auth.password_reset` nobody requested | Treat as an account takeover in progress. The mailbox is the weak point, not the panel. |

### 11.4 Things that are deliberate, so do not "fix" them

- **No strict CSP on `/admin`.** Livewire and Alpine build functions at runtime, so it would need `'unsafe-eval'` — most of the appearance of protection, little of the substance. The panel is defended by auth, mandatory 2FA, the IP allowlist, a non-default path and `frame-ancestors`.
- **`style-src` allows `'unsafe-inline'`, `script-src` does not.** Angular sets style attributes during hydration and hashes cannot cover those. Inline style is a defacement risk; inline script is code execution.
- **HSTS is sent with `preload`.** Do not submit the domain to the preload list until every subdomain is on HTTPS — removal takes months.

---

## 12. Backup and restore

> **An untested backup is not a backup.** The restore drill is the deliverable here, not the backup scripts. Run it on a schedule; a drill that has not run this month is a backup you are guessing about.

### 12.1 What is protected, and by what

| Asset | Mechanism | Cadence |
|---|---|---|
| Postgres, point-in-time | `pg_basebackup` + continuous WAL archiving | base nightly, WAL continuous |
| Postgres, logical | `pg_dump -Fc` | weekly |
| Media tree (50 GB+) | `rsync --ignore-existing` | nightly |
| Prerendered HTML | **not backed up** — regenerated by `render:full` (§1.2) |  |
| Redis | **not backed up** — cache and queue only; a lost queue re-renders |  |

WAL archiving is configured in `compose.yaml` on the postgres service, writing to a **separate volume** from the data. That separation is the point: a backup on the same device as the thing it backs up is not a backup.

> **Archive failures are near-silent.** If `archive_command` starts failing, Postgres retries forever, `pg_wal` grows without bound, and the first symptom anybody notices is a full disk taking the database down. Alert on `pg_stat_archiver.failed_count` (Phase 13e).

### 12.2 Taking a backup

```bash
docker compose exec -T -u postgres postgres sh -s < scripts/backup/pg-base-backup.sh
```

Weekly, additionally:

```bash
docker compose exec -T -u postgres postgres sh -s < scripts/backup/pg-logical-dump.sh
```

Media, to a destination on **another host**:

```bash
docker compose exec -T api sh -s /path/or/user@host:/path < scripts/backup/media-backup.sh
```

> Run the Postgres scripts as **`-u postgres`**. Run as root and the artefacts are root-owned, and the drill — which runs as postgres — fails with a permission error that looks like corruption.

### 12.3 The drill

```bash
docker compose exec -T -u postgres -e PGUSER=officeland postgres sh -s < scripts/backup/pg-restore-drill.sh
```

Restores the most recent base backup into a throwaway cluster on port 5499, replays the WAL archive, promotes it, and compares row counts against the live database. **The live instance is untouched**, so this is safe to run on a schedule and safe to run in production.

It fails loudly and non-zero on a truncated artefact, a broken archive, or a row-count mismatch. A restore that starts cleanly and holds the wrong data is the worst outcome available, because it looks like success — which is why it compares counts rather than checking the exit code.

### 12.4 Measured, 2026-08-21

Development stack, 12 MB database / 50 MB cluster, 608 media files / 25.9 MB.

| Step | Wall clock |
|---|---|
| Base backup | **1 s** (6.3 MB compressed) |
| Drill — extract | 1 s |
| Drill — recover and replay WAL | **10 s** |
| Drill — promote | 1 s |
| **Drill total** | **12 s** |
| Logical dump | <1 s (168 KB) |
| Media, first run | <1 s (25.9 MB, 608 files) |
| Media, second run | <1 s, **0 files transferred** |

**Two things these numbers do and do not tell you.**

The 10-second recovery step is almost entirely fixed cost — cluster startup, crash recovery, WAL replay of a nearly idle database — not throughput. It will not scale linearly with the database, and at a few GB the extract step becomes the dominant term instead.

The media figures are the meaningful ones. The second run transferring **zero** files is the content-addressing property doing its job: paths are derived from content, so a file at a path never changes, and after the first run the nightly job is a directory comparison rather than a 50 GB read. That is what makes a 50 GB+ tree backable on a schedule at all.

> These are development-machine figures on a toy dataset. **Re-measure on the production host** (Q7) before anybody quotes an RTO. See §6 for why this machine in particular under-reports.

### 12.5 Verified end to end

The drill was proven to catch what it claims to catch, not just to exit zero:

- **WAL replay works.** A row inserted *after* the base backup was recovered by the drill — the backup held 124 categories, the restored cluster held 125. Without a working archive that number would have been 124 and the drill would have failed.
- **Corruption is caught.** A truncated `base.tar.gz` fails the drill with exit 1 rather than producing a half-restored cluster.
- **The media backup is genuinely incremental.** Second run: 0 files transferred, and `diff -r` reports the trees identical.

### 12.6 Still open

- **Where the offsite copy goes (Q21).** Every script takes a destination and none of them has one. It must be a different **host** — a copy on the same machine survives a bad `rm` and nothing else, not a failed disk and not ransomware. Q21 also has to settle retention and the RPO/RTO these are meant to meet.
- **Media restore is untested.** The copy is verified; pulling it back and serving a page from it is not. That belongs with the first real staging restore.
- **Nothing is scheduled.** These are scripts, not cron entries. Scheduling, and alerting when a run does not happen, is Phase 13e.

---

## 13. Monitoring and alerting

### 13.1 The one cron entry everything depends on

```bash
* * * * * cd /srv/officeland && docker compose exec -T api php artisan schedule:run
```

Without it every scheduled check is **inert** — and inert in the quietest way available. Nothing errors, nothing logs, the quote canary simply never runs, and the absence of alerts reads as health.

Install `infra/cron/officeland.cron` on the database host; it carries this line plus the backup entries.

### 13.2 What runs, and what it is really watching

| Check | Cadence | Watches for |
|---|---|---|
| `monitor:quote` | every 15 min | the lead pipeline silently not accepting quotes |
| `monitor:backups` | daily 09:00 | backups that **stopped happening**, and WAL archiving failures |
| `media:reap --apply` | weekly | derivative sprawl |
| base backup / logical dump / restore drill | host cron | see §12 |

### 13.3 The quote canary

```bash
docker compose exec api php artisan monitor:quote
```

Submits a synthetic quote through the real `QuoteSubmitter` — basket, quantity rules, reference allocation, snapshotting, the event trail — then **rolls the whole thing back**.

Three properties matter, and all three are asserted in `QuotePipelineMonitorTest`:

- **It leaves nothing behind.** A synthetic lead arriving four times an hour would train sales to ignore the queue, destroying what Phase 11 protects.
- **It burns no reference numbers.** References are gapless per year (D-038); an un-rolled-back monitor would consume ~35,000 a year.
- **It sends no mail.** The submitter writes, the *controller* mails. Move the mailer inside `submit()` and the monitor starts notifying sales every fifteen minutes — which is why the absence is a test, not a comment.

`monitor.quote.failed` is logged at **critical**. That is the one to page on: the business's inbound enquiries are not being accepted, and every minute is leads arriving nowhere.

Use `--keep` to commit a synthetic quote when debugging by hand. Delete it afterwards.

### 13.4 The backup heartbeat

```bash
docker compose exec api php artisan monitor:backups
```

Catches the failure that backup scripts cannot catch about themselves: **the backup that silently stopped running.** A cron entry never installed on a new server, a renamed container, a full disk — all of them leave the existing backups sitting there looking healthy.

It checks two things:

- **`pg_stat_archiver.failed_count`.** A failing `archive_command` is retried forever while `pg_wal` grows; the first symptom is the disk filling and the database stopping, by which point the archive has a hole and PITR is broken for that window.
- **Age of the newest base backup**, default limit 36h.

> The age check is **skipped**, not failed, when the backup volume is not visible — the API container does not mount it, and a daily false alarm is how a real one gets ignored.

**This is deliberately not part of `/health/ready`.** A stale backup must page somebody; it must not deregister the instance. Failing readiness over an old backup is a self-inflicted outage in response to a problem that has not caused one.

### 13.5 Not built yet

- **Sentry** (release tagging, source maps) needs a DSN — a client credential. When it is wired, quote payloads must be scrubbed before they leave: `email`, `phone` and `message` are customer PII, and shipping them to a third party is an NDPA question, not just a config one.
- **Nothing routes these logs anywhere.** `critical` reaching a log file is not an alert. Whatever watches — Sentry, a log drain, a cron mailer — still has to be chosen and pointed at `monitor.quote.failed` and `backup.monitor.failed`.
- **k6 load testing (13b)** and **axe-core (13c)** remain open; both need inputs this environment cannot supply (Q7, and a browser harness).

---

## 14. Redirects and cutover

### 14.1 How a redirect actually reaches a crawler

Four steps, and **all four must happen**. Before Phase 14 the first three existed and the fourth did not, so every legacy URL returned 200 with the homepage while the table, the CSV and the admin screen all looked correct.

```
docs/REDIRECT_MAP.csv          reviewed in pull requests
  → redirects:import --apply   into the `redirects` table
  → redirects:publish          generates infra/caddy/redirects.caddy
  → docker compose restart api Caddy re-reads it
  → redirects:verify           proves a crawler gets the right status
```

> **`redirects:publish` writes a file Caddy only reads at start-up.** Editing a redirect in Filament changes nothing on the live site until it is published *and* the edge restarts. This is the redirect equivalent of the asset-publish trap in §2.

### 14.2 Why Caddy and not PHP

D-009 puts no application in the request path, and a redirect is the last place to make an exception: legacy URLs get hit by crawlers **in bulk** in the weeks after cutover — exactly when the site is under most scrutiny — and that traffic produces no page and no revenue.

A `map` rather than thousands of `redir` lines: at D-007's 5,063 product URLs, individual matchers would be 10,000+ lines each evaluated in turn. A map is one lookup regardless of size.

### 14.3 The gate

```bash
docker compose exec api php artisan redirects:verify --base=http://127.0.0.1
```

Makes a real request for **every** `from_path` and fails on any of:

| Failure | Why it matters |
|---|---|
| Wrong status | a 301 answering 200 is a legacy page still live; answering 404 has thrown the equity away |
| Wrong destination | worse than no redirect — it looks right in a report and reads as bait-and-switch to a crawler |
| Chain | every hop loses a little; point the map at the final destination |
| Loop | an outage on a URL Google already has indexed |

It does **not** follow redirects, deliberately: following would report the final page and hide a 302 pretending to be a 301, or a two-hop chain.

Verified against its own failure mode — a deliberate chain was inserted and the gate caught it and exited non-zero.

### 14.4 Status codes, and 410 vs 404

| Code | Use for |
|---|---|
| **301** | a legacy URL with a genuine successor |
| **302** | temporary only. If you are unsure, it is a 301 |
| **410** | deliberately gone with no successor — `/wp-admin/`, `/feed/`, `/cart/`, merchandising pseudo-categories |
| **404** | not in the map at all: unknown, possibly a typo |

**410 is not a stricter 404.** It tells a crawler the URL is gone on purpose and will not return; it is dropped from the index faster and stops being re-crawled. A 404 is retried for months.

> The 410 block is wrapped in `handle`, not emitted as a bare `respond`. Caddy's directive order puts `respond` **after** `handle`, so a bare respond loses to the static file handler and the URL 404s instead — which is what it did until this was found.

### 14.5 Fault: a legacy URL returns 200 with the homepage

This was the state of the system before Phase 14, and it is worth recognising because it looks like the site working.

The static handler's `try_files` ended in `/index.html`. That is an SPA fallback and it is wrong for a prerendered site: under D-009 every real route has its own file, so falling back to the homepage can only ever be incorrect. Caddy found a file, answered **200**, and `handle_errors` never fired — a soft 404 on every unknown URL, which SEO_SPEC §8 forbids, and it also made the `/404/` page built in Phase 8 unreachable.

Check with any nonsense URL:

```bash
curl -sS -o /dev/null -w "%{http_code}\n" http://localhost:8095/definitely-not-a-page/
```

404 is correct. 200 means the fallback is back.

### 14.6 Fault: a new category is missing from the megamenu

The pages exist and serve, but the department never appears in the header.

**The navigation endpoint is cached.** `ng build` fetches `/api/v1/navigation` once at build time to render the megamenu, so a taxonomy change that has not invalidated that cache produces a build with the *old* tree — every new page renders and is reachable by URL, and nothing links to it.

It fails quietly: the gates all pass, because the pages are real and the crawl depth is unaffected.

```bash
docker compose exec api php artisan cache:clear
docker compose exec render sh -lc 'cd /repo/apps/web && npx ng build'
docker compose restart render          # publish — see §2 step 3
```

> Seeding the taxonomy does not clear the cache. Until it does, `cache:clear`
> belongs in the same breath as `db:seed --class=TaxonomySeeder`.

---

## 15. Bulk product import (Icecat)

### 15.1 The worker is what runs the import
There is an always-on `worker` service (compose.yaml) running `queue:work` over
the `imports`, `media` and `default` queues. It is what actually drains queued
work — Icecat import jobs and image derivatives — in the background, off the
request path. It recycles itself hourly (`--max-time`) and exits if it grows past
`--memory=448`MB; `restart: unless-stopped` brings it straight back. Its
container is capped (`mem_limit 1536m`, `cpus 2.0`) so a runaway job can take down
at most the worker, never the api or the box.

Confirm it is running and consuming:

```bash
docker compose ps worker
docker compose logs --tail=30 worker
docker compose exec -T api php artisan queue:monitor imports,media
```

### 15.2 Importing a brand (the resilient way)
Prefer `catalogue:icecat-import` over `catalogue:icecat-discover`. It only
streams the index and ENQUEUES one small job per new product; the worker does the
fetching and image work. It survives an SSH disconnect (the jobs outlive your
shell), retries transient Icecat errors on its own, and spreads the load.

```bash
# dry run — how many NEW products would be queued
docker compose exec -T api php artisan catalogue:icecat-import --brand=dell --since=2022

# enqueue them (worker drains in the background)
docker compose exec -T api php artisan catalogue:icecat-import --brand=dell --since=2022 --apply

# every configured brand in a single index pass
docker compose exec -T api php artisan catalogue:icecat-import --all --since=2022 --apply
```

It is resumable: products already imported (dedup by `icecat_id`) are never
re-queued, so re-running only adds what is new. Each product mirrors at most
`config('icecat.max_images')` images (default 3).

Then publish the drafts that have a renderable image, specs and a category:

```bash
docker compose exec -T api php artisan catalogue:icecat-publish --brand=dell --apply
```

> `catalogue:icecat-discover` still exists for small/interactive runs, but it does
> the fetching and (optionally inline) image work in one foreground loop — fine
> for a few hundred products, fragile for a brand with thousands.

### 15.3 Trimming images on already-imported products
The 3-image cap applies to new imports. To bring older products (imported when
the cap was higher) into line:

```bash
docker compose exec -T api php artisan catalogue:trim-product-images --keep=3          # dry run
docker compose exec -T api php artisan catalogue:trim-product-images --keep=3 --apply
```

It only removes the product→media links beyond the cap; the content-addressed
Media rows are kept.

### 15.4 If the queue backs up
`queue:monitor` shows depth. The worker processes serially per container; to go
faster, scale it:

```bash
docker compose up -d --scale worker=3 worker
```

Watch host memory (`docker stats`) when scaling — each worker can use up to its
`mem_limit`. The media queue is the slow part (libvips derivatives); the imports
queue is mostly network wait.
