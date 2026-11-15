<?php

/**
 * Golden tests for ZgwDocumentDelivery::deliver: creating the document and
 * relating it to the case.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\CaseSystem
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\CaseSystem;

use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\CaseSystem\ZgwDocumentDelivery;
use PHPUnit\Framework\TestCase;

/**
 * Every create and case-relation path, recorded before deliver() was split.
 */
class ZgwDocumentDeliveryGoldenTest extends TestCase {

	/**
	 * The record.
	 *
	 * @var string
	 */
	private const GOLDEN = '{"no documenten source":{"outcome":"409: The delivery names no Documenten API source (documentenSource).","sent":[]},"empty documenten source":{"outcome":"409: The delivery names no Documenten API source (documentenSource).","sent":[]},"created without url":{"outcome":"502: The Documenten API created the document without an address.","sent":[["drc-nourl","POST","\\/enkelvoudiginformatieobjecten",{"json":{"bronorganisatie":"001","titel":"Besluit","auteur":"Gemeente","bestandsnaam":"besluit.pdf","bestandsomvang":9,"inhoud":null}}]]},"create refused":{"outcome":"400: Documenten API answered 400: Ongeldig titel: te lang","sent":[["drc-refuse","POST","\\/enkelvoudiginformatieobjecten",{"json":{"bronorganisatie":"001","titel":"Besluit","auteur":"Gemeente","bestandsnaam":"besluit.pdf","bestandsomvang":9,"inhoud":null}}]]},"no case":{"outcome":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","zaakinformatieobject":null,"document":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","lock":""}},"sent":[["drc","POST","\\/enkelvoudiginformatieobjecten",{"json":{"bronorganisatie":"001","titel":"Besluit","auteur":"Gemeente","bestandsnaam":"besluit.pdf","bestandsomvang":9,"inhoud":"UERGLUJZVEVT"}}]]},"inline not true":{"outcome":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","zaakinformatieobject":null,"document":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","lock":""}},"sent":[["drc","POST","\\/enkelvoudiginformatieobjecten",{"json":{"bronorganisatie":"001","titel":"Besluit","auteur":"Gemeente","bestandsnaam":"besluit.pdf","bestandsomvang":9,"inhoud":null}}]]},"case without zaken source":{"outcome":"409: The delivery names a case but no Zaken API source (zakenSource).","sent":[["drc","POST","\\/enkelvoudiginformatieobjecten",{"json":{"bronorganisatie":"001","titel":"Besluit","auteur":"Gemeente","bestandsnaam":"besluit.pdf","bestandsomvang":9,"inhoud":"UERGLUJZVEVT"}}],["drc","DELETE","https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1",[]]]},"case related":{"outcome":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","zaakinformatieobject":"https:\\/\\/zrc\\/zaakinformatieobjecten\\/1","document":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","lock":""}},"sent":[["drc","POST","\\/enkelvoudiginformatieobjecten",{"json":{"bronorganisatie":"001","titel":"Besluit","auteur":"Gemeente","bestandsnaam":"besluit.pdf","bestandsomvang":9,"inhoud":"UERGLUJZVEVT"}}],["zrc","POST","\\/zaakinformatieobjecten",{"json":{"informatieobject":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","zaak":"https:\\/\\/zrc\\/z\\/1","titel":"Besluit"}}]]},"long titel":{"outcome":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","zaakinformatieobject":"https:\\/\\/zrc\\/zaakinformatieobjecten\\/1","document":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","lock":""}},"sent":[["drc","POST","\\/enkelvoudiginformatieobjecten",{"json":{"titel":"\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9","bestandsomvang":9,"inhoud":"UERGLUJZVEVT"}}],["zrc","POST","\\/zaakinformatieobjecten",{"json":{"informatieobject":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","zaak":"https:\\/\\/zrc\\/z\\/1","titel":"\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9\\u00e9"}}]]},"no titel, no file name":{"outcome":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","zaakinformatieobject":"https:\\/\\/zrc\\/zaakinformatieobjecten\\/1","document":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","lock":""}},"sent":[["drc","POST","\\/enkelvoudiginformatieobjecten",{"json":{"bestandsomvang":9,"inhoud":"UERGLUJZVEVT"}}],["zrc","POST","\\/zaakinformatieobjecten",{"json":{"informatieobject":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","zaak":"https:\\/\\/zrc\\/z\\/1"}}]]},"relation without url":{"outcome":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","zaakinformatieobject":"","document":{"url":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","lock":""}},"sent":[["drc","POST","\\/enkelvoudiginformatieobjecten",{"json":{"bronorganisatie":"001","titel":"Besluit","auteur":"Gemeente","bestandsnaam":"besluit.pdf","bestandsomvang":9,"inhoud":"UERGLUJZVEVT"}}],["zrc-nourl","POST","\\/zaakinformatieobjecten",{"json":{"informatieobject":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","zaak":"https:\\/\\/zrc\\/z\\/1","titel":"Besluit"}}]]},"relation refused":{"outcome":"400: Zaken API answered 400: Ongeldig titel: te lang","sent":[["drc","POST","\\/enkelvoudiginformatieobjecten",{"json":{"bronorganisatie":"001","titel":"Besluit","auteur":"Gemeente","bestandsnaam":"besluit.pdf","bestandsomvang":9,"inhoud":"UERGLUJZVEVT"}}],["zrc-refuse","POST","\\/zaakinformatieobjecten",{"json":{"informatieobject":"https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1","zaak":"https:\\/\\/zrc\\/z\\/1","titel":"Besluit"}}],["drc","DELETE","https:\\/\\/drc\\/enkelvoudiginformatieobjecten\\/1",[]]]}}';

	/**
	 * GOLDEN: every delivery case, with every call sent and the outcome.
	 *
	 * @return void
	 */
	public function testGoldenDeliveries(): void {
		$long = str_repeat('é', 210);
		$document = ['titel' => 'Besluit', 'bronorganisatie' => '001', 'taal' => '', 'auteur' => 'Gemeente', 'bestandsnaam' => 'besluit.pdf'];
		$cases = [
			'no documenten source' => [$document, ['zaakUrl' => 'z/1', 'zakenSource' => 'zrc']],
			'empty documenten source' => [$document, ['documentenSource' => '']],
			'created without url' => [$document, ['documentenSource' => 'drc-nourl']],
			'create refused' => [$document, ['documentenSource' => 'drc-refuse']],
			'no case' => [$document, ['documentenSource' => 'drc', 'inline' => true]],
			'inline not true' => [$document, ['documentenSource' => 'drc', 'inline' => 'yes']],
			'case without zaken source' => [$document, ['documentenSource' => 'drc', 'inline' => true, 'zaakUrl' => 'https://zrc/z/1']],
			'case related' => [$document, ['documentenSource' => 'drc', 'inline' => true, 'zaakUrl' => 'https://zrc/z/1', 'zakenSource' => 'zrc']],
			'long titel' => [['titel' => $long], ['documentenSource' => 'drc', 'inline' => true, 'zaakUrl' => 'https://zrc/z/1', 'zakenSource' => 'zrc']],
			'no titel, no file name' => [[], ['documentenSource' => 'drc', 'inline' => true, 'zaakUrl' => 'https://zrc/z/1', 'zakenSource' => 'zrc']],
			'relation without url' => [$document, ['documentenSource' => 'drc', 'inline' => true, 'zaakUrl' => 'https://zrc/z/1', 'zakenSource' => 'zrc-nourl']],
			'relation refused' => [$document, ['documentenSource' => 'drc', 'inline' => true, 'zaakUrl' => 'https://zrc/z/1', 'zakenSource' => 'zrc-refuse']],
		];

		$record = [];
		foreach ($cases as $name => [$doc, $settings]) {
			$sent = [];
			$transport = $this->createMock(CallServiceCaseSystemTransport::class);
			$transport->method('send')->willReturnCallback(
				function (string $sourceId, string $method, string $address, array $options = []) use (&$sent): array {
					$sent[] = [$sourceId, $method, $address, $options];
					if (str_ends_with($sourceId, '-refuse') === true) {
						return ['status' => 400, 'data' => ['detail' => 'Ongeldig', 'invalidParams' => [['name' => 'titel', 'reason' => 'te lang']]], 'raw' => ''];
					}

					if (str_ends_with($sourceId, '-nourl') === true) {
						return ['status' => 201, 'data' => ['id' => 'x'], 'raw' => ''];
					}

					return ['status' => 201, 'data' => ['url' => 'https://' . $sourceId . $address . '/1', 'lock' => ''], 'raw' => ''];
				}
			);

			try {
				$outcome = (new ZgwDocumentDelivery($transport))->deliver($doc, 'PDF-BYTES', $settings);
			} catch (CaseSystemRefusal $refusal) {
				$outcome = $refusal->getStatus() . ': ' . $refusal->getMessage();
			}

			$record[$name] = ['outcome' => $outcome, 'sent' => $sent];
		}//end foreach

		$this->assertSame(self::GOLDEN, json_encode($record));
	}//end testGoldenDeliveries()
}//end class
