<?php

/**
 * The context one endpoint rule pipeline run hands to each rule.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;

/**
 * Carries the inputs of one EndpointService::processRules() run, so each rule
 * handler names the run instead of the values every rule type may need.
 *
 * @spec openspec/specs/rule-pipeline/spec.md
 */
class EndpointRuleDispatch {

	/**
	 * The endpoint being processed.
	 *
	 * @var ObjectEntity
	 */
	public ObjectEntity $endpoint;

	/**
	 * The endpoint's object data.
	 *
	 * @var array
	 */
	public array $endpointData = [];

	/**
	 * The incoming request.
	 *
	 * @var IRequest
	 */
	public IRequest $request;

	/**
	 * The rule timing being run: "before" or "after".
	 *
	 * @var string
	 */
	public string $timing = 'before';

	/**
	 * The object id, for rules scoped to a single object.
	 *
	 * @var string|null
	 */
	public ?string $objectId = null;

	/**
	 * The flow token threaded through the rule chain.
	 *
	 * @var FlowToken|null
	 */
	public ?FlowToken $flowToken = null;

	/**
	 * On a resumed run, the order of the suspended approval rule; rules up to it already ran.
	 *
	 * @var integer|null
	 */
	public ?int $resumeAfterOrder = null;

	/**
	 * The active execution trace context, or null when the run is not traced.
	 *
	 * @var ExecutionTraceContext|null
	 */
	public ?ExecutionTraceContext $trace = null;

	/**
	 * Whether write-shaped rule types skip their write.
	 *
	 * @var boolean
	 */
	public bool $dryRun = false;

	/**
	 * The rule being applied.
	 *
	 * @var ObjectEntity
	 */
	public ObjectEntity $rule;
}//end class
