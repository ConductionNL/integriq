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
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The zgw leg (design D4).
 *
 * Every outside call is a step in the record, so a repeated hand-over resumes
 * after the last one done: a case already created is not created again
 * (REQ-SREG-003). Files (the PDF and attachments as informatieobjecten) come
 * with reading the hand-over's files as the calling app; until then a
 * hand-over with files is refused rather than registered without them.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
 */
class ZgwRegistrationLeg implements RegistrationLeg {

	/**
	 * The CRS headers the Zaken API asks for on a case.
	 */
	private const CRS = ['Accept-Crs' => 'EPSG:4326', 'Content-Crs' => 'EPSG:4326'];

	/**
	 * Constructor.
	 *
	 * @param CallServiceCaseSystemTransport $transport Sends to the target's ZGW sources.
	 * @param ITimeFactory                   $time      The registration date.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function __construct(
		private readonly CallServiceCaseSystemTransport $transport,
		private readonly ITimeFactory $time,
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
	 * Register one submission: the case, its initiator and its properties,
	 * skipping every step an earlier hand-over already did.
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
		$settings = self::settingsOf(target: $target, handover: $handover);
		$run      = new ZgwRegistrationRun(settings: $settings, steps: $steps);

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

		$this->addProperties(run: $run, zaak: (string)($zaak['url'] ?? ''));

		return ['externalReference' => (string)($zaak['reference'] ?? ''), 'externalUrl' => (string)($zaak['url'] ?? ''), 'steps' => $run->steps()];
	}//end register()

	/**
	 * The target's ZGW settings, refused when one the leg needs is missing or
	 * the hand-over carries files.
	 *
	 * @param array<string,mixed> $target   The registration target.
	 * @param array<string,mixed> $handover The hand-over.
	 *
	 * @return array<string,mixed> `zaken`, `zaaktype`, `bronorganisatie`, `verantwoordelijkeOrganisatie`, `initiatorRoltype`, `zaakeigenschappen`.
	 *
	 * @throws RegistrationRefusal When the target is incomplete or files are handed over.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	private static function settingsOf(array $target, array $handover): array {
		$zgw      = (array)($target['zgw'] ?? []);
		$settings = [
			'zaken' => (string)($target['sources']['zaken'] ?? ''),
			'zaaktype' => (string)($zgw['zaaktype'] ?? ''),
			'bronorganisatie' => (string)($zgw['bronorganisatie'] ?? ''),
			'verantwoordelijkeOrganisatie' => (string)($zgw['verantwoordelijkeOrganisatie'] ?? ($zgw['bronorganisatie'] ?? '')),
			'initiatorRoltype' => (string)($zgw['initiatorRoltype'] ?? ''),
			'zaakeigenschappen' => (array)($zgw['zaakeigenschappen'] ?? []),
		];
		if (in_array('', [$settings['zaken'], $settings['zaaktype'], $settings['bronorganisatie']], true) === true) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: 'The target lacks its Zaken API source, zaaktype or bronorganisatie.'
			);
		}

		if (isset($handover['pdf']) === true || ((array)($handover['attachments'] ?? [])) !== []) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: 'Sending files to a ZGW target is not available yet.'
			);
		}

		return $settings;
	}//end settingsOf()

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
