<?php

/**
 * Integriq RegisterConnectorExtensions Event.
 *
 * The collect event an extension app answers with its intake channels,
 * digital post providers, payment providers and connector templates.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface;
use OCA\Integriq\Service\Payment\PaymentProviderInterface;
use OCP\EventDispatcher\Event;

/**
 * "Give me your connector adapters": dispatched once, when integriq first
 * needs one of its registries. A listener in an extension app adds its own
 * adapters, each with its app id. Integriq's built-ins are registered first
 * and keep their ids: a contribution that claims one is refused and logged.
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */
class RegisterConnectorExtensionsEvent extends Event {

	/**
	 * Contributed intake channel adapters.
	 *
	 * @var array<int,array{adapter: IntakeChannelAdapterInterface, appId: string}>
	 */
	private array $intakeChannels = [];

	/**
	 * Contributed digital post providers.
	 *
	 * @var array<int,array{provider: DigitalPostProviderInterface, appId: string}>
	 */
	private array $digitalPostProviders = [];

	/**
	 * Contributed payment providers.
	 *
	 * @var array<int,array{provider: PaymentProviderInterface, appId: string}>
	 */
	private array $paymentProviders = [];

	/**
	 * Contributed connector template directories.
	 *
	 * @var array<int,array{directory: string, appId: string}>
	 */
	private array $templateDirectories = [];

	/**
	 * Contribute an intake channel adapter.
	 *
	 * @param IntakeChannelAdapterInterface $adapter The adapter.
	 * @param string                        $appId   The contributing app.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function addIntakeChannel(IntakeChannelAdapterInterface $adapter, string $appId): void {
		$this->intakeChannels[] = ['adapter' => $adapter, 'appId' => $appId];
	}//end addIntakeChannel()

	/**
	 * Contribute a digital post provider.
	 *
	 * @param DigitalPostProviderInterface $provider The provider.
	 * @param string                       $appId    The contributing app.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function addDigitalPostProvider(DigitalPostProviderInterface $provider, string $appId): void {
		$this->digitalPostProviders[] = ['provider' => $provider, 'appId' => $appId];
	}//end addDigitalPostProvider()

	/**
	 * Contribute a payment provider.
	 *
	 * @param PaymentProviderInterface $provider The provider.
	 * @param string                   $appId    The contributing app.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function addPaymentProvider(PaymentProviderInterface $provider, string $appId): void {
		$this->paymentProviders[] = ['provider' => $provider, 'appId' => $appId];
	}//end addPaymentProvider()

	/**
	 * Contribute a directory of connector templates.
	 *
	 * @param string $directory The directory holding the template files.
	 * @param string $appId     The contributing app.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function addConnectorTemplates(string $directory, string $appId): void {
		$this->templateDirectories[] = ['directory' => $directory, 'appId' => $appId];
	}//end addConnectorTemplates()

	/**
	 * The contributed intake channel adapters, in the order they were added.
	 *
	 * @return array<int,array{adapter: IntakeChannelAdapterInterface, appId: string}>
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function getIntakeChannels(): array {
		return $this->intakeChannels;
	}//end getIntakeChannels()

	/**
	 * The contributed digital post providers, in the order they were added.
	 *
	 * @return array<int,array{provider: DigitalPostProviderInterface, appId: string}>
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function getDigitalPostProviders(): array {
		return $this->digitalPostProviders;
	}//end getDigitalPostProviders()

	/**
	 * The contributed payment providers, in the order they were added.
	 *
	 * @return array<int,array{provider: PaymentProviderInterface, appId: string}>
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function getPaymentProviders(): array {
		return $this->paymentProviders;
	}//end getPaymentProviders()

	/**
	 * The contributed connector template directories, in the order they were added.
	 *
	 * @return array<int,array{directory: string, appId: string}>
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function getConnectorTemplates(): array {
		return $this->templateDirectories;
	}//end getConnectorTemplates()
}//end class
