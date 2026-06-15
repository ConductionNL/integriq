#!/usr/bin/env bash
#
# OpenConnector API-contract test runner (Newman / Postman).
#
# Runs tests/integration/openconnector.postman_collection.json against a live
# Nextcloud instance serving the openconnector app. The collection is
# self-contained and idempotent: it creates the OpenRegister objects it needs
# (source, mapping, rule, endpoint, job, synchronization, webhook, consumer) and
# deletes them again in teardown.
#
# Usage:
#   ./run-newman.sh                                  # defaults to localhost:8080, admin:admin
#   BASE_URL=http://localhost:8080 ./run-newman.sh
#   ADMIN_USER=admin ADMIN_PASS=admin ./run-newman.sh
#
# Uses a globally-installed `newman` if present, otherwise falls back to
# `npx newman`. Runs are serialised via flock (when available) so concurrent
# CI agents do not trip the Nextcloud brute-force protection.
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

set -euo pipefail

# Re-exec under an exclusive flock so parallel agents serialise.
LOCK_FILE="/tmp/uiaudit-openconnector.lock"
if [ "${OPENCONNECTOR_NEWMAN_LOCKED:-}" != "1" ] && command -v flock >/dev/null 2>&1; then
  export OPENCONNECTOR_NEWMAN_LOCKED=1
  exec flock "${LOCK_FILE}" "$0" "$@"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COLLECTION="${SCRIPT_DIR}/openconnector.postman_collection.json"

BASE_URL="${BASE_URL:-http://localhost:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"

# Authenticated requests use baseUrl; the authorization (no-auth) tests use a
# DIFFERENT host alias so the session cookie that authenticated requests
# establish (host-scoped) is never sent to them — keeping them genuinely
# unauthenticated. Defaults to the 127.0.0.1 form of baseUrl.
if [ -n "${NO_AUTH_BASE:-}" ]; then
  NOAUTH_BASE="${NO_AUTH_BASE}"
elif [[ "${BASE_URL}" == *"localhost"* ]]; then
  NOAUTH_BASE="${BASE_URL/localhost/127.0.0.1}"
else
  NOAUTH_BASE="${BASE_URL/127.0.0.1/localhost}"
fi

if command -v newman >/dev/null 2>&1; then
  NEWMAN=(newman)
else
  NEWMAN=(npx --yes newman)
fi

# --ignore-redirects: assert NC's 401 on unauthenticated requests directly
# instead of following a 303-to-login to a 200 HTML page (so authz tests are honest).
"${NEWMAN[@]}" run "${COLLECTION}" \
  --env-var "baseUrl=${BASE_URL}" \
  --env-var "noAuthBase=${NOAUTH_BASE}" \
  --env-var "adminUser=${ADMIN_USER}" \
  --env-var "adminPass=${ADMIN_PASS}" \
  --ignore-redirects \
  --reporters cli \
  --color on \
  "$@"
