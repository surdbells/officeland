/**
 * Load profile for the search and facet endpoints (Phase 13b).
 *
 * ── Why these endpoints and not the storefront ───────────────────────────
 * Under D-009 every catalogue page is a static file served by Caddy with no
 * application in the path — load testing those measures Caddy and the kernel,
 * not this system. Search is the opposite: it is the one surface rendered at
 * request time, and it is the most expensive query in the codebase.
 *
 * ── The shape that matters ───────────────────────────────────────────────
 * `PostgresSearchEngine` runs a weighted FTS query, and **when FTS finds
 * nothing it falls back to a trigram scan**, then aggregates facets. So the
 * cost of a search depends on whether it matches, and the worst case is a
 * plausible-looking query that misses. The scenarios below deliberately
 * include that case rather than only hitting terms that resolve quickly —
 * a load test built from happy-path queries measures the fast path and
 * reports it as capacity.
 *
 * ── Rate limiting is bypassed, on purpose ────────────────────────────────
 * k6 runs on the compose network, so its address is inside
 * `API_TRUSTED_PREFIXES` and the D-052 limiters do not apply. That is what we
 * want here: the limiter is a separate control with its own tests, and the
 * question this script answers is what the application can actually serve.
 * Testing them together would measure the limiter and tell us nothing.
 *
 *   docker compose run --rm k6 run /scripts/search.js
 *   docker compose run --rm k6 run -e PROFILE=breaking /scripts/search.js
 */

import http from 'k6/http';
import { check } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://api';
const PROFILE = __ENV.PROFILE || 'load';

/*
 * The floor. `/health/live` touches no database and does no work — it measures
 * what it costs simply to get a request into and out of the framework on this
 * host.
 *
 * Without it the search numbers are unusable. On Docker Desktop for Windows a
 * bind-mounted file read costs ~29ms (D-033) and Laravel reads hundreds of
 * files to boot, so every endpoint pays seconds of overhead that has nothing to
 * do with the query being tested. Subtracting this baseline turns an absolute
 * number that is worthless off this machine into a MARGINAL cost that is
 * roughly portable — and the marginal cost is the thing capacity planning
 * actually needs.
 */
const baselineDuration = new Trend('baseline_duration', true);

/** Split out so a slow facet aggregation cannot hide behind fast suggests. */
const searchDuration = new Trend('search_duration', true);
const suggestDuration = new Trend('suggest_duration', true);
const facetDuration = new Trend('facet_duration', true);
const errorRate = new Rate('errors');

/*
 * Terms chosen to exercise the three real cost paths, not to look realistic.
 *
 *   hit      resolves through FTS and stops
 *   miss     finds nothing, so it pays FTS *and* the trigram fallback
 *   code     the exact SKU/MPN short-circuit, the cheapest path
 */
const TERMS = {
  hit: ['toner', 'printer', 'chair', 'paper', 'laser'],
  miss: ['zzqx', 'toneer', 'chiar', 'prnter', 'qwertyuiop'],
  code: ['CE320A', 'W1A53A', 'OL-M404DN'],
};

const pick = (list) => list[Math.floor(Math.random() * list.length)];

/*
 * Two profiles.
 *
 * `load` is a realistic ramp for a B2B catalogue in one country: search is used
 * by a minority of visitors, and the site is prerendered, so this is not a
 * consumer-scale funnel.
 *
 * `breaking` climbs until something gives. The number it produces is the point
 * of the exercise — "it handled our expected load" is unfalsifiable without
 * knowing how much headroom that represents.
 */
/** p99 is a deliverable of §13b, and k6's default summary stops at p95. */
const TREND_STATS = ['min', 'med', 'avg', 'p(95)', 'p(99)', 'max'];

export const options =
  PROFILE === 'breaking'
    ? {
        summaryTrendStats: TREND_STATS,
        stages: [
          { duration: '20s', target: 10 },
          { duration: '20s', target: 25 },
          { duration: '20s', target: 50 },
          { duration: '20s', target: 100 },
          { duration: '20s', target: 200 },
          { duration: '10s', target: 0 },
        ],
        thresholds: {
          // Deliberately absent: this profile is meant to break things, and a
          // threshold would abort the run before it found the limit.
        },
      }
    : {
        summaryTrendStats: TREND_STATS,
        stages: [
          { duration: '15s', target: 5 },
          { duration: '30s', target: 20 },
          { duration: '15s', target: 0 },
        ],
        thresholds: {
          // SEO_SPEC §9 budgets are for page delivery; these are API budgets.
          // A search that takes longer than half a second feels broken while
          // somebody is typing.
          'search_duration{kind:miss}': ['p(95)<800'],
          search_duration: ['p(95)<500', 'p(99)<1000'],
          suggest_duration: ['p(95)<200'],
          errors: ['rate<0.01'],
        },
      };

export default function () {
  const baseline = http.get(`${BASE}/health/live`, { tags: { name: 'baseline' } });
  baselineDuration.add(baseline.timings.duration);

  const kind = Math.random() < 0.25 ? 'miss' : Math.random() < 0.75 ? 'hit' : 'code';
  const term = pick(TERMS[kind]);

  const search = http.get(`${BASE}/api/v1/search?q=${encodeURIComponent(term)}`, {
    tags: { name: 'search', kind },
  });
  searchDuration.add(search.timings.duration, { kind });
  errorRate.add(search.status >= 400);
  check(search, { 'search 200': (r) => r.status === 200 });

  const suggest = http.get(`${BASE}/api/v1/search/suggest?q=${encodeURIComponent(term.slice(0, 3))}`, {
    tags: { name: 'suggest' },
  });
  suggestDuration.add(suggest.timings.duration);
  errorRate.add(suggest.status >= 400);

  /*
   * The facet path, via a category listing. Counted separately because facet
   * aggregation is a GROUP BY over the matched set and scales with the
   * category, not with the query — a different cost curve entirely.
   */
  const facets = http.get(`${BASE}/api/v1/categories/printers-consumables/products?per_page=24`, {
    tags: { name: 'facets' },
  });
  facetDuration.add(facets.timings.duration);
  errorRate.add(facets.status >= 400);
  check(facets, { 'facets 200': (r) => r.status === 200 });
}
