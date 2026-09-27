<?php

/**
 * ExchangeErrorCodeCatalogue and ExchangeTargetCatalogue.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Exchange;

use OCA\Integriq\Service\Exchange\ExchangeErrorCodeCatalogue;
use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogue;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-001 and REQ-007 scenarios.
 */
class ExchangeErrorCodeCatalogueTest extends TestCase {

	/**
	 * A catalogue reading from a store that returns the given rows.
	 *
	 * @param array<string, array<string, mixed>> $rowsBySlug Stored catalogues by slug.
	 *
	 * @return ExchangeErrorCodeCatalogue The catalogue.
	 */
	private function catalogue(array $rowsBySlug = []): ExchangeErrorCodeCatalogue {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = [], ...$rest) use ($rowsBySlug): array {
				$slug = (string)($config['filters']['slug'] ?? '');
				if (isset($rowsBySlug[$slug]) === false) {
					return ['results' => [], 'total' => 0];
				}

				$entity = new ObjectEntity();
				$entity->setObject(['mapping' => $rowsBySlug[$slug]]);
				return ['results' => [$entity], 'total' => 1];
			}
		);

		return new ExchangeErrorCodeCatalogue($objects, $this->createMock(LoggerInterface::class));

	}//end catalogue()

	/**
	 * A catalogued code gets its label from the shipped fragment.
	 *
	 * @return void
	 */
	public function testACataloguedCodeGetsItsLabel(): void {
		$entry = $this->catalogue()->resolve('bron-rod', 'BRON-102');

		$this->assertSame('Ontbrekende geboortedatum', $entry['label']);
		$this->assertSame('Missing birth date', $entry['labelEn']);
		$this->assertSame('blocking', $entry['severity']);

	}//end testACataloguedCodeGetsItsLabel()

	/**
	 * A runner code resolves on any target; an unknown code falls back.
	 *
	 * @return void
	 */
	public function testRunnerCodesAndTheFallback(): void {
		$catalogue = $this->catalogue();

		$this->assertSame('De eigenaar-app is niet geïnstalleerd of staat uit', $catalogue->resolve('oso', 'gate-app-absent')['label']);
		$this->assertSame('BRON-999', $catalogue->resolve('bron-rod', 'BRON-999')['label']);

	}//end testRunnerCodesAndTheFallback()

	/**
	 * A stored row wins over the shipped one, so an administrator's edit counts.
	 *
	 * @return void
	 */
	public function testAStoredRowWins(): void {
		$catalogue = $this->catalogue(
			['learniq-exchange-error-codes-oso' => ['OSO-301' => ['label' => 'Dossier mist hoofdstukken', 'labelEn' => 'x', 'category' => 'dossier', 'severity' => 'blocking']]]
		);

		$entry = $catalogue->resolve('oso', 'OSO-301');

		$this->assertSame('Dossier mist hoofdstukken', $entry['label']);
		$this->assertSame('blocking', $entry['severity']);

	}//end testAStoredRowWins()

	/**
	 * The target catalogue knows fourteen targets and their directions.
	 *
	 * @return void
	 */
	public function testTheTargetCatalogue(): void {
		$targets = new ExchangeTargetCatalogue();

		$this->assertCount(14, $targets->all());
		$this->assertTrue($targets->supportsDirection('oso', 'import'));
		$this->assertFalse($targets->supportsDirection('leerplicht', 'import'));
		$this->assertFalse($targets->supportsDirection('fax', 'export'));
		$this->assertSame('DUO ROD', $targets->label('bron-rod'));
		$this->assertSame('fax', $targets->label('fax'));

	}//end testTheTargetCatalogue()
}//end class
