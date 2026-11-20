<?php

/**
 * CatalogusEndpointHandler unit tests.
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
use OCA\Integriq\Service\Catalogi\CatalogusEndpointHandler;
use OCA\Integriq\Service\Catalogi\InformatieobjecttypeEndpointHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The catalogussen routes.
 */
#[CoversClass(CatalogusEndpointHandler::class)]
#[UsesClass(CatalogiBinding::class)]
#[UsesClass(CatalogiGate::class)]
#[UsesClass(CatalogiTokenService::class)]
#[UsesClass(InformatieobjecttypeEndpointHandler::class)]
class CatalogusEndpointHandlerTest extends TestCase {

	use CatalogiFixture;

	/**
	 * The handler over two stored types, one a concept.
	 *
	 * @return CatalogusEndpointHandler
	 */
	private function handler(): CatalogusEndpointHandler {
		$gate = $this->gate(binding: self::binding());

		return new CatalogusEndpointHandler(
			gate: $gate,
			types: new InformatieobjecttypeEndpointHandler(
				gate: $gate,
				typesReader: static fn (): array => [['id' => 'type-1', 'name' => 'Besluit', 'active' => true], ['id' => 'type-2', 'name' => 'Ontwerp', 'active' => false]]
			)
		);
	}//end handler()

	/**
	 * The catalogue a type's `catalogus` URL names resolves, listing its definitive types.
	 *
	 * @return void
	 */
	public function testTheCatalogueResolves(): void {
		$answer = $this->handler()->show(authorization: self::bearer(clientId: 'reader'), uuid: 'cat-1', baseUrl: self::BASE_URL);

		self::assertSame(200, $answer['status']);
		self::assertSame(
			[
				'url' => self::BASE_URL . '/catalogussen/cat-1',
				'domein' => 'WOO',
				'rsin' => '002220647',
				'naam' => 'Documenttypen',
				'informatieobjecttypen' => [self::BASE_URL . '/informatieobjecttypen/type-1'],
				'zaaktypen' => [],
				'besluittypen' => [],
			],
			$answer['body']
		);
		self::assertSame(404, $this->handler()->show(authorization: self::bearer(clientId: 'reader'), uuid: 'cat-2', baseUrl: self::BASE_URL)['status']);
	}//end testTheCatalogueResolves()

	/**
	 * The list holds the one catalogue, filtered on domein and rsin.
	 *
	 * @return void
	 */
	public function testTheListFiltersOnDomeinAndRsin(): void {
		$bearer = self::bearer(clientId: 'reader');

		self::assertSame(1, $this->handler()->index(authorization: $bearer, baseUrl: self::BASE_URL, query: ['domein' => 'WOO'])['body']['count']);
		self::assertSame(0, $this->handler()->index(authorization: $bearer, baseUrl: self::BASE_URL, query: ['rsin' => '123456789'])['body']['count']);
		self::assertSame(403, $this->handler()->index(authorization: self::bearer(clientId: 'stranger'), baseUrl: self::BASE_URL, query: [])['status']);
	}//end testTheListFiltersOnDomeinAndRsin()

}//end class
