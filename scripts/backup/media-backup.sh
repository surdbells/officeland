#!/bin/sh
# Incremental backup of the media tree (Phase 13d).
#
#   scripts/backup/media-backup.sh <destination>
#
# ── Content addressing makes this cheap, and that is not an accident ─────
# Media lives at `{sha[0:2]}/{sha[2:4]}/{sha}/…` (Phase 4b). A file's path is
# derived from its content, so **a file at a given path never changes**: an
# edited image is a new hash and therefore a new path. The tree is append-only
# in practice.
#
# That is what makes a 50 GB+ tree backable with rsync on a schedule: after the
# first run, only genuinely new files transfer. `--ignore-existing` is safe
# here for exactly the same reason, and it turns the nightly run into a
# directory comparison rather than a checksum of 75,000 files.
#
# ── Why the derivatives are backed up rather than regenerated ────────────
# They could be regenerated from the originals — but Phase 4 measured 3.6s per
# image for 15 derivatives, so 75,000 images is roughly **75 hours** of libvips
# on one core. That is not a recovery, it is an outage with a progress bar.
# Storage is cheaper than the downtime.
#
# ── OPEN: where this actually goes (Q21) ─────────────────────────────────
# The destination is a parameter because nobody has decided it yet. It must be
# a different HOST, not merely a different directory — a backup on the same
# machine survives a bad `rm` and nothing else. Q21 also needs to settle the
# retention period and the RPO/RTO this is meant to meet.

set -eu

SOURCE="${MEDIA_ROOT:-/app/storage-media}"
DESTINATION="${1:-}"

if [ -z "${DESTINATION}" ]; then
    cat >&2 <<'EOF'
Usage: media-backup.sh <destination>

  A local path, or an rsync target such as backup@host:/srv/officeland/media.

  It MUST be a different host. A copy on the same machine protects against a
  mistaken delete and nothing else — not a failed disk, not a lost server,
  not the ransomware case that is the whole reason offsite backups exist.

  The destination is still an open question — see Q21 in docs/DECISIONS.md.
EOF
    exit 2
fi

if [ ! -d "${SOURCE}" ]; then
    echo "No media tree at ${SOURCE}." >&2
    exit 1
fi

started=$(date +%s)

before=$(find "${SOURCE}" -type f | wc -l)

# --ignore-existing  content-addressed paths never change, so a file already
#                    present is by definition identical
# --partial          a 50 GB first run over a Nigerian uplink WILL be
#                    interrupted; resuming beats restarting
# --stats            so the log line answers "did anything actually transfer"
rsync \
    --archive \
    --ignore-existing \
    --partial \
    --human-readable \
    --stats \
    "${SOURCE}/" "${DESTINATION}/"

elapsed=$(( $(date +%s) - started ))

echo
echo "Media backup complete in ${elapsed}s — ${before} file(s) in the source tree."
echo
echo "This is a copy, not a verified backup. Restoring the media tree is"
echo "untested until somebody has pulled it back and served a page from it."
