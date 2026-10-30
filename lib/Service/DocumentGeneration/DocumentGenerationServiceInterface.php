<?php

/**
 * Contract of the DocumentGenerationService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\DocumentGeneration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DocumentGeneration;

use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see DocumentGenerationService} that other components call.
 */
interface DocumentGenerationServiceInterface {
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
	 * @spec openspec/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	public function requestRender(
		string $sourceId,
		string $templateId,
		array $data,
		string $requestedBy = '',
		string $requestedByApp = 'filinq',
	): ObjectEntity;

	/**
	 * Ask the vendor again what became of a job it took.
	 *
	 * @param ObjectEntity $job The job to poll.
	 *
	 * @return ObjectEntity The job, updated.
	 *
	 * @spec openspec/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	public function pollJob(ObjectEntity $job): ObjectEntity;
}
