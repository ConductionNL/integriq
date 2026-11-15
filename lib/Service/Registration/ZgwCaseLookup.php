<?php

/**
 * Integriq ZgwCaseLookup.
 *
 * Finds the ZGW case an earlier hand-over of a submission created.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Registration
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

namespace OCA\Integriq\Service\Registration;

use DateTimeImmutable;
use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The outside check of design D5: a case carrying the submission reference as a kenmerk is reused.
 *
 * Used by the ZGW leg when no step records the case, so a hand-over whose
 * record was lost does not create the case a second time.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
 */
final class ZgwCaseLookup {

	/**
	 * The CRS headers the Zaken API asks for on a case.
	 *
	 * @var array<string,string>
	 */
	private const CRS = ['Accept-Crs' => 'EPSG:4326', 'Content-Crs' => 'EPSG:4326'];

	/**
	 * The most pages of cases read for one lookup.
	 *
	 * @var int
	 */
	private const PAGES = 10;

	/**
	 * Constructor.
	 *
	 * @param CallServiceCaseSystemTransport $transport Sends to the target's Zaken API.
	 * @param ITimeFactory                   $time      Today, for a hand-over without a submission time.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function __construct(
		private readonly CallServiceCaseSystemTransport $transport,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The case an earlier hand-over created without a record of it (design D5).
	 *
	 * The Zaken API has no kenmerk filter, so the cases of the target's
	 * zaaktype and bronorganisatie started on or after the submission date are
	 * listed and their kenmerken compared: a case with the submission
	 * reference from this app is the one to reuse. At most
	 * {@see self::PAGES} pages are read.
	 *
	 * @param array<string,mixed> $settings The target settings.
	 * @param array<string,mixed> $handover The hand-over.
	 *
	 * @return array{step:string,url:string,reference:string,found:true}|null The zaak step for the found case, or null when there is none.
	 *
	 * @throws RegistrationRefusal When the Zaken API cannot be asked.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function existingCase(array $settings, array $handover): ?array {
		$reference = (string)($handover['reference'] ?? '');
		if ($reference === '') {
			return null;
		}

		$bron    = (string)($handover['app'] ?? 'integriq');
		$address = '/zaken';
		$options = [
			'query' => [
				'zaaktype' => $settings['zaaktype'],
				'bronorganisatie' => $settings['bronorganisatie'],
				'startdatum__gte' => $this->lookupSince(handover: $handover),
			],
			'headers' => self::CRS,
		];
		for ($page = 0; $page < self::PAGES && $address !== ''; $page++) {
			$answer = $this->get(source: $settings['zaken'], address: $address, options: $options);
			foreach ((array)($answer['results'] ?? []) as $case) {
				if (is_array($case) === true && self::carriesReference(case: $case, reference: $reference, bron: $bron) === true) {
					return ['step' => 'zaak', 'url' => (string)$case['url'], 'reference' => (string)($case['identificatie'] ?? ''), 'found' => true];
				}
			}

			$address = '';
			if (is_string($answer['next'] ?? null) === true) {
				// The next link carries the query itself.
				$address = $answer['next'];
				$options = ['headers' => self::CRS];
			}
		}

		return null;
	}//end existingCase()

	/**
	 * The first start date an earlier case of this submission can have: the day it was submitted.
	 *
	 * @param array<string,mixed> $handover The hand-over.
	 *
	 * @return string The date, Y-m-d; thirty days back when the hand-over names no submission time.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	private function lookupSince(array $handover): string {
		$submitted = (string)($handover['submittedAt'] ?? '');
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $submitted, $match) === 1) {
			return $match[0];
		}

		return DateTimeImmutable::createFromInterface($this->time->getDateTime())->modify('-30 days')->format('Y-m-d');
	}//end lookupSince()

	/**
	 * Whether a listed case carries the submission reference as a kenmerk from this app.
	 *
	 * @param array<string,mixed> $case      The case as listed.
	 * @param string              $reference The submission reference.
	 * @param string              $bron      The app that handed the submission over.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	private static function carriesReference(array $case, string $reference, string $bron): bool {
		if (is_string($case['url'] ?? null) === false || $case['url'] === '') {
			return false;
		}

		foreach ((array)($case['kenmerken'] ?? []) as $kenmerk) {
			if (is_array($kenmerk) === true && ($kenmerk['kenmerk'] ?? null) === $reference && ($kenmerk['bron'] ?? null) === $bron) {
				return true;
			}
		}

		return false;
	}//end carriesReference()

	/**
	 * Whether the case of a zaak step still needs its initiator role.
	 *
	 * A case integriq created now has none. A case found by kenmerk may have
	 * got it from the earlier hand-over, so the Zaken API is asked.
	 *
	 * @param array<string,mixed> $settings The target settings.
	 * @param array<string,mixed> $zaak     The zaak step.
	 *
	 * @return bool
	 *
	 * @throws RegistrationRefusal When the Zaken API cannot be asked.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function needsInitiator(array $settings, array $zaak): bool {
		if (($zaak['found'] ?? false) !== true) {
			return true;
		}

		return $this->hasInitiator(settings: $settings, zaak: (string)($zaak['url'] ?? '')) === false;
	}//end needsInitiator()

	/**
	 * Whether a found case already has an initiator role.
	 *
	 * @param array<string,mixed> $settings The target settings.
	 * @param string              $zaak     The case url.
	 *
	 * @return bool
	 *
	 * @throws RegistrationRefusal When the Zaken API cannot be asked.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	private function hasInitiator(array $settings, string $zaak): bool {
		$answer = $this->get(
			source: $settings['zaken'],
			address: '/rollen',
			options: ['query' => ['zaak' => $zaak, 'omschrijvingGeneriek' => 'initiator']]
		);

		return (array)($answer['results'] ?? []) !== [];
	}//end hasInitiator()

	/**
	 * GET from the Zaken API, refusing a transport failure or a non-2xx answer.
	 *
	 * @param string              $source  The source id.
	 * @param string              $address The path or absolute url.
	 * @param array<string,mixed> $options The query and headers.
	 *
	 * @return array<string,mixed> The answer body.
	 *
	 * @throws RegistrationRefusal When the call fails.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	private function get(string $source, string $address, array $options): array {
		try {
			$answer = $this->transport->send(sourceId: $source, method: 'GET', address: $address, options: $options);
		} catch (CaseSystemRefusal $e) {
			throw new RegistrationRefusal(reason: RegistrationRefusal::SOURCE_UNAVAILABLE, retryable: false, message: $e->getMessage());
		}

		$status = $answer['status'];
		if ($status >= 200 && $status < 300) {
			return (array)$answer['data'];
		}

		$detail = '';
		if (is_array($answer['data']) === true) {
			$detail = (string)($answer['data']['detail'] ?? ($answer['data']['title'] ?? ''));
		}

		throw RegistrationRefusal::fromStatus(status: $status, detail: $detail);
	}//end get()
}//end class
