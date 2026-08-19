-- OfficeLand — required PostgreSQL extensions.
--
-- These are created here so a fresh `docker compose up` yields a usable
-- database. Production installs them via Laravel migrations (MASTER BUILD
-- PROMPT section 10.3 requires migrations, not manual server changes) — this
-- file exists so local development does not depend on migration ordering.
--
-- Note: ltree is deliberately absent. The category tree uses a materialized
-- path; see docs/ARCHITECTURE.md section 3 for the justification.

CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- trigram similarity, typo tolerance
CREATE EXTENSION IF NOT EXISTS unaccent;  -- accent folding in the FTS pipeline
CREATE EXTENSION IF NOT EXISTS citext;    -- case-insensitive email storage
CREATE EXTENSION IF NOT EXISTS pgcrypto;  -- token hashing

-- Transaction pooling means session-level SET does not persist across
-- transactions, so search_path is bound to the role instead.
-- See docs/ARCHITECTURE.md section 5.
ALTER ROLE officeland SET search_path = public;
