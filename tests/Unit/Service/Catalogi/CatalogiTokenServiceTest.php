<?php

/**
 * CatalogiTokenService unit tests: the scope is checked before any read.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Catalogi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-only-an-authorised-client-reads-and-concept-types-stay-hidden-req-zcs-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Catalogi;

use OCA\Integriq\Service\Catalogi\CatalogiBinding;
use OCA\Integriq\Service\Catalogi\CatalogiGate;
use OCA\Integriq\Service\Catalogi\CatalogiTokenService;
use OCA\Integriq\Service\Catalogi\InformatieobjecttypeEndpointHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Token, scope and concept permission.
 */
#[CoversClass(CatalogiTokenService::class)]
#[CoversClass(CatalogiGate::class)]
#[UsesClass(CatalogiBinding::class)]
#[UsesClass(InformatieobjecttypeEndpointHandler::class)]
class CatalogiTokenServiceTest extends TestCase {

	use CatalogiFixture;

	/**
	 * A client without catalogi.lezen gets 403 and the types are never read.
	 *
	 * @return void
	 */
	public function testAMissingScopeIsRefusedBeforeAnyRead(): void {
		$handler = new InformatieobjecttypeEndpointHandler(
			gate: $this->gate(binding: self::binding()),
			typesReader: static function (): array {
				self::fail('the document types were read for a refused client');
			}
		);

		foreach (['stranger', 'unknown-client'] as $client) {
			$answer = $handler->index(authorization: self::bearer(clientId: $client), baseUrl: self::BASE_URL, query: []);
			self::assertSame(403, $answer['status'], $client);
			self::assertSame('permission-denied', $answer['body']['code']);
		}

		self::assertSame(403, $handler->show(authorization: self::bearer(clientId: 'stranger'), uuid: 'type-1', baseUrl: self::BASE_URL)['status']);
	}//end testAMissingScopeIsRefusedBeforeAnyRead()

	/**
	 * No bearer token, or one the JWT check refuses, is 401.
	 *
	 * @return void
	 */
	public function testAMissingOrInvalidTokenIs401(): void {
		$tokens = $this->tokens();

		self::assertSame(['status' => 401, 'concepts' => false], $tokens->verdict(authorization: '', binding: self::binding()));
		self::assertSame(['status' => 401, 'concepts' => false], $tokens->verdict(authorization: 'Token abc', binding: self::binding()));
		self::assertSame(['status' => 401, 'concepts' => false], $tokens->verdict(authorization: 'Bearer invalid', binding: self::binding()));
		self::assertSame(401, $this->gate(binding: self::binding())->open(authorization: '')['refusal']['status']);
	}//end testAMissingOrInvalidTokenIs401()

	/**
	 * An authorised client is allowed, with its own concept permission.
	 *
	 * @return void
	 */
	public function testAnAuthorisedClientCarriesItsConceptPermission(): void {
		$tokens = $this->tokens();

		self::assertSame(['status' => 200, 'concepts' => false], $tokens->verdict(authorization: self::bearer(clientId: 'reader'), binding: self::binding()));
		self::assertSame(['status' => 200, 'concepts' => true], $tokens->verdict(authorization: self::bearer(clientId: 'concepts'), binding: self::binding()));
		self::assertSame(403, $tokens->verdict(authorization: 'Bearer a.not-json.c', binding: self::binding())['status'], 'a payload naming no client');
	}//end testAnAuthorisedClientCarriesItsConceptPermission()

}//end class
