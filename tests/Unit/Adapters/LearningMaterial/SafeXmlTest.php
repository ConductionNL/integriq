<?php

/**
 * Unit tests for the XML reader every Edurep response goes through.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use DOMXPath;
use OCA\Integriq\Adapters\LearningMaterial\SafeXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only a well-formed document whose root is in the expected namespace is
 * read, without touching the caller's libxml error handling, and entities
 * are not expanded.
 */
#[CoversClass(SafeXml::class)]
class SafeXmlTest extends TestCase {
	private const SRU = 'http://docs.oasis-open.org/ns/search-ws/sruResponse';

	/**
	 * @return array<string, array{string}>
	 */
	public static function unreadable(): array {
		return [
			'empty' => [''],
			'not xml' => ['Service Unavailable'],
			'not well-formed' => ['<sru:searchRetrieveResponse xmlns:sru="'.self::SRU.'"><sru:records>'],
			'other namespace' => ['<searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/"/>'],
			'no namespace' => ['<searchRetrieveResponse/>'],
		];
	}//end unreadable()

	#[DataProvider('unreadable')]
	public function testOnlyAWellFormedRootInTheNamespaceIsRead(string $xml): void {
		$this->assertNull((new SafeXml())->root(xml: $xml, namespace: self::SRU));
	}//end testOnlyAWellFormedRootInTheNamespaceIsRead()

	public function testTheRootIsReturnedForADocumentInTheNamespace(): void {
		$root = (new SafeXml())->root(
			xml: '<sru:searchRetrieveResponse xmlns:sru="'.self::SRU.'"><sru:numberOfRecords>3</sru:numberOfRecords></sru:searchRetrieveResponse>',
			namespace: self::SRU
		);

		$this->assertNotNull($root);
		$this->assertSame('searchRetrieveResponse', $root->localName);
		$this->assertSame('3', $root->textContent);
	}//end testTheRootIsReturnedForADocumentInTheNamespace()

	public function testTheCallersLibxmlErrorHandlingIsLeftAsItWas(): void {
		$safe = new SafeXml();

		foreach ([false, true] as $before) {
			libxml_use_internal_errors($before);
			$safe->root(xml: '<broken', namespace: self::SRU);

			$this->assertSame($before, libxml_use_internal_errors($before));
			$this->assertSame([], libxml_get_errors());
		}

		libxml_use_internal_errors(false);
	}//end testTheCallersLibxmlErrorHandlingIsLeftAsItWas()

	public function testEntitiesAreNotExpanded(): void {
		$xml  = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY name "expanded">]>'
			.'<r xmlns="'.self::SRU.'">&name;</r>';
		$root = (new SafeXml())->root(xml: $xml, namespace: self::SRU);

		$this->assertNotNull($root);
		$this->assertStringNotContainsString('expanded', $root->ownerDocument->saveXML($root));
	}//end testEntitiesAreNotExpanded()

	public function testNodesAreAListInDocumentOrderWithinTheContext(): void {
		$root  = (new SafeXml())->root(
			xml: '<r xmlns="'.self::SRU.'"><a>1</a><b><a>2</a><a>3</a></b></r>',
			namespace: self::SRU
		);
		$xpath = new DOMXPath($root->ownerDocument);
		$xpath->registerNamespace('s', self::SRU);
		$safe  = new SafeXml();

		$all = $safe->nodes(xpath: $xpath, expression: '//s:a');
		$this->assertSame([0, 1, 2], array_keys($all));
		$this->assertSame(['1', '2', '3'], array_map(static fn ($node): string => $node->textContent, $all));

		$inner = $safe->nodes(xpath: $xpath, expression: 's:a', context: $safe->nodes(xpath: $xpath, expression: '//s:b')[0]);
		$this->assertSame(['2', '3'], array_map(static fn ($node): string => $node->textContent, $inner));

		$this->assertSame([], $safe->nodes(xpath: $xpath, expression: '//s:missing'));
	}//end testNodesAreAListInDocumentOrderWithinTheContext()
}//end class
