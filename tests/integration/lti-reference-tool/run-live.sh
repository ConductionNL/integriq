#!/bin/bash
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# connectors-lti-platform-launch, tasks 2 and 3, against a running Nextcloud with integriq:
# registers this reference tool and a placement, raises LtiLaunchRequestedEvent for a learner
# (as learniq does), follows the browser's hops with the learner's login, and lets the tool
# take a token and post a score. Prints the tool's checks; exits non-zero when one fails.
#   NC_ROOT=/path/to/nextcloud LEARNER=user LEARNER_PASSWORD=... ./run-live.sh
# Needs: the learner account, an admin (ADMIN/ADMIN_PASSWORD, default admin/admin), jq.
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
NC=${NC_URL:-http://localhost:8080}; TOOL=${LTI_TOOL_URL:-http://127.0.0.1:9500}
A=(-s -u "${ADMIN:-admin}:${ADMIN_PASSWORD:-admin}" -H 'OCS-APIRequest: true' -H 'Content-Type: application/json')
OR="$NC/index.php/apps/openregister/api/objects/integriq"; RUN=$(date +%s)
export LTI_TOOL_DIR=${LTI_TOOL_DIR:-$(mktemp -d)} LTI_PLATFORM_URL=$NC LTI_TOOL_URL=$TOOL LTI_CLIENT_ID="ref-tool-$RUN"
TID=$(curl "${A[@]}" -X POST "$OR/lti_tool" -d "{\"name\":\"Reference tool $RUN\",\"clientId\":\"$LTI_CLIENT_ID\",\"oidcLoginUrl\":\"$TOOL/login\",\"launchUrl\":\"$TOOL/launch\",\"redirectUris\":[\"$TOOL/launch\"],\"jwksUri\":\"$TOOL/jwks.json\",\"status\":\"approved\"}" | jq -r .id)
DID=$(curl "${A[@]}" -X POST "$OR/lti_deployment" -d "{\"name\":\"Placement $RUN\",\"deploymentId\":\"dep-$RUN\",\"ltiToolId\":\"$TID\"}" | jq -r .id)
curl "${A[@]}" -o /dev/null -X POST "$NC/index.php/apps/integriq/api/lti/lti_tool/$TID/keys/generate"
export LTI_TOOL_UUID=$TID RUN DID
PHP_CLI_SERVER_WORKERS=4 php -S "${TOOL#http://}" "$HERE/tool.php" > "$LTI_TOOL_DIR/server.log" 2>&1 & SERVER=$!
trap 'kill $SERVER; curl "${A[@]}" -o /dev/null -X DELETE "$OR/lti_deployment/$DID"; curl "${A[@]}" -o /dev/null -X DELETE "$OR/lti_tool/$TID"' EXIT
sleep 1
LOGIN=$(php -r '
require getenv("NC_ROOT") . "/lib/base.php"; \OC_App::loadApps();
\OCP\Server::get(\OCP\IUserSession::class)->setUser(\OCP\Server::get(\OCP\IUserManager::class)->get(getenv("LEARNER")));
$e = new \OCA\Integriq\Event\LtiLaunchRequestedEvent(sourceApp: "learniq", placementId: "placement-" . getenv("RUN"), deploymentUuid: getenv("DID"), userId: getenv("LEARNER"), contextId: "course-1", contextTitle: "Live run");
\OCP\Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->dispatchTyped($e);
echo http_build_query($e->getLoginInitiation()["fields"] ?? []);' 2>/dev/null | tail -1)
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST --data "$LOGIN" "$TOOL/login")
FORM=$(curl -s -u "$LEARNER:$LEARNER_PASSWORD" "$LOC" | php -r '$s = stream_get_contents(STDIN); preg_match_all("/<input[^>]*name=\"([^\"]+)\"[^>]*value=\"([^\"]*)\"/", $s, $m); echo http_build_query(array_combine(array_map("html_entity_decode", $m[1]), array_map("html_entity_decode", $m[2])));')
RESULT=$(curl -s -X POST --data "$FORM" "$TOOL/launch"); echo "$RESULT" | jq .
echo "$RESULT" | jq -e '.launch.signatureVerified and .launch.stateMatches and .launch.nonceMatches and .token == 200 and (.score == 200 or .score == 204)' > /dev/null
