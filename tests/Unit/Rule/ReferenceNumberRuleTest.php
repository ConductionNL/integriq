<?php

/**
 * Unit tests for ReferenceNumberRule.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Rule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Rule;

use OCA\OpenConnector\Rule\ReferenceNumberRule;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the dialect-agnostic referentienummer generation rule.
 *
 * @spec openspec/changes/vng-klantinteracties-adapter/specs/rule-pipeline/spec.md#req-rule-007
 */
class ReferentienummerRuleTest extends TestCase {

	/**
	 * Build a rule ObjectEntity carrying the given referentienummer configuration.
	 *
	 * @param array $configuration The `configuration.referentienummer` payload.
	 *
	 * @return ObjectEntity
	 */
	private function makeRule(array $configuration = []): ObjectEntity {
		$rule = new ObjectEntity();
		$rule->setObject(['name' => 'vng-referentienummer', 'configuration' => ['referentienummer' => $configuration]]);
		return $rule;
	}//end makeRule()

	/**
	 * The default (no scheme configured) reference is a UUIDv4.
	 *
	 * @return void
	 */
	public function testDefaultReferenceIsUuidV4(): void {
		$subject = new ReferenceNumberRule();
		$result = $subject->apply(rule: $this->makeRule(), data: ['body' => []]);

		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$result['body']['referentienummer']
		);
	}//end testDefaultReferenceIsUuidV4()

	/**
	 * Two invocations produce two different references.
	 *
	 * @return void
	 */
	public function testReferenceIsUnique(): void {
		$subject = new ReferenceNumberRule();
		$first = $subject->apply(rule: $this->makeRule(), data: ['body' => []]);
		$second = $subject->apply(rule: $this->makeRule(), data: ['body' => []]);

		$this->assertNotSame($first['body']['referentienummer'], $second['body']['referentienummer']);
	}//end testReferenceIsUnique()

	/**
	 * A configured scheme overrides the default UUIDv4-only shape.
	 *
	 * @return void
	 */
	public function testConfiguredSchemeOverridesDefault(): void {
		$subject = new ReferenceNumberRule();
		$result = $subject->apply(
			rule: $this->makeRule(['scheme' => 'GEM-{year}-{uuid}']),
			data: ['body' => []]
		);

		$this->assertMatchesRegularExpression('/^GEM-\d{4}-[0-9a-f-]{36}$/', $result['body']['referentienummer']);
	}//end testConfiguredSchemeOverridesDefault()

	/**
	 * A configured targetField stamps the reference under a different key.
	 *
	 * @return void
	 */
	public function testConfiguredTargetFieldIsUsed(): void {
		$subject = new ReferenceNumberRule();
		$result = $subject->apply(
			rule: $this->makeRule(['targetField' => 'messageRef']),
			data: ['body' => []]
		);

		$this->assertArrayHasKey('messageRef', $result['body']);
		$this->assertArrayNotHasKey('referentienummer', $result['body']);
	}//end testConfiguredTargetFieldIsUsed()

}//end class
