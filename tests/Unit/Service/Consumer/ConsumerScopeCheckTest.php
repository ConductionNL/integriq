<?php

/**
 * Unit tests for ConsumerScopeCheck — required scopes per method, the scopes a
 * caller holds, and which are missing.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use OCA\Integriq\Service\Consumer\ConsumerScopeCheck;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the scope check.
 */
#[CoversClass(ConsumerScopeCheck::class)]
class ConsumerScopeCheckTest extends TestCase {

	/**
	 * A list applies to every method; a map applies per method, case-insensitively, plus its `*` entry.
	 *
	 * @return void
	 */
	public function testRequiredScopesPerMethod(): void {
		$this->assertSame([], ConsumerScopeCheck::requiredFor(authentication: ['type' => 'apikey'], method: 'GET'));
		$this->assertSame([], ConsumerScopeCheck::requiredFor(authentication: ['requiredScopes' => 'zaken:read'], method: 'GET'));
		$this->assertSame(['a', 'b'], ConsumerScopeCheck::requiredFor(authentication: ['requiredScopes' => ['a', 'b', 'a', '', 3]], method: 'DELETE'));

		$map = ['requiredScopes' => ['post' => ['zaken:write'], '*' => ['zaken:any'], 'GET' => ['zaken:read'], 'PUT' => 'not-a-list']];
		$this->assertSame(['zaken:write', 'zaken:any'], ConsumerScopeCheck::requiredFor(authentication: $map, method: 'POST'));
		$this->assertSame(['zaken:any', 'zaken:read'], ConsumerScopeCheck::requiredFor(authentication: $map, method: 'get'));
		$this->assertSame(['zaken:any'], ConsumerScopeCheck::requiredFor(authentication: $map, method: 'PUT'));
	}//end testRequiredScopesPerMethod()

	/**
	 * A token's scope or scp claim wins; without either, the consumer's stored scopes count.
	 *
	 * @return void
	 */
	public function testHeldScopes(): void {
		$consumer = new ObjectEntity();
		$consumer->setObject(['scopes' => ['stored', ' stored ', 7]]);

		$this->assertSame(['a', 'b'], ConsumerScopeCheck::heldBy(consumer: $consumer, tokenClaims: ['scope' => ' a  b ']));
		$this->assertSame(['x'], ConsumerScopeCheck::heldBy(consumer: $consumer, tokenClaims: ['scp' => ['x', '']]));
		$this->assertSame(['stored'], ConsumerScopeCheck::heldBy(consumer: $consumer, tokenClaims: ['sub' => 'c']));
		$this->assertSame(['stored'], ConsumerScopeCheck::heldBy(consumer: $consumer, tokenClaims: null));
		$this->assertSame([], ConsumerScopeCheck::heldBy(consumer: null, tokenClaims: null));

		$without = new ObjectEntity();
		$without->setObject(['scopes' => 'zaken:read']);
		$this->assertSame([], ConsumerScopeCheck::heldBy(consumer: $without, tokenClaims: null));
	}//end testHeldScopes()

	/**
	 * Missing scopes are the required ones not held, in required order.
	 *
	 * @return void
	 */
	public function testMissingScopes(): void {
		$this->assertSame(['c', 'a'], ConsumerScopeCheck::missing(required: ['c', 'b', 'a'], held: ['b']));
		$this->assertSame([], ConsumerScopeCheck::missing(required: ['a'], held: ['a', 'z']));
	}//end testMissingScopes()
}//end class
