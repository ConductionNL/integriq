<?php

/**
 * Unit tests for ContractMatchNode (`openconnector.contract`).
 *
 * The core semantics under test: ONE bulk lookup for the whole page (its
 * filter carries the collected origin ids), the create/update/skip decision
 * per item — where `skip` demands an equal hash AND a completed target side —
 * and `invalid` for an item without an origin id, which is never sent to the
 * lookup.
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

use OCA\OpenConnector\Flow\ContractMatchNode;
use OCA\OpenConnector\Flow\FlowOwner;
use OCA\OpenConnector\Service\SynchronizationContractService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Tests for the contract-match flow node.
 */
class ContractMatchNodeTest extends TestCase {

	/**
	 * The contract store double.
	 *
	 * @var SynchronizationContractService&MockObject
	 */
	private $contractService;

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private $userManager;

	/**
	 * The node under test.
	 *
	 * @var ContractMatchNode
	 */
	private ContractMatchNode $node;

	/**
	 * Build the node with doubles for everything it delegates to.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->contractService = $this->createMock(SynchronizationContractService::class);
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
		$urlGenerator->method('imagePath')->willReturn('/apps/openconnector/img/flow-synchronization-run.svg');

		$this->node = new ContractMatchNode(
			synchronizationContractService: $this->contractService,
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
		$this->assertSame('openconnector.contract', $this->node->getId());
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
		$this->assertSame(
			['synchronization', 'idPosition', 'hashPosition', 'output', 'onError'],
			$this->node->configKeys()
		);

		foreach ($this->node->configForm() as $field) {
			$this->assertContains($field['key'], $this->node->configKeys());
			$this->assertNotSame('', (string)($field['label'] ?? ''));
		}

	}//end testConfigVocabularyIsPinned()

	/**
	 * A step naming no synchronization is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsMissingSynchronization(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/synchronization/');

		$this->node->validateConfig(['output' => 'contract']);

	}//end testValidateRejectsMissingSynchronization()

	/**
	 * An inline synchronization definition is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsInlineDefinition(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/inline definition/');

		$this->node->validateConfig(['synchronization' => ['name' => 'inline']]);

	}//end testValidateRejectsInlineDefinition()

	/**
	 * A credential-bearing field is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsForbiddenFields(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node->validateConfig(['synchronization' => 'demo-sync', 'apiKey' => 'nope']);

	}//end testValidateRejectsForbiddenFields()

	/**
	 * ONE lookup for the page; create/update/skip/invalid decided per item.
	 *
	 * @return void
	 */
	public function testOneBulkLookupDecidesTheWholePage(): void {
		$this->givenOwner();

		$unchangedJson = ['id' => 'o-1', 'b' => 2, 'a' => 1];
		$changedJson = ['id' => 'o-2', 'value' => 'new'];
		$newJson = ['id' => 'o-3', 'value' => 'fresh'];

		$filtersSeen = [];
		$this->contractService->expects($this->once())
			->method('findAllObjects')
			->willReturnCallback(
				function (array $filters) use (&$filtersSeen, $unchangedJson): array {
					$filtersSeen[] = $filters;

					return [
						$this->contractObject(
							uuid: 'c-1',
							payload: [
								'uuid' => 'c-1',
								'originId' => 'o-1',
								'originHash' => $this->hashOf($unchangedJson),
								'targetId' => 't-1',
								'targetHash' => 'th-1',
							]
						),
						$this->contractObject(
							uuid: 'c-2',
							payload: [
								'uuid' => 'c-2',
								'originId' => 'o-2',
								'originHash' => 'stale-hash',
								'targetId' => 't-2',
								'targetHash' => 'th-2',
							]
						),
					];
				}
			);

		$out = $this->node->execute(
			[
				['json' => $unchangedJson],
				['json' => $changedJson],
				['json' => $newJson],
				['json' => ['value' => 'no id here']],
			],
			['synchronization' => 'demo-sync'],
			$this->context()
		);

		// The single lookup carried the page's collected origin ids — and NOT
		// the id-less item.
		$this->assertSame(
			[['synchronizationId' => 'demo-sync', 'originId' => ['o-1', 'o-2', 'o-3']]],
			$filtersSeen
		);

		$this->assertCount(4, $out);

		$this->assertSame('skip', $out[0]['json']['contract']['outcome']);
		$this->assertSame('c-1', $out[0]['json']['contract']['contractUuid']);
		$this->assertSame('t-1', $out[0]['json']['contract']['targetId']);
		$this->assertSame($this->hashOf($unchangedJson), $out[0]['json']['contract']['originHash']);

		$this->assertSame('update', $out[1]['json']['contract']['outcome']);
		$this->assertSame('c-2', $out[1]['json']['contract']['contractUuid']);

		$this->assertSame('create', $out[2]['json']['contract']['outcome']);
		$this->assertSame('o-3', $out[2]['json']['contract']['originId']);
		$this->assertArrayNotHasKey('contractUuid', $out[2]['json']['contract']);

		$this->assertSame('invalid', $out[3]['json']['contract']['outcome']);

	}//end testOneBulkLookupDecidesTheWholePage()

	/**
	 * An equal hash on a contract without a completed target side is `update`.
	 *
	 * @return void
	 */
	public function testEqualHashWithoutTargetIsUpdateNotSkip(): void {
		$this->givenOwner();

		$json = ['id' => 'o-1', 'value' => 'same'];

		$this->contractService->method('findAllObjects')->willReturn(
			[
				$this->contractObject(
					uuid: 'c-1',
					payload: [
						'uuid' => 'c-1',
						'originId' => 'o-1',
						'originHash' => $this->hashOf($json),
						'targetId' => null,
						'targetHash' => null,
					]
				),
			]
		);

		$out = $this->node->execute(
			[['json' => $json]],
			['synchronization' => 'demo-sync'],
			$this->context()
		);

		$this->assertSame('update', $out[0]['json']['contract']['outcome']);

	}//end testEqualHashWithoutTargetIsUpdateNotSkip()

	/**
	 * `hashPosition` scopes the hash so volatile siblings cannot force updates.
	 *
	 * @return void
	 */
	public function testHashPositionScopesTheHash(): void {
		$this->givenOwner();

		$this->contractService->method('findAllObjects')->willReturn(
			[
				$this->contractObject(
					uuid: 'c-1',
					payload: [
						'uuid' => 'c-1',
						'originId' => 'o-1',
						'originHash' => $this->hashOf(['x' => 1]),
						'targetId' => 't-1',
						'targetHash' => 'th-1',
					]
				),
			]
		);

		$out = $this->node->execute(
			[['json' => ['id' => 'o-1', 'data' => ['x' => 1], 'fetchedAt' => 'just now']]],
			['synchronization' => 'demo-sync', 'hashPosition' => 'data', 'output' => 'decision'],
			$this->context()
		);

		$this->assertSame('skip', $out[0]['json']['decision']['outcome']);
		$this->assertSame($this->hashOf(['x' => 1]), $out[0]['json']['decision']['originHash']);

	}//end testHashPositionScopesTheHash()

	/**
	 * A page with no usable ids performs no lookup at all.
	 *
	 * @return void
	 */
	public function testPageWithoutIdsPerformsNoLookup(): void {
		$this->givenOwner();

		$this->contractService->expects($this->never())->method('findAllObjects');

		$out = $this->node->execute(
			[['json' => ['value' => 'no id']]],
			['synchronization' => 'demo-sync'],
			$this->context()
		);

		$this->assertSame('invalid', $out[0]['json']['contract']['outcome']);

	}//end testPageWithoutIdsPerformsNoLookup()

	/**
	 * A run context naming an owner and a step.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(): array {
		return ['triggeredBy' => 'alice', 'stepId' => 'step-contract'];
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

	/**
	 * Build a contract ObjectEntity carrying the given payload.
	 *
	 * A real entity rather than a mock: `jsonSerialize()` merges the payload
	 * with entity metadata, and the node reads THAT shape.
	 *
	 * @param string $uuid The contract uuid.
	 * @param array $payload The contract payload.
	 *
	 * @return ObjectEntity The contract object.
	 */
	private function contractObject(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);

		return $entity;
	}//end contractObject()

	/**
	 * The node's hash recipe: md5 over a serialised, recursively key-sorted copy.
	 *
	 * @param array $json The hash input.
	 *
	 * @return string The expected origin hash.
	 */
	private function hashOf(array $json): string {
		$sort = static function (array $value) use (&$sort): array {
			ksort($value);
			foreach (array_keys($value) as $key) {
				if (is_array($value[$key]) === true) {
					$value[$key] = $sort($value[$key]);
				}
			}

			return $value;
		};

		return md5(serialize($sort($json)));
	}//end hashOf()
}//end class
