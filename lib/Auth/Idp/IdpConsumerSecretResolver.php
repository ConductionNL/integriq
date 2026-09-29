<?php

/**
 * Integriq IdpConsumerSecretResolver.
 *
 * Reads a consuming app's exchange secret. The older form carries it inline;
 * the current form names a credential in OpenRegister's credential broker, and
 * the secret is read from there at the moment of the exchange, never copied
 * into app config.
 *
 * Every failure answers an empty string, and an empty expected secret is a
 * refused exchange. This is not the fail-open `return null` shape: the caller
 * treats "no secret" as "no match", never as "no check".
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Service\BrokeredCallService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The expected exchange secret of one consumer.
 *
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */
class IdpConsumerSecretResolver {

	/**
	 * FQCN of the OpenRegister credential broker, resolved lazily.
	 *
	 * @var string
	 */
	public const BROKER_CLASS = 'OCA\OpenRegister\Service\Credential\CredentialBrokerService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the broker lazily.
	 * @param LoggerInterface $logger Records a refused read, by class only.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The secret a consumer must present at the exchange.
	 *
	 * @param IdpConsumer $consumer The consumer.
	 *
	 * @return string The secret, or an empty string when there is none to compare against.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function expectedSecret(IdpConsumer $consumer): string {
		if ($consumer->isEnabled() === false) {
			return '';
		}

		if ($consumer->isLegacy() === true) {
			return $consumer->getLegacySecret();
		}

		$reference = trim($consumer->getSecretRef());
		if ($reference === '') {
			return '';
		}

		$broker = $this->resolveBroker();
		if ($broker === null || method_exists($broker, 'resolveInjectable') === false) {
			$this->logger->warning(
				'Integriq idp-broker: the credential broker is unavailable, so consumer ' . $consumer->getId()
				. ' cannot redeem.'
			);
			return '';
		}

		$organisation = trim($consumer->getSecretOrganisation());
		if ($organisation === '') {
			$organisation = null;
		}

		try {
			// Positional: the broker is only known as an object with this
			// method, so its parameter names cannot be checked statically.
			$secret = $broker->resolveInjectable($reference, BrokeredCallService::APP_ID, null, $organisation);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Integriq idp-broker: the credential broker refused the exchange secret of consumer '
				. $consumer->getId() . ' (' . $exception::class . ').'
			);
			return '';
		}

		return trim((string)$secret);

	}//end expectedSecret()

	/**
	 * The broker instance, or null when OpenRegister does not provide one.
	 *
	 * Protected so a test can hand in a broker double.
	 *
	 * @return object|null The broker.
	 *
	 * @spec exclude Container-resolution seam, a lazy cross-app lookup with no behaviour of its own.
	 */
	protected function resolveBroker(): ?object {
		if (class_exists(self::BROKER_CLASS) === false) {
			return null;
		}

		try {
			return $this->container->get(self::BROKER_CLASS);
		} catch (Throwable) {
			return null;
		}

	}//end resolveBroker()

}//end class
