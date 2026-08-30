<?php

/**
 * Unit tests for ApplyMappingNode (`openconnector.apply-mapping`).
 *
 * The core semantics under test: the mapping runs once per item inside ONE
 * step execution, the result replaces the record unless an output key routes
 * it, and a failed item is explicit error state — never a silently unmapped
 * record shaped like a mapped one.
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
use OCA\Integriq\Flow\ApplyMappingNode;
use OCA\Integriq\Flow\FlowNodeSupport;
use OCA\Integriq\Flow\FlowOwner;
use OCA\Integriq\Service\MappingService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tests for the apply-mapping flow node.
 */
class ApplyMappingNodeTest extends TestCase {

	/**
	 * The mapping engine double.
	 *
	 * @var MappingService&MockObject
	 */
	private $mappingService;

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private $userManager;

	/**
	 * The node under test.
	 *
	 * @var ApplyMappingNode
	 */
	private ApplyMappingNode $node;

	/**
	 * Build the node with doubles for everything it delegates to.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mappingService = $this->createMock(MappingService::class);
		$this->userManager = $this->createMock(IUserManager::class);

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
		$urlGenerator->method('imagePath')->willReturn('/apps/integriq/img/flow-synchronization-run.svg');

		$this->node = new ApplyMappingNode(
			mappingService: $this->mappingService,
			flowOwner: new FlowOwner(
				userManager: $this->userManager,
				userSession: $this->createMock(IUserSession::class),
				l10n: $l10n
			),
			l10n: $l10n,
			urlGenerator: $urlGenerator,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * The palette metadata is present and app-namespaced.
	 *
	 * @return void
	 */
	public function testPaletteMetadata(): void {
		$this->assertSame('openconnector.apply-mapping', $this->node->getId());
		$this->assertNotSame('', $this->node->getDisplayName());
		$this->assertNotSame('', $this->node->getDescription());
		$this->assertNotSame('', $this->node->getIcon());
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($this->node->isAvailableForScope(-1));

	}//end testPaletteMetadata()

	/**
	 * The config vocabulary is pinned, and the form describes only known keys.
	 *
	 * @return void
	 */
	public function testConfigVocabularyIsPinned(): void {
		$this->assertSame(['mapping', 'input', 'output', 'onError'], $this->node->configKeys());

		foreach ($this->node->configForm() as $field) {
			$this->assertContains($field['key'], $this->node->configKeys());
			$this->assertNotSame('', (string)($field['label'] ?? ''));
		}

	}//end testConfigVocabularyIsPinned()

	/**
	 * An inline mapping definition is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsInlineMapping(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/inline definition/');

		$this->node->validateConfig(['mapping' => ['sourceProperty' => 'targetProperty']]);

	}//end testValidateRejectsInlineMapping()

	/**
	 * A step naming no mapping is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsMissingMapping(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/mapping/');

		$this->node->validateConfig(['output' => 'mapped']);

	}//end testValidateRejectsMissingMapping()

	/**
	 * A reserved output key is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsReservedOutputKey(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/reserved/');

		$this->node->validateConfig(['mapping' => 'demo-mapping', 'output' => 'json']);

	}//end testValidateRejectsReservedOutputKey()

	/**
	 * A blank `input` path is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsEmptyInputPath(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/input/');

		$this->node->validateConfig(['mapping' => 'demo-mapping', 'input' => ' ']);

	}//end testValidateRejectsEmptyInputPath()

	/**
	 * An unknown `onError` policy is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsUnknownOnErrorPolicy(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/onError/');

		$this->node->validateConfig(['mapping' => 'demo-mapping', 'onError' => 'explode']);

	}//end testValidateRejectsUnknownOnErrorPolicy()

	/**
	 * An `input` path that resolves to no object raises — nothing is mapped.
	 *
	 * @return void
	 */
	public function testUnresolvableInputPathRaises(): void {
		$this->givenOwner();

		$this->mappingService->expects($this->never())->method('executeMapping');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/did not resolve/');

		$this->node->execute(
			[['json' => ['name' => 'no payload key here']]],
			['mapping' => 'demo-mapping', 'input' => 'payload.data'],
			$this->context()
		);

	}//end testUnresolvableInputPathRaises()

	/**
	 * A run whose context names no step falls back to the node id in error state.
	 *
	 * @return void
	 */
	public function testUnnamedStepFallsBackToTheNodeId(): void {
		$this->givenOwner();

		$this->mappingService->method('executeMapping')
			->willThrowException(new RuntimeException('unmappable'));

		$out = $this->node->execute(
			[['json' => ['name' => 'bad']]],
			['mapping' => 'demo-mapping', 'onError' => 'continue'],
			['triggeredBy' => 'alice']
		);

		$this->assertSame('openconnector.apply-mapping', $out[0]['json'][FlowNodeSupport::ERROR_KEY]['step']);

	}//end testUnnamedStepFallsBackToTheNodeId()

	/**
	 * Every item is mapped in one execution, and the result replaces the record.
	 *
	 * @return void
	 */
	public function testMapsEveryItemReplacingTheRecord(): void {
		$this->givenOwner();

		$inputsSeen = [];
		$this->mappingService->expects($this->exactly(2))
			->method('executeMapping')
			->willReturnCallback(
				static function ($mapping, array $input) use (&$inputsSeen): array {
					$inputsSeen[] = ['mapping' => $mapping, 'input' => $input];

					return ['mapped' => $input['name']];
				}
			);

		$out = $this->node->execute(
			[
				['json' => ['name' => 'first', 'extra' => true]],
				['json' => ['name' => 'second']],
			],
			['mapping' => 'demo-mapping'],
			$this->context()
		);

		$this->assertCount(2, $out);
		$this->assertSame(['mapped' => 'first'], $out[0]['json']);
		$this->assertSame(['mapped' => 'second'], $out[1]['json']);
		$this->assertSame(['item' => 0], $out[0]['pairedItem']);
		$this->assertSame(['item' => 1], $out[1]['pairedItem']);
		$this->assertSame('demo-mapping', $inputsSeen[0]['mapping']);
		$this->assertSame(['name' => 'first', 'extra' => true], $inputsSeen[0]['input']);

	}//end testMapsEveryItemReplacingTheRecord()

	/**
	 * `input` scopes what is mapped and `output` routes the result beside it.
	 *
	 * @return void
	 */
	public function testInputPathAndOutputKeyModes(): void {
		$this->givenOwner();

		$this->mappingService->expects($this->once())
			->method('executeMapping')
			->willReturnCallback(
				function ($mapping, array $input): array {
					$this->assertSame(['a' => 1], $input);

					return ['b' => 2];
				}
			);

		$out = $this->node->execute(
			[['json' => ['raw' => ['a' => 1], 'keep' => 'me']]],
			['mapping' => 'demo-mapping', 'input' => 'raw', 'output' => 'mapped'],
			$this->context()
		);

		$this->assertCount(1, $out);
		$this->assertSame(['b' => 2], $out[0]['json']['mapped']);
		$this->assertSame('me', $out[0]['json']['keep']);
		$this->assertSame(['a' => 1], $out[0]['json']['raw']);

	}//end testInputPathAndOutputKeyModes()

	/**
	 * Under `continue` a failed item carries explicit error state and the page goes on.
	 *
	 * @return void
	 */
	public function testContinuePolicyWritesErrorState(): void {
		$this->givenOwner();

		$this->mappingService->method('executeMapping')->willReturnCallback(
			static function ($mapping, array $input): array {
				if (($input['name'] ?? null) === 'bad') {
					throw new RuntimeException('unmappable');
				}

				return ['mapped' => $input['name']];
			}
		);

		$out = $this->node->execute(
			[
				['json' => ['name' => 'bad']],
				['json' => ['name' => 'good']],
			],
			['mapping' => 'demo-mapping', 'onError' => 'continue'],
			$this->context()
		);

		$this->assertCount(2, $out);
		$this->assertSame('mapping', $out[0]['json'][FlowNodeSupport::ERROR_KEY]['kind']);
		$this->assertStringContainsString('unmappable', $out[0]['json'][FlowNodeSupport::ERROR_KEY]['message']);
		$this->assertSame('bad', $out[0]['json']['name']);
		$this->assertSame(['mapped' => 'good'], $out[1]['json']);

	}//end testContinuePolicyWritesErrorState()

	/**
	 * Under the default `stop` policy a failed item raises.
	 *
	 * @return void
	 */
	public function testStopPolicyRaises(): void {
		$this->givenOwner();

		$this->mappingService->method('executeMapping')
			->willThrowException(new RuntimeException('unmappable'));

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/unmappable/');

		$this->node->execute(
			[['json' => ['name' => 'bad']]],
			['mapping' => 'demo-mapping'],
			$this->context()
		);

	}//end testStopPolicyRaises()

	/**
	 * An empty input list maps nothing and returns nothing.
	 *
	 * @return void
	 */
	public function testEmptyInputMapsNothing(): void {
		$this->mappingService->expects($this->never())->method('executeMapping');

		$this->assertSame([], $this->node->execute([], ['mapping' => 'demo-mapping'], $this->context()));

	}//end testEmptyInputMapsNothing()

	/**
	 * A run context naming an owner and a step.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(): array {
		return ['triggeredBy' => 'alice', 'stepId' => 'step-map'];
	}//end context()

	/**
	 * Make the user manager resolve the run owner.
	 *
	 * @return IUser The owner double.
	 */
	private function givenOwner(): IUser {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->willReturn($owner);

		return $owner;
	}//end givenOwner()
}//end class
