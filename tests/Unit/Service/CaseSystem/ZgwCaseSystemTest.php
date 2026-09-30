<?php

/**
 * Unit tests for the ZGW mapping of the five case-system operations.
 *
 * Every request the mapping sends is recorded and validated against the real
 * ZGW request schemas (Zaken API 1.5.1 and Documenten API 1.4.2, converted
 * from VNG-Realisatie's openapi.yaml under tests/fixtures/zgw), so a payload
 * a real Zaken or Documenten API would refuse cannot pass here.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\CaseSystem
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\CaseSystem;

use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\CaseSystem\ZgwCaseSystem;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The ZGW mapping, against recorded requests.
 */
class ZgwCaseSystemTest extends TestCase {

	private const ZAKEN = 'aaaaaaaa-0000-4000-8000-000000000001';

	private const DOCUMENTEN = 'aaaaaaaa-0000-4000-8000-000000000002';

	private const ZAAK_URL = 'https://zaken.example.nl/api/v1/zaken/11111111-1111-4111-8111-111111111111';

	private const EIO_URL = 'https://documenten.example.nl/api/v1/enkelvoudiginformatieobjecten/22222222-2222-4222-8222-222222222222';

	private const DECISION_TYPE = 'https://catalogi.example.nl/api/v1/informatieobjecttypen/33333333-3333-4333-8333-333333333333';

	private const MEETING_TYPE = 'https://catalogi.example.nl/api/v1/zaaktypen/44444444-4444-4444-8444-444444444444';

	/**
	 * Requests the transport received, in order.
	 *
	 * @var array<int,array{source:string,method:string,address:string,options:array}>
	 */
	private array $sent = [];

	/**
	 * Canned answers keyed by "METHOD address" (first match wins).
	 *
	 * @var array<string,array{status:int,data:mixed,raw:string}>
	 */
	private array $answers = [];

	/**
	 * The configuration of a fully set-up source.
	 *
	 * @return array<string,mixed>
	 */
	private function configuration(): array {
		return [
			'zakenSource' => self::ZAKEN,
			'documentenSource' => self::DOCUMENTEN,
			'kinds' => ['decision' => self::DECISION_TYPE, 'agenda' => self::DECISION_TYPE],
			'meetingZaaktype' => self::MEETING_TYPE,
			'bronorganisatie' => '002220647',
		];
	}//end configuration()

	/**
	 * A mapping over a recording transport.
	 *
	 * @return ZgwCaseSystem
	 */
	private function mapping(): ZgwCaseSystem {
		$transport = $this->createMock(CallServiceCaseSystemTransport::class);
		$transport->method('send')->willReturnCallback(
			function (string $source, string $method, string $address, array $options = []): array {
				$this->sent[] = ['source' => $source, 'method' => $method, 'address' => $address, 'options' => $options];
				foreach ($this->answers as $key => $answer) {
					if ($key === $method . ' ' . $address) {
						return $answer;
					}
				}

				return ['status' => 200, 'data' => [], 'raw' => '{}'];
			}
		);

		return new ZgwCaseSystem(transport: $transport);
	}//end mapping()

	/**
	 * Validation errors of a request body against a ZGW request schema.
	 *
	 * @param string $file       The schema fixture file.
	 * @param string $definition The schema definition name.
	 * @param array  $body       The request body.
	 *
	 * @return array Errors, empty when valid.
	 */
	private static function zgwErrors(string $file, string $definition, array $body): array {
		$schema = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/zgw/' . $file), true);
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

	/**
	 * A confidential decision is created with the kind's type and vertrouwelijk, then linked.
	 *
	 * @return void
	 */
	public function testAConfidentialDecisionIsAddedWithItsTypeAndVertrouwelijk(): void {
		$this->answers['POST /enkelvoudiginformatieobjecten'] = ['status' => 201, 'data' => ['url' => self::EIO_URL], 'raw' => ''];
		$this->answers['POST /zaakinformatieobjecten'] = ['status' => 201, 'data' => ['url' => 'https://zaken.example.nl/api/v1/zaakinformatieobjecten/x'], 'raw' => ''];

		$answer = $this->mapping()->run(
			operation: 'add-document',
			body: [
				'case' => self::ZAAK_URL,
				'name' => 'Besluit 2026-12.pdf',
				'kind' => 'decision',
				'content' => base64_encode('%PDF-1.7 besluit'),
				'confidential' => true,
				'ground' => 'Artikel 5.1 lid 2 onder e Woo',
			],
			configuration: $this->configuration(),
			sourceName: 'Zaken en Documenten',
		);

		$this->assertSame(['url' => self::EIO_URL], $answer);

		$document = $this->sentTo('POST', '/enkelvoudiginformatieobjecten');
		$this->assertNotNull($document);
		$this->assertSame(self::DOCUMENTEN, $document['source']);
		$payload = $document['options']['json'];
		$this->assertSame([], self::zgwErrors('documenten-1.4.2.schema.json', 'EnkelvoudigInformatieObjectCreateLockRequest', $payload));
		$this->assertSame(self::DECISION_TYPE, $payload['informatieobjecttype']);
		$this->assertSame('vertrouwelijk', $payload['vertrouwelijkheidaanduiding']);
		$this->assertSame('Artikel 5.1 lid 2 onder e Woo', $payload['beschrijving']);
		$this->assertSame(base64_encode('%PDF-1.7 besluit'), $payload['inhoud']);
		$this->assertSame('dut', $payload['taal']);

		$link = $this->sentTo('POST', '/zaakinformatieobjecten');
		$this->assertNotNull($link);
		$this->assertSame(self::ZAKEN, $link['source']);
		$this->assertSame([], self::zgwErrors('zaken-1.5.1.schema.json', 'ZaakInformatieObject', $link['options']['json']));
		$this->assertSame(self::EIO_URL, $link['options']['json']['informatieobject']);
		$this->assertSame(self::ZAAK_URL, $link['options']['json']['zaak']);
	}//end testAConfidentialDecisionIsAddedWithItsTypeAndVertrouwelijk()

	/**
	 * A public document carries openbaar and no ground in its description.
	 *
	 * @return void
	 */
	public function testAPublicDocumentIsOpenbaar(): void {
		$this->answers['POST /enkelvoudiginformatieobjecten'] = ['status' => 201, 'data' => ['url' => self::EIO_URL], 'raw' => ''];

		$this->mapping()->run(
			operation: 'add-document',
			body: ['case' => self::ZAAK_URL, 'name' => 'Agenda.pdf', 'kind' => 'agenda', 'content' => base64_encode('x'), 'confidential' => false, 'ground' => 'ignored'],
			configuration: $this->configuration(),
			sourceName: 'Zaken en Documenten',
		);

		$payload = $this->sentTo('POST', '/enkelvoudiginformatieobjecten')['options']['json'];
		$this->assertSame('openbaar', $payload['vertrouwelijkheidaanduiding']);
		$this->assertArrayNotHasKey('beschrijving', $payload);
	}//end testAPublicDocumentIsOpenbaar()

	/**
	 * A failed link deletes the created document and answers the link's status.
	 *
	 * @return void
	 */
	public function testAFailedLinkLeavesNoOrphanDocument(): void {
		$this->answers['POST /enkelvoudiginformatieobjecten'] = ['status' => 201, 'data' => ['url' => self::EIO_URL], 'raw' => ''];
		$this->answers['POST /zaakinformatieobjecten'] = [
			'status' => 400,
			'data' => ['title' => 'Invalid input.', 'detail' => 'De zaak is gesloten.', 'invalidParams' => [['name' => 'zaak', 'reason' => 'secret internals']]],
			'raw' => '',
		];

		try {
			$this->mapping()->run(
				operation: 'add-document',
				body: ['case' => self::ZAAK_URL, 'name' => 'Besluit.pdf', 'kind' => 'decision', 'content' => base64_encode('x'), 'confidential' => false, 'ground' => ''],
				configuration: $this->configuration(),
				sourceName: 'Zaken en Documenten',
			);
			$this->fail('A refused link must be a refusal.');
		} catch (CaseSystemRefusal $refusal) {
			$this->assertSame(400, $refusal->getStatus());
			$this->assertStringContainsString('De zaak is gesloten.', $refusal->getMessage());
			$this->assertStringNotContainsString('secret internals', $refusal->getMessage());
		}

		$delete = $this->sentTo('DELETE', self::EIO_URL);
		$this->assertNotNull($delete, 'The document created before the failed link must be deleted again.');
		$this->assertSame(self::DOCUMENTEN, $delete['source']);
	}//end testAFailedLinkLeavesNoOrphanDocument()

	/**
	 * An unknown kind answers 422 naming the kind, and nothing is sent.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsRefusedNamingIt(): void {
		try {
			$this->mapping()->run(
				operation: 'add-document',
				body: ['case' => self::ZAAK_URL, 'name' => 'x.pdf', 'kind' => 'minutes', 'content' => 'eA==', 'confidential' => false, 'ground' => ''],
				configuration: $this->configuration(),
				sourceName: 'Zaken en Documenten',
			);
			$this->fail('An unknown kind must be refused.');
		} catch (CaseSystemRefusal $refusal) {
			$this->assertSame(422, $refusal->getStatus());
			$this->assertStringContainsString('minutes', $refusal->getMessage());
		}

		$this->assertSame([], $this->sent);
	}//end testAnUnknownKindIsRefusedNamingIt()

	/**
	 * create-case posts a valid zaak with the meeting zaaktype and the CRS headers.
	 *
	 * @return void
	 */
	public function testCreateCasePostsAValidZaak(): void {
		$this->answers['POST /zaken'] = ['status' => 201, 'data' => ['url' => self::ZAAK_URL, 'identificatie' => 'ZAAK-2026-0042'], 'raw' => ''];

		$answer = $this->mapping()->run(
			operation: 'create-case',
			body: ['kind' => 'meeting', 'title' => 'Raadsvergadering 12 november 2026', 'date' => '2026-11-12'],
			configuration: $this->configuration(),
			sourceName: 'Zaken en Documenten',
		);

		$this->assertSame(['url' => self::ZAAK_URL, 'identification' => 'ZAAK-2026-0042'], $answer);
		$request = $this->sentTo('POST', '/zaken');
		$this->assertSame(self::ZAKEN, $request['source']);
		$this->assertSame([], self::zgwErrors('zaken-1.5.1.schema.json', 'Zaak', $request['options']['json']));
		$this->assertSame(self::MEETING_TYPE, $request['options']['json']['zaaktype']);
		$this->assertSame('2026-11-12', $request['options']['json']['startdatum']);
		$this->assertSame('EPSG:4326', $request['options']['headers']['Accept-Crs']);
		$this->assertSame('EPSG:4326', $request['options']['headers']['Content-Crs']);
	}//end testCreateCasePostsAValidZaak()

	/**
	 * create-case refuses a kind other than meeting and a date that is not Y-m-d.
	 *
	 * @return void
	 */
	public function testCreateCaseRefusesAnotherKindOrABadDate(): void {
		foreach ([['kind' => 'project', 'title' => 't', 'date' => '2026-11-12'], ['kind' => 'meeting', 'title' => 't', 'date' => '12-11-2026']] as $body) {
			try {
				$this->mapping()->run(operation: 'create-case', body: $body, configuration: $this->configuration(), sourceName: 'Z');
				$this->fail('Must be refused: ' . json_encode($body));
			} catch (CaseSystemRefusal $refusal) {
				$this->assertSame(422, $refusal->getStatus());
			}
		}

		$this->assertSame([], $this->sent);
	}//end testCreateCaseRefusesAnotherKindOrABadDate()

	/**
	 * read-case by number searches on identificatie; by address it reads the zaak.
	 *
	 * @return void
	 */
	public function testReadCaseByNumberAndByAddress(): void {
		$zaak = ['url' => self::ZAAK_URL, 'identificatie' => 'ZAAK-2026-0001', 'omschrijving' => 'Raadsvergadering oktober'];
		$this->answers['GET /zaken'] = ['status' => 200, 'data' => ['count' => 1, 'results' => [$zaak]], 'raw' => ''];
		$this->answers['GET ' . self::ZAAK_URL] = ['status' => 200, 'data' => $zaak, 'raw' => ''];

		$expected = ['url' => self::ZAAK_URL, 'identification' => 'ZAAK-2026-0001', 'title' => 'Raadsvergadering oktober'];
		$this->assertSame($expected, $this->mapping()->run(operation: 'read-case', body: ['reference' => 'ZAAK-2026-0001'], configuration: $this->configuration(), sourceName: 'Z'));
		$search = $this->sentTo('GET', '/zaken');
		$this->assertSame(['identificatie' => 'ZAAK-2026-0001'], $search['options']['query']);
		$this->assertSame('EPSG:4326', $search['options']['headers']['Accept-Crs']);

		$this->assertSame($expected, $this->mapping()->run(operation: 'read-case', body: ['reference' => self::ZAAK_URL], configuration: $this->configuration(), sourceName: 'Z'));
	}//end testReadCaseByNumberAndByAddress()

	/**
	 * A case number nobody knows answers an empty case (no url = not found).
	 *
	 * @return void
	 */
	public function testAnUnknownCaseNumberAnswersNoUrl(): void {
		$this->answers['GET /zaken'] = ['status' => 200, 'data' => ['count' => 0, 'results' => []], 'raw' => ''];

		$answer = $this->mapping()->run(operation: 'read-case', body: ['reference' => 'ZAAK-0'], configuration: $this->configuration(), sourceName: 'Z');

		$this->assertSame('', $answer['url']);
	}//end testAnUnknownCaseNumberAnswersNoUrl()

	/**
	 * list-documents reads the links, then each document's title.
	 *
	 * @return void
	 */
	public function testListDocumentsNamesEachDocument(): void {
		$this->answers['GET /zaakinformatieobjecten'] = ['status' => 200, 'data' => [['informatieobject' => self::EIO_URL, 'zaak' => self::ZAAK_URL]], 'raw' => ''];
		$this->answers['GET ' . self::EIO_URL] = ['status' => 200, 'data' => ['url' => self::EIO_URL, 'titel' => 'Agenda oktober'], 'raw' => ''];

		$answer = $this->mapping()->run(operation: 'list-documents', body: ['case' => self::ZAAK_URL], configuration: $this->configuration(), sourceName: 'Z');

		$this->assertSame(['documents' => [['url' => self::EIO_URL, 'name' => 'Agenda oktober']]], $answer);
		$this->assertSame(['zaak' => self::ZAAK_URL], $this->sentTo('GET', '/zaakinformatieobjecten')['options']['query']);
	}//end testListDocumentsNamesEachDocument()

	/**
	 * read-document answers the title and the downloaded content in base64.
	 *
	 * @return void
	 */
	public function testReadDocumentAnswersBase64Content(): void {
		$download = self::EIO_URL . '/download';
		$this->answers['GET ' . self::EIO_URL] = ['status' => 200, 'data' => ['url' => self::EIO_URL, 'titel' => 'Besluit', 'inhoud' => $download], 'raw' => ''];
		$this->answers['GET ' . $download] = ['status' => 200, 'data' => null, 'raw' => "%PDF-1.7\x00\xff binary"];

		$answer = $this->mapping()->run(operation: 'read-document', body: ['document' => self::EIO_URL], configuration: $this->configuration(), sourceName: 'Z');

		$this->assertSame(['name' => 'Besluit', 'content' => base64_encode("%PDF-1.7\x00\xff binary")], $answer);
		$this->assertSame(self::DOCUMENTEN, $this->sentTo('GET', $download)['source']);
	}//end testReadDocumentAnswersBase64Content()

	/**
	 * A source that names no Zaken API answers 409 naming the missing setting.
	 *
	 * @return void
	 */
	public function testAMissingZakenSourceIsNamed(): void {
		$configuration = $this->configuration();
		unset($configuration['zakenSource']);

		try {
			$this->mapping()->run(operation: 'read-case', body: ['reference' => 'ZAAK-1'], configuration: $configuration, sourceName: 'Z');
			$this->fail('A missing Zaken source must be refused.');
		} catch (CaseSystemRefusal $refusal) {
			$this->assertSame(409, $refusal->getStatus());
			$this->assertStringContainsString('zakenSource', $refusal->getMessage());
		}
	}//end testAMissingZakenSourceIsNamed()

	/**
	 * A ZGW error answers its status and its detail, never its body.
	 *
	 * @return void
	 */
	public function testAZgwErrorAnswersItsStatusAndDetailOnly(): void {
		$this->answers['POST /zaken'] = ['status' => 403, 'data' => ['detail' => 'Niet geautoriseerd voor dit zaaktype.', 'code' => 'permission_denied', 'instance' => 'urn:uuid:secret'], 'raw' => ''];

		try {
			$this->mapping()->run(operation: 'create-case', body: ['kind' => 'meeting', 'title' => 't', 'date' => '2026-11-12'], configuration: $this->configuration(), sourceName: 'Z');
			$this->fail('A ZGW error must be a refusal.');
		} catch (CaseSystemRefusal $refusal) {
			$this->assertSame(403, $refusal->getStatus());
			$this->assertStringContainsString('Niet geautoriseerd voor dit zaaktype.', $refusal->getMessage());
			$this->assertStringNotContainsString('urn:uuid:secret', $refusal->getMessage());
		}
	}//end testAZgwErrorAnswersItsStatusAndDetailOnly()
}//end class
