#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V.
# SPDX-License-Identifier: EUPL-1.2
#
# Provision Integriq's OpenRegister register + schemas on a freshly
# installed Nextcloud, for the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so it is
# invoked as:
#
#     playwright-seed-command: 'bash apps/integriq/tests/e2e/ci-seed.sh'
#
# The `Integration Tests (Newman)` job needs the SAME register provisioning —
# its collection drives /apps/openregister/api/objects/openconnector/<schema>
# directly — but must NOT run the SPA warm-up or the bundle gate, because that
# job never builds the frontend. It therefore invokes this same script with
# the register-only scope:
#
#     newman-seed-command: 'SEED_SCOPE=register bash apps/integriq/tests/e2e/ci-seed.sh'
#
# SEED_SCOPE=register  -> steps 1-3 only (build descriptor, import, verify).
# SEED_SCOPE unset/full -> steps 1-5 (adds SPA warm-up + bundle gate).
#
# Note the Newman seed step in the shared workflow declares no `env:` block,
# so this script sees neither BASE_URL nor ADMIN_USER there. The CI fallback
# in "Target resolution" below (gated on GITHUB_ACTIONS/CI) is what supplies
# them; the admin credentials fall back to admin/admin the same way.
#
# WHY THIS IS NEEDED
# ------------------
# Since the chain-C OR-cutover, integriq's Sources, Mappings,
# Synchronizations, Jobs, Rules, Endpoints and Consumers are OpenRegister
# OBJECTS. There is no `oc_openconnector_sources` table any more — they live in
# `oc_openregister_table_<register>_<schema>` under register `openconnector`.
# With no register there is nothing for the SPA to resolve, nothing for the
# fixtures to create, and nothing for the specs to assert.
#
# Nothing in a fresh CI install reliably creates it:
#
#   * The `InitializeRegister` post-migration repair step is the correct home
#     for the import, but `occ app:enable integriq` does not run it — a
#     run on a clean NC 31 checkout produced `integriq 0.3.4 enabled` and
#     no register at all, and `occ maintenance:repair` (which does iterate
#     enabled apps' `repair-steps.post-migration`) also came back with the
#     register absent. Both commands exit 0 either way. Measured, not assumed:
#     `GET /apps/openregister/api/registers` returned 14 register slugs after
#     both, none of them `openconnector`. (14, not 0 — the query was capable of
#     matching; the register genuinely was not there.)
#
#   * Even when it does run, `InitializeRegister::run()` catches `\Throwable`
#     around the import and downgrades every failure to `$output->warning(...)`,
#     so a denied or broken import is indistinguishable from a successful one
#     at the shell.
#
#   * `RegisterBootstrapJob` is the hourly cron fallback (ADR-076 rule 4). CI
#     never runs cron.
#
#   * The import path it uses, `ConfigurationService::importFromApp()`, is
#     version-gated with `force: false`. That path can advance the recorded
#     configuration version WITHOUT applying the register, after which every
#     later attempt sees "already current" and does nothing either.
#
# The observed failure mode in that state is NOT "register missing". It is 133
# failing specs whose messages all point somewhere else — `element(s) not
# found` for `main`, `Nav entry "Webhooks" must be present`, `Add Source button
# must be visible`. One real cause wearing 130 disguises.
#
# So this script does the import EXPLICITLY, over the admin HTTP API (a real
# session, so no RBAC ambiguity) with `force: true` (so the version guard
# cannot no-op it), and then VERIFIES that the register and the schema slugs
# the fixtures actually resolve by are present. A failed provision becomes one
# loud step failure here instead of a wall of misleading spec failures later.
#
# Idempotent: the import is idempotent server-side and re-running only
# re-verifies.

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# ── Target resolution ────────────────────────────────────────────────────────
# Accept every name the shared workflow exports (it sets BASE_URL,
# NEXTCLOUD_URL and NC_BASE_URL on both the seed and the test step) plus the
# local-convention PLAYWRIGHT_BASE_URL.
#
# The CI fallback is gated on actually being in CI. On a developer box
# `localhost:8080` is the SHARED dev container, and this script performs ADMIN
# WRITES — it must never silently import a register into someone else's
# environment. Off CI, an unset target is a hard error.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[ci-seed] target:  ${BASE}"
echo "[ci-seed] app dir: ${APP_DIR}"

# ── 1. Build the register descriptor exactly as the repair step would ────────
# ADR-037: the shipped descriptor is `lib/Settings/integriq_register.json`
# with every `lib/Settings/register.d/*.json` fragment deep-merged over it in
# sorted filename order. Each OpenSpec change drops its own fragment instead of
# editing the monolith, and the fragments carry real content the suite depends
# on — the catalog items, the connector templates, and the source/consumer/rule
# lockdown overlays. Importing the bare monolith would provision a register
# that does not match what an installed instance has.
#
# The merge rule mirrors `InitializeRegister::deepMergeConfig()`: list+list
# concatenates, object+object recurses, anything else the overlay wins. The
# version string mirrors the same method's `<version>+frag.<md5-prefix>`, which
# is what OpenRegister records for the configuration.
#
# The builder ASSERTS the merged result actually contains the register and the
# core schemas before anything is sent. A silently-empty merge would otherwise
# POST a valid-looking descriptor, get HTTP 200 back, and fail verification two
# steps later with a much worse error message.
PAYLOAD="$(mktemp)"
python3 - "$APP_DIR" "$PAYLOAD" <<'PY'
import glob
import hashlib
import json
import os
import re
import sys

app_dir, out_path = sys.argv[1], sys.argv[2]
settings = os.path.join(app_dir, 'lib', 'Settings')


def deep_merge(base, overlay):
    """Mirror InitializeRegister::deepMergeConfig()."""
    for key, value in overlay.items():
        current = base.get(key)
        if isinstance(current, list) and isinstance(value, list):
            base[key] = current + value
        elif isinstance(current, dict) and isinstance(value, dict):
            base[key] = deep_merge(current, value)
        else:
            base[key] = value
    return base


descriptor_path = os.path.join(settings, 'integriq_register.json')
if not os.path.isfile(descriptor_path):
    print(f'::error::register descriptor missing at {descriptor_path}')
    sys.exit(1)

with open(descriptor_path, 'rb') as handle:
    descriptor = json.loads(handle.read())

signature = ''
fragments = sorted(glob.glob(os.path.join(settings, 'register.d', '*.json')))
for fragment_path in fragments:
    with open(fragment_path, 'rb') as handle:
        raw = handle.read()
    try:
        fragment = json.loads(raw)
    except json.JSONDecodeError as exc:
        print(f'::warning::skipping malformed register fragment '
              f'{os.path.basename(fragment_path)}: {exc}')
        continue
    descriptor = deep_merge(descriptor, fragment)
    signature += f'{os.path.basename(fragment_path)}:{hashlib.md5(raw).hexdigest()};'

# App version, read from appinfo/info.xml so this does not depend on `occ`
# (whose stdout carries unrelated app-loading warnings that would corrupt a
# scraped value).
version = '1.0.0'
info_path = os.path.join(app_dir, 'appinfo', 'info.xml')
if os.path.isfile(info_path):
    with open(info_path, encoding='utf-8') as handle:
        match = re.search(r'<version>([^<]+)</version>', handle.read())
    if match:
        version = match.group(1).strip()
if signature:
    version += '+frag.' + hashlib.md5(signature.encode()).hexdigest()[:8]

components = descriptor.get('components', {})
registers = components.get('registers', {})
schemas = components.get('schemas', {})

# Provision the STRUCTURE, not the shipped demo data.
#
# The merged descriptor carries ~87 seed objects — the connector catalog
# templates and example Sources/Mappings/Synchronizations from
# `register.d/*.json`. Importing them made the e2e suite depend on how many
# demo rows the product happens to ship, and the index pages are paginated:
# with 22 seeded sources, a row a test had just created through the UI was not
# on page 1, so three specs reported "newly-created row must appear in the
# list" about a row that existed and was listed. The number they were really
# measuring was the page size.
#
# An e2e fixture should be deterministic and owned by the tests. Every spec in
# this suite creates the data it needs and cleans it up, so it needs the
# register and the schemas to exist and nothing more. The catalog specs that
# DO want the shipped templates are `test.describe.skip`ped today; if they are
# revived, they should seed the templates they assert on rather than rely on
# the whole demo payload being present.
#
# This does NOT change what a real install gets: `InitializeRegister` imports
# the full descriptor, objects included.
seed_objects = components.pop('objects', {})

register_slugs = {
    value.get('slug')
    for value in registers.values()
    if isinstance(value, dict)
}
if 'openconnector' not in register_slugs:
    print('::error::the merged descriptor declares no `openconnector` register '
          f'(found: {sorted(s for s in register_slugs if s)}). Nothing worth POSTing.')
    sys.exit(1)

# The slugs tests/e2e/workflows/_fixture.ts and the spec-coverage specs resolve
# objects by, via /apps/openregister/api/objects/openconnector/<schema>.
required = ['source', 'mapping', 'synchronization', 'job', 'rule', 'endpoint', 'consumer', 'event']
missing = [slug for slug in required if slug not in schemas]
if missing:
    print(f'::error::the merged descriptor is missing core schemas: {missing}')
    sys.exit(1)

with open(out_path, 'w', encoding='utf-8') as handle:
    json.dump(
        {
            # UploadHandler::getUploadedJson() only looks at `url`, `json` or an
            # uploaded file — the descriptor has to arrive under `json`, not as
            # the request body itself.
            'json': descriptor,
            # ConfigurationsController compares with `=== true` (or the literal
            # string 'true'), so this must stay a JSON boolean. A form-encoded
            # "true" would arrive as something else entirely on some paths and
            # silently give us the version-guarded no-op this script exists to
            # bypass.
            'force': True,
            'appId': 'openconnector',
            'version': version,
        },
        handle,
    )

print(f'[ci-seed] merged {len(fragments)} register.d fragment(s); '
      f'{len(schemas)} schemas; '
      f'{len(seed_objects)} shipped demo object(s) deliberately NOT imported; '
      f'version {version}')
PY

echo "[ci-seed] payload: $(wc -c < "$PAYLOAD") bytes"

# ── 2. Import it into OpenRegister ───────────────────────────────────────────
# `configurations#import` is admin-gated in the controller body
# (`isCurrentUserAdmin()`), so basic auth against the admin account is what it
# wants. Unlike the repair step's in-process call this has a real session, so
# there is no RBAC path on which it can be denied for being anonymous.
IMPORT_URL="${BASE}/index.php/apps/openregister/api/configurations/import"
echo "[ci-seed] POST ${IMPORT_URL} (force: true)"

IMPORT_BODY="$(mktemp)"
IMPORT_CODE="$(
	curl -sS -o "$IMPORT_BODY" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data-binary "@${PAYLOAD}" \
		"$IMPORT_URL" || echo 000
)"

echo "[ci-seed] import HTTP ${IMPORT_CODE}"
head -c 1500 "$IMPORT_BODY"; echo

if [ "$IMPORT_CODE" != "200" ]; then
	echo "::error::Integriq register import failed (HTTP ${IMPORT_CODE}). Every integriq entity is an OpenRegister object; without the register the e2e suite has nothing to read or create."
	exit 1
fi

# ── 3. Verify the register and schemas are actually there ────────────────────
# HTTP 200 from an import endpoint is not the same as the register existing:
# OpenRegister's import reports "Import successful" even when its per-object
# loop skipped everything. Verify against OpenRegister directly, by the same
# slugs the fixtures resolve by.
verify() {
	python3 - "$1" "$2" "$APP_DIR" <<'PY'
import json
import os
import sys

path, kind, app_dir = sys.argv[1], sys.argv[2], sys.argv[3]

# The hand-maintained floor: what the fixtures and workflow specs create and
# read through /objects/openconnector/<schema>.
required = {
    'registers': ['openconnector'],
    # tests/e2e/workflows/_fixture.ts creates Sources / Mappings /
    # Synchronizations through /objects/openconnector/<schema>; the
    # spec-coverage index-page specs read jobs, rules, endpoints, consumers
    # and events the same way.
    'schemas': ['source', 'mapping', 'synchronization', 'job', 'rule',
                'endpoint', 'consumer', 'event'],
}[kind]


def manifest_schemas():
    """Every schema a manifest page BINDS A ROUTE TO.

    🔴 THE HAND-MAINTAINED LIST ABOVE CANNOT KEEP UP WITH THE MANIFEST, and on
    2026-08-16 it did not: `SynchronizationRuns` (`/synchronization-runs`,
    schema `synchronization_run`, shipped by the `sync-run-progress.json`
    register fragment) was never in it. When that binding did not resolve on
    the CI instance, the seed said `schemas OK` and the failure surfaced two
    jobs later as a console assertion reading

        Error fetching openconnector-synchronization_run collection: Proxy(Object)

    — which names neither the schema nor the seed. A page whose route is
    declared but whose collection cannot be fetched is a seeding failure, and
    it belongs here, by name, before any spec runs.

    Derived from the manifest rather than listed, so a page added tomorrow is
    covered without anyone remembering this file.
    """
    manifest_path = os.path.join(app_dir, 'src', 'manifest.json')
    try:
        with open(manifest_path, encoding='utf-8') as handle:
            pages = json.load(handle).get('pages') or []
    except (OSError, json.JSONDecodeError) as exc:
        # Not fatal: this is an ADDITION to the floor above, and a manifest we
        # cannot read must not take the checks that do work down with it.
        print(f'::warning::could not read src/manifest.json for schema '
              f'verification ({exc}); falling back to the hand-listed slugs.')
        return []

    found = []
    for page in pages:
        if not isinstance(page, dict):
            continue
        config = page.get('config')
        if not isinstance(config, dict):
            continue
        # Only pages bound to THIS register — a page pointing at another app's
        # register is that app's to provision.
        if config.get('register') != 'openconnector':
            continue
        schema = config.get('schema')
        if isinstance(schema, str) and schema:
            found.append(schema)
    return found


# ⚠️ ADVISORY, NOT A GATE — deliberately.
#
# The hand-listed floor stays a hard error: those slugs are what the fixtures
# CREATE, so without them the suite cannot run at all. The manifest-derived set
# is reported as a warning instead, because promoting it would turn today's ONE
# failing spec into a failed seed and take the other 165 passing specs with it.
# The job's verdict should keep coming from the specs; this exists so the log
# NAMES the schema instead of leaving `Proxy(Object)` to be reverse-engineered.
advisory = []
if kind == 'schemas':
    advisory = sorted(set(manifest_schemas()) - set(required))

with open(path, encoding='utf-8') as handle:
    raw = handle.read()
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::{kind} endpoint did not return JSON. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)

items = body if isinstance(body, list) else body.get('results', [])
slugs = {item.get('slug') for item in items if isinstance(item, dict)}
missing = [slug for slug in required if slug not in slugs]

# ⚠️ PRINT THE REQUIRED SLUGS, NOT A TRUNCATED DUMP OF EVERYTHING PRESENT.
# This used to print `sorted(slugs)[:60]` beside a `len(slugs)` of 71 — an
# instrument whose evidence disagreed with its own count, cut off alphabetically
# at 'rule'. `synchronization_run` sorts after that, so the one slug in question
# could not be seen either way, and reading the line gave a false answer in both
# directions. The full count still gets printed; what gets ENUMERATED is the set
# this check is actually about.
present_required = [slug for slug in required if slug in slugs]
print(f'[ci-seed] {kind}: {len(slugs)} present on the instance; '
      f'{len(present_required)}/{len(required)} required -> {present_required}')

if advisory:
    unbound = [slug for slug in advisory if slug not in slugs]
    if unbound:
        print(f'::warning::{len(unbound)} manifest page(s) bind a route to a schema '
              f'that is NOT on this instance: {unbound}')
        print('::warning::Each one\'s index page will fail to fetch its collection, '
              'and the spec failure reads "Error fetching openconnector-<schema> '
              'collection: Proxy(Object)" — which names neither the schema nor the '
              'seed. That is what this warning is for.')
        print('::warning::Check that the register.d fragment declaring it is merged '
              'into the descriptor AND that the import attached it to the '
              'openconnector register.')
    else:
        print(f'[ci-seed] all {len(advisory)} manifest-bound schema(s) resolve.')

if missing:
    print(f'::error::Integriq {kind} missing after import: {missing}')
    sys.exit(1)
print(f'[ci-seed] {kind} OK ({len(required)} required slug(s) present)')
PY
}

REG_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=300" -o "$REG_BODY"
verify "$REG_BODY" registers

SCH_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/schemas?_limit=1000" -o "$SCH_BODY"
verify "$SCH_BODY" schemas

# ── 3a. PROBE THE COLLECTION ENDPOINT THE PAGES ACTUALLY CALL ────────────────
# 🔴 A SLUG BEING PRESENT IN /api/schemas IS NOT THE SAME AS ITS COLLECTION
# BEING FETCHABLE, and on 2026-08-16 the two disagreed. `synchronization_run`
# was in the 71 slugs the check above enumerates — so it printed
# `all 11 manifest-bound schema(s) resolve` — while
#
#     GET /apps/openregister/api/objects/openconnector/synchronization_run
#
# returned 404 `{"message":"Schema not found: 'synchronization_run'"}`, because
# declaring a schema in `components.schemas` does NOT attach it to the register:
# the register's own `schemas` list is what binds it, and the fragment shipping
# the schema had not added itself there. The check above cannot see that, and it
# read as a pass.
#
# So probe the endpoint itself. This is the same request CnIndexPage makes, so a
# 200 here is evidence about the thing that failed rather than about a
# neighbouring list. Advisory (like the check above) — the specs keep the
# verdict — but the log now names the schema AND the status code.
echo "[ci-seed] probing the object collection endpoint for every manifest-bound schema"
PROBE_SCHEMAS="$(
	python3 - "$APP_DIR" <<'PY'
import json
import os
import sys

manifest = os.path.join(sys.argv[1], 'src', 'manifest.json')
try:
    with open(manifest, encoding='utf-8') as handle:
        pages = json.load(handle).get('pages') or []
except (OSError, json.JSONDecodeError):
    pages = []

slugs = []
for page in pages:
    if not isinstance(page, dict):
        continue
    config = page.get('config')
    if not isinstance(config, dict) or config.get('register') != 'openconnector':
        continue
    schema = config.get('schema')
    if isinstance(schema, str) and schema and schema not in slugs:
        slugs.append(schema)
print(' '.join(slugs))
PY
)"

PROBE_TOTAL=0
PROBE_BAD=0
for SLUG in $PROBE_SCHEMAS; do
	PROBE_TOTAL=$((PROBE_TOTAL + 1))
	PROBE_CODE="$(
		curl -sS -o /dev/null -w '%{http_code}' \
			-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
			"${BASE}/index.php/apps/openregister/api/objects/openconnector/${SLUG}?_limit=1" \
			|| echo 000
	)"
	if [ "$PROBE_CODE" = "200" ]; then
		echo "[ci-seed]   ${SLUG}: ${PROBE_CODE}"
	else
		PROBE_BAD=$((PROBE_BAD + 1))
		echo "::warning::objects/openconnector/${SLUG} returned HTTP ${PROBE_CODE} — its index page will log 'Error fetching openconnector-${SLUG} collection' and the spec will fail on the console gate. Attach the schema to the openconnector register (components.registers.openconnector.schemas), not just components.schemas."
	fi
done

# ⚠️ A ZERO-PROBE RUN MUST NOT READ AS A CLEAN ONE.
if [ "$PROBE_TOTAL" -eq 0 ]; then
	echo "::warning::NOT ONE collection endpoint was probed — src/manifest.json yielded no openconnector-bound page. This says nothing about the instance."
else
	echo "[ci-seed] probed ${PROBE_TOTAL} collection endpoint(s); ${PROBE_BAD} not fetchable."
fi

echo "[ci-seed] Integriq register + schemas provisioned."

# ── 3b. Stop here for API-only consumers (SEED_SCOPE=register) ───────────────
# The `Integration Tests (Newman)` job needs EXACTLY steps 1-3 and nothing
# else: every request in the collection is an HTTP API call, and that job
# never runs `npm run build`, so the SPA warm-up below would warm nothing and
# the bundle gate would hard-fail the seed on a bundle the job is not supposed
# to have. Splitting on scope keeps ONE definition of "provision the register"
# for both jobs — the Newman failure this exists to fix was 51 assertions all
# 404ing on /apps/openregister/api/objects/openconnector/<schema> because that
# job had no seed step at all.
if [ "${SEED_SCOPE:-full}" = "register" ]; then
	echo "[ci-seed] SEED_SCOPE=register — skipping SPA warm-up and bundle gate (API-only consumer)."
	exit 0
fi

# ── 4. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# The shared workflow serves Nextcloud with `php -S 0.0.0.0:8080`. It now sets
# PHP_CLI_SERVER_WORKERS=8, but the first hit still pays a cold opcache and the
# first parse of a ~9 MB webpack bundle. Warming here puts that cost in the
# environment-preparation step, where it belongs, instead of inside whichever
# spec happens to sort first. Failures are ignored on purpose: this is a
# warm-up, not a gate — the real checks are above and below.
for path in \
	"/index.php/apps/integriq/" \
	"/index.php/apps/openregister/api/registers?_limit=1" \
	"/index.php/apps/openregister/api/objects/openconnector/source?_limit=1" \
	"/index.php/apps/openregister/api/objects/openconnector/mapping?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# ── 5. Bundle gate ───────────────────────────────────────────────────────────
# Pull the main webpack bundle once, and on CI assert it really is JavaScript.
#
# Do NOT hardcode the URL. Nextcloud serves an app's assets from whichever apps
# directory it was installed into — `/apps/<app>/js/...` on the CI runner,
# `/custom_apps/<app>/js/...` in the docker dev images — and asking for the
# wrong one does not 404. It returns **HTTP 200 with `text/html`**: the NC
# error page, served through index.php. A status-code check therefore reports
# success while fetching a 40 KB HTML page instead of a 9 MB bundle.
#
# Read the real src out of the rendered app page instead, and verify the
# response Content-Type.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/integriq/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that aborts the script right here — so the case the gate
# below exists to explain (no bundle) would die with a bare non-zero exit and
# none of the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*integriq-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app, and the environment hides it well: when the
# bundle is absent Nextcloud does not 404, it serves its HTML error page with
# HTTP 200 and Content-Type text/html. To every status-code check in the
# pipeline, `npm run build` producing nothing looks exactly like success.
#
# The specs are the honest signal; this check just makes the cause loud and
# immediate instead of arriving as a wall of selector timeouts.
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle verified as JavaScript."
			;;
		*)
			echo "::error::The Integriq frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac
fi

echo "[ci-seed] done."
