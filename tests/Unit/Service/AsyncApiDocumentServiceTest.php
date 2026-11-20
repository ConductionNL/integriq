<?php

/**
 * Unit tests for AsyncApiDocumentService — the AsyncAPI 3.0 document of an API
 * product's channels, and which product version it describes.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\AsyncApiDocumentService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the AsyncAPI document.
 */
#[CoversClass(AsyncApiDocumentService::class)]
class AsyncApiDocumentServiceTest extends TestCase {

	private const BASE = 'https://gemeente.example:8443/index.php/apps/integriq/api/products/woo-publications/';

	/**
	 * A product row.
	 *
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity The row.
	 */
	private static function product(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('p-' . ($data['version'] ?? 'x'));
		$entity->setObject($data);

		return $entity;
	}//end product()

	/**
	 * The publications channel becomes a channel with one message per type and a push and pull operation.
	 *
	 * @return void
	 */
	public function testAChannelBecomesAnAsyncApiChannel(): void {
		$document = (new AsyncApiDocumentService())->build(
			product: self::product(
				[
					'name' => 'WOO Publications API',
					'description' => 'Publish WOO records',
					'version' => '2.0.0',
					'channels' => [
						['slug' => 'publications', 'title' => 'Publications', 'types' => ['nl.woo.publication.created', 'nl.woo.publication.updated'], 'description' => 'A publication changed'],
					],
				]
			),
			baseUrl: self::BASE
		);

		$this->assertSame('3.0.0', $document['asyncapi']);
		$this->assertSame(['title' => 'WOO Publications API', 'version' => '2.0.0', 'description' => 'Publish WOO records'], $document['info']);
		$this->assertSame('gemeente.example:8443', $document['servers']['integriq']['host']);
		$this->assertSame('https', $document['servers']['integriq']['protocol']);
		$this->assertSame('/index.php/apps/integriq/api/products/woo-publications', $document['servers']['integriq']['pathname']);
		$this->assertSame(
			[
				'address' => 'channels/publications/events',
				'title' => 'Publications',
				'description' => 'A publication changed',
				'messages' => [
					'nl.woo.publication.created' => ['$ref' => '#/components/messages/nl.woo.publication.created'],
					'nl.woo.publication.updated' => ['$ref' => '#/components/messages/nl.woo.publication.updated'],
				],
			],
			$document['channels']['publications']
		);
		$this->assertSame(['publications.pull', 'publications.push'], array_keys($document['operations']));
		$this->assertSame(['$ref' => '#/channels/publications'], $document['operations']['publications.push']['channel']);
		$this->assertSame(
			[['$ref' => '#/channels/publications/messages/nl.woo.publication.created'], ['$ref' => '#/channels/publications/messages/nl.woo.publication.updated']],
			$document['operations']['publications.pull']['messages']
		);
		$message = $document['components']['messages']['nl.woo.publication.created'];
		$this->assertSame('application/cloudevents+json', $message['contentType']);
		$this->assertSame(['type' => 'string', 'const' => 'nl.woo.publication.created'], $message['payload']['properties']['type']);
		$this->assertSame(['specversion', 'id', 'source', 'type'], $message['payload']['required']);
	}//end testAChannelBecomesAnAsyncApiChannel()

	/**
	 * Channels without a slug or types, and a second channel with a taken slug, are left out.
	 *
	 * @return void
	 */
	public function testMalformedAndDuplicateChannelsAreLeftOut(): void {
		$document = (new AsyncApiDocumentService())->build(
			product: self::product(
				[
					'name' => 'P',
					'channels' => [
						['slug' => 'a', 'types' => ['t.one', 't.one', '']],
						['slug' => 'a', 'title' => 'Second a', 'types' => ['t.two']],
						['slug' => '', 'types' => ['t.three']],
						['slug' => 'b', 'types' => []],
						'not a channel',
					],
				]
			),
			baseUrl: self::BASE
		);

		$this->assertSame(['a'], array_keys($document['channels']));
		$this->assertSame('a', $document['channels']['a']['title']);
		$this->assertSame(['t.one'], array_keys($document['components']['messages']));
		$this->assertArrayNotHasKey('description', $document['info']);
	}//end testMalformedAndDuplicateChannelsAreLeftOut()

	/**
	 * A product without channels still answers a valid document, with empty objects rather than lists.
	 *
	 * @return void
	 */
	public function testAProductWithoutChannelsHasEmptyObjects(): void {
		$document = (new AsyncApiDocumentService())->build(product: self::product(['name' => 'P', 'version' => '1.0.0']), baseUrl: self::BASE);

		$this->assertSame('{}', json_encode($document['channels']));
		$this->assertSame('{}', json_encode($document['operations']));
		$this->assertSame('{"messages":{}}', json_encode($document['components']));
	}//end testAProductWithoutChannelsHasEmptyObjects()

	/**
	 * The described version is the highest active one, else the highest.
	 *
	 * @return void
	 */
	public function testTheLatestActiveVersionIsDescribed(): void {
		$service = new AsyncApiDocumentService();
		$v1 = self::product(['version' => '1.0.0', 'status' => 'deprecated']);
		$v2 = self::product(['version' => '2.0.0', 'status' => 'active']);
		$v10 = self::product(['version' => '10.0.0', 'status' => 'deprecated']);
		$v19 = self::product(['version' => '1.9.0', 'status' => 'active']);

		$this->assertSame($v2, $service->latestVersion([$v1, $v10, $v2, $v19]));
		$this->assertSame($v10, $service->latestVersion([$v1, $v10]));
		$this->assertNull($service->latestVersion([]));
	}//end testTheLatestActiveVersionIsDescribed()
}//end class
