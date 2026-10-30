<?php

/**
 * Integriq AuthRegistrar.
 *
 * Registers the container bindings the Auth component offers to the
 * rest of the app.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth;

use OCA\Integriq\Auth\Idp\EnvelopeExchangeService;
use OCA\Integriq\Auth\Idp\EnvelopeExchangeServiceInterface;
use OCA\Integriq\Auth\Idp\GovernmentIdpAdapterInterface;
use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCA\Integriq\Auth\Idp\IdpBrokerConfigInterface;
use OCA\Integriq\Auth\Idp\IdpLoginService;
use OCA\Integriq\Auth\Idp\IdpLoginServiceInterface;
use OCA\Integriq\Auth\Idp\LogGovernmentIdpAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Auth contract to the class that implements it, so callers
 * outside the Auth component depend on the contract only.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class AuthRegistrar {
	/**
	 * Register the contract aliases on the app's registration context.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it binds each contract to its class and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(IdpLoginServiceInterface::class, IdpLoginService::class);
		$context->registerServiceAlias(IdpBrokerConfigInterface::class, IdpBrokerConfig::class);
		$context->registerServiceAlias(EnvelopeExchangeServiceInterface::class, EnvelopeExchangeService::class);
		$this->registerGovernmentIdpAdapter(context: $context);
	}
	/**
	 * Registers the government IdP adapter.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerGovernmentIdpAdapter(IRegistrationContext $context): void {
		// The government identity provider seam, bound to the adapter that
		// REFUSES (openspec/specs/digid-eherkenning-auth-adapter/spec.md,
		// "Dormant seam"). Nothing resolves this interface yet: the SAML
		// Service Provider and the OIDC Relying Party are vendor work behind
		// it, and until they land every authentication attempt logs and
		// throws "broker not configured".
		//
		// It is registered rather than left unbound because an unbound
		// interface fails with a container error nobody can act on, and
		// because flipping to a live adapter must be this one line and not a
		// search for every call site.
		$context->registerService(
			GovernmentIdpAdapterInterface::class,
			static function ($c): GovernmentIdpAdapterInterface {
				return new LogGovernmentIdpAdapter(logger: $c->get('Psr\Log\LoggerInterface'));
			}
		);
	}//end registerGovernmentIdpAdapter()
}
