# Load test — search and facets

**Phase 13b.** Run 2026-08-22 with k6 against the development stack. Script: `infra/k6/search.js`.

> **The headline numbers in this document are worthless as absolutes, and the document says so throughout.** What it establishes is a *method* and two portable facts. Re-run on the production host before anybody quotes a capacity figure — see **Q7**.

---

## What was tested, and why only this

Under D-009 every catalogue page is a static file served by Caddy with **no application in the path**. Load testing those measures Caddy and the kernel, not this system.

Search is the opposite: the one surface rendered at request time, and the most expensive query in the codebase — weighted FTS, then a **trigram fallback whenever FTS finds nothing**, then facet aggregation. The script therefore drives three deliberately different cost paths rather than a realistic-looking mix:

| Path | Query shape | Why included |
|---|---|---|
| `hit` | resolves through FTS and stops | the common case |
| `miss` | finds nothing, pays FTS **and** the trigram fallback | the worst case, and the one a scraper produces |
| `code` | exact SKU/MPN short-circuit | the cheapest path |

A load test built only from queries that match measures the fast path and reports it as capacity.

**Rate limiting is bypassed on purpose.** k6 runs on the compose network, so its address falls inside `API_TRUSTED_PREFIXES` and the D-052 limiters do not apply. That is deliberate: the limiter is a separate control with its own tests, and the question here is what the application can serve. Testing both together would measure the limiter.

---

## The finding that makes the rest readable

The script measures `/health/live` — which touches no database and does no work — as a **baseline** on every iteration. Without it the numbers are uninterpretable.

**Load profile, 20 VUs, 292 requests, 0 errors:**

| Endpoint | med | p95 | p99 |
|---|---|---|---|
| `/health/live` — *does nothing* | 2.52s | **3.46s** | 3.57s |
| search | 2.41s | **3.00s** | 3.13s |
| suggest | 2.42s | 3.02s | 3.19s |
| facets (category listing) | 2.50s | 3.03s | 3.34s |

**Search measures faster than an endpoint that does nothing.** The difference is noise. Every one of these numbers is ~2.4s of environment plus something too small to see.

That environment is documented: on Docker Desktop for Windows a bind-mounted file read costs about **29ms**, and `app/`, `config/` and `routes/` are on the bind mount (D-033, RUNBOOK §6). Only `vendor/` was moved to a named volume.

**Portable conclusion:** at the current data scale the marginal cost of a search — including the trigram fallback — is **below the measurement noise floor of this host**. That is a real result. It does not say search is fast at 15,000 products; it says search is not what these numbers are measuring.

---

## Breaking point

**Breaking profile, ramp to 200 VUs, 1,382 requests:**

| | Result |
|---|---|
| Errors | **0** (0 of 989 checks failed) |
| Throughput ceiling | **~9.9 req/s** |
| p95 at 200 VUs | 15.5s (from 3.0s at 20 VUs) |
| Baseline p95 at 200 VUs | 14.9s — **degrades in lockstep** |

Two things are worth taking from this.

**It degrades by queueing, not by failing.** Ten times the concurrency produced zero errors, zero 5xx, and no connection failures — latency climbed and throughput plateaued. That is the failure mode you want: a saturated system that is slow is recoverable, one that is throwing errors is an incident.

**The breaking point found is the host's, not the application's.** The no-op baseline degraded at the same rate as search, so saturation is in request handling, not in Postgres or the query layer. Running this on the production host would produce an entirely different — and actually useful — number.

---

## Thresholds

The script asserts p95 < 500ms for search, < 800ms for the miss path, < 200ms for suggest, and an error rate under 1%.

**These fail on this machine**, by roughly an order of magnitude, entirely on the environmental cost above. They are left in and left failing on purpose: a threshold relaxed to make a development machine green is a threshold that will never catch a regression in production. The error-rate threshold passes.

---

## Running it

```bash
docker run --rm --network officeland_default -v "$PWD/infra/k6:/scripts:ro" grafana/k6:latest run /scripts/search.js
```

Breaking profile:

```bash
docker run --rm --network officeland_default -v "$PWD/infra/k6:/scripts:ro" grafana/k6:latest run -e PROFILE=breaking /scripts/search.js
```

> On Git Bash, prefix with `MSYS_NO_PATHCONV=1` or the container path `/scripts` is rewritten to a Windows path and k6 reports the script as missing.

---

## What this does not tell you, and what would

- **Anything about production capacity.** Needs Q7 hardware. The method transfers; the numbers do not.
- **Search cost at catalogue scale.** The demo catalogue is 10 products. FTS on a GIN index and a trigram fallback across 15,000 rows are different problems, and the fallback is the one to watch — it is the path a miss takes, and misses are what scrapers and typos generate. Re-run after Phase 5b ingestion.
- **Facet cost on a large category.** Facet aggregation is a `GROUP BY` over the matched set, so it scales with the category, not the query. The largest category here holds 5 products.
- **Concurrency limits of the pooler.** PgBouncer sizing still uses development defaults (Phase 1 carried this forward); it needs the same production arithmetic.
