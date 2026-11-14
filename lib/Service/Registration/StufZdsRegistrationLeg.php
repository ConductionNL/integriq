<?php

/**
 * Integriq StufZdsRegistrationLeg.
 *
 * Registers a submission at a StUF-ZDS case system (design D4): one
 * creeerZaak through StufZdsCaseClient, with the mapped case, the applicant
 * as heeftAlsInitiator and the target's zaaktype. A source configured with
 * `caseIdentificationStrategy: vooraf` asks genereerZaakIdentificatie first,
 * inside the case client. The zaak identificatie is the external reference.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registration;

use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\StufZkn\StufZdsCaseClientInterface;

/**
 * The stuf-zds leg (design D4).
 *
 * Files (the PDF and attachments, genereerDocumentidentificatie_Di02 and
 * voegZaakdocumentToe_Lk01) come with reading the hand-over's files as the
 * calling app; until then a hand-over with files is refused rather than
 * registered without them.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */
class StufZdsRegistrationLeg implements RegistrationLeg {

	/**
	 * The step name of the zaak creation.
	 *
	 * @var string
	 */
	private const STEP = 'zaak';

	/**
	 * Constructor.
	 *
	 * @param StufZdsCaseClientInterface $cases The StUF-ZDS case operations.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function __construct(
		private readonly StufZdsCaseClientInterface $cases,
	) {
	}//end __construct()

	/**
	 * The kind.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function kind(): string {
		return 'stuf-zds';
	}//end kind()

	/**
	 * Create the zaak once.
	 *
	 * @param array<string,mixed>            $target   The registration target.
	 * @param array<string,mixed>            $mapped   The mapped case: omschrijving, startdatum and any other case fields.
	 * @param array<string,mixed>            $handover The hand-over.
	 * @param array<int,array<string,mixed>> $steps    What an earlier hand-over already did.
	 *
	 * @return array{externalReference:string,externalUrl:string,steps:array<int,array<string,mixed>>}
	 *
	 * @throws RegistrationRefusal When the zaak is not created.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function register(array $target, array $mapped, array $handover, array $steps): array {
		foreach ($steps as $step) {
			if (($step['step'] ?? null) === self::STEP) {
				return ['externalReference' => (string)($step['reference'] ?? ''), 'externalUrl' => '', 'steps' => $steps];
			}
		}

		[$source, $zaaktype] = self::settingsOf(target: $target, handover: $handover);

		$case = $mapped + ['omschrijving' => (string)($handover['form']['title'] ?? '')];
		$initiator = self::initiator(applicant: (array)($handover['applicant'] ?? []));
		if ($initiator !== null) {
			$case['betrokkenen'] = array_merge([$initiator], (array)($case['betrokkenen'] ?? []));
		}

		$context = ['correlationId' => (string)$handover['reference']];
		if (is_string($handover['app'] ?? null) === true) {
			$context['sourceApp'] = $handover['app'];
		}

		$result = $this->send(source: $source, case: $case, zaaktype: $zaaktype, context: $context);

		$reference = (string)($result['caseIdentification'] ?? '');
		$steps[]   = ['step' => self::STEP, 'reference' => $reference, 'referentienummer' => (string)($result['referentienummer'] ?? '')];

		return ['externalReference' => $reference, 'externalUrl' => '', 'steps' => $steps];
	}//end register()

	/**
	 * The StUF-ZKN source and zaaktype of the target, refused when this leg cannot serve them.
	 *
	 * @param array<string,mixed> $target   The target.
	 * @param array<string,mixed> $handover The hand-over.
	 *
	 * @return array{0:string,1:string}
	 *
	 * @throws RegistrationRefusal source-unavailable.
	 */
	private static function settingsOf(array $target, array $handover): array {
		$source   = (string)($target['sources']['zkn'] ?? '');
		$zaaktype = (string)($target['stufZds']['zaaktypeCode'] ?? '');
		if ($source === '' || $zaaktype === '') {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: 'The target lacks its StUF-ZKN source or zaaktype code.'
			);
		}

		if (isset($handover['pdf']) === true || ((array)($handover['attachments'] ?? [])) !== []) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: 'Sending files to a StUF-ZDS target is not available yet.'
			);
		}

		return [$source, $zaaktype];
	}//end settingsOf()

	/**
	 * The applicant as the zaak's initiator, or null for an anonymous submission.
	 *
	 * The StUF-ZDS message builder names an initiator as a natuurlijk persoon by
	 * BSN; a KvK applicant is refused rather than sent as somebody else.
	 *
	 * @param array<string,mixed> $applicant `kind` (bsn or kvk) and `value`.
	 *
	 * @return array<string,string>|null
	 *
	 * @throws RegistrationRefusal source-unavailable for a KvK applicant.
	 */
	private static function initiator(array $applicant): ?array {
		$kind  = (string)($applicant['kind'] ?? '');
		$value = trim((string)($applicant['value'] ?? ''));
		if ($value === '') {
			return null;
		}

		if ($kind === 'kvk') {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: 'A KvK applicant is not sent to a StUF-ZDS target yet.'
			);
		}

		if ($kind !== 'bsn') {
			return null;
		}

		return ['role' => 'heeftAlsInitiator', 'bsn' => $value];
	}//end initiator()

	/**
	 * Send creeerZaak and answer a created zaak, refusing everything else.
	 *
	 * @param string              $source   The StUF-ZKN source.
	 * @param array<string,mixed> $case     The case.
	 * @param string              $zaaktype The zaaktype.
	 * @param array<string,mixed> $context  The correlation id and calling app.
	 *
	 * @return array<string,mixed> The case client's result.
	 *
	 * @throws RegistrationRefusal mapping-failed, source-unavailable or outside-refused.
	 */
	private function send(string $source, array $case, string $zaaktype, array $context): array {
		try {
			$result = $this->cases->creeerZaak(sourceId: $source, case: $case, zaaktypeOmschrijving: $zaaktype, context: $context);
		} catch (StufZknTranslationException $e) {
			throw new RegistrationRefusal(reason: RegistrationRefusal::MAPPING_FAILED, retryable: false, message: $e->getMessage());
		} catch (StufZknProviderException $e) {
			throw new RegistrationRefusal(reason: RegistrationRefusal::SOURCE_UNAVAILABLE, retryable: false, message: $e->getMessage());
		}

		if ($result['success'] === true && (string)($result['caseIdentification'] ?? '') !== '') {
			return $result;
		}

		$fout    = (array)($result['fout'] ?? []);
		$message = trim((string)($fout['omschrijving'] ?? '') . ' ' . (string)($fout['details'] ?? ''));
		if ($result['success'] === true) {
			$message = 'The case system confirmed the zaak but named no identificatie.';
		}

		if (($result['retrying'] ?? false) === true || ($fout['code'] ?? '') === 'CIRCUIT_OPEN') {
			throw new RegistrationRefusal(reason: RegistrationRefusal::SOURCE_UNAVAILABLE, retryable: true, message: $message);
		}

		throw new RegistrationRefusal(
			reason: RegistrationRefusal::OUTSIDE_REFUSED,
			retryable: false,
			message: trim((string)($fout['code'] ?? '') . ': ' . $message, ': ')
		);
	}//end send()
}//end class
