<?php

/**
 * Unit tests for ZgwRegistrationLeg (form-submission-registration-targets D4).
 *
 * The case-system transport is the double: it stands for the Zaken API.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registration;

use DateTime;
use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\CaseSystem\DocumentDeliveryRun;
use OCA\Integriq\Service\CaseSystem\DocumentFile;
use OCA\Integriq\Service\CaseSystem\ZgwDocumentDelivery;
use OCA\Integriq\Service\Registration\RegistrationFiles;
use OCA\Integriq\Service\Registration\RegistrationRefusal;
use OCA\Integriq\Service\Registration\ZgwRegistrationLeg;
use OCA\Integriq\Service\Registration\ZgwCaseLookup;
use OCA\Integriq\Service\Registration\ZgwRegistrationRun;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ZgwRegistrationLeg::class)]
#[UsesClass(ZgwRegistrationRun::class)]
#[UsesClass(ZgwCaseLookup::class)]
#[UsesClass(RegistrationRefusal::class)]
#[UsesClass(ZgwDocumentDelivery::class)]
#[UsesClass(DocumentDeliveryRun::class)]
#[UsesClass(DocumentFile::class)]
#[UsesClass(CaseSystemRefusal::class)]
class ZgwRegistrationLegTest extends TestCase {

	private const ZAAK = 'https://zaken.example/api/v1/zaken/z-1';

	private const TARGET = [
		'kind' => 'zgw',
		'sources' => ['zaken' => 'src-zaken', 'documenten' => 'src-docs'],
		'zgw' => [
			'zaaktype' => 'https://catalogi.example/api/v1/zaaktypen/zt-1',
			'bronorganisatie' => '002220647',
			'initiatorRoltype' => 'https://catalogi.example/api/v1/roltypen/rt-1',
		],
	];

	private const HANDOVER = [
		'reference' => 'OF-7Q2K',
		'app' => 'portaliq',
		'form' => ['slug' => 'melding', 'title' => 'Melding openbare ruimte'],
		'applicant' => ['kind' => 'bsn', 'value' => '999990627'],
		'answers' => ['onderwerp' => 'losse tegel'],
	];

	/**
	 * The transport double.
	 *
	 * @var CallServiceCaseSystemTransport&MockObject
	 */
	private CallServiceCaseSystemTransport&MockObject $transport;

	/**
	 * Every call the transport received: source, method, address, options.
	 *
	 * @var list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * The GETs sent (the kenmerk lookup and the initiator check), as [source, method, address, options].
	 *
	 * @var array<int,array{0:string,1:string,2:string,3:array<string,mixed>}>
	 */
	private array $reads = [];

	/**
	 * The answers to the GETs, in order; an empty list once they run out.
	 *
	 * @var array<int,array{status:int,data:mixed,raw:string}>
	 */
	private array $readAnswers = [];

	/**
	 * The leg over a doubled transport that answers each call from the list.
	 *
	 * @param list<array{status: int, data: mixed, raw: string}> $answers The answers, in call order.
	 *
	 * @return ZgwRegistrationLeg
	 */
	private function leg(array $answers, ?RegistrationFiles $files = null): ZgwRegistrationLeg {
		$this->transport = $this->createMock(CallServiceCaseSystemTransport::class);
		$this->transport->method('send')->willReturnCallback(
			function (string $sourceId, string $method, string $address, array $options = []) use (&$answers): array {
				if ($method === 'GET' && in_array(strtok($address, '?'), ['/zaken', '/rollen', 'https://zaken.example/api/v1/zaken'], true) === true) {
					$this->reads[] = [$sourceId, $method, $address, $options];
					return array_shift($this->readAnswers) ?? self::listed([]);
				}

				$this->calls[] = [$sourceId, $method, $address, $options];
				return array_shift($answers) ?? ['status' => 500, 'data' => null, 'raw' => ''];
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14 10:00:00'));

		if ($files === null) {
			return new ZgwRegistrationLeg($this->transport, $time);
		}

		return new ZgwRegistrationLeg($this->transport, $time, new ZgwDocumentDelivery($this->transport), $files);
	}//end leg()

	/**
	 * A file reader double that answers each file id with a named file, and records who read it.
	 *
	 * @param list<array{0: string, 1: int}> $reads Filled with the app and file id of every read.
	 *
	 * @return RegistrationFiles&MockObject
	 */
	private function files(array &$reads): RegistrationFiles&MockObject {
		$files = $this->createMock(RegistrationFiles::class);
		$files->method('read')->willReturnCallback(
			static function (string $app, array $file) use (&$reads): array {
				$reads[] = [$app, (int)$file['fileId']];
				return ['content' => 'bytes-' . $file['fileId'], 'name' => (string)($file['name'] ?? 'inzending.pdf'), 'mimeType' => 'application/pdf'];
			}
		);

		return $files;
	}//end files()

	/**
	 * The target with a Documenten source and informatieobjecttypen.
	 *
	 * @return array<string, mixed>
	 */
	private static function documentTarget(): array {
		$target = self::TARGET;
		$target['zgw']['informatieobjecttypePdf']        = 'https://catalogi.example/iot/pdf';
		$target['zgw']['informatieobjecttypeAttachment'] = 'https://catalogi.example/iot/bijlage';
		$target['zgw']['vertrouwelijkheidaanduiding']    = 'zaakvertrouwelijk';

		return $target;
	}//end documentTarget()

	/**
	 * A created answer.
	 *
	 * @param array<string, mixed> $data The created object.
	 *
	 * @return array{status: int, data: mixed, raw: string}
	 */
	private static function created(array $data): array {
		return ['status' => 201, 'data' => $data, 'raw' => ''];
	}//end created()

	/**
	 * A list answer of the Zaken API.
	 *
	 * @param array<int,array<string,mixed>> $results The listed objects.
	 * @param string|null                    $next    The next page.
	 *
	 * @return array{status: int, data: mixed, raw: string}
	 */
	private static function listed(array $results, ?string $next = null): array {
		return ['status' => 200, 'data' => ['count' => count($results), 'next' => $next, 'results' => $results], 'raw' => ''];
	}//end listed()

	public function testACaseAndItsInitiatorAreCreatedInThatOrder(): void {
		$leg = $this->leg([self::created(['url' => self::ZAAK, 'identificatie' => 'ZAAK-2026-0001']), self::created(['url' => 'https://zaken.example/rollen/r-1'])]);

		$result = $leg->register(target: self::TARGET, mapped: ['toelichting' => 'losse tegel'], handover: self::HANDOVER, steps: []);

		self::assertSame('ZAAK-2026-0001', $result['externalReference']);
		self::assertSame(self::ZAAK, $result['externalUrl']);
		self::assertSame(
			[['step' => 'zaak', 'url' => self::ZAAK, 'reference' => 'ZAAK-2026-0001'], ['step' => 'rol']],
			$result['steps']
		);
		self::assertCount(2, $this->calls);

		[$source, $method, $address, $options] = $this->calls[0];
		self::assertSame(['src-zaken', 'POST', '/zaken'], [$source, $method, $address]);
		self::assertSame(
			[
				'toelichting' => 'losse tegel',
				'omschrijving' => 'Melding openbare ruimte',
				'zaaktype' => 'https://catalogi.example/api/v1/zaaktypen/zt-1',
				'bronorganisatie' => '002220647',
				'verantwoordelijkeOrganisatie' => '002220647',
				'kenmerken' => [['kenmerk' => 'OF-7Q2K', 'bron' => 'portaliq']],
				'registratiedatum' => '2026-11-14',
				'startdatum' => '2026-11-14',
			],
			$options['json']
		);
		self::assertSame(['Accept-Crs' => 'EPSG:4326', 'Content-Crs' => 'EPSG:4326'], $options['headers']);

		self::assertSame(['src-zaken', 'POST', '/rollen'], array_slice($this->calls[1], 0, 3));
		self::assertSame(
			[
				'zaak' => self::ZAAK,
				'betrokkeneType' => 'natuurlijk_persoon',
				'omschrijvingGeneriek' => 'initiator',
				'roltoelichting' => 'Indiener',
				'betrokkeneIdentificatie' => ['inpBsn' => '999990627'],
				'roltype' => 'https://catalogi.example/api/v1/roltypen/rt-1',
			],
			$this->calls[1][3]['json']
		);
	}//end testACaseAndItsInitiatorAreCreatedInThatOrder()

	public function testAKvkApplicantIsANietNatuurlijkPersoonWithTheKvkNumber(): void {
		$leg = $this->leg([self::created(['url' => self::ZAAK, 'identificatie' => 'ZAAK-1']), self::created([])]);

		$leg->register(target: self::TARGET, mapped: [], handover: ['applicant' => ['kind' => 'kvk', 'value' => '69599084']] + self::HANDOVER, steps: []);

		self::assertSame('niet_natuurlijk_persoon', $this->calls[1][3]['json']['betrokkeneType']);
		self::assertSame(['kvkNummer' => '69599084'], $this->calls[1][3]['json']['betrokkeneIdentificatie']);
	}//end testAKvkApplicantIsANietNatuurlijkPersoonWithTheKvkNumber()

	public function testWithoutAnApplicantNoRoleIsCreated(): void {
		$leg = $this->leg([self::created(['url' => self::ZAAK, 'identificatie' => 'ZAAK-1'])]);

		$result = $leg->register(target: self::TARGET, mapped: [], handover: ['applicant' => []] + self::HANDOVER, steps: []);

		self::assertCount(1, $this->calls);
		self::assertSame([['step' => 'zaak', 'url' => self::ZAAK, 'reference' => 'ZAAK-1']], $result['steps']);
	}//end testWithoutAnApplicantNoRoleIsCreated()

	public function testAResumedRegistrationDoesNotCreateTheCaseAgain(): void {
		$leg   = $this->leg([self::created([])]);
		$steps = [['step' => 'zaak', 'url' => self::ZAAK, 'reference' => 'ZAAK-1']];

		$result = $leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: $steps);

		self::assertCount(1, $this->calls);
		self::assertSame('/rollen', $this->calls[0][2]);
		self::assertSame('ZAAK-1', $result['externalReference']);
		self::assertSame([...$steps, ['step' => 'rol']], $result['steps']);
	}//end testAResumedRegistrationDoesNotCreateTheCaseAgain()

	public function testAFinishedRegistrationMakesNoCall(): void {
		$leg   = $this->leg([]);
		$steps = [['step' => 'zaak', 'url' => self::ZAAK, 'reference' => 'ZAAK-1'], ['step' => 'rol']];

		$result = $leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: $steps);

		self::assertSame([], $this->calls);
		self::assertSame($steps, $result['steps']);
	}//end testAFinishedRegistrationMakesNoCall()

	public function testZaakeigenschappenAreAddedOnceEach(): void {
		$target = self::TARGET;
		$target['zgw']['zaakeigenschappen'] = ['https://catalogi.example/eigenschappen/e-1' => 'tegel', 'https://catalogi.example/eigenschappen/e-2' => 'stoep'];
		$leg    = $this->leg([self::created([])]);
		$steps  = [['step' => 'zaak', 'url' => self::ZAAK, 'reference' => 'ZAAK-1'], ['step' => 'rol'], ['step' => 'zaakeigenschap:https://catalogi.example/eigenschappen/e-1']];

		$result = $leg->register(target: $target, mapped: [], handover: self::HANDOVER, steps: $steps);

		self::assertCount(1, $this->calls);
		self::assertSame(self::ZAAK . '/zaakeigenschappen', $this->calls[0][2]);
		self::assertSame(['zaak' => self::ZAAK, 'eigenschap' => 'https://catalogi.example/eigenschappen/e-2', 'waarde' => 'stoep'], $this->calls[0][3]['json']);
		self::assertSame(['step' => 'zaakeigenschap:https://catalogi.example/eigenschappen/e-2'], $result['steps'][3]);
	}//end testZaakeigenschappenAreAddedOnceEach()

	public function testAnIncompleteTargetIsRefusedBeforeAnyCall(): void {
		$target = self::TARGET;
		unset($target['zgw']['zaaktype']);
		$leg = $this->leg([]);

		try {
			$leg->register(target: $target, mapped: [], handover: self::HANDOVER, steps: []);
			self::fail('An incomplete target was registered at.');
		} catch (RegistrationRefusal $e) {
			self::assertSame(RegistrationRefusal::SOURCE_UNAVAILABLE, $e->getReason());
			self::assertFalse($e->isRetryable());
		}

		self::assertSame([], $this->calls);
	}//end testAnIncompleteTargetIsRefusedBeforeAnyCall()

	public function testAHandOverWithFilesIsRefusedBeforeAnyCall(): void {
		$leg = $this->leg([]);

		try {
			$leg->register(target: self::TARGET, mapped: [], handover: ['attachments' => [['fileId' => 12]]] + self::HANDOVER, steps: []);
			self::fail('Files were silently dropped.');
		} catch (RegistrationRefusal $e) {
			self::assertSame(RegistrationRefusal::SOURCE_UNAVAILABLE, $e->getReason());
			self::assertSame('Sending files to a ZGW target is not available on this instance.', $e->getMessage());
		}

		self::assertSame([], $this->calls);
	}//end testAHandOverWithFilesIsRefusedBeforeAnyCall()

	public function testTwoAttachmentsMakeThreeDocumentsAndThreeLinksAfterTheCaseAndRole(): void {
		$reads   = [];
		$answers = [self::created(['url' => self::ZAAK, 'identificatie' => 'ZAAK-1']), self::created([])];
		foreach (['d-1', 'd-2', 'd-3'] as $id) {
			$answers[] = self::created(['url' => 'https://docs.example/eio/' . $id]);
			$answers[] = self::created(['url' => 'https://zaken.example/zio/' . $id]);
		}

		$leg      = $this->leg($answers, $this->files($reads));
		$handover = ['app' => 'portaliq', 'pdf' => ['fileId' => 1234], 'attachments' => [['fileId' => 1235, 'name' => 'kaart.pdf'], ['fileId' => 1236, 'name' => 'foto.pdf']]] + self::HANDOVER;

		$result = $leg->register(target: self::documentTarget(), mapped: [], handover: $handover, steps: []);

		self::assertSame(
			[
				['src-zaken', 'POST', '/zaken'],
				['src-zaken', 'POST', '/rollen'],
				['src-docs', 'POST', '/enkelvoudiginformatieobjecten'],
				['src-zaken', 'POST', '/zaakinformatieobjecten'],
				['src-docs', 'POST', '/enkelvoudiginformatieobjecten'],
				['src-zaken', 'POST', '/zaakinformatieobjecten'],
				['src-docs', 'POST', '/enkelvoudiginformatieobjecten'],
				['src-zaken', 'POST', '/zaakinformatieobjecten'],
			],
			array_map(static fn (array $call): array => array_slice($call, 0, 3), $this->calls)
		);
		self::assertSame([['portaliq', 1234], ['portaliq', 1235], ['portaliq', 1236]], $reads);
		self::assertSame(
			[
				'bronorganisatie' => '002220647',
				'creatiedatum' => '2026-11-14',
				'titel' => 'Melding openbare ruimte',
				'auteur' => 'portaliq',
				'taal' => 'dut',
				'formaat' => 'application/pdf',
				'bestandsnaam' => 'inzending.pdf',
				'informatieobjecttype' => 'https://catalogi.example/iot/pdf',
				'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
				'bestandsomvang' => 10,
				'inhoud' => null,
			],
			$this->calls[2][3]['json']
		);
		self::assertSame('kaart.pdf', $this->calls[4][3]['json']['titel']);
		self::assertSame('https://catalogi.example/iot/bijlage', $this->calls[4][3]['json']['informatieobjecttype']);
		self::assertSame(['informatieobject' => 'https://docs.example/eio/d-1', 'zaak' => self::ZAAK, 'titel' => 'Melding openbare ruimte'], $this->calls[3][3]['json']);
		self::assertSame(
			['step' => 'document:1236', 'url' => 'https://docs.example/eio/d-3', 'zaakinformatieobject' => 'https://zaken.example/zio/d-3'],
			$result['steps'][4]
		);
		self::assertSame('ZAAK-1', $result['externalReference']);
	}//end testTwoAttachmentsMakeThreeDocumentsAndThreeLinksAfterTheCaseAndRole()

	public function testAResumedRegistrationCreatesOnlyTheMissingDocuments(): void {
		$reads = [];
		$leg   = $this->leg([self::created(['url' => 'https://docs.example/eio/d-2']), self::created(['url' => 'https://zaken.example/zio/d-2'])], $this->files($reads));
		$steps = [
			['step' => 'zaak', 'url' => self::ZAAK, 'reference' => 'ZAAK-1'],
			['step' => 'rol'],
			['step' => 'document:1234', 'url' => 'https://docs.example/eio/d-1', 'zaakinformatieobject' => 'https://zaken.example/zio/d-1'],
		];

		$result = $leg->register(target: self::documentTarget(), mapped: [], handover: ['pdf' => ['fileId' => 1234], 'attachments' => [['fileId' => 1235]]] + self::HANDOVER, steps: $steps);

		self::assertSame([['portaliq', 1235]], $reads);
		self::assertSame(['/enkelvoudiginformatieobjecten', '/zaakinformatieobjecten'], array_column($this->calls, 2));
		self::assertSame('ZAAK-1', $result['externalReference']);
		self::assertSame('document:1235', $result['steps'][3]['step']);
	}//end testAResumedRegistrationCreatesOnlyTheMissingDocuments()

	public function testATargetWithoutItsDocumentenSourceOrTypeIsRefusedBeforeAnyCall(): void {
		$withoutSource = self::documentTarget();
		unset($withoutSource['sources']['documenten']);
		$withoutType = self::documentTarget();
		unset($withoutType['zgw']['informatieobjecttypeAttachment']);
		$reads = [];

		foreach ([[$withoutSource, 'Documenten API source'], [$withoutType, 'informatieobjecttype']] as [$target, $missing]) {
			$leg = $this->leg([], $this->files($reads));
			try {
				$leg->register(target: $target, mapped: [], handover: ['pdf' => ['fileId' => 1234]] + self::HANDOVER, steps: []);
				self::fail('Files were sent to an incomplete target.');
			} catch (RegistrationRefusal $e) {
				self::assertSame(RegistrationRefusal::SOURCE_UNAVAILABLE, $e->getReason());
				self::assertFalse($e->isRetryable());
				self::assertStringContainsString($missing, $e->getMessage());
			}
		}

		self::assertSame([], $this->calls);
		self::assertSame([], $reads);
	}//end testATargetWithoutItsDocumentenSourceOrTypeIsRefusedBeforeAnyCall()

	public function testARefusedDocumentKeepsTheStatusAndRemovesTheOrphan(): void {
		$reads   = [];
		$answers = [
			self::created(['url' => 'https://docs.example/eio/d-1']),
			['status' => 503, 'data' => ['detail' => 'onderhoud'], 'raw' => ''],
			['status' => 204, 'data' => null, 'raw' => ''],
		];
		$leg     = $this->leg($answers, $this->files($reads));
		$steps   = [['step' => 'zaak', 'url' => self::ZAAK, 'reference' => 'ZAAK-1'], ['step' => 'rol']];

		try {
			$leg->register(target: self::documentTarget(), mapped: [], handover: ['pdf' => ['fileId' => 1234]] + self::HANDOVER, steps: $steps);
			self::fail('A refused link was taken for a registered document.');
		} catch (RegistrationRefusal $e) {
			self::assertSame(RegistrationRefusal::SOURCE_UNAVAILABLE, $e->getReason());
			self::assertTrue($e->isRetryable());
		}

		self::assertSame(['POST', 'POST', 'DELETE'], array_column($this->calls, 1));
		self::assertSame('https://docs.example/eio/d-1', $this->calls[2][2]);
	}//end testARefusedDocumentKeepsTheStatusAndRemovesTheOrphan()

	public function testARefusedCaseIsOutsideRefusedWithTheApisDetail(): void {
		$leg = $this->leg([['status' => 400, 'data' => ['detail' => 'zaaktype is niet geldig'], 'raw' => '']]);

		try {
			$leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: []);
			self::fail('A refused case was registered.');
		} catch (RegistrationRefusal $e) {
			self::assertSame(RegistrationRefusal::OUTSIDE_REFUSED, $e->getReason());
			self::assertFalse($e->isRetryable());
			self::assertSame('The ZGW source answered 400. zaaktype is niet geldig', $e->getMessage());
		}
	}//end testARefusedCaseIsOutsideRefusedWithTheApisDetail()

	public function testAnUnavailableSourceIsRetryable(): void {
		$leg = $this->leg([['status' => 503, 'data' => null, 'raw' => '']]);

		try {
			$leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: []);
			self::fail('An unavailable source registered.');
		} catch (RegistrationRefusal $e) {
			self::assertSame(RegistrationRefusal::SOURCE_UNAVAILABLE, $e->getReason());
			self::assertTrue($e->isRetryable());
		}
	}//end testAnUnavailableSourceIsRetryable()

	public function testAMissingSourceIsNotRetryable(): void {
		$this->transport = $this->createMock(CallServiceCaseSystemTransport::class);
		$this->transport->method('send')->willThrowException(new CaseSystemRefusal(status: 404, message: 'No source src-zaken.'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14'));
		$leg = new ZgwRegistrationLeg($this->transport, $time);

		try {
			$leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: []);
			self::fail('A missing source registered.');
		} catch (RegistrationRefusal $e) {
			self::assertSame(RegistrationRefusal::SOURCE_UNAVAILABLE, $e->getReason());
			self::assertFalse($e->isRetryable());
			self::assertSame('No source src-zaken.', $e->getMessage());
		}
	}//end testAMissingSourceIsNotRetryable()

	public function testACaseWithoutAUrlIsRefused(): void {
		$leg = $this->leg([self::created(['identificatie' => 'ZAAK-1'])]);

		$this->expectException(RegistrationRefusal::class);
		$this->expectExceptionMessage('The Zaken API created the case but named no url.');

		$leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: []);
	}//end testACaseWithoutAUrlIsRefused()

	public function testTheKindIsZgw(): void {
		self::assertSame('zgw', $this->leg([])->kind());
	}//end testTheKindIsZgw()
	/**
	 * Without a zaak step the cases of the zaaktype and bronorganisatie since the submission day are asked first.
	 *
	 * @return void
	 */
	public function testTheLookupAsksTheCasesOfTheZaaktypeSinceTheSubmissionDay(): void {
		$leg = $this->leg([self::created(['url' => self::ZAAK, 'identificatie' => 'ZAAK-1']), self::created([])]);

		$leg->register(target: self::TARGET, mapped: [], handover: ['submittedAt' => '2026-11-10T09:30:00+01:00'] + self::HANDOVER, steps: []);

		self::assertCount(1, $this->reads);
		self::assertSame(['src-zaken', 'GET', '/zaken'], array_slice($this->reads[0], 0, 3));
		self::assertSame(
			[
				'zaaktype' => 'https://catalogi.example/api/v1/zaaktypen/zt-1',
				'bronorganisatie' => '002220647',
				'startdatum__gte' => '2026-11-10',
			],
			$this->reads[0][3]['query']
		);
		self::assertSame(['POST', 'POST'], array_column($this->calls, 1));
	}//end testTheLookupAsksTheCasesOfTheZaaktypeSinceTheSubmissionDay()

	/**
	 * Without a submission time the lookup goes thirty days back.
	 *
	 * @return void
	 */
	public function testWithoutASubmissionTimeTheLookupGoesThirtyDaysBack(): void {
		$leg = $this->leg([self::created(['url' => self::ZAAK, 'identificatie' => 'ZAAK-1']), self::created([])]);

		$result = $leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: []);

		self::assertSame('2026-10-15', $this->reads[0][3]['query']['startdatum__gte']);
		self::assertSame('2026-11-14', $this->calls[0][3]['json']['startdatum']);
		self::assertSame('ZAAK-1', $result['externalReference']);
	}//end testWithoutASubmissionTimeTheLookupGoesThirtyDaysBack()

	/**
	 * A case carrying the reference from this app is reused, and its initiator is not added twice.
	 *
	 * @return void
	 */
	public function testACaseFoundByKenmerkIsReusedWithItsInitiator(): void {
		$this->readAnswers = [
			self::listed([
				['url' => 'https://zaken.example/api/v1/zaken/other', 'identificatie' => 'ZAAK-0', 'kenmerken' => [['kenmerk' => 'OF-7Q2K', 'bron' => 'anotherapp']]],
				['url' => self::ZAAK, 'identificatie' => 'ZAAK-7', 'kenmerken' => [['kenmerk' => 'X', 'bron' => 'y'], ['kenmerk' => 'OF-7Q2K', 'bron' => 'portaliq']]],
			]),
			self::listed([['url' => 'https://zaken.example/api/v1/rollen/r-1']]),
		];
		$leg = $this->leg([]);

		$result = $leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: []);

		self::assertSame([], $this->calls);
		self::assertSame('ZAAK-7', $result['externalReference']);
		self::assertSame(self::ZAAK, $result['externalUrl']);
		self::assertSame([['step' => 'zaak', 'url' => self::ZAAK, 'reference' => 'ZAAK-7', 'found' => true], ['step' => 'rol']], $result['steps']);
		self::assertSame(['src-zaken', 'GET', '/rollen'], array_slice($this->reads[1], 0, 3));
		self::assertSame(['zaak' => self::ZAAK, 'omschrijvingGeneriek' => 'initiator'], $this->reads[1][3]['query']);
	}//end testACaseFoundByKenmerkIsReusedWithItsInitiator()

	/**
	 * A found case without an initiator gets one.
	 *
	 * @return void
	 */
	public function testAFoundCaseWithoutAnInitiatorGetsOne(): void {
		$this->readAnswers = [
			self::listed([['url' => self::ZAAK, 'identificatie' => 'ZAAK-7', 'kenmerken' => [['kenmerk' => 'OF-7Q2K', 'bron' => 'portaliq']]]]),
			self::listed([]),
		];
		$leg = $this->leg([self::created(['url' => 'https://zaken.example/api/v1/rollen/r-2'])]);

		$leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: []);

		self::assertSame([['src-zaken', 'POST', '/rollen']], array_map(static fn (array $call): array => array_slice($call, 0, 3), $this->calls));
		self::assertSame(self::ZAAK, $this->calls[0][3]['json']['zaak']);
	}//end testAFoundCaseWithoutAnInitiatorGetsOne()

	/**
	 * The lookup follows the next page, without repeating the query the link already carries.
	 *
	 * @return void
	 */
	public function testTheLookupFollowsTheNextPage(): void {
		$next = 'https://zaken.example/api/v1/zaken?page=2';
		$this->readAnswers = [
			self::listed([['url' => 'https://zaken.example/api/v1/zaken/a', 'kenmerken' => []]], $next),
			self::listed([['url' => self::ZAAK, 'identificatie' => 'ZAAK-9', 'kenmerken' => [['kenmerk' => 'OF-7Q2K', 'bron' => 'portaliq']]]]),
		];
		$leg = $this->leg([]);

		$result = $leg->register(target: self::TARGET, mapped: [], handover: ['applicant' => []] + self::HANDOVER, steps: []);

		self::assertSame('ZAAK-9', $result['externalReference']);
		self::assertSame($next, $this->reads[1][2]);
		self::assertArrayNotHasKey('query', $this->reads[1][3]);
		self::assertSame([], $this->calls);
	}//end testTheLookupFollowsTheNextPage()

	/**
	 * The lookup reads at most ten pages before it creates the case.
	 *
	 * @return void
	 */
	public function testTheLookupStopsAfterTenPages(): void {
		$next = 'https://zaken.example/api/v1/zaken?page=n';
		$this->readAnswers = array_fill(0, 12, self::listed([], $next));
		$leg = $this->leg([self::created(['url' => self::ZAAK, 'identificatie' => 'ZAAK-1'])]);

		$leg->register(target: self::TARGET, mapped: [], handover: ['applicant' => []] + self::HANDOVER, steps: []);

		self::assertCount(10, $this->reads);
		self::assertSame([['src-zaken', 'POST', '/zaken']], array_map(static fn (array $call): array => array_slice($call, 0, 3), $this->calls));
	}//end testTheLookupStopsAfterTenPages()

	/**
	 * A recorded case is never looked up again.
	 *
	 * @return void
	 */
	public function testARecordedCaseIsNotLookedUp(): void {
		$leg = $this->leg([]);

		$leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: [['step' => 'zaak', 'url' => self::ZAAK, 'reference' => 'ZAAK-1'], ['step' => 'rol']]);

		self::assertSame([], $this->reads);
		self::assertSame([], $this->calls);
	}//end testARecordedCaseIsNotLookedUp()

	/**
	 * A Zaken API that cannot list is source-unavailable and retryable, and no case is created.
	 *
	 * @return void
	 */
	public function testAFailingLookupCreatesNothing(): void {
		$this->readAnswers = [['status' => 503, 'data' => ['detail' => 'onderhoud'], 'raw' => '']];
		$leg = $this->leg([self::created(['url' => self::ZAAK, 'identificatie' => 'ZAAK-1'])]);

		try {
			$leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: []);
			self::fail('A failed lookup created a case.');
		} catch (RegistrationRefusal $e) {
			self::assertSame([RegistrationRefusal::SOURCE_UNAVAILABLE, true], [$e->getReason(), $e->isRetryable()]);
			self::assertSame([], $e->getSteps());
		}

		self::assertSame([], $this->calls);
	}//end testAFailingLookupCreatesNothing()

	/**
	 * A refusal after the case carries the case and role, so the retry does not create them again.
	 *
	 * @return void
	 */
	public function testARefusalAfterTheCaseCarriesTheStepsDone(): void {
		$leg = $this->leg([self::created(['url' => self::ZAAK, 'identificatie' => 'ZAAK-1']), ['status' => 503, 'data' => null, 'raw' => '']]);

		try {
			$leg->register(target: self::TARGET, mapped: [], handover: self::HANDOVER, steps: []);
			self::fail('A refused role was taken for a created one.');
		} catch (RegistrationRefusal $e) {
			self::assertSame([['step' => 'zaak', 'url' => self::ZAAK, 'reference' => 'ZAAK-1']], $e->getSteps());
		}
	}//end testARefusalAfterTheCaseCarriesTheStepsDone()
}//end class
