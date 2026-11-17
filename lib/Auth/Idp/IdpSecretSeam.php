<?php

/**
 * Integriq IdpSecretSeam.
 *
 * Reads the keys, certificates and salts an identity provider registration
 * needs. Every read goes through OpenRegister's credential broker with
 * integriq as the app and the registration's organisation as the acting
 * organisation, at the moment of use, so a value rotated in Keepiq is used
 * from the next login on. It refuses, in this order, when Keepiq is not
 * enabled, when the broker or its resolveInjectable() is absent, and when the
 * reference is empty, unknown or resolves to nothing. A value is never
 * cached, logged or put in an error message.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-identity-provider-secrets-are-read-from-keepiq-and-fail-closed-req-idpc-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpSecretRefusedException;
use OCA\Integriq\Service\BrokeredCallServiceInterface;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one way an identity provider secret is read, failing closed.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-identity-provider-secrets-are-read-from-keepiq-and-fail-closed-req-idpc-004
 */
class IdpSecretSeam {

	/**
	 * The app that holds the bytes.
	 *
	 * @var string
	 */
	public const KEEPIQ_APP_ID = 'keepiq';

	/**
	 * OpenRegister's credential broker.
	 *
	 * @var string
	 */
	public const BROKER_CLASS = 'OCA\OpenRegister\Service\Credential\CredentialBrokerService';

	/**
	 * Keepiq is not installed or not enabled.
	 *
	 * @var string
	 */
	public const REASON_KEEPIQ_MISSING = 'keepiq-missing';

	/**
	 * The credential broker or its resolveInjectable() is absent.
	 *
	 * @var string
	 */
	public const REASON_BROKER_MISSING = 'broker-missing';

	/**
	 * The reference is empty, unknown, not allowed for integriq or resolves to nothing.
	 *
	 * @var string
	 */
	public const REASON_SECRET_MISSING = 'secret-missing';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Answers whether Keepiq is enabled.
	 * @param ContainerInterface $container  Provides OpenRegister's credential broker when it is installed.
	 * @param LoggerInterface    $logger     Logs a refusal by registration and role, never a value.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Read one secret of a registration.
	 *
	 * @param string $registration The registration id, for the log.
	 * @param string $organisation The organisation the registration is for, the acting organisation.
	 * @param string $role         The role of the secret (signing, decryption, clientSecret, pseudonymKeys, salt).
	 * @param string $reference    The OpenRegister credential id.
	 *
	 * @return string The secret, for this request only.
	 *
	 * @throws IdpSecretRefusedException When Keepiq or the broker is missing, or the reference does not resolve.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-identity-provider-secrets-are-read-from-keepiq-and-fail-closed-req-idpc-004
	 */
	public function read(string $registration, string $organisation, string $role, string $reference): string {
		if ($this->appManager->isEnabledForAnyone(self::KEEPIQ_APP_ID) === false) {
			$this->refuse(reason: self::REASON_KEEPIQ_MISSING, registration: $registration, role: $role, cause: 'Keepiq is not enabled');
		}

		try {
			$broker = $this->resolveBroker();
		} catch (Throwable $exception) {
			$this->refuse(
				reason: self::REASON_BROKER_MISSING,
				registration: $registration,
				role: $role,
				cause: 'the credential broker cannot be built ('.$exception::class.')'
			);
		}

		if ($broker === null || method_exists($broker, 'resolveInjectable') === false) {
			$this->refuse(reason: self::REASON_BROKER_MISSING, registration: $registration, role: $role, cause: 'the credential broker is unavailable');
		}

		$reference = trim($reference);
		if ($reference === '') {
			$this->refuse(reason: self::REASON_SECRET_MISSING, registration: $registration, role: $role, cause: 'no credential is referenced');
		}

		$organisation = trim($organisation);
		if ($organisation === '') {
			$organisation = null;
		}

		try {
			// Positional: the broker is only known as an object with this
			// method, so its parameter names cannot be checked statically.
			$secret = $broker->resolveInjectable($reference, BrokeredCallServiceInterface::APP_ID, null, $organisation);
		} catch (Throwable $exception) {
			$this->refuse(
				reason: self::REASON_SECRET_MISSING,
				registration: $registration,
				role: $role,
				cause: 'the credential broker refused it ('.$exception::class.')'
			);
		}

		if (is_string($secret) === false || trim($secret) === '') {
			$this->refuse(reason: self::REASON_SECRET_MISSING, registration: $registration, role: $role, cause: 'the credential resolves to nothing');
		}

		return $secret;

	}//end read()

	/**
	 * Log and throw a refusal, naming the registration and the role only.
	 *
	 * @param string $reason       One of the REASON_* constants.
	 * @param string $registration The registration id.
	 * @param string $role         The role of the secret.
	 * @param string $cause        Why, with no value and no reference in it.
	 *
	 * @return never
	 *
	 * @throws IdpSecretRefusedException Always.
	 */
	private function refuse(string $reason, string $registration, string $role, string $cause): never {
		$detail = sprintf('The %s secret of identity provider registration %s cannot be read: %s.', $role, $registration, $cause);
		$this->logger->warning('[IdpSecretSeam] ' . $detail, ['registration' => $registration, 'role' => $role, 'reason' => $reason]);

		throw new IdpSecretRefusedException(reason: $reason, detail: $detail);

	}//end refuse()

	/**
	 * OpenRegister's credential broker, or null when it is not installed.
	 *
	 * @return object|null
	 *
	 * @throws \Psr\Container\ContainerExceptionInterface When the container cannot build it; read() refuses.
	 */
	protected function resolveBroker(): ?object {
		if (class_exists(self::BROKER_CLASS) === false) {
			return null;
		}

		return $this->container->get(self::BROKER_CLASS);

	}//end resolveBroker()
}//end class
