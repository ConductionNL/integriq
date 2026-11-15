<?php

/**
 * Unit tests for RegistrationTargetController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
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

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\RegistrationTargetController;
use OCA\Integriq\Service\Registration\RegistrationService;
use OCA\Integriq\Service\Registration\RegistrationStore;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The picker list: enabled targets the app may use, slug, title and kind only.
 */
#[CoversClass(RegistrationTargetController::class)]
#[UsesClass(RegistrationService::class)]
class RegistrationTargetControllerTest extends TestCase {

	/**
	 * The controller over these targets.
	 *
	 * @param array<int,array<string,mixed>> $targets The stored targets.
	 *
	 * @return RegistrationTargetController
	 */
	private function controller(array $targets): RegistrationTargetController {
		$store = $this->createMock(RegistrationStore::class);
		$store->method('findTargets')->willReturn($targets);
		return new RegistrationTargetController($this->createMock(IRequest::class), $store);
	}//end controller()

	/**
	 * Only enabled targets the app may use are listed, without sources, mapping or settings.
	 *
	 * @return void
	 */
	public function testTheListHidesHowATargetIsReached(): void {
		$zgw = [
			'slug' => 'zaaksysteem-milieu', 'title' => 'Zaaksysteem milieu', 'kind' => 'zgw',
			'sources' => ['zaken' => 's-1'], 'mapping' => 'm-1', 'zgw' => ['bronorganisatie' => '002220647'],
		];
		$targets = [
			$zgw,
			['slug' => 'off', 'title' => 'Off', 'kind' => 'json', 'enabled' => false],
			['slug' => 'dossiq-only', 'title' => 'D', 'kind' => 'json', 'allowedApps' => ['dossiq']],
		];

		$data = $this->controller($targets)->index(app: 'portaliq')->getData();

		$this->assertSame(['results' => [['slug' => 'zaaksysteem-milieu', 'title' => 'Zaaksysteem milieu', 'kind' => 'zgw']]], $data);
	}//end testTheListHidesHowATargetIsReached()

	/**
	 * Without an app the list is empty.
	 *
	 * @return void
	 */
	public function testWithoutAnAppNothingIsListed(): void {
		$this->assertSame(['results' => []], $this->controller([['slug' => 'a', 'title' => 'A', 'kind' => 'json']])->index()->getData());
	}//end testWithoutAnAppNothingIsListed()

	/**
	 * The list is open to signed-in users.
	 *
	 * @return void
	 */
	public function testTheListIsForSignedInUsers(): void {
		$method = new ReflectionMethod(RegistrationTargetController::class, 'index');
		$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
	}//end testTheListIsForSignedInUsers()
}//end class
