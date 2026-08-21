# Security Review — OWASP Top 10 (2021)

**Phase 13a.** Reviewed 2026-08-21 against commit `4b5d306`, covering every request surface: the public API, the quote pipeline, the mail webhook, the Filament admin panel, the media pipeline and the edge configuration.

This is a **code and configuration review**, not a penetration test. It says what the system does and where the evidence is. It cannot tell you how the deployed estate behaves — that needs the production host (**Q7**) and is a separate exercise before go-live.

**Four findings, all fixed in this phase.** Two were serious: the public API had no rate limiting of any kind, and nothing anywhere recorded a failed login.

---

## Findings

| # | Category | Severity | Status |
|---|---|---|---|
| F1 | A04 Insecure Design | **High** | Fixed |
| F2 | A09 Logging Failures | **High** | Fixed |
| F3 | A05 Misconfiguration | Medium | Fixed |
| F4 | A02 Cryptographic Failures | Medium | Fixed |

### F1 — The public API had no rate limit (A04, High)

**What was wrong.** Only two surfaces were throttled: the admin panel (`throttle:admin`) and quote submission (`AbuseGuard`, 10/hour per IP). Every other endpoint — catalogue reads, basket writes, and search — accepted unlimited requests.

**Why it matters most for search.** `PostgresSearchEngine` runs a weighted full-text query, then a **trigram fallback when FTS finds nothing**, then facet aggregation. A query designed to miss goes down the most expensive path every time, and there was nothing to stop one client sending it continuously. That is the cheapest way to take the site down and it needed no tooling.

Basket writes were the second problem: `POST /api/v1/basket/items` inserts rows, so an unthrottled caller could grow the table for the cost of one request per row.

**Fixed** in `config/api.php` and `AppServiceProvider`, with per-surface limits rather than one global number:

| Surface | Per minute | Why this number |
|---|---|---|
| catalogue | 300 | Cheap and ETagged; mostly consumed by the render worker |
| search | 60 | The most expensive query in the system |
| basket | 60 | Writes rows |
| webhook | 120 | Unauthenticated by necessity; caps HMAC work |

**The exemption is the risky part, so it is asserted.** The render worker walks 15,000+ routes during a full build, far past any human limit — throttling it would turn a template fix into a half-built site. Internal addresses are therefore exempt, which is only safe because PHP runs **inside** Caddy under FrankenPHP: there is no proxy hop, `$request->ip()` is the real socket address, and an internet client cannot present a private one. `ApiHardeningTest::test_a_public_address_is_never_treated_as_internal` pins that.

> **If a load balancer is ever put in front of Caddy**, it must be added to Caddy's `trusted_proxies`, and its address must not be private — otherwise the exemption hands the limit-free path to everyone.

### F2 — Failed logins were not recorded anywhere (A09, High)

**What was wrong.** No listener, no log line, nothing. Filament caps login attempts at five per minute (`vendor/filament/filament/src/Auth/Pages/Login.php:185`), so brute force could not succeed quickly — but *cannot succeed quickly* is not *somebody will notice*. A fortnight of patient credential stuffing against `/admin` left no trace, and the first evidence of a compromise would have been its consequences.

**Fixed** by `App\Listeners\LogAuthenticationEvents`, registered explicitly rather than by auto-discovery — these are the events an investigation starts from, and a listener that silently stops being discovered is a log that silently stops being written.

| Event | Level | Recorded |
|---|---|---|
| `Failed` | warning | email, guard, IP, user agent, **whether the account exists** |
| `Lockout` | warning | IP, user agent |
| `Login` / `Logout` | info | user id, guard, IP |
| `PasswordReset` | warning | user id, IP |

`user_exists` is the field that makes the log useful: a run of failures against a real account is an attack, the same run against addresses that do not exist is a scanner working a leaked list. Failures are `warning` rather than `info` so they clear a level filter — a failed login at `info` is a line nobody has ever alerted on.

**The password is never logged**, asserted directly in `ApiHardeningTest::test_the_password_is_never_logged`.

### F3 — No Content-Security-Policy and no framing protection (A05, Medium)

**What was wrong.** The edge sent HSTS, `X-Content-Type-Options`, `Referrer-Policy` and `Permissions-Policy` — but no CSP and nothing preventing the site, including `/admin`, from being framed.

**Why the obvious fix did not work.** Angular 22 ships `security.autoCsp`, which computes hashes for its own inline scripts. It refuses to run here: *"Cannot set both SSR and auto-CSP at the same time."* D-009 prerenders through the SSR builder, so auto-CSP is unavailable to this project.

A nonce is also unavailable — nonces must be unique per response and injected by whatever serves the page, and the storefront is static files with no application in the path.

**Fixed** with `apps/web/tools/csp-hashes.mjs`, which derives the hashes from the prerendered output and generates `infra/caddy/csp.caddy`. Measured, not assumed:

- **3 distinct inline scripts across 136 prerendered pages.** Angular's hydration bootstrap embeds the event-replay contract for the events a page actually binds, so pages genuinely differ — the policy is the union, and the tool fails above 24 distinct hashes because that would mean something page-specific is being inlined, at which point hashes are the wrong mechanism.
- **No external stylesheet, web font or CDN script** in the built output, so `default-src 'self'` costs nothing.
- **`style-src` keeps `'unsafe-inline'` and `script-src` does not.** Angular sets style attributes during hydration, which hashes cannot cover. The asymmetry is deliberate: inline style is a defacement risk, inline script is code execution.
- **`frame-ancestors 'none'`** is why this is a header and not a `<meta>` tag — browsers ignore `frame-ancestors` in meta, and it is exactly the directive that protects the admin panel. `X-Frame-Options: DENY` covers older browsers.

Verified end to end against the served bytes: every inline script in the response from `GET /` is covered by the CSP in the same response's headers. CI runs `--check` and fails when the build stops matching, because a stale hash serves a policy that blanks the site.

**The admin panel is deliberately not under a strict script CSP.** Livewire and Alpine construct functions at runtime, which needs `'unsafe-eval'`; a CSP containing `'unsafe-eval'` gives most of the illusion of protection and little of the substance. The panel is defended by authentication, mandatory 2FA, an optional IP allowlist, a non-default path, `throttle:admin`, and now `frame-ancestors`.

### F4 — The session cookie was not `Secure` by default (A02, Medium)

**What was wrong.** `config/session.php` carried Laravel's stock `env('SESSION_SECURE_COOKIE')` with no default, so unless an operator set the variable the admin session cookie could be sent over plain HTTP — via a redirect, a mixed-content asset, or a misconfigured probe. That cookie is a live grant of catalogue write access.

**Fixed** by defaulting to on in production. The safe value is the default; the override is the deliberate act. `env('APP_ENV')` rather than `app()->isProduction()` because config is loaded before the container is ready and cached config must not depend on runtime state.

---

## Reviewed and found sound

Recorded because "we looked and it was fine" is the part of a review that is worth keeping.

**A03 Injection.** Every raw SQL fragment was read. `PostgresSearchEngine::tsQueryExpression` splits the user's term on non-alphanumerics and binds each word as a parameter, interpolating only tsquery operators — user input cannot reach the query as syntax. `SimilarityGate::score` passes both texts as bindings. The one interpolated literal (`orderByRaw` with boost weights) uses class constants, with a comment explaining that Postgres cannot infer a `CASE` branch type from a placeholder. Eloquent everywhere else.

**A01 Access control.** Basket lines are scoped through `$basket->items()->whereKey(...)`, so item ids from another visitor's basket resolve to a 404 rather than an object — no IDOR. Filament resources gate on `Role::managesQuotes()` / `managesCatalogue()`, and `AdminAuthorisationTest` asserts through the Gate rather than the UI, on the correct principle that a hidden button proves nothing.

**A07 Authentication.** Mandatory app-based 2FA with recovery codes; login capped at five attempts per minute by Filament; `AuthenticateSession` invalidates other sessions on password change; the admin path is configurable, because a predictable one is free reconnaissance.

**A08 Integrity.** Media MIME is detected from **magic bytes** via `finfo`, never from the extension or the client-declared type, and SVG is rejected explicitly — an SVG is a script container. The mail webhook verifies HMAC-SHA256 over the **raw** body with a freshness window and `hash_equals` (D-042). Basket CSV export prefixes `=+-@` to defuse formula injection.

**A10 SSRF.** No user input reaches an outbound request. Angular's dev-server host guard is a deliberate allowlist rather than a wildcard, with the reasoning in `apps/web/src/server.ts`.

**A06 Vulnerable components.** `composer audit` and `npm audit --audit-level=high` both run in CI and fail the build.

---

## Not covered, and what it would take

- **Penetration testing.** This is a code review. An authenticated test against a deployed instance is a different exercise and belongs after Q7 settles the host.
- **The deployed TLS configuration.** HSTS is sent with `preload`, which is a commitment that is painful to reverse — do not submit to the preload list until the certificate and every subdomain are settled.
- **Dependency provenance.** `composer audit` catches *known* advisories. It says nothing about a package that has not been reported yet.
- **The admin panel under a strict CSP.** Deliberately deferred, reasoning under F3.
- **Rate limiting at the edge.** The limits here are per-process in Redis. A volumetric attack is absorbed upstream, not by PHP — that is a Cloudflare-or-equivalent decision, still open.
