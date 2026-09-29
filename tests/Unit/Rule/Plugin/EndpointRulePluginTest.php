<?php

/**
 * A custom rule runs a plug-in a sibling app registered
 * (gateway-endpoint-transform-and-plugins, REQ-GTP-002).
 *
 * Drives the real RuleService with the real registry. The sibling app's side
 * is a listener answering RegisterEndpointRulePluginsEvent, dispatched through
 * the dispatcher double the way Nextcloud would call a registered listener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Rule\Plugin
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Rule\Plugin;

use Exception;
use OCA\Integriq\Rule\Plugin\ConnectRelationsPlugin;
use OCA\Integriq\Rule\Plugin\EndpointRulePluginInterface;
use OCA\Integriq\Rule\Plugin\EndpointRulePluginRegistry;
use OCA\Integriq\Rule\Plugin\RegisterEndpointRulePluginsEvent;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\SoftwareCatalogueService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the endpoint rule plug-in point.
 */
class EndpointRulePluginTest extends TestCase {

	/**
	 * The software catalogue double connectRelations calls.
	 *
	 * @var SoftwareCatalogueService&MockObject
	 */
	private SoftwareCatalogueService&MockObject $catalogueService;

	/**
	 * Set up the catalogue double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->catalogueService = $this->createMock(SoftwareCatalogueService::class);
	}//end setUp()

	/**
	 * A plug-in a sibling app would ship: masks the BSN in the body.
	 *
	 * @return EndpointRulePluginInterface The plug-in.
	 */
	private function bsnMask(): EndpointRulePluginInterface {
		return new class implements EndpointRulePluginInterface {
			/**
			 * The id.
			 *
			 * @return string
			 */
			public function id(): string {
				return 'bsn-mask';
			}

			/**
			 * Mask the BSN.
			 *
			 * @param array $rule The rule.
			 * @param array $data The data.
			 *
			 * @return array
			 */
			public function process(array $rule, array $data): array {
				$data['body']['bsn'] = '*****' . substr((string)($data['body']['bsn'] ?? ''), -4);
				return $data;
			}
		};
	}//end bsnMask()

	/**
	 * A dispatcher that plays the sibling app's registered listener.
	 *
	 * @param EndpointRulePluginInterface ...$plugins What the sibling registers.
	 *
	 * @return IEventDispatcher&MockObject The dispatcher.
	 */
	private function siblingDispatcher(EndpointRulePluginInterface ...$plugins): IEventDispatcher&MockObject {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(
				function ($event) use ($plugins): void {
					self::assertInstanceOf(RegisterEndpointRulePluginsEvent::class, $event);
					foreach ($plugins as $plugin) {
						$event->register($plugin);
					}
				}
			);
		return $dispatcher;
	}//end siblingDispatcher()

	/**
	 * The real RuleService on a given registry.
	 *
	 * @param EndpointRulePluginRegistry|null $registry The registry, or null for the default.
	 *
	 * @return RuleService The service.
	 */
	private function ruleService(?EndpointRulePluginRegistry $registry): RuleService {
		return new RuleService(
			$this->createMock(ObjectService::class),
			$this->catalogueService,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(CallService::class),
			ObjectServiceMockBuilder::make($this),
			null,
			$registry,
		);
	}//end ruleService()

	/**
	 * GIVEN a sibling app that registered `bsn-mask`, WHEN a custom rule names
	 * it, THEN the plug-in's output is what the pipeline carries on with.
	 *
	 * @return void
	 */
	public function testACustomRuleRunsThePluginASiblingAppRegistered(): void {
		$registry = new EndpointRulePluginRegistry(
			plugins: [new ConnectRelationsPlugin($this->catalogueService)],
			dispatcher: $this->siblingDispatcher($this->bsnMask()),
		);
		$rule = ObjectServiceMockBuilder::objectEntity(
			$this,
			['type' => 'custom', 'configuration' => ['plugin' => 'bsn-mask']],
			'rule-1'
		);

		$result = $this->ruleService($registry)->processCustomRule($rule, ['body' => ['bsn' => '123456782']]);

		$this->assertSame(['body' => ['bsn' => '*****6782']], $result);
		$this->assertSame(['bsn-mask', 'connectRelations'], $registry->ids());
	}//end testACustomRuleRunsThePluginASiblingAppRegistered()

	/**
	 * An id no plug-in answers to is refused, naming the id.
	 *
	 * @return void
	 */
	public function testAnIdNoPluginAnswersToIsRefusedByName(): void {
		$registry = new EndpointRulePluginRegistry(dispatcher: $this->siblingDispatcher());
		$rule = ObjectServiceMockBuilder::objectEntity(
			$this,
			['type' => 'custom', 'configuration' => ['plugin' => 'bsn-mask']],
			'rule-1'
		);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage("No rule plug-in 'bsn-mask' is installed");

		$this->ruleService($registry)->processCustomRule($rule, []);
	}//end testAnIdNoPluginAnswersToIsRefusedByName()

	/**
	 * A rule written before plug-ins, naming `connectRelations` in
	 * `configuration.type`, runs as before, also on the default registry.
	 *
	 * @return void
	 */
	public function testConnectRelationsRunsUnchanged(): void {
		$modelId = '9b2c7a4e-5f1d-4c3b-8a6e-2d1f0e9c8b7a';
		$this->catalogueService->expects($this->once())->method('extendModel')->with($modelId);
		$rule = ObjectServiceMockBuilder::objectEntity(
			$this,
			['type' => 'custom', 'configuration' => ['type' => 'connectRelations']],
			'rule-1'
		);

		$result = $this->ruleService(null)->processCustomRule($rule, ['path' => 'models/' . $modelId]);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(['message' => 'Connected views succesfully'], $result->getData());
	}//end testConnectRelationsRunsUnchanged()

	/**
	 * The first plug-in to claim an id keeps it.
	 *
	 * @return void
	 */
	public function testASecondPluginWithATakenIdIsIgnored(): void {
		$registry = new EndpointRulePluginRegistry(plugins: [$this->bsnMask()]);

		$this->assertFalse($registry->register($this->bsnMask()));
		$this->assertSame(['bsn-mask'], $registry->ids());
	}//end testASecondPluginWithATakenIdIsIgnored()
}//end class
