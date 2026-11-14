<?php

/**
 * StUFZKNService edge-case unit tests
 *
 * The StUF-ZKN entry points answer a Fo03 fault for an envelope without a
 * body and for a zakLk01 that carries no case object. A zakLk01 for a case
 * that already exists merges into it instead of creating a second one, and a
 * new case's identification comes from what OpenRegister stored. A zakLv01
 * searches with the criteria it names and answers only the mapped fields.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\StUFXMLBuilder;
use OCA\Integriq\Service\StUFZKNService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Edge cases of the StUF-ZKN entry points.
 *
 * @covers \OCA\Integriq\Service\StUFZKNService
 * @uses \OCA\Integriq\Util\SafeXmlParser
 * @uses \OCA\Integriq\Service\Stuf\StufNamespaces
 */
class StUFZKNServiceEdgesTest extends TestCase {

	private const STUURGEGEVENS = ['zenderOrganisatie' => '001122334'];

	/** @var ORObjectService&MockObject */
	private ORObjectService $objects;

	/** @var StUFXMLBuilder&MockObject */
	private StUFXMLBuilder $builder;

	private StUFZKNService $service;

	protected function setUp(): void {
		$this->objects = $this->createMock(ORObjectService::class);
		$this->builder = $this->createMock(StUFXMLBuilder::class);
		$this->builder->method('buildFo03')->willReturnCallback(static fn (string $code, string $omschrijving, array $stuurgegevens): string => "Fo03 {$code}: {$omschrijving}");
		$this->service = new StUFZKNService(logger: $this->createMock(LoggerInterface::class), orObjectService: $this->objects, xmlBuilder: $this->builder);
	}//end setUp()

	public function testAnEnvelopeWithoutABodyIsAFault(): void {
		$envelope = '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ZKN="http://www.egem.nl/StUF/sector/zkn/0310"><SOAP-ENV:Header/></SOAP-ENV:Envelope>';

		$this->assertSame('Fo03 StUF046: Malformed XML: missing SOAP body.', $this->service->handleZakLk01(soapXml: $envelope, stuurgegevens: self::STUURGEGEVENS));
		$this->assertSame('Fo03 StUF046: Malformed XML: missing SOAP body.', $this->service->handleZakLv01(soapXml: $envelope, stuurgegevens: self::STUURGEGEVENS));
	}//end testAnEnvelopeWithoutABodyIsAFault()

	public function testAZakLk01WithoutACaseObjectIsAFault(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertSame(
			'Fo03 StUF046: Missing required zaak data in zakLk01.',
			$this->service->handleZakLk01(soapXml: $this->envelope('<ZKN:zakLk01><ZKN:parameters/></ZKN:zakLk01>'), stuurgegevens: self::STUURGEGEVENS)
		);
		$this->assertSame(
			'Fo03 StUF046: Missing required zaak data in zakLk01.',
			$this->service->handleZakLk01(soapXml: $this->envelope('<ZKN:zakLv01/>'), stuurgegevens: self::STUURGEGEVENS)
		);
	}//end testAZakLk01WithoutACaseObjectIsAFault()

	public function testAKnownCaseIsMergedNotCreatedTwice(): void {
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['register' => 'zaken', 'schema' => 'zaak', 'zaakidentificatie' => 'ZAAK-1']]
		)->willReturn(['results' => [['zaakidentificatie' => 'ZAAK-1', 'omschrijving' => 'oud', 'status' => 'open']]]);
		$this->objects->expects($this->once())->method('saveObject')->with(
			['zaakidentificatie' => 'ZAAK-1', 'omschrijving' => 'nieuw', 'status' => 'open', 'zaaktype' => 'melding'],
			'zaken',
			'zaak'
		)->willReturn($this->stored(['zaakidentificatie' => 'ZAAK-1']));
		$this->builder->expects($this->once())->method('buildBv03')->with(caseIdentification: 'ZAAK-1', stuurgegevens: self::STUURGEGEVENS)->willReturn('<Bv03/>');

		$answer = $this->service->handleZakLk01(soapXml: $this->zakLk01(identification: 'ZAAK-1'), stuurgegevens: self::STUURGEGEVENS);

		$this->assertSame('<Bv03/>', $answer);
	}//end testAKnownCaseIsMergedNotCreatedTwice()

	public function testAKnownCaseReturnedAsAnEntityIsMergedToo(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['zaakidentificatie' => 'ZAAK-1', 'einddatum' => '20261231']);
		$this->objects->method('findAll')->willReturn(['results' => [$entity]]);
		$this->objects->expects($this->once())->method('saveObject')->with(
			['zaakidentificatie' => 'ZAAK-1', 'einddatum' => '20261231', 'omschrijving' => 'nieuw', 'zaaktype' => 'melding'],
			'zaken',
			'zaak'
		)->willReturn($this->stored(['zaakidentificatie' => 'ZAAK-1']));
		$this->builder->method('buildBv03')->willReturn('<Bv03/>');

		$this->service->handleZakLk01(soapXml: $this->zakLk01(identification: 'ZAAK-1'), stuurgegevens: self::STUURGEGEVENS);
	}//end testAKnownCaseReturnedAsAnEntityIsMergedToo()

	public function testANewCaseWithoutIdentificationTakesTheOneOpenRegisterStored(): void {
		$this->objects->expects($this->never())->method('findAll');
		$this->objects->expects($this->once())->method('saveObject')->willReturn($this->stored(['zaakidentificatie' => 'ZAAK-NIEUW']));
		$this->builder->expects($this->once())->method('buildBv03')->with(caseIdentification: 'ZAAK-NIEUW', stuurgegevens: $this->anything())->willReturn('<Bv03/>');

		$this->service->handleZakLk01(soapXml: $this->zakLk01(identification: ''), stuurgegevens: self::STUURGEGEVENS);
	}//end testANewCaseWithoutIdentificationTakesTheOneOpenRegisterStored()

	public function testAZakLv01SearchesWithItsCriteriaAndAnswersOnlyMappedFields(): void {
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['register' => 'zaken', 'schema' => 'zaak', 'zaaktype' => 'melding', 'status' => 'open']]
		)->willReturn(['results' => [['zaakidentificatie' => 'ZAAK-3', 'zaaktype' => 'melding', 'intern' => 'geheim']]]);
		$this->builder->expects($this->once())->method('buildZakLa01')->with(
			cases: [['zaakidentificatie' => 'ZAAK-3', 'zaaktype' => 'melding']],
			stuurgegevens: $this->anything()
		)->willReturn('<zakLa01/>');

		$answer = $this->service->handleZakLv01(
			soapXml: $this->envelope(
				'<ZKN:zakLv01><ZKN:gelijk><ZKN:zaaktype>melding</ZKN:zaaktype><ZKN:status>open</ZKN:status>'
				. '<ZKN:onbekend>x</ZKN:onbekend><ZKN:omschrijving></ZKN:omschrijving></ZKN:gelijk></ZKN:zakLv01>'
			),
			stuurgegevens: self::STUURGEGEVENS
		);

		$this->assertSame('<zakLa01/>', $answer);
	}//end testAZakLv01SearchesWithItsCriteriaAndAnswersOnlyMappedFields()

	public function testAZakLv01WithoutCriteriaSearchesEverything(): void {
		$this->objects->expects($this->once())->method('findAll')->with(['filters' => ['register' => 'zaken', 'schema' => 'zaak']])->willReturn([]);
		$this->builder->expects($this->once())->method('buildZakLa01')->with(cases: [], stuurgegevens: self::STUURGEGEVENS)->willReturn('<zakLa01/>');

		$this->service->handleZakLv01(soapXml: $this->envelope('<ZKN:zakLv01/>'), stuurgegevens: self::STUURGEGEVENS);
	}//end testAZakLv01WithoutCriteriaSearchesEverything()

	/**
	 * What OpenRegister answers a save with.
	 *
	 * @param array<string, mixed> $object The stored object.
	 *
	 * @return ObjectEntity
	 */
	private function stored(array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($object);

		return $entity;
	}//end stored()

	/**
	 * A zakLk01 for a case of type melding, described as nieuw.
	 *
	 * @param string $identification The identification ('' for none).
	 *
	 * @return string
	 */
	private function zakLk01(string $identification): string {
		$identificatie = ($identification === '' ? '' : '<ZKN:identificatie>' . $identification . '</ZKN:identificatie>');

		return $this->envelope(
			'<ZKN:zakLk01><ZKN:object>' . $identificatie
			. '<ZKN:omschrijving>nieuw</ZKN:omschrijving><ZKN:zaaktype>melding</ZKN:zaaktype></ZKN:object></ZKN:zakLk01>'
		);
	}//end zakLk01()

	/**
	 * A SOAP 1.1 envelope around a body.
	 *
	 * @param string $body The body content.
	 *
	 * @return string
	 */
	private function envelope(string $body): string {
		return '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
			. ' xmlns:StUF="http://www.egem.nl/StUF/StUF0301" xmlns:ZKN="http://www.egem.nl/StUF/sector/zkn/0310">'
			. '<SOAP-ENV:Body>' . $body . '</SOAP-ENV:Body></SOAP-ENV:Envelope>';
	}//end envelope()
}//end class
