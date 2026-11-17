<?php

/**
 * Integriq ObjectsApiRegistrationLeg.
 *
 * Creates one object of the configured type in the Objects API.
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
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registration;

use DateTimeImmutable;
use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use Throwable;

/**
 * The objects-api leg (design D4): one POST /objects of the configured type
 * and version whose record.data is the mapped submission.
 *
 * Files to the Documenten API (a target with an informatieobjecttype) come
 * with the document upload of connectors-case-system-document-delivery; until
 * then such a target is refused rather than registered without its files.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */
class ObjectsApiRegistrationLeg implements RegistrationLeg {

	/**
	 * The step name of the object creation.
	 *
	 * @var string
	 */
	private const STEP = 'object';

	/**
	 * Constructor.
	 *
	 * @param CallServiceCaseSystemTransport $transport Sends on the target's Objects API source.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function __construct(
		private readonly CallServiceCaseSystemTransport $transport,
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
		return 'objects-api';
	}//end kind()

	/**
	 * Create the object once.
	 *
	 * @param array<string,mixed>            $target   The registration target.
	 * @param array<string,mixed>            $mapped   The mapped record data.
	 * @param array<string,mixed>            $handover The hand-over.
	 * @param array<int,array<string,mixed>> $steps    Steps already done.
	 *
	 * @return array{externalReference:string,externalUrl:string,steps:array<int,array<string,mixed>>}
	 *
	 * @throws RegistrationRefusal When the target is incomplete, the source is missing or the Objects API refuses.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function register(array $target, array $mapped, array $handover, array $steps): array {
		foreach ($steps as $step) {
			if (($step['step'] ?? null) === self::STEP) {
				return ['externalReference' => (string)($step['reference'] ?? ''), 'externalUrl' => (string)($step['url'] ?? ''), 'steps' => $steps];
			}
		}

		$settings = self::settingsOf(target: $target);
		$body     = [
			'type' => (string)$settings['objecttype'],
			'record' => [
				'typeVersion' => (int)$settings['objecttypeVersion'],
				'data' => $mapped,
				'startAt' => self::startAt(submittedAt: (string)($handover['submittedAt'] ?? '')),
			],
		];

		$data      = $this->post(source: (string)$target['sources']['objects'], body: $body);
		$reference = (string)($data['uuid'] ?? '');
		$url       = (string)($data['url'] ?? '');
		$steps[]   = ['step' => self::STEP, 'reference' => $reference, 'url' => $url];

		return ['externalReference' => $reference, 'externalUrl' => $url, 'steps' => $steps];
	}//end register()

	/**
	 * The target's Objects API settings, refused when this leg cannot serve them.
	 *
	 * @param array<string,mixed> $target The target.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws RegistrationRefusal source-unavailable.
	 */
	private static function settingsOf(array $target): array {
		$settings = (array)($target['objectsApi'] ?? []);
		if ((string)($settings['objecttype'] ?? '') === ''
			|| (string)($target['sources']['objects'] ?? '') === ''
			|| is_numeric($settings['objecttypeVersion'] ?? null) === false
		) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: 'The target lacks its Objects API source, object type or object type version.'
			);
		}

		if ((string)($settings['informatieobjecttype'] ?? '') !== '') {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: 'Sending files to the Documenten API for an Objects API target is not available yet.'
			);
		}

		return $settings;
	}//end settingsOf()

	/**
	 * POST the object and answer the created object, refusing anything but a 2xx.
	 *
	 * @param string              $source The Objects API source uuid.
	 * @param array<string,mixed> $body   The object.
	 *
	 * @return array<string,mixed> The created object.
	 *
	 * @throws RegistrationRefusal When the source is missing or the Objects API refuses.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RegistrationRefusal::fromStatus is the named constructor for an outside answer.
	 */
	private function post(string $source, array $body): array {
		try {
			$answer = $this->transport->send(sourceId: $source, method: 'POST', address: '/objects', options: ['json' => $body]);
		} catch (CaseSystemRefusal $e) {
			throw new RegistrationRefusal(reason: RegistrationRefusal::SOURCE_UNAVAILABLE, retryable: false, message: $e->getMessage());
		}

		if ($answer['status'] < 200 || $answer['status'] >= 300) {
			throw RegistrationRefusal::fromStatus(status: $answer['status'], detail: substr($answer['raw'], 0, 500));
		}

		if (is_array($answer['data']) === true) {
			return $answer['data'];
		}

		return [];
	}//end post()

	/**
	 * The record's startAt: the submission's date, else today.
	 *
	 * @param string $submittedAt When the submission was made, as the app gave it.
	 *
	 * @return string A Y-m-d date.
	 */
	private static function startAt(string $submittedAt): string {
		if ($submittedAt === '') {
			$submittedAt = 'now';
		}

		try {
			return (new DateTimeImmutable($submittedAt))->format('Y-m-d');
		} catch (Throwable) {
			return (new DateTimeImmutable('now'))->format('Y-m-d');
		}
	}//end startAt()
}//end class
