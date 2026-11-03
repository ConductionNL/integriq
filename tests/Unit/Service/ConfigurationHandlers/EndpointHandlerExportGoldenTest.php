<?php

/**
 * Golden characterization of EndpointHandler::export().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */

namespace OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers;

use OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Every id/slug translation and redaction export() makes, with the real
 * SensitiveFieldRegistry. Regenerate the golden file on the code it must
 * describe with ENDPOINT_EXPORT_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 * @uses   \OCA\Integriq\Service\Security\StructuredSecretRedactor
 * @uses   \OCA\Integriq\Service\Security\SensitiveValueMasker
 */
class EndpointHandlerExportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../fixtures/configuration/endpoint-handler-export.json';

	/**
	 * The global id/slug mappings.
	 *
	 * @return array<string, array<string, array<int|string, int|string>>>
	 */
	private function mappings(): array {
		$pairs = [
			'source' => ['src-uuid-5' => 'src-five', 6 => 'src-six'],
			'register' => [1 => 'reg-one', 4 => 'reg-four'],
			'schema' => [2 => 'sch-two'],
			'mapping' => ['map-uuid-7' => 'map-seven', 8 => 'map-eight'],
			'rule' => ['rule-uuid-3' => 'rule-three', 33 => 'rule-int', 0 => 'rule-zero'],
		];

		$mappings = [];
		foreach ($pairs as $type => $idToSlug) {
			$mappings[$type] = ['idToSlug' => $idToSlug, 'slugToId' => array_flip($idToSlug)];
		}

		return $mappings;
	}//end mappings()

	/**
	 * Endpoint objects to export.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		return [
			'bare' => [],
			'ids-dropped' => ['id' => 9, 'uuid' => 'u-9', 'name' => 'n', 'slug' => 'keep'],
			'empty-slug' => ['slug' => ''],
			'zero-slug' => ['slug' => '0'],
			'api-mapped' => ['targetType' => 'api', 'targetId' => 'src-uuid-5'],
			'api-int-mapped' => ['targetType' => 'api', 'targetId' => 6],
			'api-unmapped' => ['targetType' => 'api', 'targetId' => 'nope'],
			'database-mapped' => ['targetType' => 'database', 'targetId' => 'src-uuid-5'],
			'type-without-id' => ['targetType' => 'api'],
			'id-without-type' => ['targetId' => 'src-uuid-5'],
			'unknown-type' => ['targetType' => 'other', 'targetId' => 'src-uuid-5'],
			'rs-both' => ['targetType' => 'register/schema', 'targetId' => '1/2'],
			'rs-register-only' => ['targetType' => 'register/schema', 'targetId' => '4/77'],
			'rs-none' => ['targetType' => 'register/schema', 'targetId' => '55/66'],
			'rs-no-slash' => ['targetType' => 'register/schema', 'targetId' => '12'],
			'rs-three-parts' => ['targetType' => 'register/schema', 'targetId' => '1/2/3'],
			'mappings-both' => ['inputMapping' => 'map-uuid-7', 'outputMapping' => 8],
			'mappings-unknown' => ['inputMapping' => 'x', 'outputMapping' => 'y'],
			'mapping-input-only' => ['inputMapping' => 8],
			'rules-mixed' => ['rules' => ['rule-uuid-3', '33', 33, 'gone', 0, '0']],
			'rules-empty' => ['rules' => []],
			'rules-keyed' => ['rules' => ['a' => 'rule-uuid-3', 'b' => 'gone']],
			'config-secret' => ['configuration' => ['headers' => ['Authorization' => 'Bearer x', 'Accept' => 'json'], 'apiKey' => 'k', 'password' => 'p', 'plain' => 1]],
			'config-not-array' => ['configuration' => 'text'],
			'config-empty' => ['configuration' => []],
			'config-session-header' => ['configuration' => ['X-Session-Id' => 'sess-top', 'headers' => ['X-Session-Id' => 'sess-h', 'Accept' => 'json']]],
			'top-level-secret' => ['name' => 'n', 'apiKey' => 'k-top', 'clientSecret' => 'cs-top'],
			'configurations-list' => ['configurations' => ['profile-a'], 'conditions' => ['token' => 'cond-t', '==' => [1, 1]]],
			'nested-under-sensitive' => ['configuration' => ['auth' => ['user' => 'u', 'pass' => 'p', 'value' => 'v'], 'timeout' => 5]],
			'header-list' => ['configuration' => ['headers' => [['name' => 'Authorization', 'value' => 'Bearer z'], ['name' => 'X-Session-Token', 'value' => 'st'], ['name' => 'Accept', 'value' => 'json']]]],
			'url-secrets' => ['endpoint' => '/api/x?api_key=AK&page=2', 'configuration' => ['url' => 'https://user:pw@host.example/p?token=T&q={{ id }}', 'plainUrl' => 'https://host.example/p?q=1']],
			'session-field-kept' => ['configuration' => ['sessionTimeout' => 30, 'session' => 'cookie-store']],
		];
	}//end cases()

	/**
	 * Every case's export as JSON text, keyed by case and entity kind.
	 *
	 * @return array<string, string>
	 */
	private function record(): array {
		$handler = new EndpointHandler($this->createMock(OrObjectService::class), new SensitiveFieldRegistry());
		$out = [];
		foreach ($this->cases() as $name => $object) {
			$entity = new ObjectEntity();
			$entity->setObject($object);
			$entity->setUuid('entity-uuid');
			$out[$name] = json_encode($handler->export($entity, $this->mappings()));
		}

		return $out;
	}//end record()

	/**
	 * Every case matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = $this->record();
		if (getenv('ENDPOINT_EXPORT_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertCount(count($golden), $actual);
		foreach ($actual as $case => $value) {
			$this->assertSame($golden[$case], $value, $case);
		}
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
