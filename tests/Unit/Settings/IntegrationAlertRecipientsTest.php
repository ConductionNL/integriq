<?php

/**
 * Who hears about integriq's own alerts: the notification rules in the merged
 * register name the settable alert group, never the `openconnector-ops` group
 * that exists on no instance.
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

use OCA\Integriq\Notification\ConnectionAlertRecipientResolver;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Reads every x-openregister-notifications rule of the merged register.
 */
final class IntegrationAlertRecipientsTest extends TestCase {

	/**
	 * The rules that told the operations group before Ruben's decision of
	 * 29 Sep 2026 (default `admin`, changeable in settings).
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private const OPERATIONS_RULES = [
		['event_message', 'delivery-retries-exhausted'],
		['job', 'job-overdue'],
		['call_log', 'call-failed'],
		['job_log', 'job-error'],
		['synchronization_log', 'sync-failed'],
		['peppol_transmission', 'transmission-failed'],
		['payment_intent', 'payment-failed'],
		['bankfeed_batch', 'batch-synced'],
		['cardfeed_batch', 'batch-synced'],
		['sms_message', 'message-failed'],
		['approval_request', 'created'],
	];

	/**
	 * Every rule of the merged register, keyed "schema/rule".
	 *
	 * @return array<string, array<string, mixed>> The rules.
	 */
	private static function rules(): array {
		$rules = [];
		foreach (RegisterSchemaValidator::descriptor()['components']['schemas'] as $slug => $schema) {
			foreach (($schema['x-openregister-notifications'] ?? []) as $name => $rule) {
				$rules[$slug.'/'.$name] = $rule;
			}
		}

		return $rules;
	}//end rules()

	/**
	 * No rule names the `openconnector-ops` group, which exists on no instance.
	 *
	 * @return void
	 */
	public function testNoRuleNamesTheOperationsGroupThatDoesNotExist(): void {
		foreach (self::rules() as $key => $rule) {
			foreach (($rule['recipients'] ?? []) as $recipient) {
				$this->assertNotContains(
					'openconnector-ops',
					($recipient['groups'] ?? []),
					$key.' still names openconnector-ops'
				);
			}
		}
	}//end testNoRuleNamesTheOperationsGroupThatDoesNotExist()

	/**
	 * Each rule that told the operations group now tells the settable alert
	 * group, which is `admin` until an administrator names another one.
	 *
	 * @return void
	 */
	public function testEachOperationsRuleTellsTheSettableAlertGroup(): void {
		$rules = self::rules();
		$resolver = ['kind' => 'expression', 'resolver' => ConnectionAlertRecipientResolver::class];
		foreach (self::OPERATIONS_RULES as [$schema, $name]) {
			$key = $schema.'/'.$name;
			$this->assertArrayHasKey($key, $rules);
			$this->assertContains($resolver, $rules[$key]['recipients'], $key.' does not name the alert group');
			$this->assertSame(
				1,
				count(array_filter($rules[$key]['recipients'], static fn (array $r): bool => $r === $resolver)),
				$key.' names the alert group more than once'
			);
		}
	}//end testEachOperationsRuleTellsTheSettableAlertGroup()
}//end class
