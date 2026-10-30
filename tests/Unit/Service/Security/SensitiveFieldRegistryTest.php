<?php

/**
 * Unit tests for SensitiveFieldRegistry.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use PHPUnit\Framework\TestCase;

/**
 * TC-1 / TC-2 — redaction matrix for the shared sensitive-field registry.
 */
class SensitiveFieldRegistryTest extends TestCase {

	/**
	 * @var SensitiveFieldRegistry
	 */
	private SensitiveFieldRegistry $registry;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = new SensitiveFieldRegistry();
	}//end setUp()

	/**
	 * TC-1: every secret-shaped field/header name from #1013/#964 is detected.
	 *
	 * @return void
	 */
	public function testIsSensitiveNameMatchesEverySecretShapedName(): void {
		$secretNames = [
			'Authorization',
			'Proxy-Authorization',
			'Cookie',
			'Set-Cookie',
			'X-Api-Key',
			'client_secret',
			'apikey',
			'password',
			'passwd',
			'access_token',
			'bearer',
			'signature',
			'assertion',
			'private_key',
			'username',
			'authorizationHeader',
			'jwt',
			'jwtId',
		];

		foreach ($secretNames as $name) {
			$this->assertTrue($this->registry->isSensitiveName(name: $name), "expected '{$name}' to be sensitive");
		}
	}//end testIsSensitiveNameMatchesEverySecretShapedName()

	/**
	 * TC-1: non-secret control names are NOT flagged.
	 *
	 * @return void
	 */
	public function testIsSensitiveNameRejectsControlNames(): void {
		foreach (['Accept', 'page', 'title'] as $name) {
			$this->assertFalse($this->registry->isSensitiveName(name: $name), "expected '{$name}' to be non-sensitive");
		}
	}//end testIsSensitiveNameRejectsControlNames()

	/**
	 * TC-1: the exact-match names lifted from SourceHandler return true even
	 * though some (e.g. `username`) do not match the regex pattern alone.
	 *
	 * @return void
	 */
	public function testIsSensitiveNameMatchesSourceHandlerExactList(): void {
		$exactNames = [
			'authorizationHeader',
			'auth',
			'authenticationConfig',
			'authorizationPassthroughMethod',
			'jwt',
			'jwtId',
			'secret',
			'username',
			'password',
			'apikey',
		];

		foreach ($exactNames as $name) {
			$this->assertTrue($this->registry->isSensitiveName(name: $name), "expected exact-match name '{$name}' to be sensitive");
		}
	}//end testIsSensitiveNameMatchesSourceHandlerExactList()

	/**
	 * TC-1: matching is case-insensitive.
	 *
	 * @return void
	 */
	public function testIsSensitiveNameIsCaseInsensitive(): void {
		$this->assertTrue($this->registry->isSensitiveName(name: 'AUTHORIZATION'));
		$this->assertTrue($this->registry->isSensitiveName(name: 'Client_Secret'));
		$this->assertTrue($this->registry->isSensitiveName(name: 'USERNAME'));
	}//end testIsSensitiveNameIsCaseInsensitive()

	/**
	 * TC-1: `isSensitiveName('client_secret')` explicitly returns true (proposal example).
	 *
	 * @return void
	 */
	public function testIsSensitiveNameClientSecret(): void {
		$this->assertTrue($this->registry->isSensitiveName(name: 'client_secret'));
	}//end testIsSensitiveNameClientSecret()

	/**
	 * TC-2: redactArray masks a deeply nested secret value without disturbing
	 * sibling keys at any level or the array's key order.
	 *
	 * @return void
	 */
	public function testRedactArrayMasksNestedSecretWithoutDisturbingStructure(): void {
		$fixture = [
			'level1' => [
				'level2' => [
					'level3' => [
						'Authorization' => 'Bearer live-secret-token',
						'Accept' => 'application/json',
					],
					'title' => 'unchanged',
				],
				'page' => 1,
			],
		];

		$result = $this->registry->redactArray(data: $fixture);

		$this->assertSame('***REDACTED***', $result['level1']['level2']['level3']['Authorization']);
		$this->assertSame('application/json', $result['level1']['level2']['level3']['Accept']);
		$this->assertSame('unchanged', $result['level1']['level2']['title']);
		$this->assertSame(1, $result['level1']['page']);

		// Key order preserved at every level.
		$this->assertSame(['level1'], array_keys($result));
		$this->assertSame(['level2', 'page'], array_keys($result['level1']));
		$this->assertSame(['level3', 'title'], array_keys($result['level1']['level2']));
		$this->assertSame(['Authorization', 'Accept'], array_keys($result['level1']['level2']['level3']));
	}//end testRedactArrayMasksNestedSecretWithoutDisturbingStructure()

	/**
	 * GIVEN a nested array `{"action":{"headers":{"Authorization":"Bearer x"}}}`
	 * WHEN redactArray(...) is called THEN the nested Authorization value
	 * SHALL be ***REDACTED*** and all other keys SHALL be unchanged.
	 *
	 * @return void
	 */
	public function testRedactArrayHandlesActionHeadersAuthorizationExample(): void {
		$fixture = ['action' => ['headers' => ['Authorization' => 'Bearer x']]];

		$result = $this->registry->redactArray(data: $fixture);

		$this->assertSame('***REDACTED***', $result['action']['headers']['Authorization']);
	}//end testRedactArrayHandlesActionHeadersAuthorizationExample()

	/**
	 * redactArray also matches on the last dot-segment of a flat dot-notation
	 * key (the shape Source's `configuration` array uses).
	 *
	 * @return void
	 */
	public function testRedactArrayMatchesLastDotSegmentOfFlatKeys(): void {
		$fixture = [
			'headers.Authorization' => 'Bearer live-token',
			'headers.Accept' => 'application/json',
		];

		$result = $this->registry->redactArray(data: $fixture);

		$this->assertSame('***REDACTED***', $result['headers.Authorization']);
		$this->assertSame('application/json', $result['headers.Accept']);
	}//end testRedactArrayMatchesLastDotSegmentOfFlatKeys()

	/**
	 * redactArray does not mutate the caller's original array (operates on a copy).
	 *
	 * @return void
	 */
	public function testRedactArrayDoesNotMutateCallerArray(): void {
		$fixture = ['Authorization' => 'Bearer live-token'];

		$this->registry->redactArray(data: $fixture);

		$this->assertSame('Bearer live-token', $fixture['Authorization']);
	}//end testRedactArrayDoesNotMutateCallerArray()
	/**
	 * A list under a sensitive key is masked item by item. PSR-7 response
	 * headers are lists (`['Authorization' => ['Bearer x']]`), whose integer
	 * keys say nothing about the secret they hold.
	 *
	 * @return void
	 */
	public function testRedactArrayMasksAListUnderASensitiveKey(): void {
		$result = $this->registry->redactArray(
			data: [
				'headers' => [
					'Authorization' => ['Bearer live-token'],
					'Set-Cookie' => ['sid=1; HttpOnly', 'csrf=2'],
					'Content-Type' => ['application/json'],
				],
			]
		);

		$this->assertSame(['***REDACTED***'], $result['headers']['Authorization']);
		$this->assertSame(['***REDACTED***', '***REDACTED***'], $result['headers']['Set-Cookie']);
		$this->assertSame(['application/json'], $result['headers']['Content-Type']);
	}//end testRedactArrayMasksAListUnderASensitiveKey()

	/**
	 * A list under a sensitive key is masked at any depth; a map under one is
	 * walked by its own keys, so a credential pointer survives (REQ-010) and a
	 * password inside it does not. Other keys are untouched.
	 *
	 * @return void
	 */
	public function testRedactArrayMasksListsUnderASensitiveKeyAtAnyDepthAndWalksMaps(): void {
		$result = $this->registry->redactArray(
			data: [
				'auth' => ['alice', 'hunter2', [['k' => 'v'], 7, null]],
				'authentication' => ['credentialRef' => ['credentialId' => 'c-1'], 'password' => 'p', 'headers' => ['Cookie' => ['x']]],
				'meta' => ['owner' => 'bob', 'tags' => ['a']],
			]
		);

		$this->assertSame(['***REDACTED***', '***REDACTED***', [['k' => '***REDACTED***'], '***REDACTED***', '***REDACTED***']], $result['auth']);
		$this->assertSame(
			['credentialRef' => ['credentialId' => 'c-1'], 'password' => '***REDACTED***', 'headers' => ['Cookie' => ['***REDACTED***']]],
			$result['authentication']
		);
		$this->assertSame(['owner' => 'bob', 'tags' => ['a']], $result['meta']);
	}//end testRedactArrayMasksListsUnderASensitiveKeyAtAnyDepthAndWalksMaps()

	/**
	 * Header names match whatever their case, nested or flat; an empty list stays empty.
	 *
	 * @return void
	 */
	public function testRedactArrayMatchesMixedCaseHeaderNamesAtAnyDepth(): void {
		$result = $this->registry->redactArray(
			data: [
				'output' => ['headers' => ['AUTHORIZATION' => ['Basic x'], 'x-Api-KEY' => ['k'], 'proxy-Authorization' => 'p']],
				'headers.SET-COOKIE' => ['a=b'],
				'Cookie' => [],
			]
		);

		$this->assertSame(['***REDACTED***'], $result['output']['headers']['AUTHORIZATION']);
		$this->assertSame(['***REDACTED***'], $result['output']['headers']['x-Api-KEY']);
		$this->assertSame('***REDACTED***', $result['output']['headers']['proxy-Authorization']);
		$this->assertSame(['***REDACTED***'], $result['headers.SET-COOKIE']);
		$this->assertSame([], $result['Cookie']);
	}//end testRedactArrayMatchesMixedCaseHeaderNamesAtAnyDepth()

	/**
	 * A call's extra exact names are matched case-insensitively, and a list under one is masked.
	 *
	 * @return void
	 */
	public function testRedactArrayMasksTheCallsExtraExactNames(): void {
		$result = $this->registry->redactArray(data: ['Pin' => ['1234'], 'name' => 'n'], extraExactNames: ['pin']);

		$this->assertSame(['Pin' => ['***REDACTED***'], 'name' => 'n'], $result);
	}//end testRedactArrayMasksTheCallsExtraExactNames()

	/**
	 * A session header is a credential; a `sessionId` payload field is not.
	 *
	 * @return void
	 */
	public function testASessionHeaderIsSensitiveButASessionFieldIsNot(): void {
		$this->assertTrue($this->registry->isSensitiveHeaderName(name: 'X-Session'));
		$this->assertTrue($this->registry->isSensitiveHeaderName(name: 'x-session-id'));
		$this->assertTrue($this->registry->isSensitiveHeaderName(name: 'Authorization'));
		$this->assertFalse($this->registry->isSensitiveHeaderName(name: 'Content-Type'));
		$this->assertFalse($this->registry->isSensitiveName(name: 'sessionId'));

		$this->assertSame(
			['X-Session' => ['***REDACTED***'], 'Content-Type' => ['text/plain']],
			$this->registry->redactHeaders(headers: ['X-Session' => ['s-1'], 'Content-Type' => ['text/plain']])
		);
	}//end testASessionHeaderIsSensitiveButASessionFieldIsNot()

	/**
	 * Issued tokens are masked in a JSON body at any depth; any other body is left alone.
	 *
	 * @return void
	 */
	public function testIssuedTokensAreMaskedInAJsonBody(): void {
		$masked = $this->registry->redactIssuedTokensInJson(body: '[{"Access_Token":"a","data":{"refresh_token":"r"}}]');
		$this->assertSame('[{"Access_Token":"***REDACTED***","data":{"refresh_token":"***REDACTED***"}}]', $masked);

		$this->assertSame(
			'{"access_token":"***REDACTED***","meta":{},"list":[],"url":"https://idp.example/ü"}',
			$this->registry->redactIssuedTokensInJson(body: '{"access_token":"x","meta":{},"list":[],"url":"https://idp.example/ü"}')
		);

		$this->assertSame('access_token=a&x=1', $this->registry->redactIssuedTokensInJson(body: 'access_token=a&x=1'));
		$this->assertSame('{"name": "x"}', $this->registry->redactIssuedTokensInJson(body: '{"name": "x"}'));
	}//end testIssuedTokensAreMaskedInAJsonBody()
}//end class
