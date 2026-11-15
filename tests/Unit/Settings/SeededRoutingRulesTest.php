<?php

/**
 * Unit test for the seeded intake routing rules.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-the-seeded-routing-rules-name-channels-integriq-has-req-ire-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Intake\Adapter\FormSubmissionAdapter;
use OCA\Integriq\Intake\Adapter\MessagingChannelAdapter;
use OCA\Integriq\Intake\Adapter\PublicSpaceReportAdapter;
use OCA\Integriq\Intake\Adapter\TeamsChannelAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Every seeded routing rule names a channel an adapter answers to.
 *
 * @coversNothing
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-the-seeded-routing-rules-name-channels-integriq-has-req-ire-005
 */
class SeededRoutingRulesTest extends TestCase {

	/**
	 * The seeded rules name the teams, public space and form channels, one of
	 * them is disabled, and each opens dossiq's `case` schema.
	 *
	 * @return void
	 */
	public function testTheSeededRulesNameChannelsIntegriqHas(): void {
		$known = [
			FormSubmissionAdapter::CHANNEL_ID,
			MessagingChannelAdapter::CHANNEL_ID,
			PublicSpaceReportAdapter::CHANNEL_ID,
			TeamsChannelAdapter::CHANNEL_ID,
		];
		$rules = $this->seededRules();

		$this->assertSame(['teams', 'public-space-report', 'form-submission'], array_column($rules, 'channelId'));
		foreach ($rules as $rule) {
			$this->assertContains($rule['channelId'], $known);
			$this->assertSame('case', $rule['targetSchema']);
			$this->assertStringNotContainsString('Voorbeeld', json_encode($rule));
		}

		$this->assertSame([true, true, false], array_column($rules, 'isEnabled'));

	}//end testTheSeededRulesNameChannelsIntegriqHas()

	/**
	 * The `intake_routing_rule` objects of the mock register.
	 *
	 * @return array<int,array<string,mixed>> The rules.
	 */
	private function seededRules(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/integriq_mock_register.json'),
			true
		);

		return array_values(
			array_filter(
				$register['components']['objects'],
				static fn (array $object): bool => (($object['@self']['schema'] ?? '') === 'intake_routing_rule')
			)
		);

	}//end seededRules()

}//end class
