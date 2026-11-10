<?php

/**
 * Golden test pinning RecipientResolver's refusals and resolved record.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Recipients
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Recipients;

use DateTimeImmutable;
use OCA\Integriq\Service\Recipients\RecipientRefusedException;
use OCA\Integriq\Service\Recipients\RecipientResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pins every refusal sentence in order, and the whole resolved record with a fixed moment.
 */
class RecipientResolverGoldenTest extends TestCase {

	/**
	 * Every refusal, in order, for a request that breaks every rule once.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$standing = [
			['address' => ' a@x.nl '],
			['address' => 'b@x.nl', 'requiredBecause' => ' wettelijk verplicht '],
			['address' => ''],
			['address' => 'c@x.nl', 'requiredBecause' => 'eigenaar'],
		];
		$suppressions = [
			['address' => 'nope@x.nl', 'reason' => 'r'],
			['address' => ' ', 'reason' => 'r'],
			['address' => 'a@x.nl', 'reason' => ' '],
			['address' => 'b@x.nl', 'reason' => 'r'],
			['address' => 'c@x.nl'],
			['address' => 'a@x.nl', 'reason' => 'fine'],
		];

		$expected = [
			'"nope@x.nl" is not on this message\'s recipient list, so suppressing it would record a decision that changed nothing.',
			'"(no address)" is not on this message\'s recipient list, so suppressing it would record a decision that changed nothing.',
			'Keeping "a@x.nl" off this message needs a reason. A year from now the record has to say why they did not receive it, and an empty reason reads the same as a mis-click.',
			'"b@x.nl" cannot be kept off this message: wettelijk verplicht',
			'Keeping "c@x.nl" off this message needs a reason. A year from now the record has to say why they did not receive it, and an empty reason reads the same as a mis-click.',
			'"c@x.nl" cannot be kept off this message: eigenaar',
		];
		$this->assertSame($expected, (new RecipientResolver())->refusals(standing: $standing, suppressions: $suppressions));

		try {
			(new RecipientResolver())->resolve(standing: $standing, suppressions: $suppressions);
			$this->fail('no refusal');
		} catch (RecipientRefusedException $exception) {
			$this->assertSame(implode(' ', $expected), $exception->getMessage());
			$this->assertSame($expected, $exception->getRefusals());
		}

	}//end testRefusals()

	/**
	 * The whole record of an accepted request, with standing, suppressed and added parts.
	 *
	 * @return void
	 */
	public function testResolvedRecord(): void {
		$now = new DateTimeImmutable('2026-03-01T09:00:00+01:00');
		$decision = (new RecipientResolver())->resolve(
			standing: [['address' => 'a@x.nl', 'name' => 'A'], ['address' => ' b@x.nl ', 'name' => 'B'], ['name' => 'no address']],
			additions: [['address' => 'z@x.nl'], ['address' => 'y@x.nl', 'user' => 'other']],
			suppressions: [['address' => 'b@x.nl', 'reason' => ' op verzoek ', 'user' => 'mgr']],
			user: 'handler',
			now: $now
		);
		$this->assertSame(
			json_encode((new RecipientResolver())->preview(
				standing: [['address' => 'a@x.nl', 'name' => 'A'], ['address' => ' b@x.nl ', 'name' => 'B'], ['name' => 'no address']],
				additions: [['address' => 'z@x.nl'], ['address' => 'y@x.nl', 'user' => 'other']],
				suppressions: [['address' => 'b@x.nl', 'reason' => ' op verzoek ', 'user' => 'mgr']],
				user: 'handler',
				now: $now
			)),
			json_encode($decision->asRecord())
		);
		$this->assertSame(
			'{"standing":[{"address":"a@x.nl","name":"A","part":"standing"},{"name":"no address","part":"standing"}],'
			. '"added":[{"address":"z@x.nl","part":"added","addedBy":"handler","addedAt":"2026-03-01T09:00:00+01:00"},'
			. '{"address":"y@x.nl","user":"other","part":"added","addedBy":"other","addedAt":"2026-03-01T09:00:00+01:00"}],'
			. '"suppressed":[{"address":" b@x.nl ","name":"B","part":"suppressed","reason":"op verzoek","suppressedBy":"mgr","suppressedAt":"2026-03-01T09:00:00+01:00","deliveryState":null}],'
			. '"resolved":["a@x.nl","z@x.nl","y@x.nl"],"standingCount":2,"addedCount":2,"suppressedCount":1}',
			json_encode($decision->asRecord(), JSON_UNESCAPED_SLASHES)
		);

	}//end testResolvedRecord()
}//end class
