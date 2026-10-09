<?php

/**
 * The two intake schemas that carry BSNs are open to their intake group,
 * their handler group and administrators only.
 *
 * Read from the real register files: the base register with every fragment
 * merged the way InitializeRegister merges them, and the demo register as
 * DemoDataService imports it (raw, no fragments). Both are imported into the
 * same schemas, so both must carry the same block.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#requirement-submissions-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\Intake\IntakeGroups;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The authorization blocks of dso_verzoek and openformulieren_submission.
 */
class IntakeRecordAuthorizationTest extends TestCase {

	/**
	 * The expected block per schema.
	 *
	 * @return array<string, array{0: string, 1: array<string, list<string>>}> Schema slug and block.
	 */
	public static function blocks(): array {
		return [
			'dso_verzoek' => [
				'dso_verzoek',
				[
					'create' => [IntakeGroups::DSO_INTAKE],
					'read' => [IntakeGroups::DSO_HANDLERS],
					'update' => [IntakeGroups::DSO_INTAKE, IntakeGroups::DSO_HANDLERS],
					'delete' => [],
				],
			],
			'openformulieren_submission' => [
				'openformulieren_submission',
				[
					'create' => [IntakeGroups::OPEN_FORMULIEREN_INTAKE],
					'read' => [IntakeGroups::OPEN_FORMULIEREN_HANDLERS],
					'update' => [IntakeGroups::OPEN_FORMULIEREN_INTAKE, IntakeGroups::OPEN_FORMULIEREN_HANDLERS],
					'delete' => [],
				],
			],
			'intake_message' => [
				'intake_message',
				[
					'create' => [IntakeGroups::INTAKE_CHANNELS_INTAKE],
					'read' => [IntakeGroups::INTAKE_CHANNELS_HANDLERS],
					'update' => [IntakeGroups::INTAKE_CHANNELS_INTAKE, IntakeGroups::INTAKE_CHANNELS_HANDLERS],
					'delete' => [],
				],
			],
			'verdict' => [
				'verdict',
				[
					'create' => [IntakeGroups::VERDICTS_INTAKE],
					'read' => [IntakeGroups::VERDICTS_HANDLERS],
					'update' => [IntakeGroups::VERDICTS_INTAKE, IntakeGroups::VERDICTS_HANDLERS],
					'delete' => [],
				],
			],
			// digital-post-service-account-and-log-redaction (REQ-DPA-007): the
			// digital post account stores the letter and its outbound log row.
			'digitalPostMessage' => [
				'digitalPostMessage',
				[
					'create' => [IntakeGroups::DIGITAL_POST_SENDERS],
					'read' => [IntakeGroups::DIGITAL_POST_SENDERS],
					'update' => [IntakeGroups::DIGITAL_POST_SENDERS],
					'delete' => [],
				],
			],
			'outbound_message' => [
				'outbound_message',
				[
					'create' => [IntakeGroups::DIGITAL_POST_SENDERS],
					'read' => [IntakeGroups::DIGITAL_POST_SENDERS],
					'update' => [IntakeGroups::DIGITAL_POST_SENDERS],
					'delete' => [],
				],
			],
		];

	}//end blocks()

	/**
	 * The merged base register carries exactly the block.
	 *
	 * @param string                       $slug  The schema slug.
	 * @param array<string, list<string>>  $block The expected block.
	 *
	 * @return void
	 *
	 * @dataProvider blocks
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#scenario-an-ordinary-account-gets-nothing
	 */
	public function testTheRegisterCarriesTheBlock(string $slug, array $block): void {
		$schema = RegisterSchemaValidator::descriptor()['components']['schemas'][$slug];

		$this->assertSame($block, $schema['authorization']);

	}//end testTheRegisterCarriesTheBlock()

	/**
	 * The demo register carries the same block, so a demo import cannot reopen the schema.
	 *
	 * @param string                       $slug  The schema slug.
	 * @param array<string, list<string>>  $block The expected block.
	 *
	 * @return void
	 *
	 * @dataProvider blocks
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#scenario-an-ordinary-account-gets-nothing
	 */
	public function testTheDemoRegisterCarriesTheSameBlock(string $slug, array $block): void {
		$mock = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/integriq_mock_register.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);

		$this->assertSame($block, $mock['components']['schemas'][$slug]['authorization']);

	}//end testTheDemoRegisterCarriesTheSameBlock()

	/**
	 * No rule grants a pseudo-group: not `public`, not `authenticated`.
	 *
	 * @param string                       $slug  The schema slug.
	 * @param array<string, list<string>>  $block The expected block.
	 *
	 * @return void
	 *
	 * @dataProvider blocks
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#scenario-an-ordinary-account-gets-nothing
	 */
	public function testNoRuleGrantsAPseudoGroup(string $slug, array $block): void {
		$schema = RegisterSchemaValidator::descriptor()['components']['schemas'][$slug];
		$named = array_merge(...array_values($schema['authorization']));

		$this->assertSame([], array_values(array_intersect($named, ['public', 'authenticated'])));
		$this->assertSame([], array_values(array_diff(array_unique($named), IntakeGroups::ALL)), 'every group is one IntakeGroups creates');

	}//end testNoRuleGrantsAPseudoGroup()
}//end class
