<?php

/**
 * The example channel against IntegrIQ's intake channel contract.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\IntegriqExampleChannel\Tests\Unit;

use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Tests\Contract\IntakeChannelContractTestCase;
use OCA\IntegriqExampleChannel\Intake\WebhookChannel;

/**
 * Every rule of the contract, run on this app's adapter.
 */
final class WebhookChannelContractTest extends IntakeChannelContractTestCase {

	/**
	 * The adapter under test.
	 *
	 * @return IntakeChannelAdapterInterface
	 */
	protected function createAdapter(): IntakeChannelAdapterInterface {
		return new WebhookChannel();
	}//end createAdapter()

	/**
	 * A delivery the webhook really sends.
	 *
	 * @return array<string,mixed>
	 */
	protected function validPayload(): array {
		return ['id' => 'evt-1', 'from' => ['name' => 'Fenna de Jong', 'email' => 'fenna@example.nl'], 'text' => 'Mijn container is niet geleegd.'];
	}//end validPayload()
}//end class
