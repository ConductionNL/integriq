<?php

/**
 * Asserts that integriq's executable-config schemas carry an admin-only write block.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\InitializeRegister;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Merges the register descriptor exactly as InitializeRegister does and checks
 * every schema that stores executable configuration, credentials or identity
 * links. An empty or absent block is default-OPEN in OpenRegister, so any
 * signed-in account could write these schemas through the generic objects API.
 */
final class EngineConfigLockdownTest extends TestCase {

	/**
	 * Schemas whose write must be admin-only.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function lockedSchemas(): array {
		$schemas = [
			'endpoint',
			'mapping',
			'job',
			'synchronization',
			'synchronization_contract',
			'flow',
			'event_subscription',
			'notificaties_abonnement',
			'environment',
			'lti_deployment',
			'lti_identity_link',
			'eudi_credential_offer',
			'eudi_issuance_session',
			'eudi_status_list',
			'source',
			'consumer',
			'rule',
			'lti_platform',
			'lti_tool',
		];

		$cases = [];
		foreach ($schemas as $schema) {
			$cases[$schema] = [$schema];
		}

		return $cases;
	}//end lockedSchemas()

	/**
	 * Build the merged descriptor (base file plus every register.d fragment, sorted).
	 *
	 * @return array<mixed> The merged descriptor.
	 */
	private function mergedDescriptor(): array {
		$settings = dirname(__DIR__, 3) . '/lib/Settings';
		$descriptor = json_decode((string)file_get_contents($settings . '/integriq_register.json'), true);
		$merge = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		$merge->setAccessible(true);

		$fragments = glob($settings . '/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$data = json_decode((string)file_get_contents($fragment), true);
			$this->assertIsArray(actual: $data, message: basename(path: $fragment) . ' must be valid JSON');
			$descriptor = $merge->invoke(null, $descriptor, $data);
		}

		return $descriptor;
	}//end mergedDescriptor()

	/**
	 * Every locked schema has a non-empty block with admin-only writes and a declared read.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return void
	 */
	#[DataProvider('lockedSchemas')]
	public function testSchemaWriteIsAdminOnly(string $schema): void {
		$authorization = ($this->mergedDescriptor()['components']['schemas'][$schema]['authorization'] ?? null);

		$this->assertIsArray(actual: $authorization, message: $schema . ' has no authorization block');
		$this->assertNotEmpty(actual: $authorization, message: $schema . ' has an empty (default-open) authorization block');
		foreach (['create', 'update', 'delete'] as $action) {
			$this->assertArrayHasKey(key: $action, array: $authorization, message: $schema . ' does not declare ' . $action);
			$this->assertSame(expected: ['admin'], actual: $authorization[$action], message: $schema . ' ' . $action . ' must be admin-only');
		}

		$this->assertArrayHasKey(key: 'read', array: $authorization, message: $schema . ' must declare read explicitly');
		$this->assertArrayNotHasKey(key: 'destroy', array: $authorization, message: 'OpenRegister <= 2.1.33 rejects a schema that declares destroy');
		$this->assertNotContains(needle: 'public', haystack: $authorization['read'], message: $schema . ' must not be readable anonymously');
	}//end testSchemaWriteIsAdminOnly()
}//end class
