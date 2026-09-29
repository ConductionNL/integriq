<?php

/**
 * The register accepts the broker subscription the Webhooks form saves.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-the-subscription-form-offers-broker-as-a-delivery-action-req-ebsc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * tests/fixtures/events/broker-subscription-payload.json against the merged event_subscription schema.
 */
class BrokerSubscriptionPayloadTest extends TestCase {

	/**
	 * The subscription object from the fixture the vitest spec builds.
	 *
	 * @return array
	 */
	private function subscription(): array {
		$path = dirname(__DIR__, 2) . '/fixtures/events/broker-subscription-payload.json';
		return json_decode((string)file_get_contents($path), true)['subscription'];

	}//end subscription()

	/**
	 * The stored RabbitMQ broker subscription passes the register's validator.
	 *
	 * @return void
	 */
	public function testTheRegisterAcceptsTheBrokerSubscriptionTheFormSaves(): void {
		$this->assertSame([], RegisterSchemaValidator::errors('event_subscription', $this->subscription()));

	}//end testTheRegisterAcceptsTheBrokerSubscriptionTheFormSaves()

	/**
	 * A content mode the schema does not know is refused, so the check above can fail.
	 *
	 * @return void
	 */
	public function testAnUnknownContentModeIsRefused(): void {
		$subscription = $this->subscription();
		$subscription['action']['contentMode'] = 'batched';

		$this->assertNotSame([], RegisterSchemaValidator::errors('event_subscription', $subscription));

	}//end testAnUnknownContentModeIsRefused()

	/**
	 * The saved subscription holds no secret, only the credential reference.
	 *
	 * @return void
	 */
	public function testTheSavedSubscriptionHoldsNoSecret(): void {
		$broker = $this->subscription()['protocolSettings']['broker'];

		$this->assertArrayNotHasKey('password', $broker);
		$this->assertArrayNotHasKey('token', $broker);
		$this->assertSame(['credentialId' => 'cred-rabbitmq-zaken'], $broker['credentialRef']);

	}//end testTheSavedSubscriptionHoldsNoSecret()

}//end class
