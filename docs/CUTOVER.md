# Cutover

**Phase 14.** The procedure for replacing the legacy WordPress site on `officelandng.com`.

> **This is the one irreversible thing in the project.** Everything else can be rebuilt from the database and the repository. Organic equity accumulated over years cannot, and D-007's entire justification for the redirect layer is that it survives this day.

**Do not start until the go/no-go list in §1 is fully green.** Every item on it has cost somebody a launch somewhere.

---

## 1. Go / no-go

| # | Must be true | Verify with | Status |
|---|---|---|---|
| 1 | SPF, DKIM and DMARC published and passing | RUNBOOK §8.1 | ⛔ **Q18** |
| 2 | Real quote emails arrive in real inboxes | RUNBOOK §8.6 | ⛔ **Q18** |
| 3 | Every redirect returns the right status and destination | `redirects:verify` | ✅ 182/182 |
| 4 | No indexable page below the content floor | enforced (D-048) | 🟡 **Q-B** — content unwritten |
| 5 | Backups running, and a restore drill passed | RUNBOOK §12 | 🟡 **Q21** — no offsite destination |
| 6 | CWV budgets measured on production hardware | `docs/LOAD_TEST.md` | ⛔ **Q7** |
| 7 | Accessibility gate green | `npm run gate:a11y` | ✅ 147 pages |
| 8 | Staging is **not** indexable | RUNBOOK §5 | ✅ edge-enforced |
| 9 | The quote-pipeline canary is scheduled and green | RUNBOOK §13.3 | ✅ |
| 10 | Rollback rehearsed, not just written | §5 below | ⛔ **not rehearsed** |

**Four of ten are blocked on client answers.** None of them is an engineering task.

---

## 2. Two weeks before

- **Reduce DNS TTL to 300s** on the A/AAAA records for `officelandng.com` and `www`. Do this *early*: the change only takes effect after the **old** TTL expires, so lowering it on the day achieves nothing. If the current TTL is 24h, this must happen at least 24h — realistically 48h — beforehand.
- **Take a full backup of the legacy site**, files and database. Not because we intend to go back to it, but because "we can restore the old site" is what makes the rollback decision in §5 a decision rather than a panic.
- **Record a ranking and coverage baseline.** Positions for the top ~50 terms, total indexed pages, and Search Console coverage counts. Without a baseline, §6's watch cannot tell a cutover problem from normal drift — and "traffic feels down" is not a metric anybody can act on.
- **Confirm Search Console and Bing Webmaster access** for the domain. Verifying a property on the day, under pressure, with DNS in flux, is avoidable pain.

---

## 3. The day before

- Full backup and a **restore drill** of the new system (RUNBOOK §12.3). A backup taken and never restored is not a backup, and cutover day is the worst possible time to discover that.
- `redirects:import --apply`, `redirects:publish`, restart, `redirects:verify` — all green.
- Full render, all four storefront gates green (RUNBOOK §2).
- **Freeze content edits.** Any publish after this point may not be in the final render.

---

## 4. Cutover

1. Final content sync and full render:
   ```bash
   docker compose exec api php artisan render:full
   ```
2. All gates:
   ```bash
   docker compose exec render sh -lc 'cd /repo/apps/web && npm run gate'
   docker compose exec api php artisan redirects:verify --base=https://officelandng.com
   ```
3. **Switch DNS.**
4. Watch propagation. Until it completes, some visitors reach the old site and some the new — expected, and the reason for the low TTL.
5. Once propagated, re-run `redirects:verify` **against the live domain**. This is the moment the redirect layer either works or does not, and it is checkable in about a minute.
6. Submit `sitemap.xml` to Search Console and Bing.
7. Request indexing for the homepage and the top ~10 category pages. Do **not** bulk-submit thousands of URLs; the sitemap is how the rest is discovered.

> **Do not remove the legacy site's files or database.** Keep them for the full 30-day watch. They cost storage; not having them costs the rollback.

---

## 5. Rollback

**The decision deadline is 48 hours after cutover.** Named in advance on purpose: without one, a struggling launch produces a week of "let's give it another day", which is how a recoverable problem becomes a permanent loss of rankings.

### Roll back if any of these is true at any point

| Trigger | Why it is not survivable |
|---|---|
| The site is down or erroring for **>1 hour** with no identified fix | every hour is lost traffic and lost trust |
| Quote submissions are failing | the lead pipeline is the revenue; a broken form is worse than the old site |
| **>20%** of legacy URLs are 404ing | the redirect layer has failed, which was the whole point |
| Indexed pages fall **>30%** against baseline in the first week | Google has decided something is badly wrong |

### Do NOT roll back for

Ranking wobble in the first fortnight — expected and normal after a domain-wide change. Individual pages moving. Traffic down while positions hold. **Rolling back has its own cost**: a second domain-wide change on top of the first, which is worse than riding out the first one.

### Procedure

1. Restore the legacy DNS records. With a 300s TTL this is minutes, not hours.
2. Confirm the legacy site serves and its own admin still works.
3. **Leave the new site running** on its origin. Do not tear anything down — the diagnosis happens afterwards, and destroying the evidence to tidy up is the most common mistake here.
4. Write down what triggered it, while it is fresh.

> **Rollback is untested.** It is written, not rehearsed. Rehearsing it means pointing DNS at the legacy site on a staging domain and confirming it serves — an hour's work that turns this section from a plan into a procedure. It is item 10 on the go/no-go list and it is not currently green.

---

## 6. The 30-day watch

The gate is **coverage and rankings stable against baseline over 30 days**. Not "the site is up".

**Daily for the first week, then weekly:**

- Search Console coverage — watch for a rise in "Not found (404)" or "Excluded by noindex"
- The 404 log for real traffic hitting URLs the map missed → add redirects, re-publish, re-verify
- The quote canary (RUNBOOK §13.3) and the lead queue — a quiet week is either a quiet week or a broken form, and only one of those is fine
- Rankings for the baseline terms

**Expect a dip.** A domain-wide change reliably produces one for two to four weeks. What matters is the trend and the recovery, not week one.

**The most useful signal is the 404 log.** Every legacy URL nobody mapped shows up there with a referrer, which is exactly the list of redirects still to write. It is the cheapest ranking recovery available in the whole process.

> **The 404 log is not populated yet.** The `not_found_log` table and its admin screen exist; nothing writes to them. Caddy's JSON access log has the same information (status, path, referer) but no one is ingesting it. **This should be closed before cutover** — see §7.

---

## 7. Known gaps

- **Product URL mapping (14a) is not done.** `docs/REDIRECT_MAP.csv` covers all 161 legacy *categories*. D-007 also lists **5,063 product URLs**, and mapping those needs the legacy URL list plus MPN/model-code matching against the new catalogue (D-016) — which in turn needs the catalogue to exist (Phase 5b, blocked on Q-A). Until then those URLs will 404 rather than redirect.
- **404 logging is not wired up.** See §6.
- **Rollback is unrehearsed.** See §5.
- **`www` and apex canonicalisation** is not configured — one must 301 to the other, decided before launch.
- **HSTS is sent with `preload`.** Do not submit to the preload list until every subdomain is on HTTPS; removal takes months.
