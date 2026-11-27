<?php

/**
 * Integriq Broker Mint Call.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Mints an organisation-scoped credential through whichever mint the installed
 * OpenRegister credential broker offers: mintCredential(CredentialMint,
 * CredentialMintOptions) on OpenRegister main, the positional mint() on older
 * brokers.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialMint;
use OCA\OpenRegister\Service\Credential\CredentialMintOptions;

/**
 * One organisation-scoped mint against the credential broker, in the broker's own call shape.
 *
 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
 */
class BrokerMintCall {

	/**
	 * The app id every credential is allowed for (the broker's Guard 2).
	 *
	 * @var string
	 */
	private const APP_ID = 'openconnector';

	/**
	 * Infrastructure credentials are organisation-scoped, never personal.
	 *
	 * @var string
	 */
	private const SCOPE_ORGANISATION = 'organisation';

	/**
	 * Mint the credential and return its uuid, or '' when the broker returned no entity.
	 *
	 * @param object                $broker The credential broker.
	 * @param array<string, string> $mint   The mint fields: label, provider, owner, secret, organisation.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	public function mint(object $broker, array $mint): string {
		if ($this->isValueApi(broker: $broker) === true) {
			// OpenRegister main: the mint takes the credential and its options as value objects.
			$credential = $broker->mintCredential(
				new CredentialMint(name: $mint['label'], provider: $mint['provider'], owner: $mint['owner']),
				new CredentialMintOptions(
					allowedApps: [self::APP_ID],
					secret: $mint['secret'],
					scope: self::SCOPE_ORGANISATION,
					organisation: $mint['organisation']
				)
			);

			return $this->credentialIdOf(credential: $credential);
		}

		// Positional: the broker is a duck-typed cross-app class.
		$credential = $broker->mint(
			$mint['label'],
			$mint['provider'],
			$mint['owner'],
			[self::APP_ID],
			$mint['secret'],
			self::SCOPE_ORGANISATION,
			$mint['organisation']
		);

		return $this->credentialIdOf(credential: $credential);

	}//end mint()

	/**
	 * Whether the broker offers any mint this class can call.
	 *
	 * @param object $broker The credential broker.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	public function canMint(object $broker): bool {
		return method_exists($broker, 'mint') === true || $this->isValueApi(broker: $broker) === true;

	}//end canMint()

	/**
	 * Whether the broker mints through OpenRegister main's value-object API.
	 *
	 * @param object $broker The credential broker.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	public function isValueApi(object $broker): bool {
		return method_exists($broker, 'mintCredential') === true
			&& class_exists(CredentialMint::class) === true
			&& class_exists(CredentialMintOptions::class) === true;

	}//end isValueApi()

	/**
	 * The uuid of a minted credential, or '' when the broker returned no entity.
	 *
	 * @param mixed $credential What the broker's mint returned.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	private function credentialIdOf(mixed $credential): string {
		if ($credential instanceof ObjectEntity === true) {
			return (string)$credential->getUuid();
		}

		return '';

	}//end credentialIdOf()
}//end class
