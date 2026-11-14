<?php

/**
 * Next-link paging: Graph's `@odata.nextLink` under `paginationMode: nextLink`, and the `next` key as before.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-each-dossier-folder-becomes-a-draft-publication-with-its-documents-req-sppi-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SynchronizationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * What the engine fetches after one page, per paging style.
 *
 * @covers \OCA\Integriq\Service\SynchronizationService
 */
class SynchronizationNextLinkPagingTest extends TestCase {

	private const GRAPH = 'https://graph.microsoft.com/v1.0';

	/**
	 * The next page the engine would fetch after `$result`.
	 *
	 * @param array<string, mixed> $result       The decoded page.
	 * @param array<string, mixed> $sourceConfig The synchronization's sourceConfig.
	 * @param string               $endpoint     The page's endpoint.
	 * @param bool|null            $known        Whether next-link paging is already known for this run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function next(array $result, array $sourceConfig, string $endpoint='/drives/d/items/f/children', ?bool $known=null): ?array {
		$engine = (new ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();

		return (new ReflectionMethod(SynchronizationService::class, 'getNextPageInfo'))->invoke(
			$engine,
			['location' => self::GRAPH],
			$endpoint,
			['query' => ['$expand' => 'listItem($expand=fields)']],
			['sourceConfig' => $sourceConfig],
			1,
			$result,
			$known
		);
	}//end next()

	/**
	 * A Graph page with a next link is followed, relative to the source and keeping the query the link leaves out.
	 *
	 * @return void
	 */
	public function testAGraphNextLinkIsFollowed(): void {
		$next = $this->next(
			result: ['@odata.context' => 'x', '@odata.nextLink' => self::GRAPH . '/drives/d/items/f/children?$skiptoken=abc', 'value' => [['id' => '1']]],
			sourceConfig: ['paginationMode' => 'nextLink'],
			endpoint: '/drives/d/items/f/children?$top=200'
		);

		$this->assertSame('/drives/d/items/f/children?%24skiptoken=abc&%24top=200', $next['endpoint']);
		$this->assertSame(2, $next['page']);
		$this->assertTrue($next['usesNextEndpoint']);
	}//end testAGraphNextLinkIsFollowed()

	/**
	 * A Graph page without a next link is the last page: no page-number refetch of the same folder.
	 *
	 * @return void
	 */
	public function testAGraphPageWithoutANextLinkEndsTheRun(): void {
		$this->assertNull($this->next(result: ['@odata.context' => 'x', 'value' => [['id' => '1']]], sourceConfig: ['paginationMode' => 'nextLink']));
		$this->assertNull($this->next(result: ['value' => [['id' => '1']]], sourceConfig: [], known: true));
	}//end testAGraphPageWithoutANextLinkEndsTheRun()

	/**
	 * Without the mode an OData answer pages by number as before, so an offset (`$skip`) source is unchanged.
	 *
	 * @return void
	 */
	public function testWithoutTheModeAnODataAnswerStillPagesByNumber(): void {
		$next = $this->next(result: ['@odata.context' => 'x', '@odata.nextLink' => self::GRAPH . '/x?$skiptoken=1', 'value' => [['id' => '1']]], sourceConfig: []);

		$this->assertSame('/drives/d/items/f/children', $next['endpoint']);
		$this->assertFalse($next['usesNextEndpoint']);
		$this->assertSame(2, $next['config']['pagination']['page']);
	}//end testWithoutTheModeAnODataAnswerStillPagesByNumber()

	/**
	 * An API answering `next` is followed as before.
	 *
	 * @return void
	 */
	public function testANextKeyIsFollowedAsBefore(): void {
		$next = $this->next(result: ['next' => self::GRAPH . '/items?page=2', 'results' => []], sourceConfig: [], endpoint: '/items');

		$this->assertSame('/items?page=2', $next['endpoint']);
		$this->assertTrue($next['usesNextEndpoint']);
	}//end testANextKeyIsFollowedAsBefore()
}//end class
