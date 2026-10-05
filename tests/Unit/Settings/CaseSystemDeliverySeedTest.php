<?php

/**
 * The seeded case system delivery synchronizations and their mappings.
 *
 * Each mapping runs through the REAL MappingService on a payload shaped as
 * filinq declares it (caseSystemDelivery: generate-store-in-case-system D6;
 * externalDocument: zgw-document-bridge), and what ZgwDocumentDelivery would
 * send is validated against the Documenten API 1.4.2 create schema
 * (tests/fixtures/zgw). Every seed is validated against the integriq
 * register as InitializeRegister imports it, and the seeded conditions are
 * evaluated by the engine's own condition check.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\ZgwDocumentDelivery;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
class CaseSystemDeliverySeedTest extends TestCase {

	private const ROOT = __DIR__ . '/../../../';

	private const FRAGMENT = self::ROOT . 'lib/Settings/register.d/case-system-delivery.json';

	private const ZAAK = 'https://open-zaak.example.nl/zaken/api/v1/zaken/0f3b8c2e-9a41-4d6e-b5c7-1e2d3f4a5b6c';

	private const TYPE = 'https://open-zaak.example.nl/catalogi/api/v1/informatieobjecttypen/9b2c7d1e-3f4a-4b5c-8d6e-7f8091a2b3c4';

	/**
	 * A caseSystemDelivery as filinq writes it (generate-store-in-case-system D2, D6).
	 */
	private const DELIVERY = [
		'deliveryStatus' => 'ready_for_writeback',
		'titel' => 'Arbeidsovereenkomst R&D',
		'bestandsnaam' => 'arbeidsovereenkomst.pdf',
		'formaat' => 'application/pdf',
		'taal' => 'dut',
		'creatiedatum' => '2026-10-04T09:30:00+02:00',
		'auteur' => 'Jan de Vries',
		'informatieobjecttype' => self::TYPE,
		'vertrouwelijkheidaanduiding' => 'vertrouwelijk',
		'bronorganisatie' => '002220647',
		'zaakUrl' => self::ZAAK,
		'sourceId' => 'bridge-source-1',
	];

	/**
	 * An externalDocument released for write-back (zgw-document-bridge REQ-DDZGW-003/005).
	 */
	private const EXTERNAL = [
		'sourceId' => 'bridge-source-1',
		'externalId' => 'DOC-2026-0042',
		'zaakIdentificatie' => 'ZAAK-2026-0042',
		'title' => 'Besluit op bezwaar',
		'filename' => 'besluit.pdf',
		'format' => 'application/pdf',
		'creatiedatum' => '2026-09-01',
		'vertrouwelijkheidaanduiding' => 'openbaar',
		'processingStatus' => 'ready_for_writeback',
		'resultFileRef' => '4711',
	];

	/**
	 * The fragment's objects, keyed by schema and slug.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private static function seeds(): array {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true, 512, JSON_THROW_ON_ERROR);
		$seeds    = [];
		foreach ($fragment['components']['objects'] as $object) {
			$seeds[$object['@self']['schema']][$object['@self']['slug']] = $object;
		}

		return $seeds;
	}//end seeds()

	/**
	 * The real mapping engine.
	 *
	 * @return MappingService
	 */
	private function mappingService(): MappingService {
		return new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(OrObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);
	}//end mappingService()

	/**
	 * The mapped object for one seeded mapping.
	 *
	 * @param string $slug  The mapping slug.
	 * @param array  $input The source object.
	 *
	 * @return array
	 */
	private function map(string $slug, array $input): array {
		$mapping = self::seeds()['mapping'][$slug];
		unset($mapping['@self']);
		return $this->mappingService()->executeMapping(mapping: $mapping, input: $input);
	}//end map()

	/**
	 * The create body ZgwDocumentDelivery sends for a mapped object.
	 *
	 * @param array $document The mapped object.
	 *
	 * @return array
	 */
	private function createBody(array $document): array {
		$delivery = new ZgwDocumentDelivery(transport: $this->createMock(CallServiceCaseSystemTransport::class));
		return $delivery->createPayload(document: $document, content: 'PDF!!', inline: false);
	}//end createBody()

	/**
	 * Validation errors of a body against the Documenten API 1.4.2 create schema.
	 *
	 * @param array $body The body.
	 *
	 * @return array Errors, empty when valid.
	 */
	private static function createErrors(array $body): array {
		$schema         = json_decode((string)file_get_contents(self::ROOT . 'tests/fixtures/zgw/documenten-1.4.2.schema.json'), true);
		$schema['$ref'] = '#/$defs/EnkelvoudigInformatieObjectCreateLockRequest';

		$result = (new Validator())->validate(
			json_decode(json_encode($body, JSON_THROW_ON_ERROR)),
			json_encode($schema, JSON_THROW_ON_ERROR)
		);
		if ($result->isValid() === true) {
			return [];
		}

		return (new ErrorFormatter())->format($result->error());
	}//end createErrors()

	/**
	 * Whether the engine's condition check lets an object through.
	 *
	 * @param mixed $conditions The seeded conditions.
	 * @param array $object     The source object.
	 *
	 * @return bool
	 */
	private static function conditionsHold(mixed $conditions, array $object): bool {
		$check = new ReflectionMethod(SynchronizationService::class, 'conditionsHold');
		return $check->invoke(null, $conditions, $object);
	}//end conditionsHold()

	/**
	 * A fresh install seeds both synchronizations and both mappings, unbound and dormant.
	 *
	 * @return void
	 */
	public function testBothSynchronizationsAreSeededUnboundOnDormantSources(): void {
		$seeds = self::seeds();
		$this->assertSame(['case-system-delivery-to-zgw-document', 'redacted-document-to-zgw-document'], array_keys($seeds['mapping']));
		$this->assertSame(['filinq-case-system-delivery', 'filinq-redacted-writeback'], array_keys($seeds['synchronization']));

		$sets = json_decode((string)file_get_contents(self::ROOT . 'lib/Settings/register.d/zgw-consumer-sets.json'), true, 512, JSON_THROW_ON_ERROR);
		$sources = [];
		foreach ($sets['components']['objects'] as $object) {
			if ($object['@self']['schema'] === 'source') {
				$sources[$object['@self']['slug']] = $object;
			}
		}

		$mappings = [
			'filinq-case-system-delivery' => 'case-system-delivery-to-zgw-document',
			'filinq-redacted-writeback' => 'redacted-document-to-zgw-document',
		];
		foreach ($seeds['synchronization'] as $slug => $synchronization) {
			$this->assertSame('', $synchronization['sourceId'], $slug . ' must ship unbound');
			$this->assertSame('register/schema', $synchronization['sourceType'], $slug);
			$this->assertSame($mappings[$slug], $synchronization['sourceTargetMapping'], $slug);
			$this->assertSame('api', $synchronization['targetType'], $slug);
			$this->assertFalse($sources[$synchronization['targetId']]['isEnabled'], $slug . ' targets a dormant source');
			$zakenSource = $synchronization['targetConfig']['zgwDocument']['zakenSource'];
			$this->assertFalse($sources[$zakenSource]['isEnabled'], $slug . ' relates on a dormant source');
		}
	}//end testBothSynchronizationsAreSeededUnboundOnDormantSources()

	/**
	 * Every seed is an object the integriq register accepts.
	 *
	 * @return void
	 */
	public function testEverySeedIsAcceptedByTheRegister(): void {
		foreach (self::seeds() as $schema => $objects) {
			foreach ($objects as $slug => $object) {
				unset($object['@self']);
				$this->assertSame([], RegisterSchemaValidator::errors($schema, $object), $schema . ' ' . $slug);
			}
		}
	}//end testEverySeedIsAcceptedByTheRegister()

	/**
	 * A delivery maps onto a create the Documenten API accepts, with its case and nothing escaped.
	 *
	 * @return void
	 */
	public function testADeliveryMapsOntoAValidDocumentCreate(): void {
		$document = $this->map(slug: 'case-system-delivery-to-zgw-document', input: self::DELIVERY);

		$this->assertSame(self::ZAAK, $document['zaakUrl']);
		$this->assertSame('Arbeidsovereenkomst R&D', $document['titel']);
		$this->assertSame('2026-10-04', $document['creatiedatum']);

		$body = $this->createBody(document: $document);
		$this->assertSame([], self::createErrors(body: $body));
		$this->assertSame(self::TYPE, $body['informatieobjecttype']);
		$this->assertSame('vertrouwelijk', $body['vertrouwelijkheidaanduiding']);
		$this->assertSame(5, $body['bestandsomvang']);
		$this->assertArrayNotHasKey('zaakUrl', $body);
	}//end testADeliveryMapsOntoAValidDocumentCreate()

	/**
	 * A delivery without a case maps to an empty zaakUrl, never to the field name.
	 *
	 * @return void
	 */
	public function testADeliveryWithoutACaseHasNoCaseUrl(): void {
		$input = self::DELIVERY;
		unset($input['zaakUrl'], $input['creatiedatum']);

		$document = $this->map(slug: 'case-system-delivery-to-zgw-document', input: $input);

		$this->assertSame('', $document['zaakUrl']);
		$this->assertSame('', $document['creatiedatum']);
		$this->assertArrayNotHasKey('creatiedatum', $this->createBody(document: $document));
	}//end testADeliveryWithoutACaseHasNoCaseUrl()

	/**
	 * A redacted copy is titled (geanonimiseerd), names its original, and carries its redacted file.
	 *
	 * @return void
	 */
	public function testARedactedCopyIsMarkedAndNamesItsOriginal(): void {
		$document = $this->map(slug: 'redacted-document-to-zgw-document', input: self::EXTERNAL);

		$this->assertSame('Besluit op bezwaar (geanonimiseerd)', $document['titel']);
		$this->assertStringContainsString('DOC-2026-0042', $document['beschrijving']);
		$this->assertSame('4711', $document['resultFileRef']);
		$this->assertSame('besluit.pdf', $document['bestandsnaam']);
		$this->assertSame('', $document['zaakUrl']);

		$synchronization = self::seeds()['synchronization']['filinq-redacted-writeback'];
		$this->assertSame('resultFileRef', $synchronization['targetConfig']['zgwDocument']['fileIdField']);

		// What an externalDocument does not carry, the administrator fills in the mapping.
		$withDestination = $this->map(
			slug: 'redacted-document-to-zgw-document',
			input: self::EXTERNAL + ['bronorganisatie' => '002220647', 'auteur' => 'Filinq', 'taal' => 'dut', 'informatieobjecttype' => self::TYPE]
		);
		$body = $this->createBody(document: $withDestination);
		$this->assertSame([], self::createErrors(body: $body));
		$this->assertArrayNotHasKey('resultFileRef', $body);
	}//end testARedactedCopyIsMarkedAndNamesItsOriginal()

	/**
	 * Only an object in ready_for_writeback is pushed; the write-back lands in the source's own state field.
	 *
	 * @return void
	 */
	public function testOnlyAnObjectReadyForWriteBackIsPushed(): void {
		$cases = [
			'filinq-case-system-delivery' => ['deliveryStatus', self::DELIVERY],
			'filinq-redacted-writeback' => ['processingStatus', self::EXTERNAL],
		];
		foreach ($cases as $slug => [$field, $object]) {
			$synchronization = self::seeds()['synchronization'][$slug];
			$conditions      = $synchronization['conditions'];

			$this->assertTrue(self::conditionsHold(conditions: $conditions, object: $object), $slug);
			foreach (['staged', 'written_back', 'writeback_failed'] as $state) {
				$this->assertFalse(self::conditionsHold(conditions: $conditions, object: [$field => $state] + $object), $slug . ' ' . $state);
			}

			$this->assertSame('written_back', $synchronization['writeBack']['onSuccess'][$field], $slug);
			$this->assertSame('{{ response.url }}', $synchronization['writeBack']['onSuccess']['resultExternalId'], $slug);
			$this->assertSame('writeback_failed', $synchronization['writeBack']['onFailure'][$field], $slug);
			$this->assertSame('{{ error.message }}', $synchronization['writeBack']['onFailure']['writeBackError'], $slug);
		}
	}//end testOnlyAnObjectReadyForWriteBackIsPushed()
}//end class
