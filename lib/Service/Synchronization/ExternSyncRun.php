<?php

/**
 * The working state of one extern-to-intern synchronization run.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Synchronization;

use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\SynchronizationRunLog;
use OCA\OpenRegister\Db\ObjectEntity;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Carries the inputs and the accumulating results of one run between the
 * stages of SynchronizationService::synchronizeExternToIntern().
 *
 * The stages read and update it in place, so each stage only names the run
 * instead of the dozen values the stages share.
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One field per value the run's stages share.
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */
class ExternSyncRun {

	/**
	 * The synchronization being run; the run's own copy, persisted at the end.
	 *
	 * @var array
	 */
	public array $synchronization = [];

	/**
	 * The run log.
	 *
	 * @var SynchronizationRunLog
	 */
	public SynchronizationRunLog $log;

	/**
	 * The flow token, held by reference so the caller sees every change.
	 *
	 * @var FlowToken
	 */
	public FlowToken $flowToken;

	/**
	 * Whether this is a test (dry) run.
	 *
	 * @var bool|null
	 */
	public ?bool $isTest = false;

	/**
	 * Whether to force the synchronization of unchanged objects.
	 *
	 * @var bool|null
	 */
	public ?bool $force = false;

	/**
	 * The data to synchronize instead of fetching it, if any.
	 *
	 * @var array|null
	 */
	public ?array $data = null;

	/**
	 * The mutation type of a single-object run, if any.
	 *
	 * @var string|null
	 */
	public ?string $mutationType = null;

	/**
	 * Explicit override for the deletion-ratio guard.
	 *
	 * @var bool|null
	 */
	public ?bool $forceDeletion = false;

	/**
	 * The approval request to consume when the run is gated, if any.
	 *
	 * @var string|null
	 */
	public ?string $approvalRequestId = null;

	/**
	 * The active execution trace context.
	 *
	 * @var ExecutionTraceContext|null
	 */
	public ?ExecutionTraceContext $trace = null;

	/**
	 * The resolved source, when the run was given a source location.
	 *
	 * @var array|null
	 */
	public ?array $source = null;

	/**
	 * The synchronization's source configuration.
	 *
	 * @var array
	 */
	public array $sourceConfig = [];

	/**
	 * The result that is stored on the run log.
	 *
	 * @var array
	 */
	public array $result = [];

	/**
	 * The fetched objects; null on a single-object delete run.
	 *
	 * @var array|null
	 */
	public ?array $objectList = null;

	/**
	 * How complete the fetch was.
	 *
	 * @var array
	 */
	public array $fetchInfo = ['complete' => true, 'pagesFetched' => 0, 'failureReason' => null];

	/**
	 * The rate-limit response that cut the fetch short, if any.
	 *
	 * @var TooManyRequestsHttpException|null
	 */
	public ?TooManyRequestsHttpException $rateLimitException = null;

	/**
	 * The approval request that let a gated run through, if any.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $gatedApprovalRequest = null;

	/**
	 * The ids of the target objects this run wrote.
	 *
	 * @var array
	 */
	public array $targetIds = [];

	/**
	 * The processing time of each object, in milliseconds.
	 *
	 * @var float[]
	 */
	public array $objectTimes = [];
}//end class
