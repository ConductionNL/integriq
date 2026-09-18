<?php

/**
 * Integriq — gateways controller tests.
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

use OCA\Integriq\Bridge\BridgeRegistry;
use OCA\Integriq\Controller\GatewaysController;
use OCA\Integriq\Gateway\GatewayCatalogue;
use OCA\Integriq\Gateway\GatewayRegistry;
use OCA\Integriq\Gateway\ZgwRegistryBinding;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue any signed-in account may read, and the administering routes
 * an ordinary one may not reach.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#scenario-the-catalogue-answers-which-laws-the-instance-reaches
 */
class GatewaysControllerTest extends TestCase {
	/**
	 * Build the controller.
	 *
	 * @param IUser|null $user The signed-in account, or null for none.
	 * @param bool $authorised Whether the action gate lets the caller through.
	 * @param BridgeRegistry|null $bridges A bridge registry double.
	 *
	 * @return GatewaysController The controller under test.
	 */
	private function controller(
		?IUser $user = null,
		bool $authorised = true,
		?BridgeRegistry $bridges = null,
	): GatewaysController {
		$actionAuth = $this->getMockBuilder(ActionAuthService::class)
			->disableOriginalConstructor()
			->onlyMethods(['requireAction'])
			->getMock();
		if ($authorised === false) {
			$actionAuth->method('requireAction')->willThrowException(
				new OCSForbiddenException("Action 'gateway.administer' requires admin rights")
			);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new GatewaysController(
			'integriq',
			$this->createMock(IRequest::class),
			new GatewayRegistry(GatewayCatalogue::entries()),
			$this->getMockBuilder(ZgwRegistryBinding::class)
				->disableOriginalConstructor()
				->onlyMethods(['current', 'isUsable', 'test'])
				->getMock(),
			($bridges ?? $this->bridges()),
			$actionAuth,
			$session
		);
	}//end controller()

	/**
	 * A bridge registry double.
	 *
	 * @return BridgeRegistry The double.
	 */
	private function bridges(): BridgeRegistry {
		return $this->getMockBuilder(BridgeRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['all', 'register', 'revoke', 'isActive', 'authenticates'])
			->getMock();
	}//end bridges()

	/**
	 * The catalogue answers which laws this instance reaches, with each
	 * entry's claim and the evidence behind it.
	 *
	 * @return void
	 */
	public function testTheCatalogueAnswersWhichLawsTheInstanceReaches(): void {
		$data = $this->controller()->index()->getData();

		$this->assertContains('Wmebv', $data['standards']);
		$first = $data['results'][0];
		$this->assertNotSame('', $first['standard']);
		$this->assertNotSame('', $first['claim']['evidence']);
		$this->assertFalse($first['claim']['certified']);
	}//end testTheCatalogueAnswersWhichLawsTheInstanceReaches()

	/**
	 * Filtering by standard narrows the list to that standard.
	 *
	 * @return void
	 */
	public function testTheCatalogueFiltersByStandard(): void {
		$data = $this->controller()->index('Wmebv')->getData();

		$this->assertNotEmpty($data['results']);
		foreach ($data['results'] as $row) {
			$this->assertSame('Wmebv', $row['standard']);
		}
	}//end testTheCatalogueFiltersByStandard()

	/**
	 * The overview shows every gateway with its jurisdiction, and exports.
	 *
	 * @return void
	 */
	public function testTheOverviewShowsJurisdictionAndExports(): void {
		$controller = $this->controller();

		$rows = $controller->overview()->getData()['results'];
		$this->assertNotEmpty($rows);
		$this->assertArrayHasKey('jurisdiction', $rows[0]);

		$export = $controller->exportOverview();
		$this->assertStringContainsString('jurisdiction', $export->render());
	}//end testTheOverviewShowsJurisdictionAndExports()

	/**
	 * An anonymous request may not test a binding.
	 *
	 * @return void
	 */
	public function testAnAnonymousRequestMayNotTestABinding(): void {
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->controller(null)->testBinding()->getStatus()
		);
	}//end testAnAnonymousRequestMayNotTestABinding()

	/**
	 * An ordinary account, the least privileged principal that reaches the
	 * route, may not revoke a bridge, and no bridge is touched.
	 *
	 * @return void
	 */
	public function testAnOrdinaryAccountMayNotRevokeABridge(): void {
		$bridges = $this->bridges();
		$bridges->expects($this->never())->method('revoke');

		$response = $this->controller($this->createMock(IUser::class), false, $bridges)
			->revokeBridge('kantoor-nijmegen');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnOrdinaryAccountMayNotRevokeABridge()

	/**
	 * An authorised account revokes a bridge.
	 *
	 * @return void
	 */
	public function testAnAuthorisedAccountRevokesABridge(): void {
		$bridges = $this->bridges();
		$bridges->method('revoke')->willReturn(true);

		$response = $this->controller($this->createMock(IUser::class), true, $bridges)
			->revokeBridge('kantoor-nijmegen');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['revoked']);
	}//end testAnAuthorisedAccountRevokesABridge()

	/**
	 * Revoking a bridge nobody registered is a 404 naming it.
	 *
	 * @return void
	 */
	public function testRevokingAnUnknownBridgeIsA404(): void {
		$bridges = $this->bridges();
		$bridges->method('revoke')->willReturn(false);

		$response = $this->controller($this->createMock(IUser::class), true, $bridges)
			->revokeBridge('er-is-geen-brug');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertStringContainsString('er-is-geen-brug', $response->getData()['error']);
	}//end testRevokingAnUnknownBridgeIsA404()

	/**
	 * The bridge listing never hands out a token hash.
	 *
	 * @return void
	 */
	public function testTheBridgeListingNeverHandsOutATokenHash(): void {
		$bridges = $this->bridges();
		$bridges->method('all')->willReturn(
			['kantoor-nijmegen' => ['id' => 'kantoor-nijmegen', 'state' => 'active', 'tokenHash' => 'deadbeef']]
		);

		$rows = $this->controller($this->createMock(IUser::class), true, $bridges)->bridges()->getData()['results'];

		$this->assertCount(1, $rows);
		$this->assertArrayNotHasKey('tokenHash', $rows[0]);
	}//end testTheBridgeListingNeverHandsOutATokenHash()
}//end class
