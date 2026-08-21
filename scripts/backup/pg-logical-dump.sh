#!/bin/sh
# Weekly logical dump (Phase 13d).
#
#   docker compose exec -T -u postgres postgres sh -s < scripts/backup/pg-logical-dump.sh
#
# ── Why this exists alongside the physical backup ────────────────────────
# The base backup is the primary: faster to take, faster to restore, and the
# only one that supports point-in-time recovery. But a physical backup is a
# byte-level copy, which means it faithfully copies problems too. A corrupt
# page, a botched extension upgrade, or a bad `UPDATE` that ran three weeks ago
# is present in every base backup taken since.
#
# A logical dump is a stream of SQL. It cannot do PITR and it is slow to
# restore at catalogue scale, but it survives the cases the physical backup
# does not — and it restores into a DIFFERENT major version, which is the only
# form of backup that helps during a version upgrade that goes wrong.
#
# Kept weekly rather than nightly because its value is depth of history, not
# recency: recency is what the WAL archive is for.

set -eu

BACKUP_ROOT="${BACKUP_ROOT:-/backups}"
DB="${POSTGRES_DB:-officeland}"
PGUSER="${PGUSER:-${POSTGRES_USER:-officeland}}"
export PGUSER

TARGET="${BACKUP_ROOT}/logical"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="${TARGET}/${DB}-${STAMP}.dump"

mkdir -p "${TARGET}"

started=$(date +%s)

# -Fc  custom format: compressed, and restorable table-by-table with
#      pg_restore, which is what you want when recovering ONE table that
#      somebody truncated rather than the whole database.
pg_dump --dbname="${DB}" --format=custom --compress=9 --file="${FILE}.partial"

# Renamed only on success, so a dump interrupted halfway can never be mistaken
# for a complete one by whatever ships these offsite.
mv "${FILE}.partial" "${FILE}"

elapsed=$(( $(date +%s) - started ))

echo "Logical dump: ${FILE}"
echo "  $(du -sh "${FILE}" | cut -f1) in ${elapsed}s"
