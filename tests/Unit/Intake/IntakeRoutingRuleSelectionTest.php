<?php

/**
 * Characterisation tests for IntakeRoutingService's rule selection,
 * condition matching and rule validation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Intake;

use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\Integriq\Intake\Adapter\FormSubmissionAdapter;
use OCA\Integriq\Intake\Adapter\PublicSpaceReportAdapter;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Intake\IntakeRoutingService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which stored rules are candidates, in what order, how each
 * condition operator decides, and every refusal of validateRule().
 */
class IntakeRoutingRuleSelectionTest extends TestCase {

	/**
	 * What findAll() answers.
	 *
	 * @var mixed
	 */
	private mixed $answer = ['results' => []];

	/**
	 * Every findAll() call, as [config, _rbac, _multitenancy].
	 *
	 * @var array
	 */
	private array $reads = [];

	/**
	 * The mocked schema mapper.
	 *
	 * @var SchemaMapper
	 */
	private $schemaMapper;

	/**
	 * The service under test.
	 *
	 * @var IntakeRoutingService
	 */
	private IntakeRoutingService $service;

	/**
	 * Build the service over a recording object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->reads[] = [$config, $_rbac, $_multitenancy];
				return $this->answer;
			}
		);
		$this->schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$this->service = new IntakeRoutingService(
			$objectService,
			$this->createMock(IEventDispatcher::class),
			$this->schemaMapper,
			$this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * A message on a channel.
	 *
	 * @param string $channelId The channel.
	 * @param string $text      The text.
	 *
	 * @return InboundMessage
	 */
	private function message(string $channelId, string $text): InboundMessage {
		return new InboundMessage($channelId, 'ext-1', ['name' => 'Jan'], $text, [], null, [], [], '2026-09-15T11:00:00+02:00', ['category' => 'licht', 'tags' => 'a']);

	}//end message()

	/**
	 * Stored rule rows.
	 *
	 * @param array ...$rules The rules.
	 *
	 * @return array
	 */
	private function rows(array ...$rules): array {
		$rows = [];
		foreach ($rules as $index => $rule) {
			$rows[] = ObjectServiceMockBuilder::objectEntity($this, $rule, 'rule-' . $index);
		}

		return $rows;

	}//end rows()

	/**
	 * Rules are read as the engine, filtered on channel and enabled, and
	 * re-checked: rows that are no entity, another channel's or disabled
	 * rules never match; the lowest order that matches wins.
	 *
	 * @return void
	 */
	public function testTheLowestOrderedEnabledRuleOfTheChannelThatMatchesWins(): void {
		$rows = $this->rows(
			['name' => 'other-channel', 'channelId' => 'web', 'order' => 1],
			['name' => 'disabled', 'channelId' => 'chat', 'isEnabled' => false, 'order' => 1],
			['name' => 'late', 'channelId' => 'chat', 'order' => 30],
			['name' => 'no-match', 'channelId' => 'chat', 'order' => 5, 'condition' => ['field' => 'text', 'value' => 'nope']],
			['name' => 'early', 'channelId' => 'chat', 'order' => '20'],
			['name' => 'no-order', 'channelId' => 'chat', 'isEnabled' => null, 'condition' => ['field' => 'text', 'operator' => 'exists']]
		);
		$this->answer = ['results' => array_merge(['not-an-entity'], $rows)];

		$rule = $this->service->firstMatchingRule($this->message('chat', 'hallo'));

		$this->assertSame('no-order', $rule['name']);
		$this->assertSame(
			[[['filters' => ['register' => 'integriq', 'schema' => 'intake_routing_rule', 'channelId' => 'chat', 'isEnabled' => true]], false, false]],
			$this->reads
		);

		$this->answer = $this->rows(['name' => 'late', 'channelId' => 'chat', 'order' => 30], ['name' => 'early', 'channelId' => 'chat', 'order' => 20]);
		$this->assertSame('early', $this->service->firstMatchingRule($this->message('chat', 'x'))['name']);

	}//end testTheLowestOrderedEnabledRuleOfTheChannelThatMatchesWins()

	/**
	 * No rule, or an answer that is no list, matches nothing.
	 *
	 * @return void
	 */
	public function testNoCandidateRuleMatchesNothing(): void {
		$this->answer = ['results' => 'broken'];
		$this->assertNull($this->service->firstMatchingRule($this->message('chat', 'x')));

		$this->answer = ['results' => $this->rows(['channelId' => 'chat', 'condition' => ['field' => 'text', 'value' => 'y']])];
		$this->assertNull($this->service->firstMatchingRule($this->message('chat', 'x')));

	}//end testNoCandidateRuleMatchesNothing()

	/**
	 * Every condition operator, and the absent or empty condition.
	 *
	 * @return void
	 */
	public function testEveryConditionOperatorDecides(): void {
		$message = $this->message('chat', 'Lantaarn KAPOT');
		$cases = [
			[[], true],
			[['condition' => []], true],
			[['condition' => 'text'], true],
			[['condition' => ['field' => 'text', 'operator' => 'contains', 'value' => 'kapot']], true],
			[['condition' => ['field' => 'text', 'operator' => 'contains', 'value' => 'heel']], false],
			[['condition' => ['field' => 'location', 'operator' => 'contains', 'value' => '']], false],
			[['condition' => ['field' => 'fields.category', 'operator' => 'exists']], true],
			[['condition' => ['field' => 'fields.missing', 'operator' => 'exists']], false],
			[['condition' => ['field' => '', 'operator' => 'exists']], false],
			[['condition' => ['field' => 'fields.category', 'operator' => 'in', 'value' => ['licht', 'water']]], true],
			[['condition' => ['field' => 'fields.category', 'operator' => 'in', 'value' => 'licht']], false],
			[['condition' => ['field' => 'correspondent.name', 'value' => 'Jan']], true],
			[['condition' => ['field' => 'channelId', 'operator' => 'unknown', 'value' => 'chat']], true],
			[['condition' => ['field' => 'externalId', 'operator' => 'equals', 'value' => 'ext-2']], false],
		];
		foreach ($cases as [$rule, $expected]) {
			$this->assertSame($expected, $this->service->matches($rule, $message), json_encode($rule));
		}

	}//end testEveryConditionOperatorDecides()

	/**
	 * A case type with the given fields.
	 *
	 * @param array $fields The field names.
	 *
	 * @return object
	 */
	private function caseType(array $fields): object {
		return new class(array_fill_keys($fields, ['type' => 'string'])) {
			/**
			 * Constructor.
			 *
			 * @param array $properties The properties.
			 */
			public function __construct(private readonly array $properties) {
			}

			/**
			 * The declared properties.
			 *
			 * @return array
			 */
			public function getProperties(): array {
				return $this->properties;
			}
		};

	}//end caseType()

	/**
	 * Validate a rule and return the refusal message, or null when accepted.
	 *
	 * @param array $rule The rule.
	 *
	 * @return string|null
	 */
	private function refusal(array $rule): ?string {
		$registry = new IntakeChannelRegistry($this->createMock(LoggerInterface::class), [new FormSubmissionAdapter(), new PublicSpaceReportAdapter()]);
		try {
			$this->service->validateRule($rule, $registry);
		} catch (IntakeRoutingException $e) {
			return $e->getMessage();
		}

		return null;

	}//end refusal()

	/**
	 * Every refusal of validateRule(), in the order the checks run.
	 *
	 * @return void
	 */
	public function testEveryRuleRefusalNamesItsCause(): void {
		$this->schemaMapper->method('find')->willReturn($this->caseType(['omschrijving', 'locatie']));
		$valid = ['channelId' => ' public-space-report ', 'targetSchema' => ' melding ', 'fieldMapping' => ['omschrijving' => 'text'], 'locationField' => ' locatie '];

		$this->assertNull($this->refusal($valid));
		$this->assertNull($this->refusal(['channelId' => 'public-space-report', 'targetSchema' => 'melding']));
		$this->assertSame('No intake channel adapter answers to "".', $this->refusal(['targetSchema' => '']));
		$this->assertSame('A routing rule must name the case type it opens.', $this->refusal(array_merge($valid, ['targetSchema' => '  ', 'fieldMapping' => 'x'])));
		$this->assertSame(
			'The field mapping must be an object of target field to source.',
			$this->refusal(array_merge($valid, ['fieldMapping' => 'x', 'locationField' => 'nope']))
		);
		$this->assertSame('Case type "melding" has no field "nope".', $this->refusal(array_merge($valid, ['locationField' => 'nope'])));
		$this->assertSame('Case type "melding" has no field "0".', $this->refusal(array_merge($valid, ['fieldMapping' => ['x']])));

	}//end testEveryRuleRefusalNamesItsCause()

}//end class
