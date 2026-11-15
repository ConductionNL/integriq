<?php

/**
 * OptOutMatchingGoldenTest.
 *
 * Pins which recorded opt-out or consent decides a send, over every scope,
 * purpose, lawful basis, channel, category and recipient reference.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-marketing-needs-recorded-consent-req-ooa-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Tests\Helpers\OptOutFixture;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The decision matrix, recorded before the matcher was reshaped.
 */
class OptOutMatchingGoldenTest extends TestCase {

	/**
	 * How many decisions of each code the matrix holds.
	 *
	 * @var array<string,int>
	 */
	private const GOLDEN_CODES = [
		'allowed' => 461,
		'exempt-override' => 46,
		'invalid-address' => 750,
		'no-consent' => 185,
		'opted-out' => 118,
	];

	/**
	 * The sha1 of the whole matrix, decision by decision.
	 *
	 * @var string
	 */
	private const GOLDEN_SHA1 = '6386a78954317449325e8e1f95284cc9f2feab0b';

	/**
	 * The fixture.
	 *
	 * @var OptOutFixture
	 */
	private OptOutFixture $fx;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fx = new OptOutFixture($this, $this->createMock(IDBConnection::class));

	}//end setUp()

	/**
	 * GOLDEN: every recorded wish against every send shape. Allowed, opted-out,
	 * no-consent and exempt-override outcomes are all pinned, so a change in
	 * which row stops or permits a send shows up here.
	 *
	 * @return void
	 */
	public function testGoldenDecisionMatrix(): void {
		$registry = $this->fx->registry();
		$wishes = [
			['address' => 'a@x.nl', 'state' => 'opted-out', 'scope' => 'instance'],
			['address' => 'b@x.nl', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'email', 'purpose' => 'marketing'],
			['address' => 'c@x.nl', 'state' => 'opted-out', 'scope' => 'case', 'ref' => 'Z-1'],
			['address' => 'd@x.nl', 'state' => 'opted-out', 'scope' => 'list', 'ref' => 'news', 'purpose' => 'service'],
			['address' => 'e@x.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'purpose' => 'marketing', 'lawfulBasis' => 'consent'],
			['address' => 'f@x.nl', 'state' => 'opted-in', 'scope' => 'list', 'ref' => 'news', 'lawfulBasis' => 'consent'],
			['address' => 'g@x.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'imported'],
			['address' => 'h@x.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'soft-opt-in', 'evidence' => ['objectionOffered' => true]],
			['address' => 'i@x.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'soft-opt-in', 'evidence' => ['note' => 'x']],
			['address' => 'k@x.nl', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'email', 'contactRef' => 'c-1'],
			['address' => 'm@x.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent'],
			['address' => 'm@x.nl', 'state' => 'opted-out', 'scope' => 'case', 'ref' => 'Z-1', 'purpose' => 'marketing'],
			['address' => '+31612345678', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'sms', 'purpose' => 'marketing'],
			['address' => '+31612345678', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'whatsapp', 'lawfulBasis' => 'consent'],
		];
		foreach ($wishes as $wish) {
			$registry->record($wish + ['sourceApp' => 'pipelinq']);
		}

		$matrix = [];
		foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'l', 'm', 'z', 'p'] as $who) {
			foreach (['email', 'sms', 'whatsapp', ''] as $channel) {
				foreach (['marketing', 'service', 'besluit'] as $category) {
					foreach ([false, true] as $consent) {
						foreach ([[], ['caseRef' => 'Z-1'], ['caseRef' => 'Z-2'], ['listRef' => 'news'], ['contactRef' => 'c-1']] as $refs) {
							$address = ($who === 'p') ? '+31612345678' : $who . '@x.nl';
							$decision = $registry->decideMany($channel, $category, $consent, [['address' => $address] + $refs], 'pipelinq', '', '', null, true)[$address];
							$matrix[] = implode('|', [$who, $channel, $category, (int)$consent, json_encode($refs), $decision['code'], (int)$decision['overridden']]);
						}
					}
				}
			}
		}

		$codes = array_count_values(array_map(static fn (string $line): string => explode('|', $line)[5], $matrix));
		ksort($codes);
		$this->assertSame(self::GOLDEN_CODES, $codes);
		$this->assertSame(self::GOLDEN_SHA1, sha1(implode("\n", $matrix)));

	}//end testGoldenDecisionMatrix()
}//end class
