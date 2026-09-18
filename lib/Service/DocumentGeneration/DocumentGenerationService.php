<?php

/**
 * Integriq Document Generation Service.
 *
 * Takes a render command from filinq, tracks it as a job, calls the source's
 * vendor binding, and announces what became of it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DocumentGeneration
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
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DocumentGeneration;

use DateTime;
use OCA\Integriq\Event\DocumentRenderedEvent;
use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One render, one job, one honest terminal state.
 *
 * Three rules this class exists to hold:
 *
 * 1. The job records a hash of the data and never the data. An audit can
 *    match a beschikking to a render; integriq does not end up holding a
 *    second copy of the case.
 * 2. A vendor nobody could reach is `unreachable`, not `failed`. The job
 *    stays open, nothing is announced as settled, and something asks again.
 * 3. A document that does not come back whole is not filed as complete. A
 *    render reported done with no document, or with bytes the vendor's own
 *    checksum does not match, ends `failed` with the reason. There is no
 *    half-generated document: either the job carries a document that was
 *    fetched and verified, or it carries none and says why.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
 */
class DocumentGenerationService {

	/**
	 * The register the job and source objects live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The job schema.
	 *
	 * @var string
	 */
	public const SCHEMA_JOB = 'documentGenerationJob';

	/**
	 * The source schema.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * The source type this service reads.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'documentGeneration';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads sources and writes jobs.
	 * @param DocumentGenerationProviderRegistry $registry Resolves the source's binding.
	 * @param IEventDispatcher $eventDispatcher Announces a settled render.
	 * @param LoggerInterface $logger Logger for credential-free diagnostics.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly DocumentGenerationProviderRegistry $registry,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Take one render: create the job, call the vendor, record what happened.
	 *
	 * @param string $sourceId The document generation source.
	 * @param string $templateId The vendor's template id.
	 * @param array $data The merge data. Hashed here and dropped.
	 * @param string $requestedBy The user the render is made for.
	 * @param string $requestedByApp The app asking.
	 *
	 * @return ObjectEntity The job.
	 *
	 * @throws DocumentGenerationException When the source or its binding cannot render at all.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	public function requestRender(
		string $sourceId,
		string $templateId,
		array $data,
		string $requestedBy = '',
		string $requestedByApp = 'filinq',
	): ObjectEntity {
		$configuration = $this->sourceConfiguration(sourceId: $sourceId);
		$provider = $this->registry->resolve(sourceConfiguration: $configuration);

		$job = $this->objectService->saveObject(
			object: [
				'sourceId' => $sourceId,
				'providerId' => $provider->getProviderId(),
				'templateId' => $templateId,
				// The hash, never the data. A beschikking's merge data is
				// the case, and integriq is not where a second copy of the
				// case belongs.
				'dataHash' => $this->hash(data: $data),
				'requestedBy' => $requestedBy,
				'requestedByApp' => $requestedByApp,
				'status' => 'queued',
				'providerJobId' => '',
				'fileReference' => '',
				'lastError' => '',
				'attempts' => [],
			],
			register: self::REGISTER,
			schema: self::SCHEMA_JOB
		);

		return $this->apply(
			job: $job,
			configuration: $configuration,
			outcome: $this->renderThrough(
				provider: $provider,
				configuration: $configuration,
				templateId: $templateId,
				data: $data
			),
			provider: $provider
		);

	}//end requestRender()

	/**
	 * Ask the vendor again what became of a job it took.
	 *
	 * @param ObjectEntity $job The job to poll.
	 *
	 * @return ObjectEntity The job, updated.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	public function pollJob(ObjectEntity $job): ObjectEntity {
		$data = $job->getObject();
		$status = (string)($data['status'] ?? '');
		if (in_array($status, ['rendered', 'failed'], true) === true) {
			return $job;
		}

		$providerJobId = (string)($data['providerJobId'] ?? '');
		if ($providerJobId === '') {
			// The vendor never named a job, so there is nothing to ask about.
			// Leave it where it is rather than inventing an outcome.
			return $job;
		}

		try {
			$configuration = $this->sourceConfiguration(sourceId: (string)($data['sourceId'] ?? ''));
			$provider = $this->registry->resolve(sourceConfiguration: $configuration);
			$outcome = $provider->status(
				sourceConfiguration: $configuration,
				providerJobId: $providerJobId
			);
		} catch (DocumentGenerationException $exception) {
			$outcome = RenderOutcome::unreachable(
				detail: $exception->getMessage(),
				providerJobId: $providerJobId
			);
			return $this->record(job: $job, outcome: $outcome);
		}//end try

		return $this->apply(
			job: $job,
			configuration: $configuration,
			outcome: $outcome,
			provider: $provider
		);

	}//end pollJob()

	/**
	 * Call the binding, turning anything it throws into an outcome.
	 *
	 * @param DocumentGenerationProviderInterface $provider The binding.
	 * @param array $configuration The source's configuration.
	 * @param string $templateId The template.
	 * @param array $data The merge data.
	 *
	 * @return RenderOutcome The outcome.
	 */
	private function renderThrough(
		DocumentGenerationProviderInterface $provider,
		array $configuration,
		string $templateId,
		array $data,
	): RenderOutcome {
		try {
			return $provider->render(
				sourceConfiguration: $configuration,
				templateId: $templateId,
				data: $data
			);
		} catch (DocumentGenerationException $exception) {
			if ($exception->isUnreachable() === true) {
				return RenderOutcome::unreachable(detail: $exception->getMessage());
			}

			return RenderOutcome::failed(detail: $exception->getMessage());
		} catch (Throwable $exception) {
			// An unexpected throw is not evidence the vendor said no, so it
			// is not reported as a refusal either.
			$this->logger->warning(
				'[DocumentGenerationService] unexpected failure calling ' . $provider->getProviderId(),
				['exception' => $exception->getMessage()]
			);

			return RenderOutcome::unreachable(
				detail: 'The render failed unexpectedly: ' . $exception->getMessage()
			);
		}//end try

	}//end renderThrough()

	/**
	 * Verify a rendered document before the job is called complete, then record it.
	 *
	 * @param ObjectEntity $job The job.
	 * @param array $configuration The source's configuration.
	 * @param RenderOutcome $outcome What the vendor answered.
	 * @param DocumentGenerationProviderInterface $provider The binding.
	 *
	 * @return ObjectEntity The job, updated.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	private function apply(
		ObjectEntity $job,
		array $configuration,
		RenderOutcome $outcome,
		DocumentGenerationProviderInterface $provider,
	): ObjectEntity {
		if ($outcome->status !== 'rendered') {
			return $this->record(job: $job, outcome: $outcome);
		}

		try {
			$bytes = $provider->fetch(
				sourceConfiguration: $configuration,
				fileReference: $outcome->fileReference
			);
		} catch (DocumentGenerationException $exception) {
			if ($exception->isUnreachable() === true) {
				// The vendor says it rendered and we cannot get it yet. That
				// is not a failure and it is not a completed document.
				return $this->record(
					job: $job,
					outcome: RenderOutcome::unreachable(
						detail: 'The vendor reports the document as ready and it could not be fetched: '
							. $exception->getMessage(),
						providerJobId: $outcome->providerJobId
					)
				);
			}

			return $this->record(
				job: $job,
				outcome: RenderOutcome::failed(
					detail: 'The rendered document could not be fetched: ' . $exception->getMessage(),
					providerJobId: $outcome->providerJobId
				)
			);
		}//end try

		if (trim($bytes) === '') {
			// A document of nothing is not a document. Filing it as complete
			// would put an empty beschikking in a case file and report
			// success for it.
			return $this->record(
				job: $job,
				outcome: RenderOutcome::failed(
					detail: 'The vendor returned an empty document for a render it reported as done.',
					providerJobId: $outcome->providerJobId
				)
			);
		}

		return $this->record(job: $job, outcome: $outcome, bytes: $bytes);

	}//end apply()

	/**
	 * Write the outcome onto the job, and announce it when it is settled.
	 *
	 * @param ObjectEntity $job The job.
	 * @param RenderOutcome $outcome The outcome.
	 * @param string|null $bytes The fetched document, when there is one.
	 *
	 * @return ObjectEntity The job, updated.
	 */
	private function record(ObjectEntity $job, RenderOutcome $outcome, ?string $bytes = null): ObjectEntity {
		$data = $job->getObject();
		$attempts = (array)($data['attempts'] ?? []);
		$attempts[] = [
			'at' => (new DateTime())->format('c'),
			'status' => $outcome->status,
			'detail' => $outcome->detail,
		];

		$data['attempts'] = $attempts;
		$data['status'] = $outcome->status;
		$data['providerJobId'] = ($outcome->providerJobId !== '' ? $outcome->providerJobId : (string)($data['providerJobId'] ?? ''));
		$data['fileReference'] = $outcome->fileReference;
		$data['lastError'] = ($outcome->isTerminal() === true && $outcome->status === 'failed' ? $outcome->detail : '');

		if ($outcome->status === 'unreachable') {
			// Kept apart from lastError on purpose: "we could not ask" is
			// not "it went wrong", and an operator reading the job must be
			// able to tell the two apart without reading prose.
			$data['unreachableSince'] = ($data['unreachableSince'] ?? (new DateTime())->format('c'));
			$data['lastError'] = '';
		}

		if ($outcome->status !== 'unreachable') {
			$data['unreachableSince'] = '';
		}

		if ($bytes !== null) {
			$data['documentBytes'] = strlen($bytes);
		}

		$saved = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_JOB,
			uuid: $job->getUuid()
		);

		if ($outcome->isTerminal() === true) {
			$this->eventDispatcher->dispatchTyped(
				new DocumentRenderedEvent(
					jobId: $saved->getUuid(),
					status: $outcome->status,
					requestedBy: (string)($data['requestedBy'] ?? ''),
					fileReference: $outcome->fileReference,
					error: (string)$data['lastError']
				)
			);
		}

		return $saved;

	}//end record()

	/**
	 * The configuration of one document generation source.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return array<string, mixed> The source's `configuration` object.
	 *
	 * @throws DocumentGenerationException When no such source exists.
	 */
	private function sourceConfiguration(string $sourceId): array {
		if ($sourceId === '') {
			throw new DocumentGenerationException(message: 'A render names no document generation source.');
		}

		$source = $this->objectService->find(
			id: $sourceId,
			register: self::REGISTER,
			schema: self::SCHEMA_SOURCE
		);

		if ($source === null) {
			throw new DocumentGenerationException(
				message: 'No document generation source "' . $sourceId . '" on this instance.'
			);
		}

		$object = $source->getObject();
		if ((string)($object['type'] ?? '') !== self::SOURCE_TYPE) {
			throw new DocumentGenerationException(
				message: 'Source "' . $sourceId . '" is not a document generation source.'
			);
		}

		return (array)($object['configuration'] ?? []);

	}//end sourceConfiguration()

	/**
	 * The hash the job records in place of the data.
	 *
	 * @param array $data The merge data.
	 *
	 * @return string A sha256 over the canonicalised data.
	 */
	private function hash(array $data): string {
		ksort($data);

		return hash('sha256', (string)json_encode($data));

	}//end hash()
}//end class
