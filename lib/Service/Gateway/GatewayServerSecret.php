<?php

/**
 * Integriq GatewayServerSecret
 *
 * The shared secret a web server or broker sends with every connection authorization call.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.integriq.app
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Gateway;

use OCA\Integriq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks the server secret against the credential broker (design D2, ADR-064).
 *
 * The secret itself is never stored by integriq: app config holds only the id
 * of an inject-only credential in OpenRegister's credential broker and the
 * organisation it belongs to, and the value is resolved at the moment of the
 * check. Anything that keeps the value from being read (nothing configured, no
 * broker, a refused or empty credential) refuses every call: the route fails
 * closed.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 */
class GatewayServerSecret {

	/**
	 * App config key holding the broker credential id of the server secret.
	 */
	public const CREDENTIAL_KEY = 'gateway_authorize_credential';

	/**
	 * App config key holding the organisation that credential belongs to.
	 */
	public const ORGANISATION_KEY = 'gateway_authorize_organisation';

	/**
	 * FQCN of OpenRegister's credential broker, resolved lazily.
	 */
	public const BROKER_CLASS = 'OCA\OpenRegister\Service\Credential\CredentialBrokerService';

	/**
	 * Constructor for GatewayServerSecret.
	 *
	 * @param IAppConfig         $appConfig The app config holding the credential reference.
	 * @param ContainerInterface $container The container the broker is resolved from.
	 * @param LoggerInterface    $logger    Records why a secret could not be read.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether the presented secret is the configured one.
	 *
	 * @param string $presented The secret the server sent.
	 *
	 * @return bool False also when no secret can be read.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
	 */
	public function verify(#[\SensitiveParameter] string $presented): bool {
		if ($presented === '') {
			return false;
		}

		$expected = $this->expected();
		if ($expected === null || $expected === '') {
			return false;
		}

		return hash_equals($expected, $presented);

	}//end verify()

	/**
	 * The configured secret, or null when it cannot be read.
	 *
	 * @return string|null
	 */
	private function expected(): ?string {
		$credentialId = trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::CREDENTIAL_KEY, ''));
		if ($credentialId === '' || class_exists(self::BROKER_CLASS) === false) {
			return null;
		}

		$organisation = trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::ORGANISATION_KEY, ''));
		if ($organisation === '') {
			$organisation = null;
		}

		try {
			$broker = $this->container->get(self::BROKER_CLASS);
			return $broker->resolveInjectable($credentialId, AppIdentity::APP_ID, null, $organisation);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: the gateway server secret could not be read', ['exception' => $e->getMessage()]);
			return null;
		}

	}//end expected()
}//end class
