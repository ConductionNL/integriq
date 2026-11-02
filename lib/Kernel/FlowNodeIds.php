<?php

/**
 * The node type ids of Integriq's flow nodes.
 *
 * @category Kernel
 * @package  OCA\Integriq\Kernel
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

namespace OCA\Integriq\Kernel;

/**
 * The node type ids of Integriq's flow nodes.
 *
 * Each node keeps its NODE_ID, which reads its value here, so code that
 * generates flow documents can name a node type without depending on the
 * node. The values are written into stored flow documents and stay frozen
 * on `openconnector.*`: renaming one makes every stored flow that uses it
 * reference a node type nothing answers to.
 *
 * @spec exclude constants only, no behaviour of their own
 */
final class FlowNodeIds {

	/**
	 * The node type id of {@see \OCA\Integriq\Flow\ApplyMappingNode}.
	 *
	 * @var string
	 */
	public const APPLY_MAPPING = 'openconnector.apply-mapping';

	/**
	 * The node type id of {@see \OCA\Integriq\Flow\ApprovalRequestNode}.
	 *
	 * @var string
	 */
	public const APPROVAL_REQUEST = 'openconnector.approval-request';

	/**
	 * The node type id of {@see \OCA\Integriq\Flow\ContractCommitNode}.
	 *
	 * @var string
	 */
	public const CONTRACT_COMMIT = 'openconnector.contract-commit';

	/**
	 * The node type id of {@see \OCA\Integriq\Flow\ContractMatchNode}.
	 *
	 * @var string
	 */
	public const CONTRACT_MATCH = 'openconnector.contract';

	/**
	 * The node type id of {@see \OCA\Integriq\Flow\ContractSweepNode}.
	 *
	 * @var string
	 */
	public const CONTRACT_SWEEP = 'openconnector.contract-sweep';

	/**
	 * The node type id of {@see \OCA\Integriq\Flow\EventEmitNode}.
	 *
	 * @var string
	 */
	public const EVENT_EMIT = 'openconnector.event-emit';

	/**
	 * The node type id of {@see \OCA\Integriq\Flow\FetchFileNode}.
	 *
	 * @var string
	 */
	public const FETCH_FILE = 'openconnector.fetch-file';

	/**
	 * The node type id of {@see \OCA\Integriq\Flow\SourceCallNode}.
	 *
	 * @var string
	 */
	public const SOURCE_CALL = 'openconnector.source-call';

	/**
	 * The node type id of {@see \OCA\Integriq\Flow\SourcePaginateNode}.
	 *
	 * @var string
	 */
	public const SOURCE_PAGINATE = 'openconnector.source-paginate';

	/**
	 * The node type id of {@see \OCA\Integriq\Flow\SynchronizationRunNode}.
	 *
	 * @var string
	 */
	public const SYNCHRONIZATION_RUN = 'openconnector.synchronization-run';
}//end class
