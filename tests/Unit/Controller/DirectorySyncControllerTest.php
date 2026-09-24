<?php

/**
 * Unit tests for DirectorySyncController — the admin surface: the connections,
 * a run that is previewed or confirmed, and the runs list narrowed to directory
 * runs.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DirectorySyncController;
use OCA\Integriq\Directory\DirectorySource;
use OCA\Integriq\Directory\DirectorySyncService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the directory admin surface.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
 */
class DirectorySyncControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var DirectorySource|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $directorySource;

	/**
	 * @var DirectorySyncService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $directorySyncService;

	/**
	 * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * Build the collaborators every test shares.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->directorySource = $this->createMock(DirectorySource::class);
		$this->directorySyncService = $this->createMock(DirectorySyncService::class);
		$this->orObjectService = $this->createMock(OrObjectService::class);

	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return DirectorySyncController The controller.
	 */
	private function controller(): DirectorySyncController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new DirectorySyncController(
			appName: 'integriq',
			request: $this->request,
			directorySource: $this->directorySource,
			directorySyncService: $this->directorySyncService,
			orObjectService: $this->orObjectService,
			l10n: $l10n
		);
	}//end controller()

	/**
	 * A directory connection with the given uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The connection.
	 */
	private function connection(string $uuid): ObjectEntity {
		$connection = new ObjectEntity();
		$connection->setUuid($uuid);
		$connection->setObject(
			[
				'name' => 'Gemeente directory',
				'type' => 'directory',
				'isEnabled' => true,
				'configuration' => ['mock' => true, 'mapping' => ['rules' => [['directoryGroup' => 'OU=Toezicht', 'group' => 'behandelaars']]]],
			]
		);

		return $connection;
	}//end connection()

	/**
	 * The connections list carries the declared mapping, so it is readable on
	 * the connection rather than only in code.
	 *
	 * @return void
	 */
	public function testTheConnectionsListCarriesTheMapping(): void {
		$this->directorySource->method('findConnections')->willReturn([$this->connection(uuid: 'conn-1')]);

		$data = $this->controller()->connections()->getData();

		$this->assertSame(1, $data['total']);
		$this->assertSame('conn-1', $data['results'][0]['id']);
		$this->assertSame('behandelaars', $data['results'][0]['mapping']['rules'][0]['group']);

	}//end testTheConnectionsListCarriesTheMapping()

	/**
	 * An unknown connection id answers 404 and runs nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownConnectionRunsNothing(): void {
		$this->directorySource->method('findConnections')->willReturn([]);
		$this->directorySyncService->expects($this->never())->method('run');

		$response = $this->controller()->run(id: 'missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAnUnknownConnectionRunsNothing()

	/**
	 * The dryRun and confirmRemovals parameters reach the service.
	 *
	 * @return void
	 */
	public function testPreviewAndConfirmationReachTheService(): void {
		$this->directorySource->method('findConnections')->willReturn([$this->connection(uuid: 'conn-1')]);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				if ($key === 'dryRun') {
					return 'true';
				}

				if ($key === 'confirmRemovals') {
					return 'false';
				}

				return $default;
			}
		);

		$this->directorySyncService->expects($this->once())
			->method('run')
			->with($this->anything(), true, false)
			->willReturn(['status' => 'success', 'dryRun' => true]);

		$response = $this->controller()->run(id: 'conn-1');

		$this->assertTrue($response->getData()['dryRun']);

	}//end testPreviewAndConfirmationReachTheService()

	/**
	 * Every route on this controller is admin-gated.
	 *
	 * A membership write is an access decision and the preview reads the whole
	 * directory, so neither belongs on an endpoint an ordinary account reaches.
	 *
	 * @return void
	 */
	public function testEveryRouteIsAdminGated(): void {
		foreach (['connections', 'run', 'runs'] as $method) {
			$attributes = (new ReflectionMethod(DirectorySyncController::class, $method))->getAttributes();

			$names = [];
			foreach ($attributes as $attribute) {
				$names[] = $attribute->getName();
			}

			$this->assertContains(
				'OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting',
				$names,
				$method . ' must be admin-gated'
			);
			$this->assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $names);
			$this->assertNotContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $names);
		}

	}//end testEveryRouteIsAdminGated()
}//end class
