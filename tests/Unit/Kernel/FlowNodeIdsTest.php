<?php

/**
 * Tests for the flow node type ids.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Kernel
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

namespace OCA\Integriq\Tests\Unit\Kernel;

use OCA\Integriq\Flow\ApplyMappingNode;
use OCA\Integriq\Flow\ApprovalRequestNode;
use OCA\Integriq\Flow\ContractCommitNode;
use OCA\Integriq\Flow\ContractMatchNode;
use OCA\Integriq\Flow\ContractSweepNode;
use OCA\Integriq\Flow\EventEmitNode;
use OCA\Integriq\Flow\FetchFileNode;
use OCA\Integriq\Flow\SourceCallNode;
use OCA\Integriq\Flow\SourcePaginateNode;
use OCA\Integriq\Flow\SynchronizationRunNode;
use OCA\Integriq\Kernel\FlowNodeIds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the stored node type ids and that every node answers with its Kernel id.
 */
class FlowNodeIdsTest extends TestCase {

	/**
	 * Each node class, its Kernel id and the frozen stored value.
	 *
	 * @return array<string, array{class-string, string, string}>
	 */
	public static function nodes(): array {
		return [
			'apply mapping'       => [ApplyMappingNode::class, FlowNodeIds::APPLY_MAPPING, 'openconnector.apply-mapping'],
			'approval request'    => [ApprovalRequestNode::class, FlowNodeIds::APPROVAL_REQUEST, 'openconnector.approval-request'],
			'contract commit'     => [ContractCommitNode::class, FlowNodeIds::CONTRACT_COMMIT, 'openconnector.contract-commit'],
			'contract match'      => [ContractMatchNode::class, FlowNodeIds::CONTRACT_MATCH, 'openconnector.contract'],
			'contract sweep'      => [ContractSweepNode::class, FlowNodeIds::CONTRACT_SWEEP, 'openconnector.contract-sweep'],
			'event emit'          => [EventEmitNode::class, FlowNodeIds::EVENT_EMIT, 'openconnector.event-emit'],
			'fetch file'          => [FetchFileNode::class, FlowNodeIds::FETCH_FILE, 'openconnector.fetch-file'],
			'source call'         => [SourceCallNode::class, FlowNodeIds::SOURCE_CALL, 'openconnector.source-call'],
			'source paginate'     => [SourcePaginateNode::class, FlowNodeIds::SOURCE_PAGINATE, 'openconnector.source-paginate'],
			'synchronization run' => [SynchronizationRunNode::class, FlowNodeIds::SYNCHRONIZATION_RUN, 'openconnector.synchronization-run'],
		];

	}//end nodes()

	/**
	 * The stored value is unchanged and the node answers with it.
	 *
	 * @param string $class  The node class.
	 * @param string $id     The Kernel id.
	 * @param string $stored The value written into stored flow documents.
	 *
	 * @return void
	 */
	#[DataProvider('nodes')]
	public function testNodeAnswersWithTheFrozenId(string $class, string $id, string $stored): void {
		$this->assertSame(expected: $stored, actual: $id);
		$this->assertSame(expected: $stored, actual: constant($class . '::NODE_ID'));

	}//end testNodeAnswersWithTheFrozenId()
}//end class
