<?php

/**
 * Integriq Verified Credential Minter.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Mints an organisation-scoped credential in the credential broker, resolves it
 * back byte for byte, and removes it again when that verify fails. No secret is
 * ever logged or returned.
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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Service\Credential\CredentialMint;
use OCA\OpenRegister\Service\Credential\CredentialMintOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mints a credential, verifies it by resolving it back, and revokes it when needed.
 *
 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
 */
class VerifiedCredentialMinter {

	/**
	 * The register the credential broker keeps its credential objects in.
	 *
	 * @var string
	 */
	private const BROKER_REGISTER = 'credential-broker';

	/**
	 * The schema of a brokered credential object.
	 *
	 * @var string
	 */
	private const BROKER_SCHEMA = 'brokeredcredential';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService    $objectService Removes a credential that is not kept.
	 * @param ContainerInterface $container     Resolves the credential broker lazily.
	 * @param LoggerInterface    $logger        Secret-free logging only.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Mint an organisation-scoped credential and resolve it back byte for byte.
	 *
	 * @param array<string, string> $mint The mint fields: label, provider, owner, organisation, part (for the secret-free log) and secret.
	 *
	 * @return string|null The credential id, or null when there is no organisation or broker, or the mint or the verify failed.
	 *
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	public function mint(array $mint): ?string {
		$broker = $this->broker();
		if ($mint['organisation'] === '' || $broker === null) {
			// Infrastructure is never minted at personal scope, nor without a broker.
			$this->logFailure(step: 'no-organisation-or-broker', part: $mint['part'], e: null);
			return null;
		}

		try {
			$credentialId = self::mintCredential(broker: $broker, mint: $mint);
		} catch (Throwable $e) {
			$this->logFailure(step: 'mint', part: $mint['part'], e: $e);
			return null;
		}

		if ($credentialId === '') {
			$this->logFailure(step: 'mint-no-uuid', part: $mint['part'], e: null);
			return null;
		}

		return $this->verify(broker: $broker, credentialId: $credentialId, mint: $mint);

	}//end mint()

	/**
	 * Remove one credential that was minted but is not kept.
	 *
	 * Best effort: a removal that fails is logged without any secret.
	 *
	 * @param string $credentialId The credential id.
	 * @param string $part         The part name, for the secret-free log.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	public function revoke(string $credentialId, string $part): void {
		try {
			$this->objectService->deleteObject(
				deletion: new ObjectDeletion(
					uuid: $credentialId,
					target: new ObjectFindTarget(register: self::BROKER_REGISTER, schema: self::BROKER_SCHEMA)
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logFailure(step: 'revoke', part: $part, e: $e);
		}

	}//end revoke()

	/**
	 * Resolve a minted credential back; remove it when it does not answer the minted secret.
	 *
	 * @param object                $broker       The credential broker.
	 * @param string                $credentialId The minted credential id.
	 * @param array<string, string> $mint         The mint fields.
	 *
	 * @return string|null The credential id, or null when the verify failed.
	 *
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function verify(object $broker, string $credentialId, array $mint): ?string {
		try {
			$verified = $broker->resolveInjectable($credentialId, InlineSecretMigrationExecutor::APP_ID, null, $mint['organisation']);
		} catch (Throwable $e) {
			// The credential exists but cannot be resolved back: remove it, so
			// none stays behind in keepiq unreferenced.
			$this->logFailure(step: 'verify', part: $mint['part'], e: $e);
			$this->revoke(credentialId: $credentialId, part: $mint['part']);
			return null;
		}

		if (self::isSameSecret(secret: $mint['secret'], verified: $verified) === false) {
			$this->logFailure(step: 'verify-mismatch', part: $mint['part'], e: null);
			$this->revoke(credentialId: $credentialId, part: $mint['part']);
			return null;
		}

		return $credentialId;

	}//end verify()

	/**
	 * Mint the credential and return its uuid, or '' when the broker returned no entity.
	 *
	 * @param object                $broker The credential broker.
	 * @param array<string, string> $mint   The mint fields.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private static function mintCredential(object $broker, array $mint): string {
		if (self::hasValueMint(broker: $broker) === true) {
			// OpenRegister main: the mint takes the credential and its options as value objects.
			$credential = $broker->mintCredential(
				new CredentialMint(name: $mint['label'], provider: $mint['provider'], owner: $mint['owner']),
				new CredentialMintOptions(
					allowedApps: [InlineSecretMigrationExecutor::APP_ID],
					secret: $mint['secret'],
					scope: InlineSecretMigrationExecutor::SCOPE_ORGANISATION,
					organisation: $mint['organisation']
				)
			);

			return self::credentialIdOf(credential: $credential);
		}

		// Positional: the broker is a duck-typed cross-app class.
		$credential = $broker->mint(
			$mint['label'],
			$mint['provider'],
			$mint['owner'],
			[InlineSecretMigrationExecutor::APP_ID],
			$mint['secret'],
			InlineSecretMigrationExecutor::SCOPE_ORGANISATION,
			$mint['organisation']
		);

		return self::credentialIdOf(credential: $credential);

	}//end mintCredential()

	/**
	 * Whether the broker mints through OpenRegister main's value-object API.
	 *
	 * @param object $broker The credential broker.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private static function hasValueMint(object $broker): bool {
		return method_exists($broker, 'mintCredential') === true
			&& class_exists(CredentialMint::class) === true
			&& class_exists(CredentialMintOptions::class) === true;

	}//end hasValueMint()

	/**
	 * The uuid of a minted credential, or '' when the broker returned no entity.
	 *
	 * @param mixed $credential What the broker's mint returned.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private static function credentialIdOf(mixed $credential): string {
		if ($credential instanceof ObjectEntity === true) {
			return (string)$credential->getUuid();
		}

		return '';

	}//end credentialIdOf()

	/**
	 * Whether the broker resolved exactly the secret that was minted.
	 *
	 * @param string $secret   The handed-over secret.
	 * @param mixed  $verified What the broker resolved.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private static function isSameSecret(string $secret, mixed $verified): bool {
		return is_string($verified) === true && hash_equals($secret, $verified) === true;

	}//end isSameSecret()

	/**
	 * The credential broker, or null when it is absent or cannot mint and verify.
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function broker(): ?object {
		try {
			$broker = $this->container->get(InlineSecretMigrationExecutor::BROKER_CLASS);
		} catch (Throwable) {
			return null;
		}

		if (self::canMintAndVerify(broker: $broker) === false) {
			return null;
		}

		return $broker;

	}//end broker()

	/**
	 * Whether a container entry is an object offering a mint (mint or mintCredential) and resolveInjectable.
	 *
	 * @param mixed $broker The container entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private static function canMintAndVerify(mixed $broker): bool {
		return is_object($broker) === true
			&& (method_exists($broker, 'mint') === true || self::hasValueMint(broker: $broker) === true)
			&& method_exists($broker, 'resolveInjectable') === true;

	}//end canMintAndVerify()

	/**
	 * Log a part that was not taken over, without any secret.
	 *
	 * @param string         $step The step that failed.
	 * @param string         $part The part name.
	 * @param Throwable|null $e    The exception; only its class is logged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	public function logFailure(string $step, string $part, ?Throwable $e): void {
		$context = ['step' => $step, 'part' => $part];
		if ($e !== null) {
			$context['errorClass'] = get_class($e);
		}

		$this->logger->warning('[integriq] connection handover: part not taken', $context);

	}//end logFailure()
}//end class
