<?php

/**
 * Unit tests for StufZdsRegistrationLeg (form-submission-registration-targets D4).
 *
 * The case client is the double: it stands for the StUF-ZDS case system.
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

use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\Registration\RegistrationRefusal;
use OCA\Integriq\Service\Registration\StufZdsRegistrationLeg;
use OCA\Integriq\Service\StufZkn\StufZdsCaseClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(StufZdsRegistrationLeg::class)]
#[UsesClass(RegistrationRefusal::class)]
class StufZdsRegistrationLegTest extends TestCase {

	private const TARGET = ['kind' => 'stuf-zds', 'sources' => ['zkn' => 'src-zkn'], 'stufZds' => ['zaaktypeCode' => 'Melding openbare ruimte']];

	private const HANDOVER = [
		'reference' => 'OF-7Q2K',
		'app' => 'portaliq',
		'form' => ['slug' => 'melding', 'title' => 'Melding'],
		'applicant' => ['kind' => 'bsn', 'value' => '999990627'],
		'answers' => ['onderwerp' => 'losse tegel'],
	];

	private StufZdsCaseClientInterface&MockObject $cases;

	/**
	 * The leg over a doubled case client.
	 *
	 * @return StufZdsRegistrationLeg
	 */
	private function leg(): StufZdsRegistrationLeg {
		$this->cases = $this->createMock(StufZdsCaseClientInterface::class);
		return new StufZdsRegistrationLeg($this->cases);
	}//end leg()

	/**
	 * A case client result.
	 *
	 * @param bool        $success  Whether the zaak was confirmed.
	 * @param string|null $zaak     The zaak identificatie.
	 * @param array|null  $fout     The fault.
	 * @param bool        $retrying Whether the outbound retries it.
	 *
	 * @return array
	 */
	private static function caseResult(bool $success, ?string $zaak = null, ?array $fout = null, bool $retrying = false): array {
		return ['success' => $success, 'retrying' => $retrying, 'referentienummer' => 'REF-1', 'messageId' => 'msg-1', 'caseIdentification' => $zaak, 'fout' => $fout];
	}//end caseResult()

	/**
	 * The refusal a register call throws.
	 *
	 * @param StufZdsRegistrationLeg $leg      The leg.
	 * @param array                  $target   The target.
	 * @param array                  $handover The hand-over.
	 *
	 * @return RegistrationRefusal
	 */
	private static function refusal(StufZdsRegistrationLeg $leg, array $target = self::TARGET, array $handover = self::HANDOVER): RegistrationRefusal {
		try {
			$leg->register($target, ['omschrijving' => 'Losse tegel'], $handover, []);
		} catch (RegistrationRefusal $refusal) {
			return $refusal;
		}

		self::fail('The registration should have been refused');
	}//end refusal()

	public function testAZaakIsCreatedWithTheApplicantAsInitiatorAndItsIdentificatieIsTheReference(): void {
		$leg = $this->leg();
		$this->cases->expects($this->once())->method('creeerZaak')
			->with(
				'src-zkn',
				['omschrijving' => 'Losse tegel', 'betrokkenen' => [['role' => 'heeftAlsInitiator', 'bsn' => '999990627']]],
				'Melding openbare ruimte',
				['correlationId' => 'OF-7Q2K', 'sourceApp' => 'portaliq']
			)
			->willReturn(self::caseResult(true, '0363-Z-12'));

		$result = $leg->register(self::TARGET, ['omschrijving' => 'Losse tegel'], self::HANDOVER, []);

		$this->assertSame('stuf-zds', $leg->kind());
		$this->assertSame('0363-Z-12', $result['externalReference']);
		$this->assertSame('', $result['externalUrl']);
		$this->assertSame([['step' => 'zaak', 'reference' => '0363-Z-12', 'referentienummer' => 'REF-1']], $result['steps']);
	}//end testAZaakIsCreatedWithTheApplicantAsInitiatorAndItsIdentificatieIsTheReference()

	public function testAnAnonymousSubmissionHasNoInitiatorAndTheFormTitleFillsAMissingOmschrijving(): void {
		$leg      = $this->leg();
		$handover = array_diff_key(self::HANDOVER, ['applicant' => 1, 'app' => 1]);
		$this->cases->expects($this->once())->method('creeerZaak')
			->with('src-zkn', ['startdatum' => '2026-10-08', 'omschrijving' => 'Melding'], 'Melding openbare ruimte', ['correlationId' => 'OF-7Q2K'])
			->willReturn(self::caseResult(true, 'Z-2'));

		$this->assertSame('Z-2', $leg->register(self::TARGET, ['startdatum' => '2026-10-08'], $handover, [])['externalReference']);
	}//end testAnAnonymousSubmissionHasNoInitiatorAndTheFormTitleFillsAMissingOmschrijving()

	public function testARepeatedHandOverReturnsTheZaakAlreadyCreatedWithoutAnotherMessage(): void {
		$leg   = $this->leg();
		$steps = [['step' => 'zaak', 'reference' => '0363-Z-12', 'referentienummer' => 'REF-1']];
		$this->cases->expects($this->never())->method('creeerZaak');

		$result = $leg->register(self::TARGET, [], self::HANDOVER, $steps);

		$this->assertSame(['0363-Z-12', $steps], [$result['externalReference'], $result['steps']]);
	}//end testARepeatedHandOverReturnsTheZaakAlreadyCreatedWithoutAnotherMessage()

	public function testAFo03IsOutsideRefusedWithTheFaultTextAndNotRetried(): void {
		$leg = $this->leg();
		$this->cases->method('creeerZaak')->willReturn(self::caseResult(false, null, ['code' => 'StUF058', 'omschrijving' => 'Zaaktype onbekend', 'details' => 'ZKT 12']));

		$refusal = self::refusal($leg);

		$this->assertSame([RegistrationRefusal::OUTSIDE_REFUSED, false, 'StUF058: Zaaktype onbekend ZKT 12'], [$refusal->getReason(), $refusal->isRetryable(), $refusal->getMessage()]);
	}//end testAFo03IsOutsideRefusedWithTheFaultTextAndNotRetried()

	public function testAnOpenCircuitOrARetryingMessageIsUnavailableAndRetryable(): void {
		foreach ([self::caseResult(false, null, ['code' => 'CIRCUIT_OPEN', 'omschrijving' => 'Paused', 'details' => '']), self::caseResult(false, null, ['code' => 'HTTP_503', 'omschrijving' => 'Down', 'details' => ''], true)] as $answer) {
			$leg = $this->leg();
			$this->cases->method('creeerZaak')->willReturn($answer);

			$refusal = self::refusal($leg);

			$this->assertSame([RegistrationRefusal::SOURCE_UNAVAILABLE, true], [$refusal->getReason(), $refusal->isRetryable()]);
		}
	}//end testAnOpenCircuitOrARetryingMessageIsUnavailableAndRetryable()

	public function testAConfirmationWithoutAZaakIdentificatieIsRefused(): void {
		$leg = $this->leg();
		$this->cases->method('creeerZaak')->willReturn(self::caseResult(true, null));

		$refusal = self::refusal($leg);

		$this->assertSame(RegistrationRefusal::OUTSIDE_REFUSED, $refusal->getReason());
		$this->assertStringContainsString('named no identificatie', $refusal->getMessage());
	}//end testAConfirmationWithoutAZaakIdentificatieIsRefused()

	public function testACaseThatCannotBeExpressedIsAMappingFailureAndASourceThatCannotSendIsUnavailable(): void {
		$leg = $this->leg();
		$this->cases->method('creeerZaak')->willThrowException(new StufZknTranslationException(message: 'omschrijving too long'));
		$this->assertSame([RegistrationRefusal::MAPPING_FAILED, false], [self::refusal($leg)->getReason(), self::refusal($leg)->isRetryable()]);

		$leg = $this->leg();
		$this->cases->method('creeerZaak')->willThrowException(new StufZknProviderException(message: 'log-mode source'));
		$refusal = self::refusal($leg);
		$this->assertSame([RegistrationRefusal::SOURCE_UNAVAILABLE, false, 'log-mode source'], [$refusal->getReason(), $refusal->isRetryable(), $refusal->getMessage()]);
	}//end testACaseThatCannotBeExpressedIsAMappingFailureAndASourceThatCannotSendIsUnavailable()

	public function testATargetWithoutItsSourceOrZaaktypeIsRefusedBeforeAnyMessage(): void {
		foreach ([['sources' => []] + self::TARGET, ['stufZds' => []] + self::TARGET] as $target) {
			$leg = $this->leg();
			$this->cases->expects($this->never())->method('creeerZaak');

			$this->assertSame(RegistrationRefusal::SOURCE_UNAVAILABLE, self::refusal($leg, $target)->getReason());
		}
	}//end testATargetWithoutItsSourceOrZaaktypeIsRefusedBeforeAnyMessage()

	public function testFilesAndAKvkApplicantAreRefusedRatherThanSentIncomplete(): void {
		$cases = [
			self::HANDOVER + ['pdf' => ['fileId' => 7]],
			self::HANDOVER + ['attachments' => [['fileId' => 8]]],
			['applicant' => ['kind' => 'kvk', 'value' => '12345678']] + self::HANDOVER,
		];
		foreach ($cases as $handover) {
			$leg = $this->leg();
			$this->cases->expects($this->never())->method('creeerZaak');

			$refusal = self::refusal($leg, self::TARGET, $handover);

			$this->assertSame([RegistrationRefusal::SOURCE_UNAVAILABLE, false], [$refusal->getReason(), $refusal->isRetryable()]);
		}
	}//end testFilesAndAKvkApplicantAreRefusedRatherThanSentIncomplete()
}//end class
