<?php

/**
 * The seeded learniq ROD mapping rows, run through the real MappingService,
 * produce payloads the ROD envelope translator accepts.
 *
 * This joins the two halves a unit test of either would miss: a mapping row
 * whose output keys the translator does not read would pass the fragment test
 * and the translator test, and still send nothing.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-002-the-school-advice-is-sent-as-aanleverenadviesvo_request
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Rod;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\Rod\RodEnvelopeTranslator;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * @coversNothing
 */
final class RodSeededMappingTest extends TestCase {

	/**
	 * Build a MappingService with inert collaborators.
	 *
	 * @return MappingService
	 */
	private function mappingService(): MappingService {
		return new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(ORObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);
	}//end mappingService()

	/**
	 * The seeded mapping row with the given slug, as an array mapping.
	 *
	 * @param string $slug The mapping slug.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $slug): array {
		$path     = dirname(__DIR__, 4).'/lib/Settings/register.d/learniq-exchange-jobs.json';
		$fragment = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		foreach ($fragment['components']['objects'] as $object) {
			if (($object['@self']['slug'] ?? '') === $slug) {
				return $object;
			}
		}

		$this->fail('No seeded mapping row '.$slug);
	}//end row()

	/**
	 * A learner-profile record maps onto a translatable inschrijving with the
	 * number in its burgerservicenummer element.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#scenario-the-learner-mapping-maps-the-number-not-the-eck-id
	 */
	public function testTheLearnerRowFeedsTheChoiceElement(): void {
		$mapped = $this->mappingService()->executeMapping(
			mapping: $this->row('learniq-bron-rod-export-learner'),
			input: [
				'eckId' => 'https://ketenid.nl/201703/00000000',
				'givenName' => 'Sanne',
				'familyName' => 'de Vries',
				'birthDate' => '2016-04-02',
				'schoolId' => '00AA',
				'persoonsgebondenNummer' => '123456782',
				'persoonsgebondenNummerType' => 'burgerservicenummer',
			]
		);

		$this->assertSame('123456782', $mapped['persoonsgebondenNummer']);
		$this->assertSame('https://ketenid.nl/201703/00000000', $mapped['eckId']);

		$mapped += ['inschrijvingsdatum' => '2026-09-01', 'leerjaar' => 4, 'groep' => '4B'];
		$xml     = (new RodEnvelopeTranslator())->translate('inschrijving', 'k', $mapped);
		$this->assertStringContainsString(
			'<persoonsgebondenNummer><burgerservicenummer>123456782</burgerservicenummer></persoonsgebondenNummer>',
			$xml
		);
	}//end testTheLearnerRowFeedsTheChoiceElement()

	/**
	 * A school-advies record maps onto a translatable AanleverenAdviesVO_Request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#scenario-advice-without-a-second-advice
	 */
	public function testTheSchoolAdviceRowFeedsAanleverenAdviesVo(): void {
		$mapped = $this->mappingService()->executeMapping(
			mapping: $this->row('learniq-bron-rod-export-schooladvies'),
			input: [
				'persoonsgebondenNummer' => '123456782',
				'persoonsgebondenNummerType' => 'onderwijsnummer',
				'adviesvolgnummer' => 'ADV2026001',
				'onderwijsaanbieder' => '100A200',
				'onderwijslocatie' => null,
				'vestigingscode' => '12AB00',
				'adviesjaar' => '2026',
				'advies1' => 'HAVO',
				'advies1Datum' => '2026-01-20',
				'advies2' => null,
				'advies2Datum' => null,
			]
		);

		$xml = (new RodEnvelopeTranslator())->translate('schooladvies', 'k', $mapped);

		$this->assertStringContainsString('<onderwijsnummer>123456782</onderwijsnummer>', $xml);
		$this->assertStringContainsString('<onderwijsaanbieder>100A200</onderwijsaanbieder>', $xml);
		$this->assertStringContainsString('<advies1><advies>HAVO</advies><adviesdatum>2026-01-20</adviesdatum></advies1>', $xml);
		$this->assertStringNotContainsString('onderwijslocatie', $xml);
		$this->assertStringNotContainsString('advies2', $xml);
	}//end testTheSchoolAdviceRowFeedsAanleverenAdviesVo()
}//end class
