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

		$data = $this->controller($resolver, null, null, $this->createMock(IUser::class))
			->resolve('bag', 'adres-1')->getData();

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
		$response = $this->controller(null, null, null, $this->createMock(IUser::class))->resolve('bag', '');

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

		$response = $this->controller($resolver, null, null, $this->createMock(IUser::class))->resolve('kadaster', 'x');

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
	 * An ordinary account may not search the registry.
	 *
	 * `suggest()` takes a free-text query and answers each hit's identifier — for
	 * `brp-haalcentraal` that identifier is a burgerservicenummer. It shipped
	 * `#[NoAdminRequired]` with no authorization decision at all, so any signed-in
	 * account reached it (integriq#2125, found reviewing #1983).
	 *
	 * Asserting the resolver is NEVER reached, not merely that the status is 403,
	 * so the gate's position is what is under test.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-an-applicant-types-an-address
	 */
	public function testAnOrdinaryAccountMayNotSearchTheRegistry(): void {
		$actionAuth = $this->actionAuthDouble();
		$actionAuth->method('requireAction')->willThrowException(
			new OCSForbiddenException("Action 'propertySource.suggest' requires admin rights")
		);
		$resolver = $this->resolverDouble();
		$resolver->expects($this->never())->method('suggest');

		$response = $this->controller($resolver, null, $actionAuth, $this->createMock(IUser::class))
			->suggest('brp-haalcentraal', 'jansen');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAnOrdinaryAccountMayNotSearchTheRegistry()

	/**
	 * An ordinary account may not read one record from the registry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-resolved-value-carries-its-provenance-req-rfs-003
	 */
	public function testAnOrdinaryAccountMayNotResolveFromTheRegistry(): void {
		$actionAuth = $this->actionAuthDouble();
		$actionAuth->method('requireAction')->willThrowException(
			new OCSForbiddenException("Action 'propertySource.resolve' requires admin rights")
		);
		$resolver = $this->resolverDouble();
		$resolver->expects($this->never())->method('resolve');

		$response = $this->controller($resolver, null, $actionAuth, $this->createMock(IUser::class))
			->resolve('brp-haalcentraal', '999990019');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAnOrdinaryAccountMayNotResolveFromTheRegistry()

	/**
	 * Both registry routes refuse an unauthenticated caller before any read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-an-applicant-types-an-address
	 */
	public function testTheRegistryRoutesRefuseAnAnonymousCaller(): void {
		$resolver = $this->resolverDouble();
		$resolver->expects($this->never())->method('suggest');
		$resolver->expects($this->never())->method('resolve');

		$controller = $this->controller($resolver, null, null, null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->suggest('brp-haalcentraal', 'jansen')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->resolve('brp-haalcentraal', '999990019')->getStatus());

	}//end testTheRegistryRoutesRefuseAnAnonymousCaller()

	/**
	 * Every action the controller gates is seeded, so an operator can see it.
	 *
	 * An action absent from `lib/actions.seed.json` still defaults to admin-only
	 * — `getAllowedGroups()` falls back to `['admin']` — but it does not appear in
	 * the admin matrix, so it cannot be widened or even known about.
	 * `propertySource.resync` shipped that way.
	 *
	 * @return void
	 *
	 * @spec exclude Registry-completeness invariant over a seed file; no requirement states which actions exist.
	 */
	public function testEveryGatedActionIsSeeded(): void {
		$seed = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/lib/actions.seed.json'),
			true
		);
		$seeded = array_keys((array)($seed['actions'] ?? []));

		foreach (
			[
				PropertySourceController::RESYNC_ACTION,
				PropertySourceController::SUGGEST_ACTION,
				PropertySourceController::RESOLVE_ACTION,
			] as $action
		) {
			$this->assertContains(
				$action,
				$seeded,
				"`$action` is gated but not seeded, so it never appears in the admin action matrix."
			);
		}

	}//end testEveryGatedActionIsSeeded()

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

		$data = $this->controller($resolver, null, null, $this->createMock(IUser::class))
			->suggest('bag', 'kerk')->getData();

		$this->assertFalse($data['results'][0]['authoritative']);
	}//end testSuggestionsAreNotAuthoritative()
}//end class
