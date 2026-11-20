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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
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
	 * Mints, verifies and revokes the broker credentials of the secret parts.
	 *
	 * @var VerifiedCredentialMinter
	 */
	private readonly VerifiedCredentialMinter $minter;

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
		ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly NestedValuePath $paths = new NestedValuePath(),
	) {
		$this->minter = new VerifiedCredentialMinter(objectService: $objectService, container: $container, logger: $logger);

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

		[$answer, $data] = $this->takeParts(source: $source, given: $given);

		$pending = array_keys($answer, self::TAKEN, true);
		if ($pending === []) {
			return $answer;
		}

		return $this->save(source: $source, data: $data, answer: $answer, pending: $pending);

	}//end takeOver()

	/**
	 * Answer every given part against the source, filling its data with what is taken.
	 *
	 * @param ObjectEntity          $source The source entity.
	 * @param array<string, string> $given  The given parts.
	 *
	 * @return array{0: array<string, string>, 1: array<string, mixed>} The answers and the (filled) data.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function takeParts(ObjectEntity $source, array $given): array {
		$data   = $source->getObject();
		$answer = [];
		foreach ($given as $part => $value) {
			[$answer[$part], $data] = $this->takePart(source: $source, data: $data, part: $part, value: $value);
		}

		$this->pendingRefs = [];

		return [$answer, $data];

	}//end takeParts()

	/**
	 * Answer one given part and, when it is taken, write it into the source data.
	 *
	 * @param ObjectEntity         $source The source entity.
	 * @param array<string, mixed> $data   The source data so far.
	 * @param string               $part   The part name.
	 * @param string               $value  The handed-over value.
	 *
	 * @return array{0: string, 1: array<string, mixed>} The outcome and the (filled) data.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function takePart(ObjectEntity $source, array $data, string $part, string $value): array {
		if (isset(self::PLAIN_PARTS[$part]) === true) {
			$outcome = $this->plainOutcome(data: $data, part: $part, value: $value);
			return $this->placeTaken(outcome: $outcome, data: $data, path: self::PLAIN_PARTS[$part], stored: $value);
		}

		$outcome = $this->takeSecret(source: $source, data: $data, part: $part, secret: $value);

		return $this->placeTaken(outcome: $outcome, data: $data, path: self::SECRET_PARTS[$part][0], stored: ($this->pendingRefs[$part] ?? null));

	}//end takePart()

	/**
	 * Write a taken value into the source data; any other outcome leaves the data as it is.
	 *
	 * @param string               $outcome The outcome for the part.
	 * @param array<string, mixed> $data    The source data so far.
	 * @param list<string>         $path    Where the part lives on a source.
	 * @param mixed                $stored  The value to write when the part is taken.
	 *
	 * @return array{0: string, 1: array<string, mixed>} The outcome and the (filled) data.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function placeTaken(string $outcome, array $data, array $path, mixed $stored): array {
		if ($outcome !== self::TAKEN) {
			return [$outcome, $data];
		}

		return [$outcome, $this->paths->set(data: $data, path: $path, value: $stored)];

	}//end placeTaken()

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
		$path = self::SECRET_PARTS[$part][0];
		if ($this->paths->isEmpty(value: $this->paths->get(data: $data, path: $path)) === false) {
			// A secret the source already holds is never compared and never replaced.
			return self::ALREADY_SET;
		}

		$credentialId = $this->mintFor(source: $source, data: $data, part: $part, secret: $secret);
		if ($credentialId === null) {
			return self::FAILED;
		}

		$this->pendingRefs[$part] = ['credentialRef' => ['credentialId' => $credentialId]];

		return self::TAKEN;

	}//end takeSecret()

	/**
	 * Mint one secret part for the source's organisation and verify it, or null when that fails.
	 *
	 * @param ObjectEntity         $source The source entity (organisation, owner, name).
	 * @param array<string, mixed> $data   The source data so far.
	 * @param string               $part   The part name.
	 * @param string               $secret The handed-over secret.
	 *
	 * @return string|null The credential id.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function mintFor(ObjectEntity $source, array $data, string $part, string $secret): ?string {
		$mint = [
			'label'        => (string)($data['name'] ?? $source->getUuid()) . ' — ' . $part,
			'provider'     => self::SECRET_PARTS[$part][1],
			'owner'        => trim((string)($source->getOwner() ?? '')),
			'organisation' => trim((string)($source->getOrganisation() ?? '')),
			'part'         => $part,
			'secret'       => $secret,
		];

		return $this->minter->mint(mint: $mint);

	}//end mintFor()

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
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA),
					uuid: (string)$source->getUuid()
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->minter->logFailure(step: 'save', part: implode(',', $pending), e: $e);
			foreach ($pending as $part) {
				$answer[$part] = self::FAILED;
			}

			// The source holds no reference to what was minted for it, so the
			// credentials go too: none stays behind in keepiq unreferenced.
			$this->revokeMinted(data: $data, pending: $pending);
		}

		return $answer;

	}//end save()

	/**
	 * Remove the credentials minted for the pending secret parts of a save that failed.
	 *
	 * Best effort: a removal that fails is logged without any secret, and the
	 * credential is then unreferenced and never resolved for this source.
	 *
	 * @param array<string, mixed> $data    The filled source data, holding the references.
	 * @param list<string>         $pending The parts written into $data.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function revokeMinted(array $data, array $pending): void {
		foreach ($pending as $part) {
			if (isset(self::SECRET_PARTS[$part]) === false) {
				continue;
			}

			$reference    = $this->paths->get(data: $data, path: self::SECRET_PARTS[$part][0]);
			$credentialId = '';
			if (is_array($reference) === true && is_array($reference['credentialRef'] ?? null) === true) {
				$credentialId = (string)($reference['credentialRef']['credentialId'] ?? '');
			}

			if ($credentialId === '') {
				continue;
			}

			$this->minter->revoke(credentialId: $credentialId, part: $part);
		}

	}//end revokeMinted()

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
			$this->minter->logFailure(step: 'find', part: '', e: $e);
			return null;
		}

		return $this->firstWithSlug(items: ($result['results'] ?? $result), slug: $slug);

	}//end findSource()

	/**
	 * The first found item that is the source with this slug, read raw.
	 *
	 * Only the raw read is used: the rendered item hides write-only fields, so
	 * filling and saving it could replace a secret the source already holds.
	 * When the raw read fails the source is treated as not found.
	 *
	 * @param iterable<mixed> $items The found items.
	 * @param string          $slug  The slug.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private function firstWithSlug(iterable $items, string $slug): ?ObjectEntity {
		foreach ($items as $item) {
			if (self::isSourceWithSlug(item: $item, slug: $slug) === true) {
				return $this->readRaw(uuid: (string)$item->getUuid());
			}
		}

		return null;

	}//end firstWithSlug()

	/**
	 * Whether a found item is a source entity carrying exactly this slug.
	 *
	 * @param mixed  $item The found item.
	 * @param string $slug The slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	private static function isSourceWithSlug(mixed $item, string $slug): bool {
		return $item instanceof ObjectEntity === true && ($item->getObject()['slug'] ?? '') === $slug;

	}//end isSourceWithSlug()

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
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::SCHEMA, render: false),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->minter->logFailure(step: 'read', part: '', e: $e);
			return null;
		}

		if ($entity instanceof ObjectEntity === false) {
			return null;
		}

		return $entity;

	}//end readRaw()

}//end class
