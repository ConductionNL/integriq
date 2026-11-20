<?php

/**
 * Integriq — syncing the declared data feeds on request.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\FeedsController;
use OCA\Integriq\Service\FeedDeclarationService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Admin only; one app or every app.
 */
class FeedsControllerTest extends TestCase {

	/**
	 * The sync is admin-only.
	 *
	 * @return void
	 */
	public function testTheSyncIsAdminOnly(): void {
		$attributes = (new ReflectionMethod(FeedsController::class, 'sync'))->getAttributes(AuthorizedAdminSetting::class);

		$this->assertCount(1, $attributes);
		$this->assertSame([IntegriqAdmin::class], array_values($attributes[0]->getArguments()));
	}//end testTheSyncIsAdminOnly()

	/**
	 * A named app is synced alone; a blank or missing name syncs every app.
	 *
	 * @return void
	 */
	public function testTheSyncPassesTheAppOn(): void {
		$summary = ['created' => 4, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'refused' => []];
		$feeds = $this->createMock(FeedDeclarationService::class);
		$feeds->expects($this->exactly(3))->method('sync')
			->willReturnCallback(
				function (?string $app = null) use ($summary): array {
					static $expected = ['shillinq', null, null];
					$this->assertSame(array_shift($expected), $app);
					return $summary;
				}
			);

		foreach (['shillinq', ' ', null] as $app) {
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->with('app')->willReturn($app);

			$this->assertSame($summary, (new FeedsController($request, $feeds))->sync()->getData());
		}
	}//end testTheSyncPassesTheAppOn()
}//end class
