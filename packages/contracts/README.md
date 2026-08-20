# API contract

**Single source of truth for the storefront's view of the API.**

| File | What | Generated from |
|---|---|---|
| `openapi.yaml` | OpenAPI 3.1 specification | `swagger-php` attributes in `apps/api/app` |
| `api.d.ts` | TypeScript types | `openapi.yaml` |

**Neither file is hand-edited.** §15.1 makes hand-written HTTP calls in the
Angular app a review rejection, and §6.2 makes a contract change that is not
reflected here a failed gate. Both are regenerated and both are committed, so
the diff shows exactly what changed for consumers.

## Regenerate

```bash
# 1. spec, from the PHP attributes
cd apps/api && vendor/bin/openapi app --output ../../packages/contracts/openapi.yaml --format yaml --version 3.1.0

# 2. types, from the spec
docker compose run --rm render npx -y openapi-typescript /contracts/openapi.yaml -o /contracts/api.d.ts
```

CI regenerates both and fails if the result differs from what is committed —
so a change to a controller or resource that is not reflected here stops the
build rather than silently drifting.

## Notes

**No prices appear anywhere in this contract.** OfficeLand is quote-first
(D-002). `pricing_mode` is returned so the storefront renders the correct call
to action from data, and posture C can be enabled later without a client
change.

**No internal integer IDs.** Resources are addressed by `uuid` or `slug`
(§8.3).

**`ProductCard` is the shared listing shape** used by the PLP, search, related
rails and collections — one component, one prop set, one set of tests (§23.5).
