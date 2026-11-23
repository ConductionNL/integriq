<?php

/**
 * IntegriqResolvedConsumerTest: the resolved consumer integriq hands OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use OCA\Integriq\Service\Consumer\IntegriqConsumerSource;
use OCA\Integriq\Service\Consumer\IntegriqResolvedConsumer;
use OCA\Integriq\Service\OAuth\IssuerKeyService;
use OCA\Integriq\Service\OAuth\TokenIssuer;
use PHPUnit\Framework\TestCase;

/**
 * The identity and the authorization are filled from one consumer, with integriq as the source.
 *
 * @covers \OCA\Integriq\Service\Consumer\IntegriqResolvedConsumer
 */
class IntegriqResolvedConsumerTest extends TestCase {

	/**
	 * A consumer object becomes an integriq identity and its own authorization, readable flat as before.
	 *
	 * @return void
	 */
	public function testARecordBecomesAnIntegriqIdentityAndItsAuthorization(): void {
		$record   = new \stdClass();
		$resolved = (new IntegriqResolvedConsumer())->fromRecord(
			who: ['uuid' => 'c-1', 'name' => 'partner', 'userId' => 'svc-partner'],
			authorizationType: 'apiKey',
			configuration: ['apiKey' => 'k-1'],
			record: $record
		);

		$this->assertSame(expected: IntegriqConsumerSource::SOURCE, actual: $resolved->identity->source);
		$this->assertSame(expected: 'c-1', actual: $resolved->identity->uuid);
		$this->assertSame(expected: 'partner', actual: $resolved->identity->name);
		$this->assertSame(expected: 'svc-partner', actual: $resolved->authorization->userId);
		$this->assertSame(expected: 'apiKey', actual: $resolved->authorization->authorizationType);
		$this->assertSame(expected: ['apiKey' => 'k-1'], actual: $resolved->authorization->configuration);
		$this->assertSame(expected: 'apiKey', actual: $resolved->authorizationType);
		$this->assertSame(expected: $record, actual: $resolved->record);
	}//end testARecordBecomesAnIntegriqIdentityAndItsAuthorization()

	/**
	 * A token of integriq's own issuer is verified against the issuer's public key, as a client credentials consumer.
	 *
	 * @return void
	 */
	public function testAnOwnTokenConsumerIsCheckedAgainstTheIssuersPublicKey(): void {
		$resolved = (new IntegriqResolvedConsumer())->ownToken(
			who: ['uuid' => 'c-2', 'name' => 'portal', 'userId' => null],
			publicPem: 'PEM',
			record: null
		);

		$this->assertSame(expected: IntegriqConsumerSource::SOURCE, actual: $resolved->identity->source);
		$this->assertSame(expected: TokenIssuer::CLIENT_CREDENTIALS, actual: $resolved->authorization->authorizationType);
		$this->assertSame(
			expected: ['algorithm' => IssuerKeyService::ALGORITHM, 'publicKey' => 'PEM'],
			actual: $resolved->authorization->configuration
		);
		$this->assertNull(actual: $resolved->authorization->userId);
	}//end testAnOwnTokenConsumerIsCheckedAgainstTheIssuersPublicKey()
}//end class
