<?php

/**
 * Integriq — action matrix controller tests.
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

use OCA\Integriq\Controller\ActionMatrixController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCP\AppFramework\Http;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The matrix read merges the seeded action keys and lists the groups; the write stores what it is given.
 *
 * @covers \OCA\Integriq\Controller\ActionMatrixController
 */
class ActionMatrixControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked action authorisation service.
	 *
	 * @var ActionAuthServiceInterface&MockObject
	 */
	private ActionAuthServiceInterface $actionAuth;

	/**
	 * The mocked group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request      = $this->createMock(IRequest::class);
		$this->actionAuth   = $this->createMock(ActionAuthServiceInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return ActionMatrixController The controller.
	 */
	private function controller(): ActionMatrixController {
		return new ActionMatrixController(
			request: $this->request,
			actionAuth: $this->actionAuth,
			groupManager: $this->groupManager
		);
	}//end controller()

	/**
	 * The seeded action keys from lib/actions.seed.json.
	 *
	 * @return array<int, string> The keys.
	 */
	private function seedKeys(): array {
		$seed = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/actions.seed.json'), true);
		return array_keys($seed['actions']);
	}//end seedKeys()

	/**
	 * The read returns the stored matrix, every stored and seeded action once, sorted, and every group id.
	 *
	 * @return void
	 */
	public function testGetMatrixMergesSeedKeysAndListsGroups(): void {
		$matrix = ['zz.custom' => ['admin'], 'job.run' => ['ops']];
		$this->actionAuth->method('getMatrix')->willReturn($matrix);

		$admin = $this->createMock(IGroup::class);
		$admin->method('getGID')->willReturn('admin');
		$ops = $this->createMock(IGroup::class);
		$ops->method('getGID')->willReturn('ops');
		$this->groupManager->expects($this->once())->method('search')->with('')->willReturn([$admin, $ops]);

		$response = $this->controller()->getMatrix();
		$data     = $response->getData();

		$expected = array_values(array_unique(array_merge(['zz.custom', 'job.run'], $this->seedKeys())));
		sort($expected);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($matrix, $data['matrix']);
		$this->assertSame($expected, $data['actions']);
		$this->assertContains('zz.custom', $data['actions']);
		$this->assertContains('approval.approve', $data['actions']);
		$this->assertSame(['admin', 'ops'], $data['groups']);
	}//end testGetMatrixMergesSeedKeysAndListsGroups()

	/**
	 * An empty stored matrix still lists every seeded action.
	 *
	 * @return void
	 */
	public function testGetMatrixWithNothingStoredListsTheSeed(): void {
		$this->actionAuth->method('getMatrix')->willReturn([]);
		$this->groupManager->method('search')->willReturn([]);

		$data     = $this->controller()->getMatrix()->getData();
		$expected = $this->seedKeys();
		sort($expected);

		$this->assertSame([], $data['matrix']);
		$this->assertSame($expected, $data['actions']);
		$this->assertSame([], $data['groups']);
	}//end testGetMatrixWithNothingStoredListsTheSeed()

	/**
	 * The write stores the posted matrix and answers with the stored one.
	 *
	 * @return void
	 */
	public function testSetMatrixStoresThePostedMatrix(): void {
		$posted = ['job.run' => ['ops']];
		$this->request->method('getParam')->with('matrix')->willReturn($posted);
		$this->actionAuth->expects($this->once())->method('setMatrix')->with($posted);
		$this->actionAuth->method('getMatrix')->willReturn(['job.run' => ['ops', 'admin']]);

		$response = $this->controller()->setMatrix();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['matrix' => ['job.run' => ['ops', 'admin']]], $response->getData());
	}//end testSetMatrixStoresThePostedMatrix()

	/**
	 * A matrix that is not an array is stored as an empty matrix.
	 *
	 * @return void
	 */
	public function testSetMatrixReplacesANonArrayWithEmpty(): void {
		$this->request->method('getParam')->with('matrix')->willReturn('not a matrix');
		$this->actionAuth->expects($this->once())->method('setMatrix')->with([]);
		$this->actionAuth->method('getMatrix')->willReturn([]);

		$this->assertSame(['matrix' => []], $this->controller()->setMatrix()->getData());
	}//end testSetMatrixReplacesANonArrayWithEmpty()

	/**
	 * A matrix that cannot be encoded is refused with 400 and the encoder's message.
	 *
	 * @return void
	 */
	public function testSetMatrixRefusesAnUnencodableMatrix(): void {
		$this->request->method('getParam')->willReturn(['a' => ['b']]);
		$this->actionAuth->method('setMatrix')->willThrowException(new \JsonException('Malformed UTF-8'));
		$this->actionAuth->expects($this->never())->method('getMatrix');

		$response = $this->controller()->setMatrix();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Could not encode the action matrix: Malformed UTF-8'], $response->getData());
	}//end testSetMatrixRefusesAnUnencodableMatrix()
}//end class
