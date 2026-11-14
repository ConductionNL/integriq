<?php

/**
 * Integriq Connection Handover Service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Takes over the settings of a connection another app used to run itself. The
 * settings land on the matching integriq `source`: plain fields only where the
 * source has none, and every secret part as a broker credential that is minted,
 * resolved back byte for byte, and only then written as a reference. A value an
 * administrator set is never overwritten, and no secret is ever logged or
 * returned.
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
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills an existing source from handed-over connection settings.
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
 */
class ConnectionHandoverService {

	/**
	 * A part was written onto the source.
	 *
	 * @var string
	 */
	public const TAKEN = 'taken';

	/**
	 * The source already holds this part (the same value, or a secret of its own).
	 *
	 * @var string
	 */
	public const ALREADY_SET = 'already-set';

	/**
	 * The source holds a different value for this plain part; it is kept.
	 *
	 * @var string
	 */
	public const CONFLICT = 'conflict';

	/**
	 * The part could not be taken; the source is unchanged for it.
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * The plain parts of a handover and where they live on a source.
	 *
	 * @var array<string, list<string>>
	 */
	public const PLAIN_PARTS = [
		'baseUrl' => ['location'],
		'clientId' => ['configuration', 'authentication', 'client_id'],
	];

	/**
	 * The secret parts of a handover: where the reference lives on a source, and the broker provider.
	 *
	 * The certificate and key sit where the call engine hydrates a TLS client
	 * identity (`cert` / `ssl_key`); the client secret where the token exchange
	 * reads it; the CA bundle in the mTLS section the transport resolves.
	 *
	 * @var array<string, array{0: list<string>, 1: string}>
	 */
	public const SECRET_PARTS = [
		'clientSecret' => [['configuration', 'authentication', 'client_secret'], 'generic-oauth2'],
		'certificate' => [['configuration', 'cert'], 'generic-apikey'],
		'privateKey' => [['configuration', 'ssl_key'], 'generic-apikey'],
		'caBundle' => [['configuration', 'authentication', 'mtls', 'caBundle'], 'generic-apikey'],
	];

	/**
	 * The register sources live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * The schema of a source.
	 *
	 * @var string
	 */
	private const SCHEMA = 'source';

	/**
	 * References minted and verified in this run, by part, waiting for the save.
	 *
	 * @var array<string, array{credentialRef: array{credentialId: string}}>
	 */
	private array $pendingRefs = [];

	/**
	 * Constructor.
	 *
	 * @param OrObjectService    $objectService Reads and saves the source.
	 * @param ContainerInterface $container     Resolves the credential broker lazily.
	 * @param LoggerInterface    $logger        Secret-free logging only.
	 * @param NestedValuePath    $paths         Reads and writes the parts inside the source data.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly NestedValuePath $paths = new NestedValuePath(),
	) {

	}//end __construct()

	/**
	 * Take over handed-over settings onto the source with the given slug.
	 *
	 * Parts that are absent or empty in the handover are not answered. When the
	 * source does not exist every given part fails; when the final save fails,
	 * every part that would have been taken fails and the source is unchanged.
	 *
	 * @param string               $sourceSlug The slug of the source to fill.
	 * @param array<string, mixed> $settings   The handed-over parts, keyed by PLAIN_PARTS / SECRET_PARTS names.
	 *
	 * @return array<string, string> Per given part: taken, already-set, conflict or failed.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	public function takeOver(string $sourceSlug, array $settings): array {
		$given = self::givenParts(settings: $settings);
		if ($given === []) {
			return [];
		}

		$source = $this->findSource(slug: $sourceSlug);
		if ($source === null) {
			$this->logger->warning(
				'[integriq] connection handover: no source with this slug; nothing taken',
				['slug' => $sourceSlug]
			);
			return array_fill_keys(array_keys($given), self::FAILED);
		}

		$data = $source->getObject();
		$answer = [];
		$pending = [];

		foreach ($given as $part => $value) {
			if (isset(self::PLAIN_PARTS[$part]) === true) {
				$answer[$part] = $this->plainOutcome(data: $data, part: $part, value: $value);
				if ($answer[$part] === self::TAKEN) {
					$data = $this->paths->set(data: $data, path: self::PLAIN_PARTS[$part], value: $value);
					$pending[] = $part;
				}

				continue;
			}

			$answer[$part] = $this->takeSecret(source: $source, data: $data, part: $part, secret: $value);
			if ($answer[$part] === self::TAKEN) {
				$data = $this->paths->set(data: $data, path: self::SECRET_PARTS[$part][0], value: $this->pendingRefs[$part]);
				$pending[] = $part;
			}
		}//end foreach

		$this->pendingRefs = [];

		if ($pending === []) {
			return $answer;
		}

		return $this->save(source: $source, data: $data, answer: $answer, pending: $pending);

	}//end takeOver()

	/**
	 * The non-empty string parts of a handover that this service knows.
	 *
	 * @param array<string, mixed> $settings The handed-over settings.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private static function givenParts(array $settings): array {
		$given = [];
		foreach (array_merge(array_keys(self::PLAIN_PARTS), array_keys(self::SECRET_PARTS)) as $part) {
			$value = ($settings[$part] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				$given[$part] = $value;
			}
		}

		return $given;

	}//end givenParts()

	/**
	 * The outcome for a plain part: taken when the source has none, else already-set or conflict.
	 *
	 * @param array<string, mixed> $data  The source data.
	 * @param string               $part  The part name.
	 * @param string               $value The handed-over value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function plainOutcome(array $data, string $part, string $value): string {
		$current = $this->paths->get(data: $data, path: self::PLAIN_PARTS[$part]);
		if ($this->paths->isEmpty(value: $current) === true) {
			return self::TAKEN;
		}

		if (is_string($current) === true && trim($current) === trim($value)) {
			return self::ALREADY_SET;
		}

		return self::CONFLICT;

	}//end plainOutcome()

	/**
	 * Mint and verify one secret part, keeping its reference for the save.
	 *
	 * @param ObjectEntity         $source The source entity (organisation, owner, name).
	 * @param array<string, mixed> $data   The source data so far.
	 * @param string               $part   The part name.
	 * @param string               $secret The handed-over secret.
	 *
	 * @return string taken, already-set or failed.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function takeSecret(ObjectEntity $source, array $data, string $part, string $secret): string {
		[$path, $provider] = self::SECRET_PARTS[$part];
		if ($this->paths->isEmpty(value: $this->paths->get(data: $data, path: $path)) === false) {
			// A secret the source already holds is never compared and never replaced.
			return self::ALREADY_SET;
		}

		$organisation = trim((string)($source->getOrganisation() ?? ''));
		$broker = $this->broker();
		if ($organisation === '' || $broker === null) {
			// Infrastructure is never minted at personal scope, nor without a broker.
			$this->logFailure(step: 'no-organisation-or-broker', part: $part, e: null);
			return self::FAILED;
		}

		$credentialId = $this->mintAndVerify(
			broker: $broker,
			label: (string)($data['name'] ?? $source->getUuid()) . ' — ' . $part,
			provider: $provider,
			owner: trim((string)($source->getOwner() ?? '')),
			organisation: $organisation,
			part: $part,
			secret: $secret
		);
		if ($credentialId === null) {
			return self::FAILED;
		}

		$this->pendingRefs[$part] = ['credentialRef' => ['credentialId' => $credentialId]];

		return self::TAKEN;

	}//end takeSecret()

	/**
	 * Mint an organisation-scoped credential and resolve it back byte for byte.
	 *
	 * @param object $broker       The credential broker.
	 * @param string $label        The credential's name.
	 * @param string $provider     The broker provider.
	 * @param string $owner        The source's owner.
	 * @param string $organisation The source's organisation.
	 * @param string $part         The part name, for the secret-free log.
	 * @param string $secret       The secret.
	 *
	 * @return string|null The credential id, or null when the mint or the verify failed.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Each argument is one field of the mint call.
	 */
	private function mintAndVerify(
		object $broker,
		string $label,
		string $provider,
		string $owner,
		string $organisation,
		string $part,
		string $secret,
	): ?string {
		try {
			// Positional: the broker is a duck-typed cross-app class.
			$credential = $broker->mint(
				$label,
				$provider,
				$owner,
				[InlineSecretMigrationExecutor::APP_ID],
				$secret,
				InlineSecretMigrationExecutor::SCOPE_ORGANISATION,
				$organisation
			);
			$credentialId = '';
			if ($credential instanceof ObjectEntity === true) {
				$credentialId = (string)$credential->getUuid();
			}

			if ($credentialId === '') {
				$this->logFailure(step: 'mint-no-uuid', part: $part, e: null);
				return null;
			}

			$verified = $broker->resolveInjectable($credentialId, InlineSecretMigrationExecutor::APP_ID, null, $organisation);
		} catch (Throwable $e) {
			$this->logFailure(step: 'mint-or-verify', part: $part, e: $e);
			return null;
		}

		if (is_string($verified) === false || hash_equals($secret, $verified) === false) {
			$this->logFailure(step: 'verify-mismatch', part: $part, e: null);
			return null;
		}

		return $credentialId;

	}//end mintAndVerify()

	/**
	 * Save the filled source; on failure every pending part fails.
	 *
	 * @param ObjectEntity          $source  The source entity.
	 * @param array<string, mixed>  $data    The filled source data.
	 * @param array<string, string> $answer  The answer so far.
	 * @param list<string>          $pending The parts written into $data.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function save(ObjectEntity $source, array $data, array $answer, array $pending): array {
		try {
			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: (string)$source->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logFailure(step: 'save', part: implode(',', $pending), e: $e);
			foreach ($pending as $part) {
				$answer[$part] = self::FAILED;
			}
		}

		return $answer;

	}//end save()

	/**
	 * The source with the given slug, read raw so secrets already set are visible.
	 *
	 * @param string $slug The slug.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function findSource(string $slug): ?ObjectEntity {
		if ($slug === '') {
			return null;
		}

		try {
			$result = $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA, 'slug' => $slug]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logFailure(step: 'find', part: '', e: $e);
			return null;
		}

		foreach (($result['results'] ?? $result) as $item) {
			if ($item instanceof ObjectEntity === false || ($item->getObject()['slug'] ?? '') !== $slug) {
				continue;
			}

			return $this->readRaw(uuid: (string)$item->getUuid()) ?? $item;
		}

		return null;

	}//end findSource()

	/**
	 * Read a source unrendered, so write-only fields keep their stored value.
	 *
	 * @param string $uuid The source uuid.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function readRaw(string $uuid): ?ObjectEntity {
		try {
			$entity = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (Throwable $e) {
			$this->logFailure(step: 'read', part: '', e: $e);
			return null;
		}

		if ($entity instanceof ObjectEntity === false) {
			return null;
		}

		return $entity;

	}//end readRaw()

	/**
	 * The credential broker, or null when it is absent or cannot mint and verify.
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function broker(): ?object {
		try {
			$broker = $this->container->get(InlineSecretMigrationExecutor::BROKER_CLASS);
		} catch (Throwable) {
			return null;
		}

		if (is_object($broker) === false
			|| method_exists($broker, 'mint') === false
			|| method_exists($broker, 'resolveInjectable') === false
		) {
			return null;
		}

		return $broker;

	}//end broker()

	/**
	 * Log a failure without any secret.
	 *
	 * @param string         $step The step that failed.
	 * @param string         $part The part name(s).
	 * @param Throwable|null $e    The exception; only its class is logged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function logFailure(string $step, string $part, ?Throwable $e): void {
		$context = ['step' => $step, 'part' => $part];
		if ($e !== null) {
			$context['errorClass'] = get_class($e);
		}

		$this->logger->warning('[integriq] connection handover: part not taken', $context);

	}//end logFailure()
}//end class
