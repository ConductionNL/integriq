<?php

/**
 * Integriq — property-source controller tests.
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

use OCA\Integriq\Controller\PropertySourceController;
use OCA\Integriq\PropertySource\Exception\UnknownPropertySourceException;
use OCA\Integriq\PropertySource\ListResyncService;
use OCA\Integriq\PropertySource\PropertySourceProviderInterface;
use OCA\Integriq\PropertySource\PropertySourceRegistry;
use OCA\Integriq\PropertySource\PropertySourceResolver;
use OCA\Integriq\PropertySource\ResolvedValue;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The HTTP surface openregister resolves through, and the resync an ordinary
 * account may not run.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-list-shaped-source-resyncs-on-demand-req-rfs-007
 */
class PropertySourceControllerTest extends TestCase {
	/**
	 * Build the controller with doubles.
	 *
	 * @param PropertySourceResolver|null $resolver Resolver double.
	 * @param ListResyncService|null $listResync Resync double.
	 * @param ActionAuthService|null $actionAuth Action gate double.
	 * @param IUser|null $user The signed-in account, or null for none.
	 *
	 * @return PropertySourceController The controller under test.
	 */
	private function controller(
		?PropertySourceResolver $resolver = null,
		?ListResyncService $listResync = null,
		?ActionAuthService $actionAuth = null,
		?IUser $user = null,
	): PropertySourceController {
		$provider = $this->createMock(PropertySourceProviderInterface::class);
		$provider->method('id')->willReturn('bag');
		$provider->method('describe')->willReturn(
			[
				'id' => 'bag',
				'label' => 'BAG address',
				'identifier' => 'pdokId',
				'stalenessBudget' => 3600,
				'listShaped' => false,
			]
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PropertySourceController(
			'integriq',
			$this->createMock(IRequest::class),
			new PropertySourceRegistry([$provider]),
			($resolver ?? $this->resolverDouble()),
			($listResync ?? $this->resyncDouble()),
			($actionAuth ?? $this->actionAuthDouble()),
			$session
		);
	}//end controller()

	/**
	 * A resolver double restricted to the methods the real class has.
	 *
	 * @return PropertySourceResolver The double.
	 */
	private function resolverDouble(): PropertySourceResolver {
		return $this->getMockBuilder(PropertySourceResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['suggest', 'resolve', 'resolveSuggestion', 'manual'])
			->getMock();
	}//end resolverDouble()

	/**
	 * A resync double restricted to the methods the real class has.
	 *
	 * @return ListResyncService The double.
	 */
	private function resyncDouble(): ListResyncService {
		return $this->getMockBuilder(ListResyncService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resync', 'currentList', 'lastReport'])
			->getMock();
	}//end resyncDouble()

	/**
	 * An action gate double restricted to the methods the real class has.
	 *
	 * @return ActionAuthService The double.
	 */
	private function actionAuthDouble(): ActionAuthService {
		return $this->getMockBuilder(ActionAuthService::class)
			->disableOriginalConstructor()
			->onlyMethods(['requireAction'])
			->getMock();
	}//end actionAuthDouble()

	/**
	 * The inventory lists every registered provider.
	 *
	 * @return void
	 */
	public function testIndexListsTheRegisteredProviders(): void {
		$data = $this->controller()->index()->getData();

		$this->assertSame(['bag'], array_column($data['results'], 'id'));
	}//end testIndexListsTheRegisteredProviders()

	/**
	 * A resolved value reaches the caller with its provenance beside it.
	 *
	 * @return void
	 */
	public function testResolveReturnsTheValueWithItsProvenance(): void {
		$resolver = $this->resolverDouble();
		$resolver->method('resolve')->willReturn(
			ResolvedValue::fromSource(['street' => 'Kerkstraat'], 'bag', 'adres-1', 1700000000)
		);

		$data = $this->controller($resolver)->resolve('bag', 'adres-1')->getData();

		$this->assertSame('Kerkstraat', $data['value']['street']);
		$this->assertSame('bag', $data['provenance']['provider']);
		$this->assertTrue($data['provenance']['live']);
	}//end testResolveReturnsTheValueWithItsProvenance()

	/**
	 * A resolve without an identifier is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function testResolveWithoutAnIdentifierIsRefused(): void {
		$response = $this->controller()->resolve('bag', '');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testResolveWithoutAnIdentifierIsRefused()

	/**
	 * An unknown provider answers 404 and names the id.
	 *
	 * @return void
	 */
	public function testAnUnknownProviderIsA404ThatNamesTheId(): void {
		$resolver = $this->resolverDouble();
		$resolver->method('resolve')->willThrowException(new UnknownPropertySourceException('kadaster', ['bag']));

		$response = $this->controller($resolver)->resolve('kadaster', 'x');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertStringContainsString('kadaster', $response->getData()['error']);
	}//end testAnUnknownProviderIsA404ThatNamesTheId()

	/**
	 * An ordinary account, which is the least privileged principal that
	 * reaches this route, is refused the resync.
	 *
	 * @return void
	 */
	public function testAnOrdinaryAccountMayNotResync(): void {
		$actionAuth = $this->actionAuthDouble();
		$actionAuth->method('requireAction')->willThrowException(
			new OCSForbiddenException("Action 'propertySource.resync' requires admin rights")
		);
		$resync = $this->resyncDouble();
		$resync->expects($this->never())->method('resync');

		$response = $this->controller(null, $resync, $actionAuth, $this->createMock(IUser::class))->resync('classificatie');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnOrdinaryAccountMayNotResync()

	/**
	 * An anonymous request is refused before the action gate is consulted.
	 *
	 * @return void
	 */
	public function testAnAnonymousRequestMayNotResync(): void {
		$actionAuth = $this->actionAuthDouble();
		$actionAuth->expects($this->never())->method('requireAction');

		$response = $this->controller(null, null, $actionAuth, null)->resync('classificatie');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousRequestMayNotResync()

	/**
	 * An authorised account gets the resync report.
	 *
	 * @return void
	 */
	public function testAnAuthorisedAccountGetsTheResyncReport(): void {
		$resync = $this->resyncDouble();
		$resync->method('resync')->willReturn(['succeeded' => true, 'changed' => 3, 'lastResyncAt' => 1700000000]);

		$data = $this->controller(null, $resync, null, $this->createMock(IUser::class))->resync('classificatie')->getData();

		$this->assertTrue($data['succeeded']);
		$this->assertSame(3, $data['changed']);
	}//end testAnAuthorisedAccountGetsTheResyncReport()

	/**
	 * Suggestions reach the caller marked as not authoritative.
	 *
	 * @return void
	 */
	public function testSuggestionsAreNotAuthoritative(): void {
		$resolver = $this->resolverDouble();
		$resolver->method('suggest')->willReturn([['identifier' => 'adres-1', 'label' => 'Kerkstraat 1', 'authoritative' => false]]);

		$data = $this->controller($resolver)->suggest('bag', 'kerk')->getData();

		$this->assertFalse($data['results'][0]['authoritative']);
	}//end testSuggestionsAreNotAuthoritative()
}//end class
