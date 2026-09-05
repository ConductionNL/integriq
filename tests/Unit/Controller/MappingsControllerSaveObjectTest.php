<?php

/**
 * MappingsController::saveObject() identity test.
 *
 * The endpoint saves the OUTPUT OF A MAPPING TEST as an object — the UI button
 * reads "save result as object". A mapping transforms SOURCE data, and source
 * records very often carry an `id`.
 *
 * `ObjectService::saveObject()` resolves its target from the payload
 * (`@self.id` first, then `id`) and the write is PUT-semantic, so a result
 * carrying either would silently REPLACE whatever object shares that
 * identifier, nulling every field the result omitted, and report success.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\MappingsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\SourceMappingService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A saved mapping result may not address an existing object.
 */
class MappingsControllerSaveObjectTest extends TestCase {
	/**
	 * The object handed to saveObject.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $written = null;

	/**
	 * Build the controller over a request carrying the given body.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return MappingsController The controller.
	 */
	private function controller(array $params): MappingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		// The REAL ObjectService type (its test stub), because
		// SourceMappingService::getOpenRegisters() declares that return type and
		// an anonymous double is rejected outright. Mocking the declared type is
		// also what keeps this honest: the assertion is about the argument the
		// controller passes to the real signature.
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object, ...$rest): ObjectEntity {
				$this->written = (array)$object;

				return new ObjectEntity();
			}
		);

		$sourceMapping = $this->createMock(SourceMappingService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn($objectService);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new MappingsController(
			'openconnector',
			$request,
			$this->createMock(MappingService::class),
			$sourceMapping,
			$l,
			$this->createMock(IUserSession::class),
			$this->createMock(ActionAuthService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * 🔴 A mapping result carrying an identity saves as a NEW object.
	 *
	 * The identity belongs to the source record the mapping read, not to the
	 * object being created from it.
	 *
	 * @return void
	 */
	public function testAMappedIdentityDoesNotAddressAnExistingObject(): void {
		$controller = $this->controller(
			[
				'object' => [
					'id' => 'source-record-42',
					'uuid' => 'source-record-42',
					'@self' => ['id' => 'source-record-42'],
					'title' => 'Mapped result',
				],
				'register' => 'zaken',
				'schema' => 'zaak',
			]
		);

		$controller->saveObject();

		$this->assertIsArray($this->written, 'the save must have happened');
		$this->assertArrayNotHasKey('id', $this->written);
		$this->assertArrayNotHasKey('uuid', $this->written);
		$this->assertArrayNotHasKey('@self', $this->written, '@self is read FIRST by saveObject');
		$this->assertSame('Mapped result', $this->written['title'], 'the mapped payload still saves');
	}//end testAMappedIdentityDoesNotAddressAnExistingObject()
}//end class
