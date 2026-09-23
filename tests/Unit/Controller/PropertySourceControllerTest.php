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
	 * The caller is now authenticated and permitted, which it did not have to
	 * be before: an unknown provider is not on the public-registry exemption
	 * list, so the query gate runs BEFORE the provider is looked up. That
	 * ordering is deliberate — answering 404 first would let an anonymous
	 * caller enumerate which registries an instance is wired to. The 404 is
	 * still what a permitted caller gets, which is what this test is about.
	 *
	 * @return void
	 */
	public function testAnUnknownProviderIsA404ThatNamesTheId(): void {
		$resolver = $this->resolverDouble();
		$resolver->method('resolve')->willThrowException(new UnknownPropertySourceException('kadaster', ['bag']));

		$response = $this->controller(
			resolver: $resolver,
			user: $this->createMock(IUser::class)
		)->resolve('kadaster', 'x');

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
	/**
	 * A BRP suggestion is refused for an account without the action.
	 *
	 * The resolver is asserted NEVER to be reached, not merely that the status
	 * is 403 — the refusal has to happen before the registry is queried, or the
	 * BSN has already left RvIG by the time we answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
	 */
	public function testABrpSuggestionIsRefusedWithoutTheAction(): void {
		$auth = $this->actionAuthDouble();
		$auth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$resolver = $this->resolverDouble();
		$resolver->expects($this->never())->method('suggest');

		$response = $this->controller(
			resolver: $resolver,
			actionAuth: $auth,
			user: $this->createMock(IUser::class)
		)->suggest('brp', 'Jansen');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testABrpSuggestionIsRefusedWithoutTheAction()

	/**
	 * A BRP resolve is refused for an account without the action.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
	 */
	public function testABrpResolveIsRefusedWithoutTheAction(): void {
		$auth = $this->actionAuthDouble();
		$auth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$resolver = $this->resolverDouble();
		$resolver->expects($this->never())->method('resolve');

		$response = $this->controller(
			resolver: $resolver,
			actionAuth: $auth,
			user: $this->createMock(IUser::class)
		)->resolve('brp', '999993653');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testABrpResolveIsRefusedWithoutTheAction()

	/**
	 * A public registry is not gated at all, so an applicant keeps working.
	 *
	 * This is the half that keeps the gate honest. `suggest()` exists for the
	 * scenario "an applicant types an address"; if closing BRP also put the BAG
	 * lookup behind an administrator the feature would be gone, and the control
	 * would be a blanket closure rather than an authorization decision.
	 *
	 * The gate is asserted NEVER to be consulted for `bag` — not merely that
	 * the call succeeds — so a future change that starts gating every provider
	 * fails here by name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#scenario-an-applicant-types-an-address
	 */
	public function testAPublicRegistryIsNotGated(): void {
		$auth = $this->actionAuthDouble();
		$auth->expects($this->never())->method('requireAction');

		$resolver = $this->resolverDouble();
		$resolver->expects($this->once())->method('suggest')->willReturn([]);

		$response = $this->controller(resolver: $resolver, actionAuth: $auth)->suggest('bag', 'Dorpsstraat');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAPublicRegistryIsNotGated()

	/**
	 * A provider nobody declared public is gated, by default and by name.
	 *
	 * The exemption list is keyed the safe way round: a provider added
	 * tomorrow is closed until someone consciously declares it public. Keyed as
	 * a list of SENSITIVE providers instead, a new personal-data provider would
	 * ship open whenever the list was not updated — which is how `suggest()`
	 * came to hand out a BSN in the first place.
	 *
	 * The action NAME is asserted too, so the prefix cannot drift away from the
	 * one an operator types into Admin Settings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
	 */
	public function testAProviderNotDeclaredPublicIsGated(): void {
		$auth = $this->actionAuthDouble();
		$auth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), 'propertySource.query.some-new-registry')
			->willThrowException(new OCSForbiddenException('nope'));

		$response = $this->controller(
			actionAuth: $auth,
			user: $this->createMock(IUser::class)
		)->suggest('some-new-registry', 'x');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAProviderNotDeclaredPublicIsGated()

	/**
	 * An anonymous caller is refused a gated provider before anything is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
	 */
	public function testAnAnonymousCallerIsRefusedAGatedProvider(): void {
		$resolver = $this->resolverDouble();
		$resolver->expects($this->never())->method('suggest');

		$response = $this->controller(resolver: $resolver, user: null)->suggest('brp', 'Jansen');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousCallerIsRefusedAGatedProvider()

}//end class
