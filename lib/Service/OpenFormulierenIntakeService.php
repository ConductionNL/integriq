<?php

/**
 * Integriq Open Formulieren Intake Service.
 *
 * Core of the open-formulieren-intake bridge: resolves the configured
 * `open-formulieren` source (webhook signature config), the per-form
 * {@see \OCA\Integriq\Service\OpenFormulieren\FormFieldMapper} mapping,
 * persists the `openformulieren_submission` lifecycle record
 * (received -> mapped|failed, later handed_off via the authenticated handoff
 * trigger), best-effort fetches + stores attachments, and executes the
 * declared `submission-to-case` handoff through OpenRegister's real
 * `Handoff\HandoffService` under the calling user's own RBAC — see
 * design.md §1.1 for why this is NOT triggered automatically at
 * webhook-receipt time (HandoffService v1 has no system-user privilege
 * lane). `OpenFormulierenController` stays a thin HTTP/auth shell, mirroring
 * `SmsDispatchService`/`NotifyNlController`.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/specs/open-formulieren-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use GuzzleHttp\Client;
use OCA\Integriq\Exception\MappingResolutionException;
use OCA\Integriq\Exception\OpenFormulierenException;
use OCA\Integriq\Service\OpenFormulieren\FormFieldMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives Open Formulieren submission ingest, mapping, attachment handling,
 * and (separately, authenticated) handoff execution.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/open-formulieren-intake/spec.md
 */
class OpenFormulierenIntakeService {

	/**
	 * OpenRegister register slug holding sources, mappings, and submissions.
	 *
	 * @var string
	 */
	// Frozen on the old id: this is the OpenRegister REGISTER SLUG, not the app id.
	// OpenRegister matches registers by slug; renaming it orphans every stored object.
	public const REGISTER = 'openconnector';

	/**
	 * OR schema slug for an Open Formulieren source (webhook signature config).
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for a per-form mapping record.
	 *
	 * @var string
	 */
	public const SCHEMA_MAPPING = 'openformulieren_form_mapping';

	/**
	 * OR schema slug for a submission lifecycle record.
	 *
	 * @var string
	 */
	public const SCHEMA_SUBMISSION = 'openformulieren_submission';

	/**
	 * `source.type` value identifying an Open Formulieren webhook source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'open-formulieren';

	/**
	 * The declared `x-openregister-handoff` entry id on
	 * `openformulieren_submission` (see lib/Settings/integriq_register.json).
	 *
	 * @var string
	 */
	public const HANDOFF_ID = 'submission-to-case';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/mapping/submission persistence.
	 * @param HandoffService $handoffService Executes the declared handoff under the caller's RBAC.
	 * @param FileService $fileService Attachment fetch-and-store + post-handoff copy.
	 * @param FormFieldMapper $fieldMapper The per-form mapping resolver.
	 * @param Client $httpClient Guzzle client (test seam: inject one with a MockHandler stack).
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly HandoffService $handoffService,
		private readonly FileService $fileService,
		private readonly FormFieldMapper $fieldMapper,
		private readonly Client $httpClient,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve the single active `open-formulieren` source
	 * (`type=open-formulieren`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws OpenFormulierenException When no active source is configured.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-signed-inbound-submission-webhook-req-001
	 */
	public function resolveActiveSource(): ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_SOURCE,
					'type' => self::SOURCE_TYPE,
					'isEnabled' => true,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			throw new OpenFormulierenException(
				message: 'No active Open Formulieren source is configured (register "openconnector", '
				. 'schema "source", type "open-formulieren", isEnabled=true).'
			);
		}

		return $results[0];
	}//end resolveActiveSource()

	/**
	 * Ingest one signed, verified submission: persist (`received`), resolve +
	 * apply the form mapping (`mapped`|`failed`, isolated to this submission),
	 * and best-effort fetch/store attachments.
	 *
	 * @param string $formSlug The Open Formulieren form slug.
	 * @param string|null $formUuid The Open Formulieren form uuid, if present.
	 * @param array<string, mixed> $submissionMeta `{uuid, submittedAt}` from the payload's `submission` block.
	 * @param array<string, mixed> $values Raw submitted field values.
	 * @param array<int, array> $attachmentRefs `[{key, url, filename, contentType}, ...]`.
	 * @param array<string, mixed>|null $authContext `{plugin, bsn, kvk}` when present — never
	 *                                               logged.
	 *
	 * @return ObjectEntity The persisted `openformulieren_submission` record (any status).
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-openformulieren-submission-lifecycle-with-per-submission-isolation-req-003
	 */
	public function ingest(
		string $formSlug,
		?string $formUuid,
		array $submissionMeta,
		array $values,
		array $attachmentRefs = [],
		?array $authContext = null,
	): ObjectEntity {
		$submission = $this->objectService->saveObject(
			object: [
				'formSlug' => $formSlug,
				'formUuid' => ($formUuid ?? ''),
				'submissionUuid' => (string)($submissionMeta['uuid'] ?? ''),
				'submittedAt' => (string)($submissionMeta['submittedAt'] ?? (new DateTime())->format('c')),
				'rawValues' => $values,
				'authContext' => ($authContext ?? []),
				'mappedTitle' => '',
				'mappedSummary' => '',
				'mappedChannel' => '',
				'mappedPriority' => '',
				'attachments' => [],
				'status' => 'received',
				'errorDetail' => null,
				'correlationId' => '',
				'targetCase' => [],
			],
			register: self::REGISTER,
			schema: self::SCHEMA_SUBMISSION
		);

		try {
			$mapped = $this->resolveAndApplyMapping(formSlug: $formSlug, values: $values);
		} catch (MappingResolutionException|OpenFormulierenException $exception) {
			$this->logger->warning(
				'[OpenFormulierenIntakeService] mapping failed for submission ' . $submission->getUuid(),
				['formSlug' => $formSlug, 'exception' => $exception->getMessage()]
			);

			return $this->objectService->saveObject(
				object: array_merge(
					$submission->getObject(),
					['status' => 'failed', 'errorDetail' => $exception->getMessage()]
				),
				register: self::REGISTER,
				schema: self::SCHEMA_SUBMISSION,
				uuid: $submission->getUuid()
			);
		}//end try

		$attachments = $this->fetchAndStoreAttachments(submission: $submission, attachmentRefs: $attachmentRefs);

		$data = $submission->getObject();
		$data['mappedTitle'] = ($mapped['mappedTitle'] ?? '');
		$data['mappedSummary'] = ($mapped['mappedSummary'] ?? '');
		$data['mappedChannel'] = ($mapped['mappedChannel'] ?? '');
		$data['mappedPriority'] = ($mapped['mappedPriority'] ?? '');
		$data['attachments'] = $attachments;
		$data['status'] = 'mapped';

		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_SUBMISSION,
			uuid: $submission->getUuid()
		);

	}//end ingest()

	/**
	 * Read one submission's current state.
	 *
	 * @param string $submissionUuid The `openformulieren_submission` uuid.
	 *
	 * @return array<string, mixed> The submission's object data, plus `id`.
	 *
	 * @throws OpenFormulierenException When no submission exists for the uuid.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-openformulieren-submission-lifecycle-with-per-submission-isolation-req-003
	 */
	public function getSubmission(string $submissionUuid): array {
		$submission = $this->objectService->find(
			id: $submissionUuid,
			register: self::REGISTER,
			schema: self::SCHEMA_SUBMISSION
		);
		if ($submission instanceof ObjectEntity === false) {
			throw new OpenFormulierenException(message: 'No submission found for uuid "' . $submissionUuid . '".');
		}

		return ($submission->getObject() + ['id' => $submission->getUuid()]);
	}//end getSubmission()

	/**
	 * Execute the declared `submission-to-case` handoff for a `mapped`
	 * submission, as the calling (real, authenticated) user — never a
	 * system-account shortcut (design.md §1.1). On success, best-effort
	 * copies stored attachments onto the created Case.
	 *
	 * @param string $submissionUuid The `openformulieren_submission` uuid.
	 *
	 * @return array<string, mixed> The engine's `execute()` result (`{status, target, correlationId}`
	 *                              or `{status: parked, queueEntry}`).
	 *
	 * @throws OpenFormulierenException When the submission is unknown or not yet `mapped`.
	 *
	 * Also propagates OpenRegister's own `Handoff\HandoffException` (not-declared /
	 * provider-unavailable) and `NotAuthorizedException` (RBAC refusal) unchanged —
	 * omitted from the @throws tag because PHPStan cannot resolve cross-app
	 * OCA\OpenRegister\Exception\* types as Throwable subtypes (same limitation
	 * documented in phpstan.neon's `unknown class OCA\\OpenRegister\\` ignores).
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-004
	 */
	public function handoff(string $submissionUuid): array {
		$submission = $this->objectService->find(
			id: $submissionUuid,
			register: self::REGISTER,
			schema: self::SCHEMA_SUBMISSION
		);
		if ($submission instanceof ObjectEntity === false) {
			throw new OpenFormulierenException(message: 'No submission found for uuid "' . $submissionUuid . '".');
		}

		$data = $submission->getObject();
		if (($data['status'] ?? null) !== 'mapped') {
			throw new OpenFormulierenException(
				message: 'Submission "' . $submissionUuid . '" is not in "mapped" status (currently "'
				. (string)($data['status'] ?? 'unknown') . '") — a handoff can only be triggered once mapping succeeded.'
			);
		}

		try {
			$result = $this->handoffService->execute(
				register: self::REGISTER,
				schema: self::SCHEMA_SUBMISSION,
				id: $submissionUuid,
				handoffId: self::HANDOFF_ID
			);
		} catch (Throwable $exception) {
			$this->markFailed(submission: $submission, message: $exception->getMessage());
			throw $exception;
		}

		if (($result['status'] ?? null) === 'executed') {
			$this->recordHandoffSuccess(submission: $submission, result: $result);
		}

		return $result;
	}//end handoff()

	/**
	 * Resolve the `openformulieren_form_mapping` record for a form slug and
	 * apply {@see FormFieldMapper} to the submitted values.
	 *
	 * @param string $formSlug The Open Formulieren form slug.
	 * @param array<string, mixed> $values Raw submitted field values.
	 *
	 * @return array<string, string> `mapped<Field>` => resolved value.
	 *
	 * @throws OpenFormulierenException When no enabled mapping exists for the form slug.
	 * @throws MappingResolutionException When a declared field cannot resolve.
	 */
	private function resolveAndApplyMapping(string $formSlug, array $values): array {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_MAPPING,
					'formSlug' => $formSlug,
					'isEnabled' => true,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			throw new OpenFormulierenException(
				message: 'No enabled openformulieren_form_mapping found for formSlug "' . $formSlug . '".'
			);
		}

		$mappingRecord = $results[0];
		$fieldMapping = (array)($mappingRecord->getObject()['fieldMapping'] ?? []);

		$this->fieldMapper->validateConfig(fieldMapping: $fieldMapping);

		return $this->fieldMapper->map(fieldMapping: $fieldMapping, submittedValues: $values);
	}//end resolveAndApplyMapping()

	/**
	 * Best-effort fetch + store every attachment ref onto the submission
	 * object. A per-attachment failure is recorded, never thrown — isolated
	 * from the submission's mapping outcome (design.md §4).
	 *
	 * @param ObjectEntity $submission The submission object (already has a uuid).
	 * @param array<int, array> $attachmentRefs `[{key, url, filename, contentType}, ...]`.
	 *
	 * @return array<int, array<string, mixed>> One entry per ref: `{key, filename, status, fileId?, error?}`.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-best-effort-attachment-handling-req-005
	 */
	private function fetchAndStoreAttachments(ObjectEntity $submission, array $attachmentRefs): array {
		$results = [];

		foreach ($attachmentRefs as $ref) {
			$key = (string)($ref['key'] ?? '');
			$url = (string)($ref['url'] ?? '');

			$defaultFilename = 'attachment';
			if ($key !== '') {
				$defaultFilename = $key;
			}

			$filename = (string)($ref['filename'] ?? $defaultFilename);

			if ($url === '') {
				$results[] = ['key' => $key, 'filename' => $filename, 'status' => 'failed', 'error' => 'missing url'];
				continue;
			}

			try {
				$response = $this->httpClient->request('GET', $url, ['http_errors' => false]);
				$status = $response->getStatusCode();
				if ($status < 200 || $status >= 300) {
					throw new OpenFormulierenException(message: 'attachment fetch returned HTTP ' . $status);
				}

				$content = (string)$response->getBody();
				$file = $this->fileService->addFile(
					objectEntity: $submission,
					fileName: $filename,
					content: $content
				);

				$results[] = [
					'key' => $key,
					'filename' => $filename,
					'status' => 'fetched',
					'fileId' => $file->getId(),
				];
				// Throwable alone: both named types are Throwables, so listing them
				// separately caught nothing extra.
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[OpenFormulierenIntakeService] attachment fetch/store failed',
					['key' => $key, 'exception' => $exception->getMessage()]
				);
				$results[] = [
					'key' => $key,
					'filename' => $filename,
					'status' => 'failed',
					'error' => $exception->getMessage(),
				];
			}//end try
		}//end foreach

		return $results;
	}//end fetchAndStoreAttachments()

	/**
	 * Best-effort copy every successfully stored attachment onto the newly
	 * created Case object, and persist the handoff's target/correlation
	 * metadata onto the submission (`status` itself was already set by the
	 * engine's own `onSuccess.set`).
	 *
	 * @param ObjectEntity $submission The (pre-handoff) submission object.
	 * @param array<string, mixed> $result The engine's `execute()` result (`status: executed`).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-best-effort-attachment-handling-req-005
	 */
	private function recordHandoffSuccess(ObjectEntity $submission, array $result): void {
		$target = (array)($result['target'] ?? []);
		$correlationId = (string)($result['correlationId'] ?? '');

		$current = $this->objectService->find(
			id: $submission->getUuid(),
			register: self::REGISTER,
			schema: self::SCHEMA_SUBMISSION
		);
		$data = $submission->getObject();
		if ($current instanceof ObjectEntity === true) {
			$data = $current->getObject();
		}

		// ObjectEntity is a cross-app (OCA\OpenRegister) class PHPStan cannot resolve
		// (see phpstan.neon's `unknown class OCA\\OpenRegister\\` ignores), so it
		// infers getObject()'s return as `mixed` and, if updated via direct index
		// assignment, narrows $data's array shape purely from the keys written here —
		// losing the (real, runtime-present) 'attachments' key read further down.
		// array_merge() (a properly-typed stdlib call) keeps $data's inferred type
		// as a generic array instead.
		$data = array_merge($data, ['targetCase' => $target, 'correlationId' => $correlationId]);

		$targetObject = null;
		if (isset($target['register'], $target['schema'], $target['uuid']) === true) {
			$targetObject = $this->objectService->find(
				id: (string)$target['uuid'],
				register: (string)$target['register'],
				schema: (string)$target['schema']
			);
		}

		$attachments = (array)($data['attachments'] ?? []);
		foreach ($attachments as $index => $attachment) {
			if (($attachment['status'] ?? null) !== 'fetched' || isset($attachment['fileId']) === false) {
				continue;
			}

			if ($targetObject instanceof ObjectEntity === false) {
				continue;
			}

			try {
				$this->fileService->copyFile(
					sourceObject: $submission,
					fileId: (int)$attachment['fileId'],
					targetObject: $targetObject
				);
				$attachments[$index]['copiedToCase'] = true;
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[OpenFormulierenIntakeService] attachment copy-to-case failed',
					['fileId' => ($attachment['fileId'] ?? null), 'exception' => $exception->getMessage()]
				);
				$attachments[$index]['copiedToCase'] = false;
				$attachments[$index]['copyError'] = $exception->getMessage();
			}
		}//end foreach

		$data['attachments'] = $attachments;

		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_SUBMISSION,
			uuid: $submission->getUuid()
		);

	}//end recordHandoffSuccess()

	/**
	 * Mark a submission `failed` after a handoff execution error — isolated
	 * to this submission, never thrown past this method (the original
	 * exception is rethrown by the caller separately).
	 *
	 * @param ObjectEntity $submission The submission being handed off.
	 * @param string $message The failure detail.
	 *
	 * @return void
	 */
	private function markFailed(ObjectEntity $submission, string $message): void {
		$this->objectService->saveObject(
			object: array_merge(
				$submission->getObject(),
				['status' => 'failed', 'errorDetail' => $message]
			),
			register: self::REGISTER,
			schema: self::SCHEMA_SUBMISSION,
			uuid: $submission->getUuid()
		);

	}//end markFailed()
}//end class
