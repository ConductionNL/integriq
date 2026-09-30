<?php

/**
 * The `job-overdue` rule on the `job` schema, read by OpenRegister's own
 * scheduled-notification grammar: its "now"-relative date filter parses, the
 * annotation validator accepts the rule, and the evaluator picks exactly the
 * enabled jobs whose next run has passed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/openconnector-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use DateTimeImmutable;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use OCA\OpenRegister\Service\Notification\ScheduledFilterEvaluator;
use OCA\OpenRegister\Service\Notification\ScheduledFilterParser;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shipped rule through the real OpenRegister classes (tests/stubs
 * holds verbatim copies of openregister development a5832f2498).
 */
final class JobOverdueRuleTest extends TestCase {

	/**
	 * The logical "now" of one scan pass.
	 *
	 * @var string
	 */
	private const NOW = '2026-09-29T12:00:00+00:00';

	/**
	 * The `job` schema as integriq ships it.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private static function jobSchema(): array {
		$register = json_decode(
			(string) file_get_contents(__DIR__.'/../../../lib/Settings/integriq_register.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		return $register['components']['schemas']['job'];
	}//end jobSchema()

	/**
	 * The shipped `job-overdue` rule.
	 *
	 * @return array<string, mixed> The rule.
	 */
	private static function rule(): array {
		return self::jobSchema()['x-openregister-notifications']['job-overdue'];
	}//end rule()

	/**
	 * A job object that the merged `job` schema accepts.
	 *
	 * @param bool   $enabled Whether the job is active.
	 * @param string $nextRun When it runs next.
	 *
	 * @return array<string, mixed> The job.
	 */
	private function job(bool $enabled, string $nextRun): array {
		$job = [
			'name'      => 'Nightly BAG pull',
			'jobClass'  => 'OCA\\Integriq\\Action\\SynchronizationAction',
			'interval'  => 3600,
			'isEnabled' => $enabled,
			'userId'    => 'admin',
			'nextRun'   => $nextRun,
		];

		$this->assertSame([], RegisterSchemaValidator::errors('job', $job), 'the job fixture must be one the register accepts');

		return $job;
	}//end job()

	/**
	 * The rule's filter uses the "now"-relative `before` operator and parses
	 * without errors.
	 *
	 * @return void
	 */
	public function testTheNowRelativeFilterParses(): void {
		$filter = self::rule()['trigger']['filter'];
		$this->assertSame(['operator' => 'before', 'value' => 'now'], $filter['nextRun']);

		$parsed = (new ScheduledFilterParser())->parse($filter, 'job-overdue');

		$this->assertSame([], $parsed['errors']);
		$this->assertNotNull($parsed['ast']);
	}//end testTheNowRelativeFilterParses()

	/**
	 * OpenRegister's annotation validator accepts the whole schema, also with
	 * the rule switched on.
	 *
	 * @return void
	 */
	public function testTheValidatorAcceptsTheRuleSwitchedOn(): void {
		$schema = self::jobSchema();
		$validator = new NotificationAnnotationValidator();
		$this->assertSame([], $validator->validate($schema));

		$schema['x-openregister-notifications']['job-overdue']['enabled'] = true;
		$this->assertSame([], $validator->validate($schema));
	}//end testTheValidatorAcceptsTheRuleSwitchedOn()

	/**
	 * Only an enabled job whose next run has passed matches.
	 *
	 * @return void
	 */
	public function testOnlyAnEnabledJobPastItsNextRunMatches(): void {
		$filter    = self::rule()['trigger']['filter'];
		$evaluator = new ScheduledFilterEvaluator();
		$now       = new DateTimeImmutable(self::NOW);

		$this->assertTrue($evaluator->matches($this->job(true, '2026-09-29T10:00:00+00:00'), $filter, $now), 'overdue');
		$this->assertFalse($evaluator->matches($this->job(true, '2026-09-29T14:00:00+00:00'), $filter, $now), 'not yet due');
		$this->assertFalse($evaluator->matches($this->job(false, '2026-09-29T10:00:00+00:00'), $filter, $now), 'disabled');
	}//end testOnlyAnEnabledJobPastItsNextRunMatches()

	/**
	 * The rule still ships switched off: turning it on is an administrator's
	 * choice (acceptance criteria of the integriq-notifications change).
	 *
	 * @return void
	 */
	public function testTheRuleShipsSwitchedOff(): void {
		$rule = self::rule();
		$this->assertFalse($rule['enabled']);
		$this->assertSame('scheduled', $rule['trigger']['type']);
		$this->assertGreaterThanOrEqual(60, $rule['trigger']['intervalSec']);
	}//end testTheRuleShipsSwitchedOff()
}//end class
