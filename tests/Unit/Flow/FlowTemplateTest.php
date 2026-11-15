<?php

/**
 * Unit tests for FlowTemplate.
 *
 * Covers the item-to-request substitution contract: deterministic
 * missing-path behaviour (empty, NEVER the literal `{{...}}`), type
 * preservation for a whole-placeholder value, the dotted write path used by
 * `config.output`, and the small documented selector grammar used by
 * `config.responseMapping`.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Flow;

use OCA\Integriq\Flow\FlowTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the flow item templater.
 */
class FlowTemplateTest extends TestCase {

	/**
	 * A dotted placeholder resolves from the item's record.
	 *
	 * @return void
	 */
	public function testRendersDottedPathFromItem(): void {
		$json = ['issue' => ['number' => 42]];

		$this->assertSame(
			'/issues/42/labels',
			FlowTemplate::renderString(template: '/issues/{{issue.number}}/labels', json: $json)
		);

	}//end testRendersDottedPathFromItem()

	/**
	 * A missing path renders empty and never leaves the literal placeholder.
	 *
	 * @return void
	 */
	public function testMissingPathRendersEmptyNeverLiteral(): void {
		$rendered = FlowTemplate::renderString(template: '/issues/{{issue.missing}}/labels', json: []);

		$this->assertSame('/issues//labels', $rendered);
		$this->assertStringNotContainsString('{{', $rendered);

	}//end testMissingPathRendersEmptyNeverLiteral()

	/**
	 * A whole-placeholder value keeps the resolved type.
	 *
	 * @return void
	 */
	public function testWholePlaceholderPreservesType(): void {
		$json = ['issue' => ['number' => 42, 'labels' => ['a', 'b']]];

		$this->assertSame(42, FlowTemplate::renderValue(value: '{{issue.number}}', json: $json));
		$this->assertSame(['a', 'b'], FlowTemplate::renderValue(value: '{{issue.labels}}', json: $json));

	}//end testWholePlaceholderPreservesType()

	/**
	 * A nested body renders every string leaf, leaving non-strings alone.
	 *
	 * @return void
	 */
	public function testRendersNestedBody(): void {
		$json = ['triage' => ['proposedLabel' => 'needs-triage']];

		$this->assertSame(
			['labels' => ['needs-triage'], 'notify' => true],
			FlowTemplate::renderValue(
				value: ['labels' => ['{{triage.proposedLabel}}'], 'notify' => true],
				json: $json
			)
		);

	}//end testRendersNestedBody()

	/**
	 * A dotted write creates missing levels.
	 *
	 * @return void
	 */
	public function testWriteCreatesMissingLevels(): void {
		$json = FlowTemplate::write(json: ['a' => 1], path: 'labelResult.applied', value: ['bug']);

		$this->assertSame(['a' => 1, 'labelResult' => ['applied' => ['bug']]], $json);

	}//end testWriteCreatesMissingLevels()

	/**
	 * The selector grammar reads dotted paths, `$.` prefixes and `[*]` maps.
	 *
	 * @return void
	 */
	public function testSelectorGrammar(): void {
		$payload = [
			'status' => 'ok',
			'labels' => [
				['name' => 'bug'],
				['name' => 'triage'],
			],
		];

		$this->assertSame('ok', FlowTemplate::select(payload: $payload, selector: '$.status'));
		$this->assertSame('ok', FlowTemplate::select(payload: $payload, selector: 'status'));
		$this->assertSame(['bug', 'triage'], FlowTemplate::select(payload: $payload, selector: '$.labels[*].name'));
		$this->assertSame('bug', FlowTemplate::select(payload: $payload, selector: '$.labels[0].name'));
		$this->assertNull(FlowTemplate::select(payload: $payload, selector: '$.nope.deeper'));

	}//end testSelectorGrammar()

	/**
	 * Placeholder detection drives the "check the rendered value too" rule.
	 *
	 * @return void
	 */
	public function testHasPlaceholder(): void {
		$this->assertTrue(FlowTemplate::hasPlaceholder(value: '/issues/{{issue.ref}}'));
		$this->assertFalse(FlowTemplate::hasPlaceholder(value: '/issues/42'));

	}//end testHasPlaceholder()

	/**
	 * `{{ @item }}` is the whole record: typed as a whole placeholder, JSON inside text.
	 *
	 * @return void
	 */
	public function testWholeItemPlaceholderResolvesToTheRecord(): void {
		$item = ['id' => 'c-1', 'title' => 'Bouwvergunning', 'status' => ['code' => 'open']];

		$this->assertSame(
			['case' => $item, 'kind' => 'c-1'],
			FlowTemplate::renderValue(value: ['case' => '{{ @item }}', 'kind' => '{{ id }}'], json: $item)
		);
		$this->assertSame('x' . json_encode($item), FlowTemplate::renderString(template: 'x{{@item}}', json: $item));

		// Only the exact path is reserved; a dotted path under it still misses.
		$this->assertNull(FlowTemplate::lookup(path: '@item.id', json: $item));

	}//end testWholeItemPlaceholderResolvesToTheRecord()

	/**
	 * A per-item reference reads the item's field with or without the `json.` prefix.
	 *
	 * @return void
	 */
	public function testRenderReferenceAcceptsBothItemPathForms(): void {
		$item = ['channel' => 'linkedin'];

		$this->assertSame('jobboard-linkedin', FlowTemplate::renderReference(template: 'jobboard-{{ json.channel }}', json: $item));
		$this->assertSame('jobboard-linkedin', FlowTemplate::renderReference(template: 'jobboard-{{channel}}', json: $item));
		$this->assertSame('jobboard-', FlowTemplate::renderReference(template: ' jobboard-{{ json.missing }} ', json: $item));

	}//end testRenderReferenceAcceptsBothItemPathForms()

	/**
	 * A record with its own `json` field keeps it: the record's key wins over the alias.
	 *
	 * @return void
	 */
	public function testRenderReferenceKeepsARecordsOwnJsonField(): void {
		$item = ['channel' => 'werk-nl', 'json' => ['channel' => 'indeed']];

		$this->assertSame('jobboard-indeed', FlowTemplate::renderReference(template: 'jobboard-{{ json.channel }}', json: $item));

	}//end testRenderReferenceKeepsARecordsOwnJsonField()

}//end class
