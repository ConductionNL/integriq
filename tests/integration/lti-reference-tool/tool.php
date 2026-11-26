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

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;

/**
 * Read an environment variable, or fall back when it is unset or empty.
 *
 * @param string $name The variable name.
 * @param string $fallback The value used when the variable is unset or empty.
 *
 * @return string The value.
 */
function envOr(string $name, string $fallback): string {
	$value = getenv($name);
	if (is_string($value) === false || $value === '') {
		return $fallback;
	}

	return $value;
}//end envOr()

require envOr(name: 'INTEGRIQ_VENDOR', fallback: __DIR__ . '/../../../vendor') . '/autoload.php';

define('DIR', envOr(name: 'LTI_TOOL_DIR', fallback: sys_get_temp_dir() . '/lti-reference-tool'));
@mkdir(DIR, 0700, true);
define('PLATFORM', envOr(name: 'LTI_PLATFORM_URL', fallback: 'http://localhost:8080'));
define('AUTHORIZE', PLATFORM . '/index.php/apps/integriq/api/lti/platform/authorize');
define('TOKEN', PLATFORM . '/index.php/apps/integriq/api/lti/token');
define('CLIENT_ID', envOr(name: 'LTI_CLIENT_ID', fallback: 'ref-tool-client'));
define('SELF', envOr(name: 'LTI_TOOL_URL', fallback: 'http://127.0.0.1:9500'));

/**
 * Append one step to the run log.
 *
 * @param string $step The step name.
 * @param array $data What the step saw.
 *
 * @return void
 */
function logEvent(string $step, array $data): void {
	$line = json_encode(['step' => $step] + $data, JSON_UNESCAPED_SLASHES) . "\n";
	file_put_contents(DIR . '/run.log', $line, FILE_APPEND);
}//end logEvent()

/**
 * The tool's signing key, created on first use.
 *
 * @return JWK The private key.
 */
function toolKey(): JWK {
	if (is_file(DIR . '/key.json') === false) {
		$key = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'ref-tool-1']);
		file_put_contents(DIR . '/key.json', json_encode($key));
	}

	return JWK::createFromJson(file_get_contents(DIR . '/key.json'));
}//end toolKey()

/**
 * Store the login state, or read it back when no state is given.
 *
 * @param array|null $set The state to store, or null to read.
 *
 * @return array The state.
 */
function state(?array $set = null): array {
	if ($set !== null) {
		file_put_contents(DIR . '/state.json', json_encode($set));
		return $set;
	}

	return decodeObject(json: (string)@file_get_contents(DIR . '/state.json'));
}//end state()

/**
 * Send one HTTP request, bypassing any proxy.
 *
 * @param string $method The HTTP method.
 * @param string $url The URL.
 * @param array $headers The request headers.
 * @param string|null $body The request body.
 *
 * @return array The status code and the response body.
 */
function http(string $method, string $url, array $headers = [], ?string $body = null): array {
	$curl = curl_init($url);
	curl_setopt_array(
		$curl,
		[
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_PROXY => '',
		]
	);
	if ($body !== null) {
		curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
	}

	$out = (string)curl_exec($curl);
	return [curl_getinfo($curl, CURLINFO_HTTP_CODE), $out];
}//end http()

/**
 * Decode a JSON object, or return an empty array.
 *
 * @param string $json The JSON text.
 *
 * @return array The decoded object.
 */
function decodeObject(string $json): array {
	$decoded = json_decode($json, true);
	if (is_array($decoded) === false) {
		return [];
	}

	return $decoded;
}//end decodeObject()

/**
 * Answer the OIDC login initiation: store state and nonce, redirect to the platform.
 *
 * @return void
 */
function handleLogin(): void {
	$params = $_REQUEST;
	$state = bin2hex(random_bytes(8));
	$nonce = bin2hex(random_bytes(8));
	state(set: ['state' => $state, 'nonce' => $nonce]);
	logEvent(
		step: 'login',
		data: [
			'received' => array_keys($params),
			'iss' => ($params['iss'] ?? null),
			'client_id' => ($params['client_id'] ?? null),
			'lti_deployment_id' => ($params['lti_deployment_id'] ?? null),
		]
	);
	$query = http_build_query(
		[
			'scope' => 'openid',
			'response_type' => 'id_token',
			'response_mode' => 'form_post',
			'prompt' => 'none',
			'client_id' => CLIENT_ID,
			'redirect_uri' => SELF . '/launch',
			'login_hint' => ($params['login_hint'] ?? ''),
			'lti_message_hint' => ($params['lti_message_hint'] ?? ''),
			'state' => $state,
			'nonce' => $nonce,
		]
	);
	header('Location: ' . AUTHORIZE . '?' . $query, true, 302);
}//end handleLogin()

/**
 * Get a client-credentials token with a signed client assertion.
 *
 * @param CompactSerializer $serializer The JWS serializer.
 *
 * @return array The status code and the decoded token response.
 */
function fetchToken(CompactSerializer $serializer): array {
	$payload = [
		'iss' => CLIENT_ID,
		'sub' => CLIENT_ID,
		'aud' => TOKEN,
		'iat' => time(),
		'exp' => (time() + 300),
		'jti' => bin2hex(random_bytes(8)),
	];
	$builder = new JWSBuilder(new AlgorithmManager([new RS256()]));
	$signed = $builder->create()->withPayload(json_encode($payload))
		->addSignature(toolKey(), ['alg' => 'RS256', 'kid' => 'ref-tool-1', 'typ' => 'JWT'])->build();
	$form = http_build_query(
		[
			'grant_type' => 'client_credentials',
			'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
			'client_assertion' => $serializer->serialize($signed, 0),
			'scope' => 'https://purl.imsglobal.org/spec/lti-ags/scope/score',
		]
	);
	[$code, $body] = http(method: 'POST', url: TOKEN, headers: ['Content-Type: application/x-www-form-urlencoded'], body: $form);
	$token = decodeObject(json: $body);
	logEvent(
		step: 'token',
		data: [
			'status' => $code,
			'tokenType' => ($token['token_type'] ?? null),
			'scope' => ($token['scope'] ?? null),
			'error' => ($token['error'] ?? null),
		]
	);

	return [$code, $token];
}//end fetchToken()

/**
 * Answer the launch: verify the id_token, fetch a token and post a score.
 *
 * @return void
 */
function handleLaunch(): void {
	$stored = state();
	$serializer = new CompactSerializer();
	$jws = $serializer->unserialize(($_POST['id_token'] ?? ''));
	$claims = json_decode($jws->getPayload(), true);
	$keySetUrl = $claims['iss'] . '/index.php/apps/integriq/.well-known/lti/lti_tool/';
	$keySetUrl .= rawurlencode((string)getenv('LTI_TOOL_UUID')) . '/jwks.json';
	[, $keySet] = http(method: 'GET', url: $keySetUrl);
	$verifier = new JWSVerifier(new AlgorithmManager([new RS256()]));
	$ags = ($claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] ?? []);
	$check = [
		'signatureVerified' => $verifier->verifyWithKeySet($jws, JWKSet::createFromJson($keySet), 0),
		'stateMatches' => (($_POST['state'] ?? '') === $stored['state']),
		'nonceMatches' => (($claims['nonce'] ?? '') === $stored['nonce']),
		'aud' => ($claims['aud'] ?? null),
		'deploymentId' => ($claims['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] ?? null),
		'messageType' => ($claims['https://purl.imsglobal.org/spec/lti/claim/message_type'] ?? null),
		'sub' => ($claims['sub'] ?? null),
		'roles' => ($claims['https://purl.imsglobal.org/spec/lti/claim/roles'] ?? null),
		'lineitem' => ($ags['lineitem'] ?? null),
		'agsScope' => ($ags['scope'] ?? null),
	];
	logEvent(step: 'launch', data: $check);

	[$tokenCode, $token] = fetchToken(serializer: $serializer);

	$score = [
		'userId' => ($claims['sub'] ?? ''),
		'scoreGiven' => 8.5,
		'scoreMaximum' => 10,
		'activityProgress' => 'Completed',
		'gradingProgress' => 'FullyGraded',
		'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
	];
	$headers = [
		'Content-Type: application/vnd.ims.lis.v1.score+json',
		'Authorization: Bearer ' . ($token['access_token'] ?? ''),
	];
	[$scoreCode, $scoreBody] = http(method: 'POST', url: ($ags['lineitem'] ?? '') . '/scores', headers: $headers, body: json_encode($score));
	logEvent(step: 'score', data: ['status' => $scoreCode, 'body' => substr($scoreBody, 0, 300)]);
	echo json_encode(['launch' => $check, 'token' => $tokenCode, 'score' => $scoreCode]);
}//end handleLaunch()

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/jwks.json') {
	echo json_encode(['keys' => [toolKey()->toPublic()->all()]]);
	return true;
}

if ($path === '/login') {
	handleLogin();
	return true;
}

if ($path === '/launch') {
	handleLaunch();
	return true;
}

http_response_code(404);
echo '{}';
return true;
