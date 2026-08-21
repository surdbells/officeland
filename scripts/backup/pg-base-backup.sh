#!/bin/sh
# Takes a Postgres base backup (Phase 13d).
#
# Runs INSIDE the postgres container:
#   docker compose exec postgres sh /backups/../scripts/pg-base-backup.sh
# or, as the runbook documents it:
#   docker compose exec -T postgres sh -s < scripts/backup/pg-base-backup.sh
#
# ── What this gives you, and what it does not ────────────────────────────
# A base backup plus the archived WAL that follows it is point-in-time
# recovery: any moment from the end of this backup up to the last archived
# segment. Without the WAL it is only a snapshot of this instant.
#
# `-X stream` copies the WAL generated DURING the backup along with it, which
# is what makes the result self-consistent and restorable on its own. Without
# it a restore depends on the archive already containing those segments, and a
# backup that needs another backup to be valid is a trap.
#
# ── Why not pg_dump ─────────────────────────────────────────────────────
# pg_dump is a logical export: it cannot do point-in-time recovery, and on a
# 15,000-product catalogue with 75,000 media rows the restore is a replay of
# every INSERT and every index build. It is kept as a SECOND, weekly artefact
# (see pg-logical-dump.sh) because it survives things a physical backup does
# not — a corrupt page, a major-version jump — but it is not the primary.

set -eu

BACKUP_ROOT="${BACKUP_ROOT:-/backups}"
LABEL="${1:-$(date -u +%Y%m%dT%H%M%SZ)}"
TARGET="${BACKUP_ROOT}/base/${LABEL}"

# `docker compose exec` lands as root, and there is no root role in the
# cluster — the failure is "role \"root\" does not exist", which reads like a
# permissions problem and is really a default-username one.
PGUSER="${PGUSER:-${POSTGRES_USER:-officeland}}"
export PGUSER

if [ -e "${TARGET}" ]; then
    echo "Refusing to overwrite an existing backup at ${TARGET}." >&2
    exit 1
fi

mkdir -p "${TARGET}"

started=$(date +%s)

# -Ft -z    one compressed tar per tablespace, so the artefact is a file to
#           ship rather than a directory tree to keep in sync
# -X stream open a second connection and stream WAL alongside, so the result
#           stands alone
# -P        progress, because a silent 40-minute command looks like a hang and
#           somebody eventually kills it
pg_basebackup \
    --pgdata="${TARGET}" \
    --format=tar \
    --gzip \
    --wal-method=stream \
    --checkpoint=fast \
    --progress \
    --verbose

finished=$(date +%s)
elapsed=$((finished - started))

size=$(du -sh "${TARGET}" | cut -f1)

# A manifest beside the artefact, so a restore does not depend on somebody
# remembering which server version wrote it.
cat > "${TARGET}/BACKUP_INFO" <<EOF
label=${LABEL}
finished_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)
elapsed_seconds=${elapsed}
size=${size}
server_version=$(postgres --version | awk '{print $3}')
EOF

echo
echo "Base backup complete: ${TARGET}"
echo "  ${size} in ${elapsed}s"
echo
echo "This is not yet a verified backup. Run the restore drill:"
echo "  scripts/backup/pg-restore-drill.sh ${LABEL}"
