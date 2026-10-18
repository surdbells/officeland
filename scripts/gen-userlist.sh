#!/usr/bin/env sh
#
# Regenerate the PgBouncer userlist from DB_PASSWORD in .env.
#
# PgBouncer authenticates clients against a static md5 userlist (auth_type=md5,
# infra/pgbouncer/pgbouncer.ini). The file committed to the repo carries the
# development-default hash, so the moment somebody sets a real DB_PASSWORD the
# app's login fails against it with "password authentication failed" - and the
# fix, a hand-computed md5, is exactly the kind of step that gets skipped.
#
# This rewrites the single userlist line to match whatever password .env sets,
# so a strong password Just Works. serve.sh and deploy.sh call it before
# `docker compose up`, since PgBouncer reads the file at start.
#
# The hash is Postgres's md5 form: 'md5' + md5(password + username). PgBouncer
# then presents that same hash upstream, so the Postgres role, this file and
# DB_PASSWORD all agree - provided the role was created with the same password
# (true on a fresh volume; on an existing one, ALTER ROLE ... PASSWORD).
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"

# Nothing to sync before the operator has written a .env; the committed default
# still lets the stack come up with the development password.
[ -f .env ] || exit 0

# tr -d '\r' so a .env saved with Windows line endings does not fold a carriage
# return into the hash input (which would then never match what the app sends).
user="$(sed -n 's/^DB_USERNAME=//p' .env | tail -1 | tr -d '\r')"
pass="$(sed -n 's/^DB_PASSWORD=//p' .env | tail -1 | tr -d '\r')"

# Mirror the compose defaults (${DB_USERNAME:-officeland}, ${DB_PASSWORD:-secret})
# so an unset value hashes to the same thing Postgres initialised with.
user="${user:-officeland}"
pass="${pass:-secret}"

hash="$(printf '%s' "${pass}${user}" | md5sum | cut -d' ' -f1)"

printf '"%s" "md5%s"\n' "$user" "$hash" > infra/pgbouncer/userlist.txt
