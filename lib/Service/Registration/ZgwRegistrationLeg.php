<?php

/**
 * Integriq ZgwRegistrationLeg.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Registers a submission at a ZGW case system (design D4): `POST /zaken` with
 * the mapped case and the target's zaaktype and organisations, `POST /rollen`
 * with the applicant as initiator (a BSN as `natuurlijk_persoon`, a KvK number
 * as `niet_natuurlijk_persoon`), then the target's optional
 * `zaakeigenschappen`. The case identificatie is the external reference and
 * the case url the external url.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registration;

use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\CaseSystem\ZgwDocumentDelivery;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The zgw leg (design D4).
 *
 * Every outside call is a step in the record, so a repeated hand-over resumes
 * after the last one done: a case already created is not created again
 * (REQ-SREG-003). The PDF and each attachment become an informatieobject
 * linked to the case, each its own step, so a resumed hand-over creates only
 * the documents still missing. The files are read as the calling app's
 * service account; a hand-over with files the leg cannot send is refused
 * before any call rather than registered without them.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
 */
class ZgwRegistrationLeg implements RegistrationLeg {

	/**
	 * The CRS headers the Zaken API asks for on a case.
	 */
	private const CRS = ['Accept-Crs' => 'EPSG:4326', 'Content-Crs' => 'EPSG:4326'];

	/**
	 * The ISO 639-2/B language codes of the hand-over languages, for an informatieobject's `taal`.
	 */
	private const LANGUAGES = ['nl' => 'dut', 'en' => 'eng', 'de' => 'ger', 'fr' => 'fre'];

	/**
	 * Constructor.
	 *
	 * @param CallServiceCaseSystemTransport $transport Sends to the target's ZGW sources.
	 * @param ITimeFactory                   $time      The registration date.
	 * @param ZgwDocumentDelivery|null       $documents Creates, uploads and links each informatieobject.
	 * @param RegistrationFiles|null         $files     Reads the hand-over's files as the calling app.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function __construct(
		private readonly CallServiceCaseSystemTransport $transport,
		private readonly ITimeFactory $time,
		private readonly ?ZgwDocumentDelivery $documents = null,
		private readonly ?RegistrationFiles $files = null,
	) {
	}//end __construct()

	/**
	 * The target kind this leg registers at.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function kind(): string {
		return 'zgw';
	}//end kind()

	/**
	 * Register one submission: the case, its initiator, its documents and its
	 * properties, skipping every step an earlier hand-over already did.
	 *
	 * @param array<string,mixed>            $target   The registration target.
	 * @param array<string,mixed>            $mapped   The hand-over in the outside shape.
	 * @param array<string,mixed>            $handover The hand-over as the app gave it.
	 * @param array<int,array<string,mixed>> $steps    What an earlier hand-over already did.
	 *
	 * @return array{externalReference:string,externalUrl:string,steps:array<int,array<string,mixed>>}
	 *
	 * @throws RegistrationRefusal When the submission is not registered.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function register(array $target, array $mapped, array $handover, array $steps): array {
		$settings = self::settingsOf(target: $target);
		$files    = self::filesOf(handover: $handover);
		if ($files !== []) {
			$this->checkDocuments(settings: $settings);
		}

		$run = new ZgwRegistrationRun(settings: $settings, steps: $steps);

		if ($run->done(step: 'zaak') === null) {
			$case = $this->createCase(settings: $settings, body: self::casePayload(settings: $settings, mapped: $mapped, handover: $handover));
			$run->record(step: ['step' => 'zaak', 'url' => $case['url'], 'reference' => $case['identificatie']]);
		}

		$zaak = (array)$run->done(step: 'zaak');
		$role = self::initiator(applicant: (array)($handover['applicant'] ?? []), zaak: (string)($zaak['url'] ?? ''));
		if ($role !== null && $run->done(step: 'rol') === null) {
			$role += array_filter(['roltype' => $settings['initiatorRoltype']]);
			$this->post(source: $settings['zaken'], call: ['address' => '/rollen', 'json' => $role]);
			$run->record(step: ['step' => 'rol']);
		}

		$this->addDocuments(run: $run, zaak: (string)($zaak['url'] ?? ''), files: $files, handover: $handover);
		$this->addProperties(run: $run, zaak: (string)($zaak['url'] ?? ''));

		return ['externalReference' => (string)($zaak['reference'] ?? ''), 'externalUrl' => (string)($zaak['url'] ?? ''), 'steps' => $run->steps()];
	}//end register()

	/**
	 * The target's ZGW settings, refused when one the leg needs is missing.
	 *
	 * @param array<string,mixed> $target The registration target.
	 *
	 * @return array<string,mixed> The sources (`zaken`, `documenten`), the case settings, the
	 *                             informatieobjecttypen of the PDF and attachments, and `inline`.
	 *
	 * @throws RegistrationRefusal When the target is incomplete.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	private static function settingsOf(array $target): array {
		$zgw      = (array)($target['zgw'] ?? []);
		$settings = [
			'zaken' => (string)($target['sources']['zaken'] ?? ''),
			'zaaktype' => (string)($zgw['zaaktype'] ?? ''),
			'bronorganisatie' => (string)($zgw['bronorganisatie'] ?? ''),
			'verantwoordelijkeOrganisatie' => (string)($zgw['verantwoordelijkeOrganisatie'] ?? ($zgw['bronorganisatie'] ?? '')),
			'initiatorRoltype' => (string)($zgw['initiatorRoltype'] ?? ''),
			'zaakeigenschappen' => (array)($zgw['zaakeigenschappen'] ?? []),
			'documenten' => (string)($target['sources']['documenten'] ?? ''),
			'informatieobjecttypePdf' => (string)($zgw['informatieobjecttypePdf'] ?? ($zgw['informatieobjecttype'] ?? '')),
			'informatieobjecttypeAttachment' => (string)($zgw['informatieobjecttypeAttachment'] ?? ($zgw['informatieobjecttype'] ?? '')),
			'vertrouwelijkheidaanduiding' => (string)($zgw['vertrouwelijkheidaanduiding'] ?? ''),
			'inline' => (($zgw['inline'] ?? false) === true),
		];
		if (in_array('', [$settings['zaken'], $settings['zaaktype'], $settings['bronorganisatie']], true) === true) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: 'The target lacks its Zaken API source, zaaktype or bronorganisatie.'
			);
		}

		return $settings;
	}//end settingsOf()

	/**
	 * The hand-over's files in document order: the PDF first, then each attachment.
	 *
	 * @param array<string,mixed> $handover The hand-over.
	 *
	 * @return list<array{file:array<string,mixed>,pdf:bool}>
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
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
	 * Refuse, before any call, a hand-over with files the leg cannot send.
	 *
	 * @param array<string,mixed> $settings The target's ZGW settings.
	 *
	 * @return void
	 *
	 * @throws RegistrationRefusal When the documents cannot be sent.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	private function checkDocuments(array $settings): void {
		$message = null;
		if ($this->documents === null || $this->files === null) {
			$message = 'Sending files to a ZGW target is not available on this instance.';
		} else if ($settings['documenten'] === '') {
			$message = 'The target lacks its Documenten API source for the files.';
		} else if ($settings['informatieobjecttypePdf'] === '' || $settings['informatieobjecttypeAttachment'] === '') {
			$message = 'The target lacks the informatieobjecttype for the files.';
		}

		if ($message !== null) {
			throw new RegistrationRefusal(reason: RegistrationRefusal::SOURCE_UNAVAILABLE, retryable: false, message: $message);
		}
	}//end checkDocuments()

	/**
	 * Add each file not added yet as an informatieobject linked to the case, each as its own step.
	 *
	 * @param ZgwRegistrationRun                            $run      The run: settings and steps.
	 * @param string                                        $zaak     The case url.
	 * @param list<array{file:array<string,mixed>,pdf:bool}> $files    The files, in document order.
	 * @param array<string,mixed>                           $handover The hand-over.
	 *
	 * @return void
	 *
	 * @throws RegistrationRefusal When a file cannot be read or an API refuses a document.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	private function addDocuments(ZgwRegistrationRun $run, string $zaak, array $files, array $handover): void {
		foreach ($files as $entry) {
			$step = 'document:' . (int)($entry['file']['fileId'] ?? 0);
			if ($run->done(step: $step) !== null || $this->documents === null || $this->files === null) {
				continue;
			}

			$read = $this->files->read(app: (string)($handover['app'] ?? ''), file: $entry['file']);
			try {
				$delivered = $this->documents->deliver(
					document: $this->documentOf(settings: $run->settings, read: $read, pdf: $entry['pdf'], handover: $handover),
					content: $read['content'],
					settings: [
						'documentenSource' => $run->settings['documenten'],
						'zakenSource' => $run->settings['zaken'],
						'zaakUrl' => $zaak,
						'inline' => $run->settings['inline'],
					]
				);
			} catch (CaseSystemRefusal $e) {
				throw RegistrationRefusal::fromStatus(status: $e->getStatus(), detail: $e->getMessage());
			}

			$run->record(step: ['step' => $step, 'url' => $delivered['url'], 'zaakinformatieobject' => $delivered['zaakinformatieobject']]);
		}
	}//end addDocuments()

	/**
	 * The informatieobject's metadata for one file.
	 *
	 * @param array<string,mixed>                              $settings The target's ZGW settings.
	 * @param array{content:string,name:string,mimeType:string} $read     The file as read.
	 * @param bool                                             $pdf      Whether it is the submission's PDF.
	 * @param array<string,mixed>                              $handover The hand-over.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	private function documentOf(array $settings, array $read, bool $pdf, array $handover): array {
		$titel = $read['name'];
		$type  = $settings['informatieobjecttypeAttachment'];
		if ($pdf === true) {
			$titel = (string)($handover['form']['title'] ?? $read['name']);
			$type  = $settings['informatieobjecttypePdf'];
		}

		return array_filter(
			[
				'bronorganisatie' => $settings['bronorganisatie'],
				'creatiedatum' => $this->time->getDateTime()->format('Y-m-d'),
				'titel' => $titel,
				'auteur' => (string)($handover['app'] ?? 'integriq'),
				'taal' => (self::LANGUAGES[(string)($handover['language'] ?? 'nl')] ?? 'dut'),
				'formaat' => $read['mimeType'],
				'bestandsnaam' => $read['name'],
				'informatieobjecttype' => $type,
				'vertrouwelijkheidaanduiding' => $settings['vertrouwelijkheidaanduiding'],
			],
			static fn (string $value): bool => $value !== ''
		);
	}//end documentOf()

	/**
	 * The case to create: the mapped case with the target's zaaktype and
	 * organisations, the dates, and the submission reference as a kenmerk.
	 *
	 * @param array<string,mixed> $settings The target's ZGW settings.
	 * @param array<string,mixed> $mapped   The hand-over in the outside shape.
	 * @param array<string,mixed> $handover The hand-over.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	private static function casePayload(array $settings, array $mapped, array $handover): array {
		$case = $mapped + ['omschrijving' => mb_substr((string)($handover['form']['title'] ?? ''), 0, 80)];

		return array_merge(
			$case,
			[
				'zaaktype' => $settings['zaaktype'],
				'bronorganisatie' => $settings['bronorganisatie'],
				'verantwoordelijkeOrganisatie' => $settings['verantwoordelijkeOrganisatie'],
				'kenmerken' => array_merge(
					(array)($case['kenmerken'] ?? []),
					[['kenmerk' => (string)($handover['reference'] ?? ''), 'bron' => (string)($handover['app'] ?? 'integriq')]]
				),
			]
		);
	}//end casePayload()

	/**
	 * Create the case, dated today unless the mapping dates it.
	 *
	 * @param array<string,mixed> $settings The target's ZGW settings.
	 * @param array<string,mixed> $body     The case.
	 *
	 * @return array{url:string,identificatie:string}
	 *
	 * @throws RegistrationRefusal When the Zaken API refuses it or cannot be reached.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	private function createCase(array $settings, array $body): array {
		$today = $this->time->getDateTime()->format('Y-m-d');
		$body += ['registratiedatum' => $today, 'startdatum' => $today];

		$created = $this->post(source: $settings['zaken'], call: ['address' => '/zaken', 'json' => $body, 'headers' => self::CRS]);
		$url     = (string)($created['url'] ?? '');
		if ($url === '') {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::OUTSIDE_REFUSED,
				retryable: false,
				message: 'The Zaken API created the case but named no url.'
			);
		}

		return ['url' => $url, 'identificatie' => (string)($created['identificatie'] ?? '')];
	}//end createCase()

	/**
	 * The initiator role for the applicant: a BSN as natuurlijk_persoon, a KvK
	 * number as niet_natuurlijk_persoon; null without an applicant.
	 *
	 * @param array<string,mixed> $applicant The hand-over's applicant: `kind` and `value`.
	 * @param string              $zaak      The case url.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	private static function initiator(array $applicant, string $zaak): ?array {
		$value = trim((string)($applicant['value'] ?? ''));
		$kinds = [
			'bsn' => ['natuurlijk_persoon', ['inpBsn' => $value]],
			'kvk' => ['niet_natuurlijk_persoon', ['kvkNummer' => $value]],
		];
		$kind = ($kinds[(string)($applicant['kind'] ?? '')] ?? null);
		if ($value === '' || $kind === null) {
			return null;
		}

		return [
			'zaak' => $zaak,
			'betrokkeneType' => $kind[0],
			'omschrijvingGeneriek' => 'initiator',
			'roltoelichting' => 'Indiener',
			'betrokkeneIdentificatie' => $kind[1],
		];
	}//end initiator()

	/**
	 * Add the target's zaakeigenschappen not added yet, each as its own step.
	 *
	 * @param ZgwRegistrationRun $run  The run: settings and steps.
	 * @param string             $zaak The case url.
	 *
	 * @return void
	 *
	 * @throws RegistrationRefusal When the Zaken API refuses one.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	private function addProperties(ZgwRegistrationRun $run, string $zaak): void {
		foreach ($run->settings['zaakeigenschappen'] as $eigenschap => $value) {
			$step = 'zaakeigenschap:' . (string)$eigenschap;
			if ($run->done(step: $step) !== null) {
				continue;
			}

			$this->post(
				source: $run->settings['zaken'],
				call: ['address' => $zaak . '/zaakeigenschappen', 'json' => ['zaak' => $zaak, 'eigenschap' => (string)$eigenschap, 'waarde' => (string)$value]]
			);
			$run->record(step: ['step' => $step]);
		}
	}//end addProperties()

	/**
	 * POST to a ZGW source and answer the created object, or refuse.
	 *
	 * A 5xx, a 429 or no answer may pass and is retryable; any other refusal
	 * is the case system's and is not.
	 *
	 * @param string                                                                     $source The source uuid.
	 * @param array{address:string,json:array<string,mixed>,headers?:array<string,string>} $call   The address, the body and any headers.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws RegistrationRefusal When the source refuses or cannot be reached.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	private function post(string $source, array $call): array {
		try {
			$answer = $this->transport->send(
				sourceId: $source,
				method: 'POST',
				address: $call['address'],
				options: ['json' => $call['json'], 'headers' => ($call['headers'] ?? [])]
			);
		} catch (CaseSystemRefusal $e) {
			throw new RegistrationRefusal(reason: RegistrationRefusal::SOURCE_UNAVAILABLE, retryable: false, message: $e->getMessage());
		}

		$status = $answer['status'];
		if ($status >= 200 && $status < 300) {
			return (array)$answer['data'];
		}

		throw self::refusalOf(status: $status, data: $answer['data']);
	}//end post()

	/**
	 * The refusal of a failed answer.
	 *
	 * @param int   $status The HTTP status.
	 * @param mixed $data   The decoded body.
	 *
	 * @return RegistrationRefusal
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	private static function refusalOf(int $status, mixed $data): RegistrationRefusal {
		$detail = '';
		if (is_array($data) === true) {
			$detail = (string)($data['detail'] ?? ($data['title'] ?? ''));
		}

		$message = trim('The ZGW source answered ' . $status . '. ' . $detail);
		if ($status === 0 || $status === 429 || $status >= 500) {
			return new RegistrationRefusal(reason: RegistrationRefusal::SOURCE_UNAVAILABLE, retryable: true, message: $message);
		}

		return new RegistrationRefusal(reason: RegistrationRefusal::OUTSIDE_REFUSED, retryable: false, message: $message);
	}//end refusalOf()
}//end class
