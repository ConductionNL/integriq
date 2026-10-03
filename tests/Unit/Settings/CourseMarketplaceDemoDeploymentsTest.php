<?php

/**
 * The course marketplace demo data: one LTI tool and one deployment per provider.
 *
 * An administrator trying a marketplace set on a demo install needs a
 * deployment to put in the placement mapping. This test reads the mock
 * register the demo data installs and checks that Go1, LinkedIn Learning and
 * Udemy Business each have one deployment, that it names its own tool, and
 * that both objects pass integriq's real `lti_tool` and `lti_deployment` schemas.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-an-administrator-imports-a-selection-not-the-whole-catalogue-req-cmkt-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Demo data lists one deployment per course marketplace provider.
 */
class CourseMarketplaceDemoDeploymentsTest extends TestCase {

	/**
	 * The providers, by the name their source carries in the fragment.
	 *
	 * @var list<string>
	 */
	private const PROVIDERS = ['Go1', 'LinkedIn Learning', 'Udemy Business'];

	/**
	 * Each provider has exactly one demo deployment, and it names a demo tool
	 * of the same provider.
	 *
	 * @return void
	 */
	public function testEachProviderHasOneDeploymentOnItsOwnTool(): void {
		$tools = $this->objectsOf(schema: 'lti_tool');
		$toolsByUuid = [];
		foreach ($tools as $tool) {
			$toolsByUuid[(string)($tool['uuid'] ?? '')] = $tool;
		}

		foreach (self::PROVIDERS as $provider) {
			$deployments = array_values(
				array_filter(
					$this->objectsOf(schema: 'lti_deployment'),
					static fn (array $row): bool => str_contains((string)($row['name'] ?? ''), $provider)
				)
			);
			$this->assertCount(1, $deployments, 'one demo deployment for ' . $provider);

			$tool = ($toolsByUuid[(string)($deployments[0]['ltiToolId'] ?? '')] ?? null);
			$this->assertNotNull($tool, 'the ' . $provider . ' deployment names a tool in the demo data');
			$this->assertStringContainsString($provider, (string)$tool['name']);
		}

	}//end testEachProviderHasOneDeploymentOnItsOwnTool()

	/**
	 * Every marketplace demo tool and deployment passes integriq's real schema.
	 *
	 * @return void
	 */
	public function testTheDemoToolsAndDeploymentsPassTheRegisterSchemas(): void {
		$checked = 0;
		foreach (['lti_tool', 'lti_deployment'] as $schema) {
			foreach ($this->objectsOf(schema: $schema) as $row) {
				if ($this->isMarketplace(row: $row) === false) {
					continue;
				}

				$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: $schema, object: $row), $schema . ' ' . (string)$row['name']);
				$checked++;
			}
		}

		$this->assertSame(6, $checked, 'three tools and three deployments');

	}//end testTheDemoToolsAndDeploymentsPassTheRegisterSchemas()

	/**
	 * The mock register's objects of one schema, without their `@self`.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function objectsOf(string $schema): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/integriq_mock_register.json'),
			true
		);
		$rows = [];
		foreach ($register['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === $schema) {
				unset($object['@self']);
				$rows[] = $object;
			}
		}

		return $rows;

	}//end objectsOf()

	/**
	 * Whether a row belongs to a course marketplace provider.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return bool
	 */
	private function isMarketplace(array $row): bool {
		foreach (self::PROVIDERS as $provider) {
			if (str_contains((string)($row['name'] ?? ''), $provider) === true) {
				return true;
			}
		}

		return false;

	}//end isMarketplace()
}//end class
