<?php

/**
 * The provider picker is fed from the registry, or it is fed from nothing.
 *
 * The failure this guards is the silent one. A picker built from a list
 * written beside the registry goes stale without a word: a binding added is
 * invisible, and a binding removed leaves an option that saves a provider id
 * nothing answers to. Neither shows on screen. The source simply never sends,
 * and the first evidence is a letter that was never posted.
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
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DigitalPostProvidersController;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the digital post provider description endpoint.
 */
class DigitalPostProvidersControllerTest extends TestCase {

	/**
	 * Build a controller over a registry carrying the given bindings.
	 *
	 * @param array $providers Provider id to config schema.
	 * @param boolean $authenticated Whether a user is logged in.
	 *
	 * @return DigitalPostProvidersController The controller.
	 */
	private function controller(array $providers, bool $authenticated = true): DigitalPostProvidersController {
		$bindings = [];
		foreach ($providers as $id => $schema) {
			$binding = $this->createMock(originalClassName: DigitalPostProviderInterface::class);
			$binding->method('getProviderId')->willReturn($id);
			$binding->method('getConfigSchema')->willReturn($schema);
			$bindings[] = $binding;
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($authenticated === true) {
			$session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));
		} else {
			$session->method('getUser')->willReturn(null);
		}

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DigitalPostProvidersController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			registry: new DigitalPostProviderRegistry(providers: $bindings),
			userSession: $session,
			l: $l10n
		);

	}//end controller()

	/**
	 * Every binding the registry carries is described.
	 *
	 * @return void
	 */
	public function testEveryBindingTheRegistryCarriesIsDescribed(): void {
		$response = $this->controller(
			providers: [
				'log'          => ['type' => 'object', 'properties' => []],
				'berichtenbox' => ['type' => 'object', 'properties' => ['oin' => ['type' => 'string']]],
				'postex'       => ['type' => 'object', 'properties' => []],
			]
		)->providers();

		$providers = $response->getData()['providers'];

		$this->assertCount(3, $providers);
		$this->assertSame(
			['log', 'berichtenbox', 'postex'],
			array_column($providers, 'providerId')
		);

	}//end testEveryBindingTheRegistryCarriesIsDescribed()

	/**
	 * A binding carries the configuration it needs, so the form can build itself.
	 *
	 * @return void
	 */
	public function testABindingCarriesTheConfigurationItNeeds(): void {
		$response = $this->controller(
			providers: ['berichtenbox' => ['type' => 'object', 'properties' => ['oin' => ['type' => 'string']]]]
		)->providers();

		$described = $response->getData()['providers'][0];

		$this->assertSame('berichtenbox', $described['providerId']);
		$this->assertArrayHasKey('oin', $described['configSchema']['properties']);

	}//end testABindingCarriesTheConfigurationItNeeds()

	/**
	 * A binding added to the registry appears without the form being edited.
	 *
	 * @return void
	 */
	public function testABindingAddedToTheRegistryAppearsOnItsOwn(): void {
		// This is the whole reason the endpoint exists rather than a constant
		// in the form. Adding a binding must not require remembering a second
		// place, because the cost of forgetting is a picker that silently
		// cannot offer it.
		$before = $this->controller(providers: ['log' => []])->providers()->getData()['providers'];
		$after  = $this->controller(providers: ['log' => [], 'postex' => []])->providers()->getData()['providers'];

		$this->assertSame(['log'], array_column($before, 'providerId'));
		$this->assertSame(['log', 'postex'], array_column($after, 'providerId'));

	}//end testABindingAddedToTheRegistryAppearsOnItsOwn()

	/**
	 * An instance carrying no bindings says so, rather than erroring.
	 *
	 * @return void
	 */
	public function testAnInstanceWithNoBindingsSaysSo(): void {
		// An empty list is a real answer, and the form can say "no digital
		// post binding is installed". A 500 here would read as a broken page.
		$response = $this->controller(providers: []);

		$this->assertSame([], $response->providers()->getData()['providers']);

	}//end testAnInstanceWithNoBindingsSaysSo()

	/**
	 * An anonymous caller is refused.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		// The config schema of a binding names the credentials it wants. That
		// is not a public inventory of what this instance is configured to
		// talk to.
		$response = $this->controller(providers: ['berichtenbox' => []], authenticated: false)->providers();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertArrayNotHasKey('providers', $response->getData());

	}//end testAnAnonymousCallerIsRefused()

}//end class
