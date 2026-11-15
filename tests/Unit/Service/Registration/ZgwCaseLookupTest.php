<?php

/**
 * Unit tests for ZgwCaseLookup: the outside check of design D5.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registration;

use DateTime;
use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\Registration\RegistrationRefusal;
use OCA\Integriq\Service\Registration\ZgwCaseLookup;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A case is reused only when it carries the submission reference from the handing-over app.
 */
#[CoversClass(ZgwCaseLookup::class)]
#[UsesClass(RegistrationRefusal::class)]
#[UsesClass(CaseSystemRefusal::class)]
class ZgwCaseLookupTest extends TestCase {

	private const SETTINGS = ['zaken' => 'src-zaken', 'zaaktype' => 'https://catalogi.example/zt-1', 'bronorganisatie' => '002220647'];

	private const ZAAK = 'https://zaken.example/api/v1/zaken/z-1';

	/**
	 * The calls sent, as [source, method, address, options].
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * A lookup over these answers.
	 *
	 * @param array<int,array{status:int,data:mixed,raw:string}> $answers The answers, in order.
	 *
	 * @return ZgwCaseLookup
	 */
	private function lookup(array $answers): ZgwCaseLookup {
		$transport = $this->createMock(CallServiceCaseSystemTransport::class);
		$transport->method('send')->willReturnCallback(
			function (string $sourceId, string $method, string $address, array $options = []) use (&$answers): array {
				$this->calls[] = [$sourceId, $method, $address, $options];
				return array_shift($answers) ?? ['status' => 200, 'data' => ['results' => []], 'raw' => ''];
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14 10:00:00'));

		return new ZgwCaseLookup($transport, $time);
	}//end lookup()

	/**
	 * A list answer.
	 *
	 * @param array<int,array<string,mixed>> $results The listed objects.
	 *
	 * @return array{status:int,data:mixed,raw:string}
	 */
	private static function listed(array $results): array {
		return ['status' => 200, 'data' => ['next' => null, 'results' => $results], 'raw' => ''];
	}//end listed()

	public function testAMatchingCaseIsAnsweredAsAFoundZaakStep(): void {
		$lookup = $this->lookup([self::listed([['url' => self::ZAAK, 'identificatie' => 'ZAAK-3', 'kenmerken' => [['kenmerk' => 'OF-1', 'bron' => 'portaliq']]]])]);

		$step = $lookup->existingCase(self::SETTINGS, ['reference' => 'OF-1', 'app' => 'portaliq', 'submittedAt' => '2026-11-01T08:00:00Z']);

		self::assertSame(['step' => 'zaak', 'url' => self::ZAAK, 'reference' => 'ZAAK-3', 'found' => true], $step);
		self::assertSame(['src-zaken', 'GET', '/zaken'], array_slice($this->calls[0], 0, 3));
		self::assertSame('2026-11-01', $this->calls[0][3]['query']['startdatum__gte']);
	}//end testAMatchingCaseIsAnsweredAsAFoundZaakStep()

	public function testTheSameReferenceFromAnotherAppOrWithoutAUrlIsNotReused(): void {
		$lookup = $this->lookup([self::listed([
			['url' => self::ZAAK, 'kenmerken' => [['kenmerk' => 'OF-1', 'bron' => 'anotherapp']]],
			['url' => '', 'kenmerken' => [['kenmerk' => 'OF-1', 'bron' => 'portaliq']]],
			['kenmerken' => [['kenmerk' => 'OF-1', 'bron' => 'portaliq']]],
			'not a case',
		])]);

		self::assertNull($lookup->existingCase(self::SETTINGS, ['reference' => 'OF-1', 'app' => 'portaliq']));
	}//end testTheSameReferenceFromAnotherAppOrWithoutAUrlIsNotReused()

	public function testAHandOverWithoutAnAppMatchesTheIntegriqBron(): void {
		$lookup = $this->lookup([self::listed([['url' => self::ZAAK, 'kenmerken' => [['kenmerk' => 'OF-1', 'bron' => 'integriq']]]])]);

		self::assertSame(self::ZAAK, $lookup->existingCase(self::SETTINGS, ['reference' => 'OF-1'])['url']);
	}//end testAHandOverWithoutAnAppMatchesTheIntegriqBron()

	public function testWithoutAReferenceNothingIsAsked(): void {
		$lookup = $this->lookup([]);

		self::assertNull($lookup->existingCase(self::SETTINGS, ['app' => 'portaliq']));
		self::assertSame([], $this->calls);
	}//end testWithoutAReferenceNothingIsAsked()

	public function testARefusedListIsOutsideRefusedAndNotRetryable(): void {
		$lookup = $this->lookup([['status' => 403, 'data' => ['detail' => 'geen lezen'], 'raw' => '']]);

		try {
			$lookup->existingCase(self::SETTINGS, ['reference' => 'OF-1']);
			self::fail('A refused list was taken for an empty one.');
		} catch (RegistrationRefusal $e) {
			self::assertSame([RegistrationRefusal::OUTSIDE_REFUSED, false], [$e->getReason(), $e->isRetryable()]);
			self::assertStringContainsString('geen lezen', $e->getMessage());
		}
	}//end testARefusedListIsOutsideRefusedAndNotRetryable()

	public function testAMissingSourceIsSourceUnavailableAndNotRetryable(): void {
		$transport = $this->createMock(CallServiceCaseSystemTransport::class);
		$transport->method('send')->willThrowException(new CaseSystemRefusal(status: 409, message: 'No source src-zaken.'));
		$lookup = new ZgwCaseLookup($transport, $this->createMock(ITimeFactory::class));

		try {
			$lookup->existingCase(self::SETTINGS, ['reference' => 'OF-1', 'submittedAt' => '2026-11-01']);
			self::fail('A missing source was taken for an empty list.');
		} catch (RegistrationRefusal $e) {
			self::assertSame([RegistrationRefusal::SOURCE_UNAVAILABLE, false, 'No source src-zaken.'], [$e->getReason(), $e->isRetryable(), $e->getMessage()]);
		}
	}//end testAMissingSourceIsSourceUnavailableAndNotRetryable()

	public function testACreatedCaseNeedsItsInitiatorWithoutAsking(): void {
		$lookup = $this->lookup([]);

		self::assertTrue($lookup->needsInitiator(self::SETTINGS, ['step' => 'zaak', 'url' => self::ZAAK]));
		self::assertSame([], $this->calls);
	}//end testACreatedCaseNeedsItsInitiatorWithoutAsking()

	public function testAFoundCaseNeedsAnInitiatorOnlyWhenItHasNone(): void {
		$lookup = $this->lookup([self::listed([]), self::listed([['url' => 'https://zaken.example/rollen/r-1']])]);
		$zaak   = ['step' => 'zaak', 'url' => self::ZAAK, 'found' => true];

		self::assertTrue($lookup->needsInitiator(self::SETTINGS, $zaak));
		self::assertFalse($lookup->needsInitiator(self::SETTINGS, $zaak));
		self::assertSame(['src-zaken', 'GET', '/rollen', ['query' => ['zaak' => self::ZAAK, 'omschrijvingGeneriek' => 'initiator']]], $this->calls[0]);
	}//end testAFoundCaseNeedsAnInitiatorOnlyWhenItHasNone()
}//end class
