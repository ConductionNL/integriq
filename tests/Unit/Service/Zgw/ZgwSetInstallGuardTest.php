<?php

/**
 * What the ZGW set installer refuses, and why each refusal earns its place.
 *
 * 🔴 TWO SETS ON ONE SCHEMA IS THE FAILURE THESE EXIST FOR. Both
 * synchronizations run, both write the bound schema, and each overwrites what
 * the other just wrote. Nothing errors. The schema holds whichever set ran
 * last, changing every few minutes, and both source pages report a healthy
 * synchronization, because from each one's own side it is healthy: it read its
 * store and it wrote its objects. An operator can watch one page forever
 * without seeing the other undoing it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Zgw;

use OCA\Integriq\Service\Zgw\ZgwSetCatalogue;
use OCA\Integriq\Service\Zgw\ZgwSetInstallGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ZgwSetInstallGuard and the packaged catalogue.
 */
class ZgwSetInstallGuardTest extends TestCase {

	private ZgwSetInstallGuard $guard;

	/**
	 * Wire the guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->guard = new ZgwSetInstallGuard();
	}//end setUp()

	/**
	 * A sound template for one set.
	 *
	 * @param array<string, mixed> $extra Fields to override.
	 *
	 * @return array<string, mixed> The template.
	 */
	private function template(array $extra = []): array {
		return array_merge(
			[
				'auth' => ZgwSetCatalogue::AUTH,
				'apiVersion' => '1.6',
				'component' => 'Zaken API',
				'synchronizations' => ['zgw-zaken-pull', 'zgw-zaken-push'],
				'mappings' => ['zgw-zaak-to-object'],
			],
			$extra
		);
	}//end template()

	/**
	 * All six sets are packaged, and nothing else is.
	 *
	 * @return void
	 */
	public function testTheSixSetsArePackaged(): void {
		$this->assertCount(6, ZgwSetCatalogue::SETS);
		foreach (['zgw-zaken', 'zgw-documenten', 'zgw-catalogi', 'zgw-besluiten', 'zgw-objecten', 'zgw-notificaties'] as $slug) {
			$this->assertTrue(ZgwSetCatalogue::isPackaged(slug: $slug));
		}

		$this->assertFalse(ZgwSetCatalogue::isPackaged(slug: 'zgw-klanten'));
	}//end testTheSixSetsArePackaged()

	/**
	 * Catalogi and notificaties do not write back, and saying which four DO is
	 * what stops a fifth being added later with nobody noticing it has no push.
	 *
	 * @return void
	 */
	public function testOnlyFourSetsWriteBack(): void {
		$this->assertTrue(ZgwSetCatalogue::writesBack(slug: 'zgw-zaken'));
		$this->assertFalse(ZgwSetCatalogue::writesBack(slug: 'zgw-catalogi'));
		$this->assertFalse(ZgwSetCatalogue::writesBack(slug: 'zgw-notificaties'));
	}//end testOnlyFourSetsWriteBack()

	/**
	 * 🔴 THE SECOND SET ON ONE SCHEMA IS REFUSED, AND THE HOLDER IS NAMED.
	 * "Already bound" sends somebody through six sets; "bound by zgw-zaken" is
	 * a thing they can act on in one step.
	 *
	 * @return void
	 */
	public function testASecondSetOnTheSameSchemaIsRefusedNamingTheHolder(): void {
		$bindings = [$this->guard->bindingKey(register: 'r1', schema: 's1') => 'zgw-zaken'];

		$refusal = $this->guard->refuse(
			slug: 'zgw-objecten',
			target: ['register' => 'r1', 'schema' => 's1'],
			bindings: $bindings
		);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('zgw-zaken', $refusal);
		$this->assertStringContainsString('overwrite each other', $refusal);
	}//end testASecondSetOnTheSameSchemaIsRefusedNamingTheHolder()

	/**
	 * Re-installing the SAME set over its own binding is not a collision, so
	 * the refusal above is not a blanket that blocks an upgrade.
	 *
	 * @return void
	 */
	public function testReinstallingTheSameSetOverItsOwnBindingIsAllowed(): void {
		$bindings = [$this->guard->bindingKey(register: 'r1', schema: 's1') => 'zgw-zaken'];

		$this->assertNull(
			$this->guard->refuse(slug: 'zgw-zaken', target: ['register' => 'r1', 'schema' => 's1'], bindings: $bindings)
		);
	}//end testReinstallingTheSameSetOverItsOwnBindingIsAllowed()

	/**
	 * A different schema is free, so the guard is per binding and not per set.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsFree(): void {
		$bindings = [$this->guard->bindingKey(register: 'r1', schema: 's1') => 'zgw-zaken'];

		$this->assertNull(
			$this->guard->refuse(slug: 'zgw-objecten', target: ['register' => 'r1', 'schema' => 's2'], bindings: $bindings)
		);
	}//end testAnotherSchemaIsFree()

	/**
	 * Installing against no target is refused: a synchronization with no target
	 * runs, reads the store and writes nowhere while reporting a good run.
	 *
	 * @return void
	 */
	public function testInstallingWithoutARegisterAndSchemaIsRefused(): void {
		$this->assertNotNull($this->guard->refuse(slug: 'zgw-zaken', target: ['register' => 'r1'], bindings: []));
		$this->assertNotNull($this->guard->refuse(slug: 'zgw-zaken', target: ['schema' => 's1'], bindings: []));
	}//end testInstallingWithoutARegisterAndSchemaIsRefused()

	/**
	 * A slug that is not one of the six is refused, and the message lists them.
	 *
	 * @return void
	 */
	public function testAnUnknownSetIsRefused(): void {
		$refusal = $this->guard->refuse(slug: 'zgw-klanten', target: ['register' => 'r1', 'schema' => 's1'], bindings: []);

		$this->assertStringContainsString('zgw-zaken', (string)$refusal);
	}//end testAnUnknownSetIsRefused()

	/**
	 * 🔴 A SET NAMING A FLEET APP IS REFUSED. App ids in this fleet move, and
	 * the lookups they feed are duck-typed: a name nothing answers to does not
	 * error, it returns false, and the integration becomes a silent no-op that
	 * every screen reports as configured.
	 *
	 * @return void
	 */
	public function testASetNamingAFleetAppIsRefused(): void {
		$refusals = $this->guard->refuseTemplate(
			slug: 'zgw-zaken',
			template: $this->template(['component' => 'Zaken API for dossiq'])
		);

		$this->assertNotSame([], $refusals);
		$this->assertStringContainsString('dossiq', $refusals[0]);
		$this->assertStringContainsString('silent', $refusals[0]);
	}//end testASetNamingAFleetAppIsRefused()

	/**
	 * 🔴 AND THE RETIRED SPELLING TOO. A set written before a rename carries
	 * the old id and a set written after carries the new; both are equally
	 * wrong here, and only checking the current one lets every pre-rename set
	 * through.
	 *
	 * @return void
	 */
	public function testARetiredFleetAppNameIsRefusedAsWell(): void {
		$refusals = $this->guard->refuseTemplate(
			slug: 'zgw-zaken',
			template: $this->template(['component' => 'Zaken API for procest'])
		);

		$this->assertStringContainsString('procest', $refusals[0]);
	}//end testARetiredFleetAppNameIsRefusedAsWell()

	/**
	 * A sound template is refused nothing, so the checks are not a blanket.
	 *
	 * @return void
	 */
	public function testASoundTemplateIsAccepted(): void {
		$this->assertSame([], $this->guard->refuseTemplate(slug: 'zgw-zaken', template: $this->template()));
	}//end testASoundTemplateIsAccepted()

	/**
	 * A template must declare its auth and the version it speaks. A store
	 * answering an unknown version is the one thing a translator cannot guess
	 * at: guessing writes 1.0 shapes into a 1.6 store and the mismatch shows up
	 * much later as missing fields.
	 *
	 * @return void
	 */
	public function testATemplateWithoutAuthOrApiVersionIsRefused(): void {
		$this->assertNotSame([], $this->guard->refuseTemplate(slug: 'zgw-zaken', template: $this->template(['auth' => 'basic'])));
		$this->assertNotSame([], $this->guard->refuseTemplate(slug: 'zgw-zaken', template: $this->template(['apiVersion' => ' '])));
	}//end testATemplateWithoutAuthOrApiVersionIsRefused()

	/**
	 * 🔴 A REFERENCE BY ID IS REFUSED (ADR-015). A shipped id points at
	 * whatever holds that id on the installing instance, so it resolves, it
	 * installs, and it wires the set to somebody else's configuration.
	 *
	 * @return void
	 */
	public function testAReferenceByIdRatherThanSlugIsRefused(): void {
		$refusals = $this->guard->refuseTemplate(
			slug: 'zgw-zaken',
			template: $this->template(['mappings' => ['17']])
		);

		$this->assertNotSame([], $refusals);
		$this->assertStringContainsString('somebody else', $refusals[0]);
	}//end testAReferenceByIdRatherThanSlugIsRefused()

	/**
	 * 🔴 EVERY SHIPPED SET PASSES ITS OWN GUARD. The check that would otherwise
	 * be a rule nobody runs against the thing it was written for.
	 *
	 * @return void
	 */
	public function testEveryShippedSetPassesTheTemplateGuard(): void {
		$directory = dirname(__DIR__, 4).'/lib/Settings/configurations';

		$checked = 0;
		foreach (array_keys(ZgwSetCatalogue::SETS) as $slug) {
			$path = $directory.'/'.$slug.'.json';
			$this->assertFileExists($path, sprintf('the packaged set "%s" must ship', $slug));

			$template = json_decode((string)file_get_contents($path), true);
			$this->assertIsArray($template, sprintf('the packaged set "%s" must be readable JSON', $slug));
			$this->assertSame([], $this->guard->refuseTemplate(slug: $slug, template: $template), $slug);
			$checked++;
		}

		// A loop that checked nothing would pass silently.
		$this->assertSame(6, $checked);
	}//end testEveryShippedSetPassesTheTemplateGuard()

	/**
	 * Every shipped set writes with the external strategy, so the store stays
	 * the truth and an operator is never shown two divergent copies with
	 * nothing saying which one is on screen.
	 *
	 * @return void
	 */
	public function testEveryShippedSetWritesExternally(): void {
		$directory = dirname(__DIR__, 4).'/lib/Settings/configurations';

		foreach (array_keys(ZgwSetCatalogue::SETS) as $slug) {
			$template = json_decode((string)file_get_contents($directory.'/'.$slug.'.json'), true);
			$this->assertSame(ZgwSetCatalogue::STORAGE_STRATEGY, ($template['storageStrategy'] ?? null), $slug);
		}
	}//end testEveryShippedSetWritesExternally()
}//end class
