<?php

/**
 * The AsyncAPI document of an API product, validated against the official
 * AsyncAPI 3.0.0 JSON schema.
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
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Whether the built document is a valid AsyncAPI 3.0.0 document.
 */
#[CoversClass(AsyncApiDocumentService::class)]
class AsyncApiDocumentSchemaTest extends TestCase {

	private const BASE = 'https://gemeente.example:8443/index.php/apps/integriq/api/products/woo-publications/';

	private const SCHEMA = __DIR__ . '/../../fixtures/asyncapi/asyncapi-3.0.0.schema.json';

	/**
	 * The document for a product.
	 *
	 * @param array<string,mixed> $data The product data.
	 *
	 * @return array<string,mixed> The AsyncAPI document.
	 */
	private static function document(array $data): array {
		$entity = new ObjectEntity();
		$entity->setUuid('p-1');
		$entity->setObject($data);

		return (new AsyncApiDocumentService())->build(product: $entity, baseUrl: self::BASE);
	}//end document()

	/**
	 * The validation errors of a document, as "path: message" lines; empty when it is valid.
	 *
	 * @param array<string,mixed> $document The document.
	 *
	 * @return list<string>
	 */
	private static function errorsOf(array $document): array {
		$validator = new Validator();
		$validator->setMaxErrors(5);
		$schema = json_decode((string)file_get_contents(self::SCHEMA));
		$data = json_decode((string)json_encode($document, JSON_UNESCAPED_SLASHES));

		$error = $validator->validate($data, $schema)->error();
		if ($error === null) {
			return [];
		}

		return ['/' . implode('/', $error->data()->fullPath()) . ': ' . $error->message()];
	}//end errorsOf()

	/**
	 * A product with two channels, several event types each, is a valid AsyncAPI 3.0.0 document.
	 *
	 * @return void
	 */
	public function testAProductWithChannelsIsAValidAsyncApiDocument(): void {
		$document = self::document(
			[
				'name' => 'WOO Publications API',
				'description' => 'Publish WOO records',
				'version' => '2.0.0',
				'channels' => [
					['slug' => 'publications', 'title' => 'Publications', 'types' => ['nl.woo.publication.created', 'nl.woo.publication.updated'], 'description' => 'A publication changed'],
					['slug' => 'requests', 'types' => ['nl.woo.request.received']],
				],
			]
		);

		$this->assertSame([], self::errorsOf($document));
		$this->assertCount(2, $document['channels']);
	}//end testAProductWithChannelsIsAValidAsyncApiDocument()

	/**
	 * A product without channels, version or description is still a valid document.
	 *
	 * @return void
	 */
	public function testABareProductIsAValidAsyncApiDocument(): void {
		$this->assertSame([], self::errorsOf(self::document(['name' => 'P'])));
	}//end testABareProductIsAValidAsyncApiDocument()

	/**
	 * The schema refuses what AsyncAPI 3.0.0 refuses, so a pass above is not a validator that accepts anything.
	 *
	 * @return void
	 */
	public function testTheSchemaRefusesAnInvalidDocument(): void {
		$document = self::document(['name' => 'P', 'channels' => [['slug' => 'a', 'types' => ['t.one']]]]);

		$withoutInfo = $document;
		unset($withoutInfo['info']);
		$this->assertNotSame([], self::errorsOf($withoutInfo));

		$wrongVersion = $document;
		$wrongVersion['asyncapi'] = '2.6.0';
		$this->assertNotSame([], self::errorsOf($wrongVersion));

		$badAction = $document;
		$badAction['operations']['a.push']['action'] = 'publish';
		$this->assertNotSame([], self::errorsOf($badAction));
	}//end testTheSchemaRefusesAnInvalidDocument()
}//end class
