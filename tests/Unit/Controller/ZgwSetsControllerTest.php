<?php

/**
 * The admin routes that list and install the packaged ZGW sets.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ZgwSetsController;
use OCA\Integriq\Service\Zgw\ZgwSetCatalogue;
use OCA\Integriq\Service\Zgw\ZgwSetInstaller;
use OCA\Integriq\Service\Zgw\ZgwSetInstallRefusedException;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Admin-only list and install; a refusal answers 409 with the guard's words.
 */
class ZgwSetsControllerTest extends TestCase {

	/**
	 * The controller with a request carrying $params and the given installer.
	 *
	 * @param ZgwSetInstaller      $installer The installer.
	 * @param array<string, mixed> $params    Request parameters.
	 *
	 * @return ZgwSetsController
	 */
	private function controller(ZgwSetInstaller $installer, array $params=[]): ZgwSetsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default=null) => ($params[$key] ?? $default));

		return new ZgwSetsController($request, $installer);
	}//end controller()

	/**
	 * Both routes are admin-only.
	 *
	 * @return void
	 */
	public function testBothRoutesAreAdminOnly(): void {
		foreach (['index', 'install'] as $method) {
			$attributes = (new ReflectionMethod(ZgwSetsController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $method);
			$this->assertSame(IntegriqAdmin::class, $attributes[0]->getArguments()[0] ?? ($attributes[0]->getArguments()['settings'] ?? null));
		}
	}//end testBothRoutesAreAdminOnly()

	/**
	 * The list shows every packaged set with the schema it is bound to, or null.
	 *
	 * @return void
	 */
	public function testTheListShowsEverySetAndItsBinding(): void {
		$installer = $this->createMock(ZgwSetInstaller::class);
		$installer->method('bindings')->willReturn(['cases/case' => 'zgw-zaken']);

		$sets = $this->controller($installer)->index()->getData()['results'];

		$this->assertCount(count(ZgwSetCatalogue::SETS), $sets);
		$bySlug = array_column($sets, null, 'slug');
		$this->assertSame('cases/case', $bySlug['zgw-zaken']['binding']);
		$this->assertNull($bySlug['zgw-objecten']['binding']);
		$this->assertTrue($bySlug['zgw-zaken']['writesBack']);
	}//end testTheListShowsEverySetAndItsBinding()

	/**
	 * An install passes the chosen register and schema and answers the binding.
	 *
	 * @return void
	 */
	public function testAnInstallAnswersTheBinding(): void {
		$installer = $this->createMock(ZgwSetInstaller::class);
		$installer->expects($this->once())->method('install')
			->with('zgw-zaken', 'cases', 'case')
			->willReturn(['set' => 'zgw-zaken', 'binding' => 'cases/case', 'synchronizations' => ['zgw-zaken-pull', 'zgw-zaken-push']]);

		$response = $this->controller($installer, ['register' => 'cases', 'schema' => 'case'])->install('zgw-zaken');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('cases/case', $response->getData()['binding']);
	}//end testAnInstallAnswersTheBinding()

	/**
	 * A refusal answers 409 with the guard's words.
	 *
	 * @return void
	 */
	public function testARefusalAnswersConflictWithItsReason(): void {
		$installer = $this->createMock(ZgwSetInstaller::class);
		$installer->method('install')->willThrowException(new ZgwSetInstallRefusedException('This schema is already bound to "zgw-zaken".'));

		$response = $this->controller($installer, ['register' => 'cases', 'schema' => 'case'])->install('zgw-objecten');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('This schema is already bound to "zgw-zaken".', $response->getData()['error']);
	}//end testARefusalAnswersConflictWithItsReason()
}//end class
