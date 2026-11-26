<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A minimal LTI 1.3 reference tool for the connectors-lti-platform-launch live run (tasks 2 and 3):
 * OIDC login, launch (the id_token verified against the platform's key set, state and nonce
 * checked), a client-credentials token with a signed client assertion, and an AGS score posted
 * to the line item the launch named. Every step is appended to $LTI_TOOL_DIR/run.log.
 * Serve it with several workers, because the platform fetches this tool's key set while the
 * tool waits for the token: PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:9500 tool.php
 * See run-live.sh next to this file.
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-launched-tool-can-send-a-grade-back-to-the-placement-req-ltil-003
 */
require (getenv('INTEGRIQ_VENDOR') ?: __DIR__ . '/../../../vendor') . '/autoload.php';
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;

define('DIR', getenv('LTI_TOOL_DIR') ?: sys_get_temp_dir() . '/lti-reference-tool');
@mkdir(DIR, 0700, true);
define('PLATFORM', getenv('LTI_PLATFORM_URL') ?: 'http://localhost:8080');
define('AUTHORIZE', PLATFORM . '/index.php/apps/integriq/api/lti/platform/authorize');
define('TOKEN', PLATFORM . '/index.php/apps/integriq/api/lti/token');
define('CLIENT_ID', getenv('LTI_CLIENT_ID') ?: 'ref-tool-client');
define('SELF', getenv('LTI_TOOL_URL') ?: 'http://127.0.0.1:9500');

function logEvent(string $step, array $data): void { file_put_contents(DIR . '/run.log', json_encode(['step' => $step] + $data, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND); }
function toolKey(): \Jose\Component\Core\JWK {
	if (is_file(DIR . '/key.json') === false) {
		file_put_contents(DIR . '/key.json', json_encode(JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'ref-tool-1'])));
	}
	return \Jose\Component\Core\JWK::createFromJson(file_get_contents(DIR . '/key.json'));
}
function state(?array $set = null): array {
	if ($set !== null) { file_put_contents(DIR . '/state.json', json_encode($set)); return $set; }
	return json_decode((string)@file_get_contents(DIR . '/state.json'), true) ?: [];
}
function http(string $method, string $url, array $headers = [], ?string $body = null): array {
	$c = curl_init($url);
	curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_PROXY => '']);
	if ($body !== null) { curl_setopt($c, CURLOPT_POSTFIELDS, $body); }
	$out = (string)curl_exec($c);
	return [curl_getinfo($c, CURLINFO_HTTP_CODE), $out];
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/jwks.json') { echo json_encode(['keys' => [toolKey()->toPublic()->all()]]); return true; }
if ($path === '/login') {
	$p = $_REQUEST;
	$st = bin2hex(random_bytes(8)); $nonce = bin2hex(random_bytes(8));
	state(['state' => $st, 'nonce' => $nonce]);
	logEvent('login', ['received' => array_keys($p), 'iss' => $p['iss'] ?? null, 'client_id' => $p['client_id'] ?? null, 'lti_deployment_id' => $p['lti_deployment_id'] ?? null]);
	$q = http_build_query(['scope' => 'openid', 'response_type' => 'id_token', 'response_mode' => 'form_post', 'prompt' => 'none', 'client_id' => CLIENT_ID,
		'redirect_uri' => SELF . '/launch', 'login_hint' => $p['login_hint'] ?? '', 'lti_message_hint' => $p['lti_message_hint'] ?? '', 'state' => $st, 'nonce' => $nonce]);
	header('Location: ' . AUTHORIZE . '?' . $q, true, 302); return true;
}
if ($path === '/launch') {
	$st = state(); $serializer = new CompactSerializer();
	$jws = $serializer->unserialize($_POST['id_token'] ?? '');
	$claims = json_decode($jws->getPayload(), true);
	[$code, $set] = http('GET', $claims['iss'] . '/index.php/apps/integriq/.well-known/lti/lti_tool/' . rawurlencode((string)getenv('LTI_TOOL_UUID')) . '/jwks.json');
	$verified = (new JWSVerifier(new AlgorithmManager([new RS256()])))->verifyWithKeySet($jws, JWKSet::createFromJson($set), 0);
	$ags = $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] ?? [];
	$check = ['signatureVerified' => $verified, 'stateMatches' => ($_POST['state'] ?? '') === $st['state'], 'nonceMatches' => ($claims['nonce'] ?? '') === $st['nonce'],
		'aud' => $claims['aud'] ?? null, 'deploymentId' => $claims['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] ?? null,
		'messageType' => $claims['https://purl.imsglobal.org/spec/lti/claim/message_type'] ?? null, 'sub' => $claims['sub'] ?? null,
		'roles' => $claims['https://purl.imsglobal.org/spec/lti/claim/roles'] ?? null, 'lineitem' => $ags['lineitem'] ?? null, 'agsScope' => $ags['scope'] ?? null];
	logEvent('launch', $check);
	$assertion = $serializer->serialize((new JWSBuilder(new AlgorithmManager([new RS256()])))->create()
		->withPayload(json_encode(['iss' => CLIENT_ID, 'sub' => CLIENT_ID, 'aud' => TOKEN, 'iat' => time(), 'exp' => time() + 300, 'jti' => bin2hex(random_bytes(8))]))
		->addSignature(toolKey(), ['alg' => 'RS256', 'kid' => 'ref-tool-1', 'typ' => 'JWT'])->build(), 0);
	[$tcode, $tbody] = http('POST', TOKEN, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'client_credentials',
		'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => $assertion,
		'scope' => 'https://purl.imsglobal.org/spec/lti-ags/scope/score']));
	$token = json_decode($tbody, true) ?: [];
	logEvent('token', ['status' => $tcode, 'tokenType' => $token['token_type'] ?? null, 'scope' => $token['scope'] ?? null, 'error' => $token['error'] ?? null]);
	$score = ['userId' => $claims['sub'] ?? '', 'scoreGiven' => 8.5, 'scoreMaximum' => 10, 'activityProgress' => 'Completed', 'gradingProgress' => 'FullyGraded', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')];
	[$scode, $sbody] = http('POST', ($ags['lineitem'] ?? '') . '/scores', ['Content-Type: application/vnd.ims.lis.v1.score+json', 'Authorization: Bearer ' . ($token['access_token'] ?? '')], json_encode($score));
	logEvent('score', ['status' => $scode, 'body' => substr($sbody, 0, 300)]);
	echo json_encode(['launch' => $check, 'token' => $tcode, 'score' => $scode]); return true;
}
http_response_code(404); echo '{}'; return true;
