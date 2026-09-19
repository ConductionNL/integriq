<?php

/**
 * The registered action kinds must match the ones dispatch can run.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * A subscription's `action.kind` is validated against the register's enum
 * before it is stored, and then read back by `EventService`'s dispatch
 * switch. Those two lists are written in different files, in different
 * languages, and nothing has ever compared them.
 *
 * When they disagree the result is silent in both directions:
 *
 * - a kind the switch handles but the enum omits cannot be saved, so a
 *   working dispatch arm is unreachable and looks merely unused;
 * - a kind the enum offers but the switch omits saves cleanly and then fails
 *   once per delivery as an unrecognised kind, which the code correctly
 *   treats as a configuration error rather than a retryable one.
 *
 * This test compares them. It is the test that would have caught `flow` and
 * `mapping` shipping their dispatch arms, their services and their unit
 * tests while the enum still listed four kinds.
 */
class EventSubscriptionActionKindTest extends TestCase {
	/**
	 * Kinds the register lets a subscription be saved with.
	 *
	 * @return array<int, string>
	 */
	private function registeredKinds(): array {
		$path = (__DIR__ . '/../../../lib/Settings/integriq_register.json');
		$this->assertFileExists($path);

		$register = json_decode(file_get_contents($path), true);
		$this->assertIsArray($register, 'the register must be readable JSON');

		$kind = ($register['components']['schemas']['event_subscription']['properties']['action']['properties']['kind'] ?? null);
		$this->assertIsArray($kind, 'event_subscription.action.kind must exist');

		return ($kind['enum'] ?? []);
	}//end registeredKinds()

	/**
	 * Kinds `EventService`'s dispatch switch actually handles.
	 *
	 * Read from the source rather than hard-coded, so adding an arm without
	 * registering it fails here instead of passing against a list this test
	 * also forgot to update.
	 *
	 * @return array<int, string>
	 */
	private function dispatchedKinds(): array {
		$path = (__DIR__ . '/../../../lib/Service/EventService.php');
		$this->assertFileExists($path);

		$source = file_get_contents($path);
		$start = strpos($source, 'switch ($kind) {');
		$this->assertNotFalse($start, 'the action.kind dispatch switch must be findable');

		$end = strpos($source, 'Unrecognised action.kind', $start);
		$this->assertNotFalse($end, 'the switch must end in the unrecognised-kind arm');

		preg_match_all("/case '([a-z_]+)':/", substr($source, $start, ($end - $start)), $matches);

		return $matches[1];
	}//end dispatchedKinds()

	/**
	 * Every kind dispatch can run must be savable.
	 *
	 * @return void
	 */
	public function testEveryDispatchedKindIsAcceptedByTheRegister(): void {
		$missing = array_values(array_diff($this->dispatchedKinds(), $this->registeredKinds()));

		$this->assertSame(
			[],
			$missing,
			'these kinds have a dispatch arm but cannot be saved, so the arm is unreachable: '
			. implode(', ', $missing)
		);
	}//end testEveryDispatchedKindIsAcceptedByTheRegister()

	/**
	 * And the other direction: a kind the register offers but nothing runs
	 * saves cleanly and then fails once per delivery.
	 *
	 * @return void
	 */
	public function testEveryRegisteredKindHasSomewhereToGo(): void {
		$orphans = array_values(array_diff($this->registeredKinds(), $this->dispatchedKinds()));

		$this->assertSame(
			[],
			$orphans,
			'these kinds can be saved but nothing dispatches them: ' . implode(', ', $orphans)
		);
	}//end testEveryRegisteredKindHasSomewhereToGo()

	/**
	 * Guard against both lists being read as empty, which would make the two
	 * comparisons above pass while measuring nothing at all.
	 *
	 * @return void
	 */
	public function testBothListsWereActuallyRead(): void {
		$this->assertContains('webhook', $this->registeredKinds());
		$this->assertContains('webhook', $this->dispatchedKinds());
		$this->assertGreaterThanOrEqual(5, count($this->registeredKinds()));
	}//end testBothListsWereActuallyRead()

	/**
	 * `flow` specifically: the arm, its service and its unit test all shipped
	 * while the enum kept it unsavable.
	 *
	 * @return void
	 */
	public function testTheFlowKindIsSavable(): void {
		$this->assertContains('flow', $this->registeredKinds());
		$this->assertContains('flow', $this->dispatchedKinds());
	}//end testTheFlowKindIsSavable()

	/**
	 * A flow needs the field naming which flow to start.
	 *
	 * @return void
	 */
	public function testAFlowActionCanNameItsFlow(): void {
		$path = (__DIR__ . '/../../../lib/Settings/integriq_register.json');
		$register = json_decode(file_get_contents($path), true);
		$properties = ($register['components']['schemas']['event_subscription']['properties']['action']['properties'] ?? []);

		$this->assertArrayHasKey('flowId', $properties);
		$this->assertSame('string', $properties['flowId']['type']);
	}//end testAFlowActionCanNameItsFlow()
}//end class
