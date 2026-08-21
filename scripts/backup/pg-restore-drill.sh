#!/bin/sh
# Restores a base backup into a throwaway cluster and proves it works (Phase 13d).
#
#   scripts/backup/pg-restore-drill.sh [label]
#
# Defaults to the most recent base backup.
#
# ── Why this script is the deliverable and the backup is not ─────────────
# "An untested backup is not a backup" is the Phase 13d gate, and it is not a
# slogan. The ways a backup silently fails to restore are all invisible from
# the backup side: a WAL archive that stopped accepting segments weeks ago, a
# tar written by a server version the restore host does not have, a
# `recovery_target` that never resolves, a base backup taken while
# `archive_command` was failing. Every one of them looks like a directory full
# of healthy-looking files until the day it matters.
#
# ── It restores to a SEPARATE cluster on purpose ─────────────────────────
# A drill that stops the live database to prove the backup works is a drill
# nobody runs twice. This starts a second Postgres on a scratch data directory,
# verifies it, and throws it away. The live instance is untouched throughout,
# so this is safe to run on a schedule and safe to run in production.
#
# ── What "verified" means here ───────────────────────────────────────────
# Not "the process exited zero". The restored cluster is queried for the row
# counts that matter and the result is compared against the live database.
# A restore that starts cleanly with an empty products table has failed, and
# only a query notices.

set -eu

BACKUP_ROOT="${BACKUP_ROOT:-/backups}"
SCRATCH="${SCRATCH:-/tmp/restore-drill}"
PORT="${DRILL_PORT:-5499}"
DB="${POSTGRES_DB:-officeland}"
PGUSER="${PGUSER:-${POSTGRES_USER:-officeland}}"
export PGUSER

LABEL="${1:-$(ls -1 "${BACKUP_ROOT}/base" | sort | tail -1)}"
SOURCE="${BACKUP_ROOT}/base/${LABEL}"

if [ ! -d "${SOURCE}" ]; then
    echo "No such backup: ${SOURCE}" >&2
    echo "Available:" >&2
    ls -1 "${BACKUP_ROOT}/base" >&2
    exit 1
fi

echo "Drill against ${LABEL}"
echo

# Any exit path leaves the scratch cluster stopped and the directory gone.
# Without this a failed drill leaves a running Postgres on the drill port, and
# the NEXT drill fails for that reason instead of the real one — which is how
# a broken backup gets mistaken for a broken script.
cleanup() {
    pg_ctl --pgdata="${SCRATCH}" --wait --timeout=60 stop >/dev/null 2>&1 || true
    rm -rf "${SCRATCH}"
}
trap cleanup EXIT INT TERM

# The live counts, captured BEFORE the restore so the comparison is against
# what the database actually holds rather than against a number typed into
# this script and left to rot.
live_counts=$(psql -d "${DB}" -tAF, -c "
    select
        (select count(*) from products),
        (select count(*) from categories),
        (select count(*) from quotes)
")

echo "live:     ${live_counts}"

started=$(date +%s)

rm -rf "${SCRATCH}"
mkdir -p "${SCRATCH}"

# -Ft -z writes base.tar.gz per tablespace plus pg_wal.tar.gz.
tar -xzf "${SOURCE}/base.tar.gz" -C "${SCRATCH}"

if [ -f "${SOURCE}/pg_wal.tar.gz" ]; then
    tar -xzf "${SOURCE}/pg_wal.tar.gz" -C "${SCRATCH}/pg_wal"
fi

chmod 700 "${SCRATCH}"

extracted=$(date +%s)

# Recovery from the WAL archive as well as the streamed segments, so the drill
# exercises the archive rather than only the self-contained part of the
# backup. If archiving has silently been failing, this is where it shows.
cat > "${SCRATCH}/postgresql.auto.conf" <<EOF
restore_command = 'cp ${BACKUP_ROOT}/wal/%f %p'
recovery_target_timeline = 'latest'
EOF

# Its presence is what puts the cluster into recovery on startup.
touch "${SCRATCH}/recovery.signal"

# archive_mode=off so the drill cluster cannot write into the real archive and
# corrupt the thing it is verifying.
pg_ctl --pgdata="${SCRATCH}" --log="${SCRATCH}/drill.log" \
    --options="-p ${PORT} -c archive_mode=off -c listen_addresses=127.0.0.1" \
    --wait --timeout=120 start

recovered=$(date +%s)

# Recovery ends with the cluster in read-only standby unless promoted. The
# counts are readable either way; promotion is what a real recovery would do
# next, so the drill does it to prove the cluster is actually usable.
pg_ctl --pgdata="${SCRATCH}" promote --wait --timeout=120 || true

promoted=$(date +%s)

# Over the Unix socket, not TCP: host connections use md5 (so the PgBouncer
# userlist format matches), and a drill that stops to ask for a password is a
# drill that cannot be scheduled. A different port means a different socket
# file, so this cannot collide with the live server.
restored_counts=$(psql -p "${PORT}" -d "${DB}" -tAF, -c "
    select
        (select count(*) from products),
        (select count(*) from categories),
        (select count(*) from quotes)
")

echo "restored: ${restored_counts}"
echo

echo "extract   $((extracted - started))s"
echo "recover   $((recovered - extracted))s"
echo "promote   $((promoted - recovered))s"
echo "TOTAL     $((promoted - started))s"
echo

if [ "${live_counts}" != "${restored_counts}" ]; then
    echo "DRILL FAILED — the restored cluster does not match the live one." >&2
    echo "  live:     ${live_counts}" >&2
    echo "  restored: ${restored_counts}" >&2
    echo >&2
    echo "A restore that starts cleanly and holds the wrong data is the worst" >&2
    echo "outcome available, because it looks like success." >&2
    exit 1
fi

echo "DRILL PASSED — restored cluster matches live (products,categories,quotes)."
