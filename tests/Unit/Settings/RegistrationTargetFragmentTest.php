<?php

/**
 * The registration-target register fragment.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * registrationTarget and registrationRecord carry the properties of design D1, each with a title, and are admin-only.
 */
#[CoversNothing]
class RegistrationTargetFragmentTest extends TestCase {

	/**
	 * The decoded fragment.
	 *
	 * @return array<string,mixed>
	 */
	private function fragment(): array {
		$raw = file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/registration-target.json');
		$this->assertNotFalse($raw);
		$fragment = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($fragment);
		return $fragment;
	}//end fragment()

	/**
	 * Both schemas are in integriq's register, carry the D1 properties with titles, and are admin-only for every verb.
	 *
	 * @return void
	 */
	public function testTheSchemasCarryDesignD1AndAreAdminOnly(): void {
		$components = $this->fragment()['components'];
		$this->assertSame(['registrationTarget', 'registrationRecord'], $components['registers']['integriq']['schemas']);

		$expected = [
			'registrationTarget' => ['slug', 'title', 'kind', 'sources', 'mapping', 'zgw', 'objectsApi', 'stufZds', 'json', 'allowedApps', 'enabled'],
			'registrationRecord' => ['target', 'app', 'submissionReference', 'state', 'steps', 'externalReference', 'externalUrl', 'lastError', 'startedAt', 'finishedAt'],
		];
		foreach ($expected as $slug => $properties) {
			$schema = $components['schemas'][$slug];
			$this->assertSame($slug, $schema['slug']);
			$this->assertSame($properties, array_keys($schema['properties']));
			foreach ($schema['properties'] as $name => $property) {
				$this->assertNotSame('', (string)($property['title'] ?? ''), $slug . '.' . $name . ' needs a title');
			}

			$this->assertSame(['create' => ['admin'], 'read' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']], $schema['authorization']);
		}

		$target = $components['schemas']['registrationTarget']['properties'];
		$this->assertSame(['zgw', 'objects-api', 'stuf-zds', 'json'], $target['kind']['enum']);
		$this->assertSame(['portaliq'], $target['allowedApps']['default']);
		$this->assertSame(['started', 'done', 'failed'], $components['schemas']['registrationRecord']['properties']['state']['enum']);
	}//end testTheSchemasCarryDesignD1AndAreAdminOnly()
}//end class
