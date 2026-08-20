#!/usr/bin/env bash
#
# check-integration-parity.sh — hydra gate-24 (`integration-parity`) entry point.
#
# gate-24 invokes exactly this path. It runs the repo-local Node checker next to
# it, which correlates this repo's OWN server-side leaf faces (a
# `new LeafDescriptor(` contributed via `RegisterLeafProvidersEvent`, or an
# `IntegrationProvider` registered on OpenRegister's `IntegrationRegistry`)
# against its JS `registerIntegration({ id, … })` call sites — ADR-019
# AD-11/AD-13 and ADR-066 decisions 4 and 7.
#
# This wrapper deliberately does NOT resolve the canonical checker out of
# `@conduction/nextcloud-vue`, and deliberately does NOT skip when something is
# missing. Both were measured to be the same defect:
#
#   * the published npm package does not ship its `scripts/` directory, and the
#     hydra-gates job never runs `npm ci`, so the resolution ALWAYS misses in CI;
#   * the historic wrapper answered that miss with `exit 0` and a friendly
#     message, so gate-24 reported PASS having correlated nothing at all.
#
# A gate whose absence looks exactly like its success is worse than no gate. So
# every way this script can fail to check something exits NON-ZERO with a named
# reason.
#
# Exit codes:
#   0 — every parity rule that had subject matter passed
#   1 — at least one parity violation, or the checker could not be run
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
JS_CHECK="${SCRIPT_DIR}/check-integration-parity.js"

if [ ! -f "${JS_CHECK}" ]; then
	echo "✗ integration parity: checker missing at ${JS_CHECK} — the gate's own machinery is absent, so NOTHING was correlated. Refusing to report a pass." >&2
	exit 1
fi

if ! command -v node >/dev/null 2>&1; then
	echo "✗ integration parity: node is required to run ${JS_CHECK} but was not found on PATH — NOTHING was correlated. Refusing to report a pass." >&2
	exit 1
fi

exec node "${JS_CHECK}" "${REPO_ROOT}"
