<?php

/**
 * Tests for ApiDesignFindingStore.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ApiDesign
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ApiDesign;

use InvalidArgumentException;
use OCA\Integriq\Service\ApiDesign\ApiDesignFindingStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Replacing open findings, keeping waivers, waiving, and the stored rule sets.
 */
#[CoversClass(ApiDesignFindingStore::class)]
class ApiDesignFindingStoreTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var InMemoryDesignObjectService
	 */
	private InMemoryDesignObjectService $objects;

	/**
	 * The store under test.
	 *
	 * @var ApiDesignFindingStore
	 */
	private ApiDesignFindingStore $store;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryDesignObjectService();
		$this->store = new ApiDesignFindingStore(objectService: $this->objects);

	}//end setUp()

	/**
	 * A finding as the rule service returns it.
	 *
	 * @param string $ruleId   The rule.
	 * @param string $path     The path.
	 * @param string $severity The severity.
	 *
	 * @return array<string, mixed>
	 */
	private static function finding(string $ruleId, string $path, string $severity = 'error'): array {
		return [
			'ruleset' => 'nl',
			'ruleId' => $ruleId,
			'severity' => $severity,
			'path' => $path,
			'message' => 'failed',
			'url' => 'https://example.nl/rule',
			'status' => 'open',
		];

	}//end finding()

	/**
	 * Earlier open findings go; a waived one that comes back is kept and not stored twice; a gone waiver goes.
	 *
	 * @return void
	 */
	public function testReplaceKeepsMatchingWaiversAndDropsTheRest(): void {
		$base = ['product' => 'p1', 'productVersion' => '1.0.0', 'ruleset' => 'nl', 'severity' => 'error'];
		$this->objects->put('api_design_finding', 'old-open', $base + ['ruleId' => '/core/a', 'path' => '/x', 'status' => 'open']);
		$this->objects->put('api_design_finding', 'kept', $base + ['ruleId' => '/core/b', 'path' => '/y', 'status' => 'waived', 'waiveReason' => 'partner']);
		$this->objects->put('api_design_finding', 'gone', $base + ['ruleId' => '/core/c', 'path' => '/z', 'status' => 'waived']);
		$this->objects->put('api_design_finding', 'other-version', ['productVersion' => '2.0.0'] + $base + ['ruleId' => '/core/a', 'path' => '/x', 'status' => 'open']);

		$result = $this->store->replace(
			productId: 'p1',
			version: '1.0.0',
			findings: [self::finding('/core/b', '/y'), self::finding('/core/a', '/x', 'warning')],
			checkedAt: '2026-11-14T12:00:00+00:00'
		);

		self::assertSame(['old-open', 'gone'], $this->objects->deleted);
		self::assertCount(2, $result);
		self::assertSame(['kept', 'waived', 'partner'], [$result[0]['id'], $result[0]['status'], $result[0]['waiveReason']]);
		self::assertSame('/core/a', $result[1]['ruleId']);
		self::assertSame('open', $result[1]['status']);
		self::assertSame('warning', $result[1]['severity']);
		self::assertSame('2026-11-14T12:00:00+00:00', $result[1]['checkedAt']);
		self::assertSame('p1', $this->objects->objects['api_design_finding'][$result[1]['id']]['product']);
		self::assertArrayHasKey('other-version', $this->objects->objects['api_design_finding']);

	}//end testReplaceKeepsMatchingWaiversAndDropsTheRest()

	/**
	 * Waiving records who, why and when.
	 *
	 * @return void
	 */
	public function testWaiveRecordsTheReason(): void {
		$this->objects->put('api_design_finding', 'f1', ['product' => 'p1', 'ruleId' => '/core/a', 'severity' => 'error', 'status' => 'open']);

		$waived = $this->store->waive(findingId: 'f1', reason: ' partner requires this path until 2027 ', userId: 'admin', waivedAt: '2026-11-14T12:00:00+00:00');

		self::assertSame('waived', $waived['status']);
		self::assertSame('partner requires this path until 2027', $waived['waiveReason']);
		self::assertSame('admin', $waived['waivedBy']);
		self::assertSame('waived', $this->objects->objects['api_design_finding']['f1']['status']);
		self::assertSame('2026-11-14T12:00:00+00:00', $this->objects->objects['api_design_finding']['f1']['waivedAt']);

	}//end testWaiveRecordsTheReason()

	/**
	 * A waiver needs a reason.
	 *
	 * @return void
	 */
	public function testWaiveWithoutAReasonIsRefused(): void {
		$this->objects->put('api_design_finding', 'f1', ['status' => 'open']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A waiver needs a reason.');
		$this->store->waive(findingId: 'f1', reason: '  ', userId: 'admin', waivedAt: 'now');

	}//end testWaiveWithoutAReasonIsRefused()

	/**
	 * Only an open finding can be waived, and an unknown one not at all.
	 *
	 * @return void
	 */
	public function testOnlyAnOpenFindingCanBeWaived(): void {
		$this->objects->put('api_design_finding', 'f1', ['status' => 'waived']);

		try {
			$this->store->waive(findingId: 'f1', reason: 'r', userId: 'admin', waivedAt: 'now');
			self::fail('A waived finding was waived again.');
		} catch (InvalidArgumentException $e) {
			self::assertSame('Only an open finding can be waived.', $e->getMessage());
		}

		$this->expectExceptionMessage('There is no such finding.');
		$this->store->waive(findingId: 'nope', reason: 'r', userId: 'admin', waivedAt: 'now');

	}//end testOnlyAnOpenFindingCanBeWaived()

	/**
	 * Stored rule sets are used when enabled and when they carry rules.
	 *
	 * @return void
	 */
	public function testStoredRuleSetsAreTheEnabledOnes(): void {
		$this->objects->put('api_design_ruleset', 'a', ['name' => 'on', 'rules' => [], 'enabled' => true]);
		$this->objects->put('api_design_ruleset', 'b', ['name' => 'off', 'rules' => [], 'enabled' => false]);
		$this->objects->put('api_design_ruleset', 'c', ['name' => 'broken', 'rules' => 'x']);
		$this->objects->put('api_design_ruleset', 'd', ['name' => 'default-on', 'rules' => [['id' => 'r']]]);

		$names = array_column($this->store->storedRuleSets(), 'name');

		self::assertSame(['on', 'default-on'], $names);

	}//end testStoredRuleSetsAreTheEnabledOnes()
}//end class
