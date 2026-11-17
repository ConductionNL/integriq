<?php

/**
 * Integriq DestinationSubmitter.
 *
 * Maps an Open Formulieren submission with its form mapping and creates the
 * destination object through OpenRegister's form submit path, in one request.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenFormulieren
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenFormulieren;

use OCA\Integriq\Service\OpenFormulierenIntakeService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\Form\FormSubmission;
use OCA\OpenRegister\Service\Form\FormSubmitServiceInterface;
use OCA\OpenRegister\Service\Form\FormSubmitter;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * The inbound submit path: no staging object, the destination in the same request.
 *
 * Every submission leaves one audit-log line with the received body's hash,
 * the time and the outcome; nothing is kept to process later.
 *
 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-submit-must-create-its-case-in-the-inbound-request
 */
class DestinationSubmitter implements DestinationSubmitterInterface {

	/**
	 * Constructor.
	 *
	 * @param ObjectService              $objectService Finds the enabled form mapping.
	 * @param FormFieldMapper            $fieldMapper   Resolves the contract fields.
	 * @param FormSubmitServiceInterface $formSubmit    OpenRegister's form submit path.
	 * @param IUserSession               $userSession   The account the submission runs as.
	 * @param IEventDispatcher           $dispatcher    Carries the audit-log line.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly FormFieldMapper $fieldMapper,
		private readonly FormSubmitServiceInterface $formSubmit,
		private readonly IUserSession $userSession,
		private readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * Map the submission and create its destination object in this request.
	 *
	 * @param string               $formSlug     The Open Formulieren form slug.
	 * @param string               $submissionId The Open Formulieren submission id, the idempotency key.
	 * @param array<string, mixed> $values       The submitted values.
	 * @param string               $payloadHash  The sha256 of the received body, for the audit log.
	 *
	 * @return array<string, mixed> OpenRegister's answer.
	 *
	 * @throws FormSubmitRefusedException 422 without a usable mapping; OpenRegister's refusal otherwise.
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-submit-must-create-its-case-in-the-inbound-request
	 */
	public function submit(string $formSlug, string $submissionId, array $values, string $payloadHash): array {
		$key = null;
		if ($submissionId !== '') {
			$key = $submissionId;
		}

		try {
			$answer = $this->formSubmit->submit(
				$this->submissionFor(formSlug: $formSlug, submissionId: $submissionId, values: $values),
				$key
			);
		} catch (FormSubmitRefusedException $refusal) {
			$this->audit(submissionId: $submissionId, payloadHash: $payloadHash, outcome: 'refused ' . $refusal->getStatus());
			throw $refusal;
		}

		$this->audit(submissionId: $submissionId, payloadHash: $payloadHash, outcome: 'created ' . (string)($answer['reference'] ?? ($answer['id'] ?? '')));

		return $answer;
	}//end submit()

	/**
	 * The submit request: the mapping's write, the mapped payload, the form as scope.
	 *
	 * @param string               $formSlug     The form slug.
	 * @param string               $submissionId The submission id.
	 * @param array<string, mixed> $values       The submitted values.
	 *
	 * @return FormSubmission
	 *
	 * @throws FormSubmitRefusedException 422 when the form has no usable mapping.
	 */
	private function submissionFor(string $formSlug, string $submissionId, array $values): FormSubmission {
		$mapping = $this->enabledMapping(formSlug: $formSlug);
		try {
			$this->fieldMapper->validateConfig(fieldMapping: (array)($mapping['fieldMapping'] ?? []));
			$mapped = $this->fieldMapper->map(fieldMapping: (array)($mapping['fieldMapping'] ?? []), submittedValues: $values);
		} catch (MappingResolutionException $exception) {
			throw self::refusal(property: 'fieldMapping', code: 'mapping-unresolved', message: $exception->getMessage());
		}

		return new FormSubmission(
			[FormDestinationWrite::writeFor(mapping: $mapping)],
			FormDestinationWrite::payloadFor(mapped: $mapped, submissionId: $submissionId),
			new FormSubmitter(subject: $this->userSession->getUser(), scope: 'openformulieren:' . $formSlug)
		);
	}//end submissionFor()

	/**
	 * The enabled mapping of a form.
	 *
	 * @param string $formSlug The form slug.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws FormSubmitRefusedException 422 when the form has no enabled mapping.
	 */
	private function enabledMapping(string $formSlug): array {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => OpenFormulierenIntakeService::REGISTER,
					'schema' => OpenFormulierenIntakeService::SCHEMA_MAPPING,
					'formSlug' => $formSlug,
					'isEnabled' => true,
				],
				'limit' => 1,
			]
		);
		foreach (($matches['results'] ?? $matches) as $candidate) {
			if ($candidate instanceof ObjectEntity === true && ($candidate->getObject()['formSlug'] ?? null) === $formSlug) {
				return (array)$candidate->getObject();
			}
		}

		throw self::refusal(property: 'formSlug', code: 'no-mapping', message: 'No enabled form mapping for form "' . $formSlug . '".');
	}//end enabledMapping()

	/**
	 * A 422 refusal with one finding.
	 *
	 * @param string $property The property the finding is on.
	 * @param string $code     The finding code.
	 * @param string $message  The sentence.
	 *
	 * @return FormSubmitRefusedException
	 */
	private static function refusal(string $property, string $code, string $message): FormSubmitRefusedException {
		return new FormSubmitRefusedException(
			message: $message,
			status: 422,
			findings: [['property' => $property, 'code' => $code, 'message' => $message]]
		);
	}//end refusal()

	/**
	 * One audit-log line: the body's hash, the time (the log's own) and the outcome.
	 *
	 * @param string $submissionId The submission id.
	 * @param string $payloadHash  The sha256 of the received body.
	 * @param string $outcome      `created <reference>` or `refused <status>`.
	 *
	 * @return void
	 */
	private function audit(string $submissionId, string $payloadHash, string $outcome): void {
		$this->dispatcher->dispatchTyped(
			new CriticalActionPerformedEvent(
				'Open Formulieren submission %s received, body sha256 %s: %s',
				[$submissionId, $payloadHash, $outcome]
			)
		);
	}//end audit()
}//end class
