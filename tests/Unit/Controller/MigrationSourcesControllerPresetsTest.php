<?php

/**
 * Integriq — migration sources controller `presets()` tests.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\MigrationSourcesController;
use OCA\Integriq\Migration\ColumnMappingValidator;
use OCA\Integriq\Migration\MigrationMappingPresetRegistry;
use OCA\Integriq\Migration\MigrationPreviewReader;
use OCA\Integriq\Migration\MigrationSourceRegistry;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/migration-mapping-presets/spec.md#requirement-an-operator-can-list-presets-over-the-existing-migration-sources-http-surface-req-002
 */
class MigrationSourcesControllerPresetsTest extends TestCase {
	/**
	 * @return void
	 */
	public function testPresetsListsFourSeededPresetsForAnAuthenticatedNonAdmin(): void {
		$registry = new MigrationSourceRegistry([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$controller = new MigrationSourcesController(
			'integriq',
			$this->createMock(IRequest::class),
			$registry,
			new MigrationPreviewReader($registry),
			new ColumnMappingValidator(),
			$session,
			$this->createMock(ActionAuthService::class),
			new MigrationMappingPresetRegistry()
		);

		$response = $controller->presets();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertCount(4, $data['results']);

		$ids = array_column($data['results'], 'id');
		sort($ids);
		$this->assertSame(['esis-export', 'magister-export', 'parnassys-export', 'somtoday-export'], $ids);
	}//end testPresetsListsFourSeededPresetsForAnAuthenticatedNonAdmin()
}//end class
