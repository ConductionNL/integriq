<?php

/**
 * Unit tests for FlowNodeListener.
 *
 * Asserts the palette contribution itself: every node id appears with
 * non-empty metadata, and a colliding id is REFUSED by the registry rather
 * than silently displacing the node already registered under it.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Flow;

use OCA\OpenConnector\Flow\ApplyMappingNode;
use OCA\OpenConnector\Flow\ContractCommitNode;
use OCA\OpenConnector\Flow\ContractMatchNode;
use OCA\OpenConnector\Flow\ContractSweepNode;
use OCA\OpenConnector\Flow\FlowNodeListener;
use OCA\OpenConnector\Flow\FlowOwner;
use OCA\OpenConnector\Flow\SourceCallNode;
use OCA\OpenConnector\Flow\SynchronizationRunNode;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\SynchronizationContractService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Service\Flow\FlowConcurrency;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\OpenRegister\Service\ObjectService as OpenRegisterObjectService;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the flow node registration listener.
 */
class FlowNodeListenerTest extends TestCase {

	/**
	 * The listener under test.
	 *
	 * @var FlowNodeListener
	 */
	private FlowNodeListener $listener;

	/**
	 * The source-call node.
	 *
	 * @var SourceCallNode
	 */
	private SourceCallNode $sourceCallNode;

	/**
	 * Build the listener over real node instances with doubled dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				if (is_array($parameters) === false || $parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/openconnector/img/icon.svg');

		$flowOwner = new FlowOwner(
			userManager: $this->createMock(IUserManager::class),
			userSession: $this->createMock(IUserSession::class),
			l10n: $l10n
		);

		$this->sourceCallNode = new SourceCallNode(
			callService: $this->createMock(CallService::class),
			concurrency: new FlowConcurrency(),
			objectService: $this->createMock(OpenRegisterObjectService::class),
			flowOwner: $flowOwner,
			l10n: $l10n,
			urlGenerator: $urlGenerator,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->listener = new FlowNodeListener(
			sourceCallNode: $this->sourceCallNode,
			synchronizationRunNode: new SynchronizationRunNode(
				synchronizationService: $this->createMock(SynchronizationService::class),
				flowOwner: $flowOwner,
				l10n: $l10n,
				urlGenerator: $urlGenerator,
				logger: $this->createMock(LoggerInterface::class)
			),
			applyMappingNode: new ApplyMappingNode(
				mappingService: $this->createMock(MappingService::class),
				flowOwner: $flowOwner,
				l10n: $l10n,
				urlGenerator: $urlGenerator,
				logger: $this->createMock(LoggerInterface::class)
			),
			contractMatchNode: new ContractMatchNode(
				synchronizationContractService: $this->createMock(SynchronizationContractService::class),
				flowOwner: $flowOwner,
				l10n: $l10n,
				urlGenerator: $urlGenerator,
				logger: $this->createMock(LoggerInterface::class)
			),
			contractCommitNode: new ContractCommitNode(
				synchronizationContractService: $this->createMock(SynchronizationContractService::class),
				flowOwner: $flowOwner,
				l10n: $l10n,
				urlGenerator: $urlGenerator,
				logger: $this->createMock(LoggerInterface::class)
			),
			contractSweepNode: new ContractSweepNode(
				synchronizationService: $this->createMock(SynchronizationService::class),
				flowOwner: $flowOwner,
				l10n: $l10n,
				urlGenerator: $urlGenerator,
				logger: $this->createMock(LoggerInterface::class)
			)
		);

	}//end setUp()

	/**
	 * Every node appears in the palette with non-empty metadata.
	 *
	 * @return void
	 */
	public function testEveryNodeAppearsInThePalette(): void {
		$registry = new FlowNodeRegistry();

		$this->listener->handle(new RegisterFlowNodesEvent(registry: $registry));

		$nodes = $registry->all();

		$this->assertArrayHasKey('openconnector.source-call', $nodes);
		$this->assertArrayHasKey('openconnector.synchronization-run', $nodes);
		$this->assertArrayHasKey('openconnector.apply-mapping', $nodes);
		$this->assertArrayHasKey('openconnector.contract', $nodes);
		$this->assertArrayHasKey('openconnector.contract-commit', $nodes);
		$this->assertArrayHasKey('openconnector.contract-sweep', $nodes);
		$this->assertCount(6, $nodes);

		foreach ($nodes as $node) {
			$this->assertNotSame('', $node->getDisplayName());
			$this->assertNotSame('', $node->getDescription());
			$this->assertNotSame('', $node->getIcon());
		}

	}//end testEveryNodeAppearsInThePalette()

	/**
	 * A colliding node id is refused, not silently displaced.
	 *
	 * @return void
	 */
	public function testCollidingNodeIdIsRefused(): void {
		$registry = new FlowNodeRegistry();
		$registry->register(node: $this->sourceCallNode);

		$this->listener->handle(new RegisterFlowNodesEvent(registry: $registry));

		$this->assertSame(['openconnector.source-call'], $registry->refused);
		$this->assertSame($this->sourceCallNode, $registry->all()['openconnector.source-call']);

	}//end testCollidingNodeIdIsRefused()

	/**
	 * An unrelated event is ignored.
	 *
	 * @return void
	 */
	public function testUnrelatedEventIsIgnored(): void {
		$this->listener->handle(new Event());

		$this->addToAssertionCount(1);

	}//end testUnrelatedEventIsIgnored()

}//end class
