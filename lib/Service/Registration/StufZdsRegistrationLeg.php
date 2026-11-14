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
use OCA\Integriq\Service\StufZkn\StufZdsDocumentDelivery;
use OCA\Integriq\Service\StufZkn\StufZdsMessageLog;

/**
 * The stuf-zds leg (design D4).
 *
 * After the zaak, the PDF and each attachment are read as the calling app and
 * sent with genereerDocumentidentificatie_Di02 and voegZaakdocumentToe_Lk01,
 * each its own step, so a resumed hand-over sends only the documents still
 * missing. A hand-over with files the leg cannot send is refused before any
 * message rather than registered without them.
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
	 * @param StufZdsCaseClientInterface   $cases     The StUF-ZDS case operations.
	 * @param StufZdsDocumentDelivery|null $documents Sends each document to the zaak.
	 * @param StufZdsMessageLog|null       $sources   Reads the StUF-ZKN source raw for the documents.
	 * @param RegistrationFiles|null       $files     Reads the hand-over's files as the calling app.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function __construct(
		private readonly StufZdsCaseClientInterface $cases,
		private readonly ?StufZdsDocumentDelivery $documents = null,
		private readonly ?StufZdsMessageLog $sources = null,
		private readonly ?RegistrationFiles $files = null,
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
	 * Create the zaak once, then send each document not sent yet.
	 *
	 * @param array<string,mixed>            $target   The registration target.
	 * @param array<string,mixed>            $mapped   The mapped case: omschrijving, startdatum and any other case fields.
	 * @param array<string,mixed>            $handover The hand-over.
	 * @param array<int,array<string,mixed>> $steps    What an earlier hand-over already did.
	 *
	 * @return array{externalReference:string,externalUrl:string,steps:array<int,array<string,mixed>>}
	 *
	 * @throws RegistrationRefusal When the zaak or a document is not created.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function register(array $target, array $mapped, array $handover, array $steps): array {
		[$source, $zaaktype] = self::settingsOf(target: $target);
		$files = self::filesOf(handover: $handover);
		if ($files !== []) {
			$this->checkDocuments(target: $target);
		}

		$reference = self::doneReference(steps: $steps);
		if ($reference === null) {
			[$reference, $steps] = $this->createZaak(source: $source, zaaktype: $zaaktype, mapped: $mapped, handover: $handover, steps: $steps);
		}

		$steps = $this->addDocuments(source: $source, target: $target, zaak: $reference, files: $files, handover: $handover, steps: $steps);

		return ['externalReference' => $reference, 'externalUrl' => '', 'steps' => $steps];
	}//end register()

	/**
	 * The zaak identificatie an earlier hand-over created, or null.
	 *
	 * @param array<int,array<string,mixed>> $steps What an earlier hand-over already did.
	 *
	 * @return string|null
	 */
	private static function doneReference(array $steps): ?string {
		foreach ($steps as $step) {
			if (($step['step'] ?? null) === self::STEP) {
				return (string)($step['reference'] ?? '');
			}
		}

		return null;
	}//end doneReference()

	/**
	 * Create the zaak and record it as a step.
	 *
	 * @param string                         $source   The StUF-ZKN source.
	 * @param string                         $zaaktype The zaaktype.
	 * @param array<string,mixed>            $mapped   The mapped case.
	 * @param array<string,mixed>            $handover The hand-over.
	 * @param array<int,array<string,mixed>> $steps    The steps so far.
	 *
	 * @return array{0:string,1:array<int,array<string,mixed>>} The zaak identificatie and the steps.
	 *
	 * @throws RegistrationRefusal When the zaak is not created.
	 */
	private function createZaak(string $source, string $zaaktype, array $mapped, array $handover, array $steps): array {

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

		return [$reference, $steps];
	}//end createZaak()

	/**
	 * The hand-over's files in document order: the PDF first, then each attachment.
	 *
	 * @param array<string,mixed> $handover The hand-over.
	 *
	 * @return list<array{file:array<string,mixed>,pdf:bool}>
	 */
	private static function filesOf(array $handover): array {
		$files = [];
		if (is_array($handover['pdf'] ?? null) === true) {
			$files[] = ['file' => $handover['pdf'], 'pdf' => true];
		}

		foreach ((array)($handover['attachments'] ?? []) as $attachment) {
			if (is_array($attachment) === true) {
				$files[] = ['file' => $attachment, 'pdf' => false];
			}
		}

		return $files;
	}//end filesOf()

	/**
	 * Refuse, before any message, a hand-over with files the leg cannot send.
	 *
	 * @param array<string,mixed> $target The target.
	 *
	 * @return void
	 *
	 * @throws RegistrationRefusal source-unavailable.
	 */
	private function checkDocuments(array $target): void {
		$message = null;
		if ($this->documents === null || $this->sources === null || $this->files === null) {
			$message = 'Sending files to a StUF-ZDS target is not available on this instance.';
		} else if (trim((string)($target['stufZds']['documenttypeCode'] ?? '')) === '') {
			$message = 'The target lacks the documenttype code for the files.';
		}

		if ($message !== null) {
			throw new RegistrationRefusal(reason: RegistrationRefusal::SOURCE_UNAVAILABLE, retryable: false, message: $message);
		}
	}//end checkDocuments()

	/**
	 * Send each file not sent yet to the zaak, each as its own step.
	 *
	 * @param string                                         $source   The StUF-ZKN source.
	 * @param array<string,mixed>                            $target   The target.
	 * @param string                                         $zaak     The zaak identificatie.
	 * @param list<array{file:array<string,mixed>,pdf:bool}> $files    The files, in document order.
	 * @param array<string,mixed>                            $handover The hand-over.
	 * @param array<int,array<string,mixed>>                 $steps    The steps so far.
	 *
	 * @return array<int,array<string,mixed>> The steps.
	 *
	 * @throws RegistrationRefusal When a file cannot be read or a document is not added.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	private function addDocuments(string $source, array $target, string $zaak, array $files, array $handover, array $steps): array {
		$done = array_column($steps, 'step');
		foreach ($files as $entry) {
			$step = 'document:' . (int)($entry['file']['fileId'] ?? 0);
			if (in_array($step, $done, true) === true || $this->documents === null || $this->sources === null || $this->files === null) {
				continue;
			}

			$read  = $this->files->read(app: (string)($handover['app'] ?? ''), file: $entry['file']);
			$titel = $read['name'];
			if ($entry['pdf'] === true) {
				$titel = (string)($handover['form']['title'] ?? $read['name']);
			}

			try {
				$sent = $this->documents->deliver(
					source: $this->sources->source(sourceId: $source),
					document: ['titel' => $titel, 'formaat' => $read['mimeType'], 'auteur' => (string)($handover['app'] ?? 'integriq')],
					file: ['content' => $read['content'], 'filename' => $read['name'], 'mimeType' => $read['mimeType']],
					zaakIdentificatie: $zaak,
					documenttype: trim((string)$target['stufZds']['documenttypeCode'])
				);
			} catch (StufZknTranslationException $e) {
				throw new RegistrationRefusal(reason: RegistrationRefusal::MAPPING_FAILED, retryable: false, message: $e->getMessage());
			} catch (StufZknProviderException $e) {
				throw new RegistrationRefusal(reason: RegistrationRefusal::OUTSIDE_REFUSED, retryable: false, message: $e->getMessage());
			}

			$steps[] = ['step' => $step, 'identificatie' => $sent['identificatie'], 'referentienummer' => $sent['referentienummer']];
		}//end foreach

		return $steps;
	}//end addDocuments()

	/**
	 * The StUF-ZKN source and zaaktype of the target, refused when this leg cannot serve them.
	 *
	 * @param array<string,mixed> $target The target.
	 *
	 * @return array{0:string,1:string}
	 *
	 * @throws RegistrationRefusal source-unavailable.
	 */
	private static function settingsOf(array $target): array {
		$source   = (string)($target['sources']['zkn'] ?? '');
		$zaaktype = (string)($target['stufZds']['zaaktypeCode'] ?? '');
		if ($source === '' || $zaaktype === '') {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: 'The target lacks its StUF-ZKN source or zaaktype code.'
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
