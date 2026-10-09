<?php

/**
 * ZgwDocumentDelivery against a recorded Documenten API exchange.
 *
 * The recorded exchange is the one the Documenten API 1.4 documents for a
 * large file: the create answers `locked: true`, a `lock` and two
 * `bestandsdelen`; each part is PUT as multipart with the lock; the object is
 * unlocked; the case relation is a ZaakInformatieObject on the Zaken API.
 * Every JSON request is validated against the real request schemas
 * (tests/fixtures/zgw, Documenten 1.4.2 and Zaken 1.5.1).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\CaseSystem;

use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\CaseSystem\ZgwDocumentDelivery;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
class ZgwDocumentDeliveryTest extends TestCase {

	private const DRC = 'https://open-zaak.example.nl/documenten/api/v1';

	private const DOCUMENT = self::DRC . '/enkelvoudiginformatieobjecten/5d1e9a40-2c7b-4f1e-8a3d-6b2c1d0e9f87';

	private const ZAAK = 'https://open-zaak.example.nl/zaken/api/v1/zaken/0f3b8c2e-9a41-4d6e-b5c7-1e2d3f4a5b6c';

	private const LETTER = [
		'bronorganisatie' => '002220647',
		'creatiedatum' => '2026-10-04',
		'titel' => 'Bevestiging indiensttreding',
		'auteur' => 'humaniq',
		'taal' => 'dut',
		'formaat' => 'application/pdf',
		'bestandsnaam' => 'bevestiging.pdf',
		'informatieobjecttype' => 'https://open-zaak.example.nl/catalogi/api/v1/informatieobjecttypen/9b2a6c1d-0e3f-4a5b-8c7d-6e5f4a3b2c1d',
		'vertrouwelijkheidaanduiding' => 'vertrouwelijk',
		'status' => 'not a document field',
	];

	/**
	 * Requests the transport received, in order.
	 *
	 * @var array<int,array{source:string,method:string,address:string,options:array}>
	 */
	private array $sent = [];

	/**
	 * Answers by "METHOD address-start", consumed in order per key.
	 *
	 * @var array<string,array{status:int,data:mixed,raw:string}>
	 */
	private array $answers = [];

	/**
	 * The recorded exchange for a 10-byte file in two parts of 6 and 4 bytes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sent    = [];
		$this->answers = [
			'POST /enkelvoudiginformatieobjecten' => self::answer(201, [
				'url' => self::DOCUMENT,
				'locked' => true,
				'lock' => '0c47fa5ef2e74a2a8f8d2ac0f8b1e1d2',
				'bestandsomvang' => 10,
				'bestandsdelen' => [
					['url' => self::DRC . '/bestandsdelen/b2', 'volgnummer' => 2, 'omvang' => 4, 'voltooid' => false],
					['url' => self::DRC . '/bestandsdelen/b1', 'volgnummer' => 1, 'omvang' => 6, 'voltooid' => false],
				],
			]),
			'PUT ' . self::DRC . '/bestandsdelen/' => self::answer(200, ['voltooid' => true]),
			'POST ' . self::DOCUMENT . '/unlock' => self::answer(204, null),
			'POST /zaakinformatieobjecten' => self::answer(201, ['url' => 'https://open-zaak.example.nl/zaken/api/v1/zaakinformatieobjecten/7']),
			'DELETE ' . self::DOCUMENT => self::answer(204, null),
		];
	}//end setUp()

	/**
	 * An answer as the transport returns it.
	 *
	 * @param int   $status The status.
	 * @param mixed $data   The decoded body.
	 *
	 * @return array{status:int,data:mixed,raw:string}
	 */
	private static function answer(int $status, mixed $data): array {
		return ['status' => $status, 'data' => $data, 'raw' => (string)json_encode($data)];
	}//end answer()

	/**
	 * The delivery over a recording transport.
	 *
	 * @return ZgwDocumentDelivery
	 */
	private function delivery(): ZgwDocumentDelivery {
		$transport = $this->createMock(CallServiceCaseSystemTransport::class);
		$transport->method('send')->willReturnCallback(
			function (string $source, string $method, string $address, array $options = []): array {
				$this->sent[] = ['source' => $source, 'method' => $method, 'address' => $address, 'options' => $options];
				foreach ($this->answers as $key => $answer) {
					[$verb, $start] = explode(' ', $key, 2);
					if ($verb === $method && str_starts_with($address, $start) === true) {
						return $answer;
					}
				}

				return self::answer(404, ['detail' => 'not recorded: ' . $method . ' ' . $address]);
			}
		);

		return new ZgwDocumentDelivery(transport: $transport);
	}//end delivery()

	/**
	 * Validation errors of a body against a ZGW schema definition.
	 *
	 * @param string $file       The schema fixture file.
	 * @param string $definition The definition name.
	 * @param array  $body       The body.
	 *
	 * @return array Errors, empty when valid.
	 */
	private static function zgwErrors(string $file, string $definition, array $body): array {
		$schema         = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/zgw/' . $file), true);
		$schema['$ref'] = '#/$defs/' . $definition;

		$result = (new Validator())->validate(
			json_decode(json_encode($body, JSON_THROW_ON_ERROR)),
			json_encode($schema, JSON_THROW_ON_ERROR)
		);
		if ($result->isValid() === true) {
			return [];
		}

		return (new ErrorFormatter())->format($result->error());
	}//end zgwErrors()

	/**
	 * Settings for the Open Zaak source set.
	 *
	 * @param array $extra More settings.
	 *
	 * @return array
	 */
	private static function settings(array $extra = []): array {
		return array_merge(['documentenSource' => 'drc-uuid', 'zakenSource' => 'zrc-uuid', 'zaakUrl' => self::ZAAK], $extra);
	}//end settings()

	/**
	 * GIVEN a Documenten API that answers with two bestandsdelen WHEN a delivery
	 * is pushed THEN both parts are uploaded in order, the object is unlocked,
	 * and a ZaakInformatieObject is created (the task's acceptance criterion).
	 *
	 * @return void
	 */
	public function testALetterIsUploadedInTwoPartsUnlockedAndRelatedToTheCase(): void {
		$result = $this->delivery()->deliver(document: self::LETTER, content: 'ABCDEFGHIJ', settings: self::settings());

		$this->assertSame(self::DOCUMENT, $result['url']);
		$this->assertSame('https://open-zaak.example.nl/zaken/api/v1/zaakinformatieobjecten/7', $result['zaakinformatieobject']);
		$this->assertSame(
			[
				'POST /enkelvoudiginformatieobjecten',
				'PUT ' . self::DRC . '/bestandsdelen/b1',
				'PUT ' . self::DRC . '/bestandsdelen/b2',
				'POST ' . self::DOCUMENT . '/unlock',
				'POST /zaakinformatieobjecten',
			],
			array_map(static fn (array $request): string => $request['method'] . ' ' . $request['address'], $this->sent)
		);

		$create = $this->sent[0]['options']['json'];
		$this->assertSame([], self::zgwErrors('documenten-1.4.2.schema.json', 'EnkelvoudigInformatieObjectCreateLockRequest', $create));
		$this->assertSame(10, $create['bestandsomvang']);
		$this->assertNull($create['inhoud']);
		$this->assertArrayNotHasKey('status', $create);
		$this->assertSame('drc-uuid', $this->sent[0]['source']);

		$first = $this->sent[1]['options']['multipart'];
		$this->assertSame('ABCDEF', $first[0]['contents']);
		$this->assertSame('bevestiging.pdf', $first[0]['filename']);
		$this->assertSame(['name' => 'lock', 'contents' => '0c47fa5ef2e74a2a8f8d2ac0f8b1e1d2'], $first[1]);
		$this->assertSame('GHIJ', $this->sent[2]['options']['multipart'][0]['contents']);

		$unlock = $this->sent[3]['options']['json'];
		$this->assertSame([], self::zgwErrors('documenten-1.4.2.schema.json', 'UnlockEnkelvoudigInformatieObjectRequest', $unlock));
		$this->assertSame('0c47fa5ef2e74a2a8f8d2ac0f8b1e1d2', $unlock['lock']);

		$relation = $this->sent[4]['options']['json'];
		$this->assertSame('zrc-uuid', $this->sent[4]['source']);
		$this->assertSame(['informatieobject' => self::DOCUMENT, 'zaak' => self::ZAAK, 'titel' => 'Bevestiging indiensttreding'], $relation);
		$this->assertSame([], self::zgwErrors('zaken-1.5.1.schema.json', 'ZaakInformatieObject', $relation));
	}//end testALetterIsUploadedInTwoPartsUnlockedAndRelatedToTheCase()

	/**
	 * A Documenten API 1.0 has no parts: the content goes in the create, inline.
	 *
	 * @return void
	 */
	public function testAnInlineDeliverySendsTheContentInTheCreateAndNoParts(): void {
		$this->answers['POST /enkelvoudiginformatieobjecten'] = self::answer(201, ['url' => self::DOCUMENT, 'locked' => false, 'bestandsdelen' => []]);

		$result = $this->delivery()->deliver(document: self::LETTER, content: 'ABCDEFGHIJ', settings: self::settings(['inline' => true, 'zaakUrl' => '']));

		$this->assertSame(self::DOCUMENT, $result['url']);
		$this->assertNull($result['zaakinformatieobject']);
		$this->assertCount(1, $this->sent);
		$create = $this->sent[0]['options']['json'];
		$this->assertSame(base64_encode('ABCDEFGHIJ'), $create['inhoud']);
		$this->assertSame([], self::zgwErrors('documenten-1.4.2.schema.json', 'EnkelvoudigInformatieObjectCreateLockRequest', $create));
	}//end testAnInlineDeliverySendsTheContentInTheCreateAndNoParts()

	/**
	 * A refused part removes the new document, so no locked empty object stays behind.
	 *
	 * @return void
	 */
	public function testARefusedPartRemovesTheDocumentAndSaysWhy(): void {
		$this->answers = ['PUT ' . self::DRC . '/bestandsdelen/b1' => self::answer(400, ['title' => 'Ongeldige invoer', 'invalidParams' => [['name' => 'lock', 'reason' => 'Lock id is niet correct']]])] + $this->answers;

		try {
			$this->delivery()->deliver(document: self::LETTER, content: 'ABCDEFGHIJ', settings: self::settings());
			$this->fail('A refused part must refuse the delivery.');
		} catch (CaseSystemRefusal $refusal) {
			$this->assertSame(400, $refusal->getStatus());
			$this->assertStringContainsString('Lock id is niet correct', $refusal->getMessage());
		}

		$last = end($this->sent);
		$this->assertSame(['DELETE', self::DOCUMENT], [$last['method'], $last['address']]);
		$this->assertNull($this->sentTo('POST', '/zaakinformatieobjecten'));
	}//end testARefusedPartRemovesTheDocumentAndSaysWhy()

	/**
	 * A refused case relation removes the new document too.
	 *
	 * @return void
	 */
	public function testARefusedCaseRelationRemovesTheDocument(): void {
		$this->answers['POST /zaakinformatieobjecten'] = self::answer(400, ['detail' => 'De zaak is afgesloten']);

		$this->expectException(CaseSystemRefusal::class);
		$this->expectExceptionMessage('Zaken API answered 400: De zaak is afgesloten');
		try {
			$this->delivery()->deliver(document: self::LETTER, content: 'ABCDEFGHIJ', settings: self::settings());
		} finally {
			$this->assertNotNull($this->sentTo('DELETE', self::DOCUMENT));
		}
	}//end testARefusedCaseRelationRemovesTheDocument()

	/**
	 * A refused create stops at once and names the API's detail; nothing is deleted.
	 *
	 * @return void
	 */
	public function testARefusedCreateNamesTheDetailAndSendsNothingElse(): void {
		$this->answers['POST /enkelvoudiginformatieobjecten'] = self::answer(400, ['detail' => 'informatieobjecttype is niet gepubliceerd']);

		try {
			$this->delivery()->deliver(document: self::LETTER, content: 'ABCDEFGHIJ', settings: self::settings());
			$this->fail('A refused create must refuse the delivery.');
		} catch (CaseSystemRefusal $refusal) {
			$this->assertSame('Documenten API answered 400: informatieobjecttype is niet gepubliceerd', $refusal->getMessage());
		}

		$this->assertCount(1, $this->sent);
	}//end testARefusedCreateNamesTheDetailAndSendsNothingElse()

	/**
	 * Parts that do not add up to the file are refused rather than unlocked.
	 *
	 * @return void
	 */
	public function testPartsThatDoNotAddUpAreRefusedAndTheDocumentRemoved(): void {
		$this->expectException(CaseSystemRefusal::class);
		$this->expectExceptionMessage('parts add up to 10 bytes, the file has 12');
		try {
			$this->delivery()->deliver(document: self::LETTER, content: 'ABCDEFGHIJKL', settings: self::settings());
		} finally {
			$this->assertNull($this->sentTo('POST', self::DOCUMENT . '/unlock'));
			$this->assertNotNull($this->sentTo('DELETE', self::DOCUMENT));
		}
	}//end testPartsThatDoNotAddUpAreRefusedAndTheDocumentRemoved()

	/**
	 * A case without a Zaken source, or no Documenten source, is named.
	 *
	 * @return void
	 */
	public function testMissingSourcesAreNamed(): void {
		try {
			$this->delivery()->deliver(document: self::LETTER, content: 'x', settings: []);
			$this->fail('No Documenten source must be refused.');
		} catch (CaseSystemRefusal $refusal) {
			$this->assertStringContainsString('documentenSource', $refusal->getMessage());
		}

		$this->answers['POST /enkelvoudiginformatieobjecten'] = self::answer(201, ['url' => self::DOCUMENT, 'bestandsdelen' => []]);
		$this->expectExceptionMessage('zakenSource');
		$this->delivery()->deliver(document: self::LETTER, content: '', settings: ['documentenSource' => 'drc-uuid', 'zaakUrl' => self::ZAAK]);
	}//end testMissingSourcesAreNamed()

	/**
	 * The recorded request whose method and address match.
	 *
	 * @param string $method  The method.
	 * @param string $address The address (or its start).
	 *
	 * @return array|null
	 */
	private function sentTo(string $method, string $address): ?array {
		foreach ($this->sent as $request) {
			if ($request['method'] === $method && str_starts_with($request['address'], $address) === true) {
				return $request;
			}
		}

		return null;
	}//end sentTo()
}//end class
