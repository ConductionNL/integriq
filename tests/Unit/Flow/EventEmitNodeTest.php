<?php

/**
 * Unit tests for EventEmitNode (`openconnector.event-emit`).
 *
 * The core semantics under test: one CloudEvent per item through the existing
 * `EventService::emitCloudEvent()` pipeline, a templated subject resolved per
 * item, and a failed emit that is explicit error state or a raise — never a
 * success-shaped summary.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Flow;

use OCA\Integriq\Exception\FlowNodeException;
use OCA\Integriq\Flow\EventEmitNode;
use OCA\Integriq\Flow\FlowNodeSupport;
use OCA\Integriq\Service\EventService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tests for the event-emit flow node.
 */
class EventEmitNodeTest extends TestCase {

	/**
	 * The event pipeline double.
	 *
	 * @var EventService&MockObject
	 */
	private $eventService;

	/**
	 * The node under test.
	 *
	 * @var EventEmitNode
	 */
	private EventEmitNode $node;

	/**
	 * Build the node with doubles for everything it delegates to.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->eventService = $this->createMock(EventService::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(EventService::class)->willReturn($this->eventService);

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
		$urlGenerator->method('imagePath')->willReturn('/core/img/actions/share.svg');

		$this->node = new EventEmitNode(
			container: $container,
			l10n: $l10n,
			urlGenerator: $urlGenerator,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A minimal valid step config.
	 *
	 * @param array $overrides Keys to override.
	 *
	 * @return array The config.
	 */
	private function config(array $overrides = []): array {
		return array_merge(
			[
				'type' => 'nl.example.object.updated',
				'source' => 'https://example.org/integriq',
			],
			$overrides
		);

	}//end config()

	/**
	 * The palette metadata is present and app-namespaced.
	 *
	 * @return void
	 */
	public function testPaletteMetadata(): void {
		$this->assertSame('openconnector.event-emit', $this->node->getId());
		$this->assertNotSame('', $this->node->getDisplayName());
		$this->assertNotSame('', $this->node->getDescription());
		$this->assertNotSame('', $this->node->getIcon());
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($this->node->isAvailableForScope(-1));

	}//end testPaletteMetadata()

	/**
	 * A step naming no event type is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateConfigRequiresType(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/type/');

		$this->node->validateConfig(config: $this->config(overrides: ['type' => '']));

	}//end testValidateConfigRequiresType()

	/**
	 * A step naming no source is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateConfigRequiresSource(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/source/');

		$this->node->validateConfig(config: $this->config(overrides: ['source' => ' ']));

	}//end testValidateConfigRequiresSource()

	/**
	 * One event is emitted per item, with the item's record as data and a
	 * per-item templated subject.
	 *
	 * @return void
	 */
	public function testEmitsOneEventPerItem(): void {
		$subjects = [];
		$this->eventService->expects($this->exactly(2))
			->method('emitCloudEvent')
			->willReturnCallback(
				function (string $type, string $source, ?string $subject, array $data) use (&$subjects): array {
					$this->assertSame('nl.example.object.updated', $type);
					$subjects[] = $subject;

					return [['message' => 'm1']];
				}
			);

		$out = $this->node->execute(
			items: [
				['json' => ['issue' => ['number' => 41]]],
				['json' => ['issue' => ['number' => 42]]],
			],
			config: $this->config(overrides: ['subject' => 'issue/{{issue.number}}']),
			context: []
		);

		$this->assertSame(['issue/41', 'issue/42'], $subjects);
		$this->assertCount(2, $out);
		$this->assertTrue($out[0]['json']['eventResult']['emitted']);
		$this->assertSame(1, $out[1]['json']['eventResult']['messageCount']);

	}//end testEmitsOneEventPerItem()

	/**
	 * An empty input list emits nothing and returns an empty list.
	 *
	 * @return void
	 */
	public function testEmptyInputEmitsNothing(): void {
		$this->eventService->expects($this->never())->method('emitCloudEvent');

		$this->assertSame([], $this->node->execute(items: [], config: $this->config(), context: []));

	}//end testEmptyInputEmitsNothing()

	/**
	 * A failed emit raises under the default policy so `onError` decides.
	 *
	 * @return void
	 */
	public function testFailedEmitRaisesByDefault(): void {
		$this->eventService->method('emitCloudEvent')->willThrowException(new RuntimeException('broker down'));

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/broker down/');

		$this->node->execute(items: [['json' => []]], config: $this->config(), context: []);

	}//end testFailedEmitRaisesByDefault()

	/**
	 * Under `onError: continue` a failed item carries explicit error state,
	 * structurally distinct from a success — never a success-shaped summary.
	 *
	 * @return void
	 */
	public function testFailedEmitUnderContinueCarriesErrorState(): void {
		$calls = 0;
		$this->eventService->method('emitCloudEvent')->willReturnCallback(
			static function () use (&$calls): array {
				$calls++;
				if ($calls === 1) {
					throw new RuntimeException('broker down');
				}

				return [];
			}
		);

		$out = $this->node->execute(
			items: [['json' => ['a' => 1]], ['json' => ['a' => 2]]],
			config: $this->config(overrides: ['onError' => 'continue']),
			context: []
		);

		$this->assertCount(2, $out);
		$this->assertArrayHasKey(FlowNodeSupport::ERROR_KEY, $out[0]['json']);
		$this->assertArrayNotHasKey('eventResult', $out[0]['json'], 'A failed item must not be shaped like a success');
		$this->assertTrue($out[1]['json']['eventResult']['emitted']);

	}//end testFailedEmitUnderContinueCarriesErrorState()
}//end class
