<?php

/**
 * InformatieobjecttypeEndpointHandler unit tests: own URLs, paging, filters, writes, concepts.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Catalogi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Catalogi;

use OCA\Integriq\Service\Catalogi\CatalogiBinding;
use OCA\Integriq\Service\Catalogi\CatalogiGate;
use OCA\Integriq\Service\Catalogi\CatalogiTokenService;
use OCA\Integriq\Service\Catalogi\InformatieobjecttypeEndpointHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The informatieobjecttypen routes.
 */
#[CoversClass(InformatieobjecttypeEndpointHandler::class)]
#[UsesClass(CatalogiBinding::class)]
#[UsesClass(CatalogiGate::class)]
#[UsesClass(CatalogiTokenService::class)]
class InformatieobjecttypeEndpointHandlerTest extends TestCase {

	use CatalogiFixture;

	/**
	 * How often the stored types were read.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * A handler over the given stored types.
	 *
	 * @param array<int, array<string, mixed>> $stored  The stored types.
	 * @param CatalogiBinding|null             $binding The binding, the fixture's by default.
	 *
	 * @return InformatieobjecttypeEndpointHandler
	 */
	private function handler(array $stored, ?CatalogiBinding $binding = null, bool $bound = true): InformatieobjecttypeEndpointHandler {
		return new InformatieobjecttypeEndpointHandler(
			gate: $this->gate(binding: ($bound === true ? ($binding ?? self::binding()) : null)),
			typesReader: function (CatalogiBinding $binding) use ($stored): array {
				++$this->reads;
				self::assertSame('documentType', $binding->schema);
				return $stored;
			}
		);
	}//end handler()

	/**
	 * The spec's "Besluit": served with its own URL and a resolvable catalogus.
	 *
	 * @return void
	 */
	public function testATypeIsServedWithItsOwnUrl(): void {
		$handler = $this->handler(stored: [['id' => 'type-1', 'name' => 'Besluit', 'confidentiality' => 'openbaar', 'category' => 'besluiten', 'active' => true]]);

		$answer = $handler->show(authorization: self::bearer(clientId: 'reader'), uuid: 'type-1', baseUrl: self::BASE_URL);

		self::assertSame(200, $answer['status']);
		self::assertSame(
			[
				'url' => self::BASE_URL . '/informatieobjecttypen/type-1',
				'uuid' => 'type-1',
				'catalogus' => self::BASE_URL . '/catalogussen/cat-1',
				'omschrijving' => 'Besluit',
				'vertrouwelijkheidaanduiding' => 'openbaar',
				'informatieobjectcategorie' => 'besluiten',
				'beginGeldigheid' => null,
				'concept' => false,
				'besluittypen' => [],
			],
			$answer['body']
		);
		self::assertSame(404, $handler->show(authorization: self::bearer(clientId: 'reader'), uuid: 'type-9', baseUrl: self::BASE_URL)['status']);
	}//end testATypeIsServedWithItsOwnUrl()

	/**
	 * 120 types: count 120, 100 on the first page with next, 20 on the second with previous.
	 *
	 * @return void
	 */
	public function testListsArePaged(): void {
		$stored = [];
		for ($i = 1; $i <= 120; $i++) {
			$stored[] = ['id' => sprintf('type-%03d', $i), 'name' => sprintf('Type %03d', $i), 'active' => true];
		}

		$handler = $this->handler(stored: $stored);
		$first = $handler->index(authorization: self::bearer(clientId: 'reader'), baseUrl: self::BASE_URL, query: [])['body'];
		$second = $handler->index(authorization: self::bearer(clientId: 'reader'), baseUrl: self::BASE_URL, query: ['page' => '2'])['body'];

		self::assertSame(120, $first['count']);
		self::assertCount(100, $first['results']);
		self::assertSame(self::BASE_URL . '/informatieobjecttypen?page=2', $first['next']);
		self::assertNull($first['previous']);
		self::assertSame('Type 001', $first['results'][0]['omschrijving']);
		self::assertCount(20, $second['results']);
		self::assertNull($second['next']);
		self::assertSame(self::BASE_URL . '/informatieobjecttypen?page=1', $second['previous']);
		self::assertSame('Type 120', $second['results'][19]['omschrijving']);
	}//end testListsArePaged()

	/**
	 * The catalogus filter: ours lists, another catalogue lists nothing.
	 *
	 * @return void
	 */
	public function testTheCatalogusFilter(): void {
		$handler = $this->handler(stored: [['id' => 'type-1', 'name' => 'Besluit', 'active' => true]]);

		$ours = $handler->index(authorization: self::bearer(clientId: 'reader'), baseUrl: self::BASE_URL, query: ['catalogus' => self::BASE_URL . '/catalogussen/cat-1']);
		$other = $handler->index(authorization: self::bearer(clientId: 'reader'), baseUrl: self::BASE_URL, query: ['catalogus' => self::BASE_URL . '/catalogussen/cat-2']);

		self::assertSame(1, $ours['body']['count']);
		self::assertSame(['count' => 0, 'next' => null, 'previous' => null, 'results' => []], $other['body']);
	}//end testTheCatalogusFilter()

	/**
	 * Any write answers 405.
	 *
	 * @return void
	 */
	public function testWritesAnswer405(): void {
		$answer = $this->handler(stored: [])->write();

		self::assertSame(405, $answer['status']);
		self::assertSame('method-not-allowed', $answer['body']['code']);
		self::assertSame(0, $this->reads);
	}//end testWritesAnswer405()

	/**
	 * Without a binding every route answers catalogue-not-configured and reads nothing.
	 *
	 * @return void
	 */
	public function testAnUnboundFacadeAnswersNotConfigured(): void {
		$handler = $this->handler(stored: [['id' => 'type-1']], bound: false);

		foreach ([
			$handler->index(authorization: self::bearer(clientId: 'reader'), baseUrl: self::BASE_URL, query: []),
			$handler->show(authorization: self::bearer(clientId: 'reader'), uuid: 'type-1', baseUrl: self::BASE_URL),
		] as $answer) {
			self::assertSame(404, $answer['status']);
			self::assertSame('catalogue-not-configured', $answer['body']['code']);
			self::assertArrayNotHasKey('results', $answer['body']);
		}

		self::assertSame(0, $this->reads);
	}//end testAnUnboundFacadeAnswersNotConfigured()

	/**
	 * An inactive type is not listed by default and answers 404 by uuid.
	 *
	 * @return void
	 */
	public function testConceptTypesAreHiddenByDefault(): void {
		$handler = $this->handler(stored: [['id' => 'final', 'name' => 'A', 'active' => true], ['id' => 'draft', 'name' => 'B', 'active' => false]]);

		$list = $handler->index(authorization: self::bearer(clientId: 'concepts'), baseUrl: self::BASE_URL, query: []);
		self::assertSame(['final'], array_column($list['body']['results'], 'uuid'));
		self::assertSame(404, $handler->show(authorization: self::bearer(clientId: 'concepts'), uuid: 'draft', baseUrl: self::BASE_URL)['status']);

		// A client without concept reads sees no concept, whatever it asks.
		$asked = $handler->index(authorization: self::bearer(clientId: 'reader'), baseUrl: self::BASE_URL, query: ['status' => 'alles']);
		self::assertSame(['final'], array_column($asked['body']['results'], 'uuid'));
		self::assertSame(404, $handler->show(authorization: self::bearer(clientId: 'reader'), uuid: 'draft', baseUrl: self::BASE_URL, status: 'concept')['status']);
	}//end testConceptTypesAreHiddenByDefault()

	/**
	 * A concept reader sees concepts when it asks for them.
	 *
	 * @return void
	 */
	public function testAConceptReaderSeesConceptsWhenAsked(): void {
		$handler = $this->handler(stored: [['id' => 'final', 'name' => 'A', 'active' => true], ['id' => 'draft', 'name' => 'B', 'active' => false]]);
		$bearer = self::bearer(clientId: 'concepts');

		self::assertSame(['final', 'draft'], array_column($handler->index(authorization: $bearer, baseUrl: self::BASE_URL, query: ['status' => 'alles'])['body']['results'], 'uuid'));
		self::assertSame(['draft'], array_column($handler->index(authorization: $bearer, baseUrl: self::BASE_URL, query: ['status' => 'concept'])['body']['results'], 'uuid'));
		self::assertTrue($handler->show(authorization: $bearer, uuid: 'draft', baseUrl: self::BASE_URL, status: 'concept')['body']['concept']);
		self::assertSame(self::BASE_URL . '/informatieobjecttypen?status=concept&page=1', (new InformatieobjecttypeEndpointHandler(gate: $this->gate(binding: null), typesReader: static fn (): array => []))->page(items: [], page: 2, listUrl: self::BASE_URL . '/informatieobjecttypen', query: ['status' => 'concept'])['previous']);
	}//end testAConceptReaderSeesConceptsWhenAsked()

}//end class
