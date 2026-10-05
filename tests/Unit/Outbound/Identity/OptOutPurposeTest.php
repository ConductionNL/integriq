<?php

/**
 * OptOutPurposeTest.
 *
 * An opt-out stops only its own purpose: marketing, service, or everything.
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
 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Db\OptOut;
use OCA\Integriq\Outbound\Identity\OptOutCategories;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Tests\Helpers\OptOutFixture;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Per-purpose matching, keys and links.
 */
class OptOutPurposeTest extends TestCase {

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
	 * A marketing unsubscribe leaves reminders running.
	 *
	 * @return void
	 */
	public function testAMarketingOptOutLeavesRemindersRunning(): void {
		$registry = $this->fx->registry();
		$this->optOut(registry: $registry, scope: 'channel', purpose: 'marketing');

		$this->assertSame('opted-out', $this->code(registry: $registry, category: 'marketing', consent: true));
		$this->assertSame('allowed', $this->code(registry: $registry, category: 'reminder'));
		$this->assertSame('allowed', $this->code(registry: $registry, category: 'case-update'));

	}//end testAMarketingOptOutLeavesRemindersRunning()

	/**
	 * A service opt-out stops case updates and reminders, not marketing.
	 *
	 * @return void
	 */
	public function testAServiceOptOutLeavesConsentedMarketing(): void {
		$registry = $this->fx->registry();
		$this->optOut(registry: $registry, scope: 'channel', purpose: 'service');
		$registry->record(['address' => 'piet@example.org', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'purpose' => 'marketing', 'lawfulBasis' => 'consent', 'sourceApp' => 'pipelinq']);

		$this->assertSame('opted-out', $this->code(registry: $registry, category: 'case-update'));
		$this->assertSame('opted-out', $this->code(registry: $registry, category: 'reminder'));
		$this->assertSame('opted-out', $this->code(registry: $registry, category: 'service'));
		$this->assertSame('allowed', $this->code(registry: $registry, category: 'marketing', consent: true));

	}//end testAServiceOptOutLeavesConsentedMarketing()

	/**
	 * Stop everything stops every non-exempt message and no besluit.
	 *
	 * @return void
	 */
	public function testStopEverythingStillLetsABesluitThrough(): void {
		$registry = $this->fx->registry();
		$this->optOut(registry: $registry, scope: 'instance', purpose: '');

		$this->assertSame('opted-out', $this->code(registry: $registry, category: 'marketing', consent: true));
		$this->assertSame('opted-out', $this->code(registry: $registry, category: 'reminder'));
		$this->assertSame('exempt-override', $this->code(registry: $registry, category: 'besluit'));

	}//end testStopEverythingStillLetsABesluitThrough()

	/**
	 * A row written before purposes stops everything.
	 *
	 * @return void
	 */
	public function testARowWithoutAPurposeStopsEverything(): void {
		$registry = $this->fx->registry();
		$registry->add('piet@example.org');

		$this->assertSame('opted-out', $this->code(registry: $registry, category: 'marketing', consent: true));
		$this->assertSame('opted-out', $this->code(registry: $registry, category: 'reminder'));

	}//end testARowWithoutAPurposeStopsEverything()

	/**
	 * A marketing and an everything opt-out are two rows, and a new marketing
	 * consent changes only the marketing row.
	 *
	 * @return void
	 */
	public function testAMarketingAndAnEverythingOptOutAreTwoRows(): void {
		$registry = $this->fx->registry();
		$this->optOut(registry: $registry, scope: 'channel', purpose: 'marketing');
		$this->optOut(registry: $registry, scope: 'channel', purpose: '');
		$this->assertCount(2, $this->fx->table->rows);

		$registry->record(['address' => 'piet@example.org', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'purpose' => 'marketing', 'lawfulBasis' => 'consent', 'sourceApp' => 'pipelinq']);

		$this->assertCount(2, $this->fx->table->rows);
		$states = [];
		foreach ($this->fx->table->rows as $row) {
			$states[(string)$row->getPurpose()] = (string)$row->getState();
		}

		$this->assertSame(['marketing' => OptOut::STATE_OPTED_IN, '' => OptOut::STATE_OPTED_OUT], $states);
		$this->assertSame('opted-out', $this->code(registry: $registry, category: 'reminder'));

	}//end testAMarketingAndAnEverythingOptOutAreTwoRows()

	/**
	 * A purpose given as a category name is grouped; an unknown one stops everything.
	 *
	 * @return void
	 */
	public function testAPurposeIsNormalised(): void {
		$categories = $this->fx->categories();

		$this->assertSame('service', $categories->normalisePurpose('reminder'));
		$this->assertSame('service', $categories->normalisePurpose('Case-Update'));
		$this->assertSame('marketing', $categories->normalisePurpose('marketing'));
		$this->assertSame('', $categories->normalisePurpose('all'));
		$this->assertSame('', $categories->normalisePurpose('newsletters-maybe'));
		$this->assertNotSame([], $this->fx->warnings);
		$this->assertSame('marketing', $categories->purposeOf('marketing'));
		$this->assertSame('service', $categories->purposeOf('case-update'));
		$this->assertSame('', $categories->purposeOf('besluit'));

		$registry = $this->fx->registry();
		$this->optOut(registry: $registry, scope: 'channel', purpose: 'reminder');
		$this->assertSame(OptOutCategories::PURPOSE_SERVICE, (string)array_values($this->fx->table->rows)[0]->getPurpose());

	}//end testAPurposeIsNormalised()

	/**
	 * The link in a marketing mail carries the marketing purpose; a reminder's carries service.
	 *
	 * @return void
	 */
	public function testALinkCarriesThePurposeOfItsMessage(): void {
		$registry = $this->fx->registry();
		$registry->record(['address' => 'piet@example.org', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'purpose' => 'marketing', 'lawfulBasis' => 'consent', 'sourceApp' => 'pipelinq']);
		$tokens = $this->fx->tokens();

		$marketing = $this->decision(registry: $registry, category: 'marketing', consent: true);
		$reminder = $this->decision(registry: $registry, category: 'reminder');

		$this->assertSame('marketing', $tokens->inspect($this->tokenOf(url: $marketing['unsubscribe']['url']))['purpose']);
		$this->assertSame('service', $tokens->inspect($this->tokenOf(url: $reminder['unsubscribe']['url']))['purpose']);
		$this->assertSame('', $tokens->inspect($tokens->mintScoped('piet@example.org', 'channel', 'email', ''))['purpose']);
		$this->assertSame('', $tokens->inspect($tokens->mint('piet@example.org', 'Z-1'))['purpose']);

	}//end testALinkCarriesThePurposeOfItsMessage()

	/**
	 * A marketing consent does not permit a service send that needs consent.
	 *
	 * @return void
	 */
	public function testAMarketingConsentOpensMarketingOnly(): void {
		$registry = $this->fx->registry();
		$registry->record(['address' => '+31612345678', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'whatsapp', 'purpose' => 'marketing', 'lawfulBasis' => 'consent', 'sourceApp' => 'pipelinq']);

		$service = $registry->decideMany('whatsapp', 'service', true, [['address' => '+31612345678']], 'pipelinq', '')['+31612345678'];
		$marketing = $registry->decideMany('whatsapp', 'marketing', true, [['address' => '+31612345678']], 'pipelinq', '')['+31612345678'];

		$this->assertSame('no-consent', $service['code']);
		$this->assertSame('allowed', $marketing['code']);

	}//end testAMarketingConsentOpensMarketingOnly()

	/**
	 * Record an opt-out for piet@example.org on email.
	 *
	 * @param OptOutRegistry $registry The registry.
	 * @param string $scope The scope.
	 * @param string $purpose The purpose.
	 *
	 * @return void
	 */
	private function optOut(OptOutRegistry $registry, string $scope, string $purpose): void {
		$registry->record(['address' => 'piet@example.org', 'state' => 'opted-out', 'scope' => $scope, 'channel' => 'email', 'purpose' => $purpose, 'sourceApp' => 'pipelinq']);

	}//end optOut()

	/**
	 * The decision code for an email to piet@example.org.
	 *
	 * @param OptOutRegistry $registry The registry.
	 * @param string $category The category.
	 * @param bool $consent Whether the send needs consent.
	 *
	 * @return string The code.
	 */
	private function code(OptOutRegistry $registry, string $category, bool $consent = false): string {
		return $this->decision(registry: $registry, category: $category, consent: $consent)['code'];

	}//end code()

	/**
	 * The decision for an email to piet@example.org.
	 *
	 * @param OptOutRegistry $registry The registry.
	 * @param string $category The category.
	 * @param bool $consent Whether the send needs consent.
	 *
	 * @return array<string,mixed> The decision.
	 */
	private function decision(OptOutRegistry $registry, string $category, bool $consent = false): array {
		return $registry->decideMany('email', $category, $consent, [['address' => 'piet@example.org']], 'pipelinq', '')['piet@example.org'];

	}//end decision()

	/**
	 * The token at the end of an unsubscribe url.
	 *
	 * @param string $url The url.
	 *
	 * @return string The token.
	 */
	private function tokenOf(string $url): string {
		return substr($url, (strrpos($url, '/') + 1));

	}//end tokenOf()

}//end class
