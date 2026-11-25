<?php

/**
 * ObjectRightsPostureTest
 *
 * Measures, on a live instance, what a signed-in user in no group may do with
 * each of the nine configuration schemas through OpenRegister's object API,
 * and checks the `object.*` seed against it: a row seeded with
 * `authenticated` must be allowed, a row seeded admin-only must be refused.
 * So the seed is the posture in force, and applying it changes nothing.
 *
 * Self-skips without a live instance. Set:
 * - INTEGRIQ_POSTURE_BASE_URL  the instance, e.g. http://nextcloud (no trailing slash)
 * - INTEGRIQ_POSTURE_ADMIN     admin credentials as user:password
 * - INTEGRIQ_POSTURE_OUT       optional file the measured posture is written to (JSON)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Integration;

use OCA\Integriq\Service\ObjectRightsService;
use PHPUnit\Framework\TestCase;

/**
 * Live measurement of the configuration schemas' object posture.
 */
class ObjectRightsPostureTest extends TestCase {

	/**
	 * The instance base URL.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Admin credentials, user:password.
	 *
	 * @var string
	 */
	private string $admin = '';

	/**
	 * The probe user's credentials, user:password.
	 *
	 * @var string
	 */
	private string $probe = '';

	/**
	 * Objects the admin created, as [schema, id], deleted afterwards.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $created = [];

	/**
	 * Read the instance settings or skip.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$base  = getenv('INTEGRIQ_POSTURE_BASE_URL');
		$admin = getenv('INTEGRIQ_POSTURE_ADMIN');
		if (is_string($base) === false || $base === '' || is_string($admin) === false || str_contains($admin, ':') === false) {
			$this->markTestSkipped('No live instance: set INTEGRIQ_POSTURE_BASE_URL and INTEGRIQ_POSTURE_ADMIN.');
		}

		if (function_exists('curl_init') === false) {
			$this->markTestSkipped('The curl extension is not loaded.');
		}

		$this->base  = rtrim($base, '/');
		$this->admin = $admin;

		$user     = 'posture-probe-' . bin2hex(random_bytes(4));
		$password = 'Pp-' . bin2hex(random_bytes(12));
		[$status] = $this->request(
			method: 'POST',
			path: '/ocs/v2.php/cloud/users',
			credentials: $this->admin,
			body: ['userid' => $user, 'password' => $password]
		);
		$this->assertSame(200, $status, 'the probe user in no group is created');
		$this->probe = $user . ':' . $password;
	}//end setUp()

	/**
	 * Remove the probe objects and the probe user.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->created as [$schema, $id]) {
			$this->request(method: 'DELETE', path: $this->objectPath(schema: $schema, id: $id), credentials: $this->admin);
		}

		if ($this->probe !== '') {
			$user = explode(':', $this->probe, 2)[0];
			$this->request(method: 'DELETE', path: '/ocs/v2.php/cloud/users/' . rawurlencode($user), credentials: $this->admin);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Every seeded object right equals what a user in no group can do today.
	 *
	 * @return void
	 */
	public function testTheSeedEqualsThePostureInForce(): void {
		$seed     = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/lib/actions.seed.json'), true, 512, JSON_THROW_ON_ERROR);
		$measured = [];
		$expected = [];

		foreach (ObjectRightsService::SCHEMAS as $schema) {
			foreach ($this->measure(schema: $schema) as $verb => $allowed) {
				$action = 'object.' . $schema . '.' . $verb;
				$measured[$action] = $allowed;
				$expected[$action] = in_array(ObjectRightsService::EVERY_SIGNED_IN_USER, ($seed['actions'][$action] ?? []), true);
			}
		}

		$out = getenv('INTEGRIQ_POSTURE_OUT');
		if (is_string($out) === true && $out !== '') {
			file_put_contents($out, json_encode($measured, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
		}

		$this->assertNotSame([], $measured, 'at least one verb was measured');
		$this->assertSame($expected, $measured, 'true = a user in no group is let through; the seed must say the same');
	}//end testTheSeedEqualsThePostureInForce()

	/**
	 * What a user in no group may do with one schema; a verb that cannot be
	 * probed (no object the admin could create) is left out.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, bool> Verb to whether the user was let through.
	 */
	private function measure(string $schema): array {
		$path    = $this->objectPath(schema: $schema, id: null);
		$outcome = [];

		[$status] = $this->request(method: 'POST', path: $path, credentials: $this->probe, body: ['name' => 'posture probe']);
		$outcome['create'] = self::letThrough(status: $status);
		if ($outcome['create'] === true) {
			// The probe's own object: its owner may always act on it, so it measures nothing more.
			[, $own] = $this->request(method: 'GET', path: $path . '?_limit=1&name=posture%20probe', credentials: $this->admin);
			foreach ((is_array($own['results'] ?? null) === true ? $own['results'] : []) as $object) {
				$this->rememberCreated(schema: $schema, object: $object);
			}
		}

		[$status, $object] = $this->request(method: 'POST', path: $path, credentials: $this->admin, body: ['name' => 'posture probe']);
		$id = $this->rememberCreated(schema: $schema, object: $object);
		if (($status < 200 || $status >= 300) || $id === null) {
			return $outcome;
		}

		$item = $this->objectPath(schema: $schema, id: $id);
		[$status] = $this->request(method: 'GET', path: $item, credentials: $this->probe);
		$outcome['read'] = self::letThrough(status: $status);
		[$status] = $this->request(method: 'PUT', path: $item, credentials: $this->probe, body: ['name' => 'posture probe, edited']);
		$outcome['update'] = self::letThrough(status: $status);
		[$status] = $this->request(method: 'DELETE', path: $item, credentials: $this->probe);
		$outcome['delete'] = self::letThrough(status: $status);

		return $outcome;
	}//end measure()

	/**
	 * Whether an answer means the authorization check let the request through.
	 * A validation refusal (400, 422) came after it, so it counts as let through.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return bool
	 */
	private static function letThrough(int $status): bool {
		return in_array($status, [401, 403, 404], true) === false && $status !== 0;
	}//end letThrough()

	/**
	 * Remember an object the admin created, for tearDown.
	 *
	 * @param string $schema The schema slug.
	 * @param mixed  $object The decoded answer.
	 *
	 * @return string|null The object's id, when the answer carried one.
	 */
	private function rememberCreated(string $schema, mixed $object): ?string {
		if (is_array($object) === false) {
			return null;
		}

		$id = ($object['id'] ?? ($object['@self']['id'] ?? ($object['uuid'] ?? null)));
		if (is_string($id) === false || $id === '') {
			return null;
		}

		$this->created[] = [$schema, $id];
		return $id;
	}//end rememberCreated()

	/**
	 * The OpenRegister object API path of a schema, or of one object in it.
	 *
	 * @param string      $schema The schema slug.
	 * @param string|null $id     The object id.
	 *
	 * @return string
	 */
	private function objectPath(string $schema, ?string $id): string {
		$path = '/index.php/apps/openregister/api/objects/integriq/' . $schema;
		if ($id !== null) {
			$path .= '/' . rawurlencode($id);
		}

		return $path;
	}//end objectPath()

	/**
	 * One HTTP request with basic authentication and a JSON body.
	 *
	 * @param string                    $method      The method.
	 * @param string                    $path        The path below the base URL.
	 * @param string                    $credentials user:password.
	 * @param array<string, mixed>|null $body        The JSON body.
	 *
	 * @return array{0: int, 1: mixed} The status (0 when the request failed) and the decoded answer.
	 */
	private function request(string $method, string $path, string $credentials, ?array $body = null): array {
		$curl = curl_init($this->base . $path);
		curl_setopt_array(
			$curl,
			[
				CURLOPT_CUSTOMREQUEST => $method,
				CURLOPT_USERPWD => $credentials,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_HTTPHEADER => ['OCS-APIRequest: true', 'Accept: application/json', 'Content-Type: application/json'],
				CURLOPT_POSTFIELDS => ($body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)),
			]
		);
		$raw    = curl_exec($curl);
		$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		curl_close($curl);

		if (is_string($raw) === false) {
			return [0, null];
		}

		return [$status, json_decode($raw, true)];
	}//end request()
}//end class
