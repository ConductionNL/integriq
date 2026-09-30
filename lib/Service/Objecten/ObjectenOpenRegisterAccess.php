<?php

/**
 * What the Objecten facade asks of OpenRegister, answered by OpenRegister.
 *
 * The facade's handlers take their OpenRegister access as callables so each
 * can be tested without an OpenRegister. This class is the production side of
 * every one of them, and the one place that knows which OpenRegister service
 * answers which question:
 *
 * - objects: the object service, so the schema validates, the audit trail
 *   records and RBAC decides, exactly as for any other read or write;
 * - a schema's definition: the schema mapper;
 * - announce: {@see \OCA\Integriq\Service\EventService::emitCloudEvent()}, so a
 *   subscription with a notifications action carries the change to an NRC;
 * - a token's key: the credential broker, by reference.
 *
 * 🔴 EVERY OBJECT READ AND WRITE RUNS AS THE TOKEN'S PRINCIPAL. The token check
 * is the first gate and OpenRegister's RBAC is the second (design D3, D7). A
 * principal that does not resolve to a user is REFUSED here, never replaced by
 * the anonymous or the system context: a read that ran without a user would
 * read whatever an anonymous caller may, and a write would name nobody in the
 * audit trail.
 *
 * The declarations themselves (the published objecttypes and the tokens) are
 * integriq's own admin-only configuration, so they are read in the system
 * context: no token principal may choose which tokens exist.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Objecten;

use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\EventService;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * OpenRegister, as the facade's seams need it.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
class ObjectenOpenRegisterAccess {

	/**
	 * The register integriq's own declarations live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema a published objecttype is declared in.
	 *
	 * @var string
	 */
	public const OBJECTTYPE_SCHEMA = 'objecttype';

	/**
	 * The schema a token is declared in.
	 *
	 * @var string
	 */
	public const TOKEN_SCHEMA = 'objecten_token';

	/**
	 * OpenRegister's object service.
	 *
	 * @var string
	 */
	public const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * OpenRegister's schema mapper.
	 *
	 * @var string
	 */
	public const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * OpenRegister's credential broker.
	 *
	 * @var string
	 */
	public const BROKER = 'OCA\OpenRegister\Service\Credential\CredentialBrokerService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Resolves the OpenRegister services lazily.
	 * @param IUserManager       $userManager Resolves a principal to a user.
	 * @param IUserSession       $userSession Carries the principal for one call.
	 * @param LoggerInterface    $logger      Records what could not be read, never its detail.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The objects of one register and schema, read as the principal.
	 *
	 * @param string $register  The register.
	 * @param string $schema    The schema.
	 * @param string $principal The token's principal.
	 *
	 * @return array<int, array<string, mixed>> The objects, rendered.
	 *
	 * @throws RuntimeException When the principal does not resolve.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-the-objecten-api-reads-objects-in-the-standards-shape-req-oaf-003
	 */
	public function readObjects(string $register, string $schema, string $principal): array {
		$service = $this->objectService();

		$found = $this->runAs(
			principal: $principal,
			callback: fn (): mixed => $service->findAll(['filters' => ['register' => $register, 'schema' => $schema]])
		);

		return $this->rows(found: $found);
	}//end readObjects()

	/**
	 * The stored schema as a JSON Schema document.
	 *
	 * Read in the system context: a schema's definition is configuration the
	 * objecttype publishes, and the token check has already passed. The objects
	 * the schema holds are read as the principal (readObjects()).
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed> The JSON Schema, empty when the schema cannot be read.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-the-objecttypen-api-serves-the-schema-it-stands-for-req-oaf-002
	 */
	public function readSchema(string $schema): array {
		try {
			// Positional: the mapper is only known as an object with this method.
			$stored = $this->service(name: self::SCHEMA_MAPPER)->find($schema, [], false, false);
		} catch (Throwable $exception) {
			$this->logger->warning('Integriq objecten: schema ' . $schema . ' could not be read (' . $exception::class . ').');
			return [];
		}

		if (is_object($stored) === false || method_exists($stored, 'getProperties') === false) {
			return [];
		}

		$document = [
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
			'type' => 'object',
			'properties' => (array)$stored->getProperties(),
		];

		if (method_exists($stored, 'getTitle') === true) {
			$document['title'] = (string)$stored->getTitle();
		}

		if (method_exists($stored, 'getRequired') === true && (array)$stored->getRequired() !== []) {
			$document['required'] = array_values((array)$stored->getRequired());
		}

		return $document;
	}//end readSchema()

	/**
	 * Create or replace one object, as the principal.
	 *
	 * @param string               $register  The register.
	 * @param string               $schema    The schema.
	 * @param string|null          $uuid      The object, null on a create.
	 * @param array<string, mixed> $data      The data.
	 * @param string               $principal The token's principal.
	 *
	 * @return array<string, mixed> The stored object.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-write-lands-in-openregister-and-announces-req-oaf-004
	 */
	public function writeObject(string $register, string $schema, ?string $uuid, array $data, string $principal): array {
		$service = $this->objectService();

		$stored = $this->runAs(
			principal: $principal,
			callback: fn (): mixed => $service->saveObject(object: $data, register: $register, schema: $schema, uuid: $uuid)
		);

		return ($this->rows(found: [$stored])[0] ?? $data);
	}//end writeObject()

	/**
	 * Delete one object, as the principal.
	 *
	 * @param string $register  The register.
	 * @param string $schema    The schema.
	 * @param string $uuid      The object.
	 * @param string $principal The token's principal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-write-lands-in-openregister-and-announces-req-oaf-004
	 */
	public function deleteObject(string $register, string $schema, string $uuid, string $principal): void {
		$service = $this->objectService();

		$deleted = $this->runAs(
			principal: $principal,
			callback: fn (): mixed => $service->deleteObject(uuid: $uuid, register: $register, schema: $schema)
		);

		if ($deleted === false) {
			throw new RuntimeException(sprintf('Object "%s" was not deleted.', $uuid));
		}
	}//end deleteObject()

	/**
	 * Announce one change on the objecten kanaal.
	 *
	 * The notification is the Notificaties API body the write handler built.
	 * It goes out as a CloudEvent, so every subscription on its type, including
	 * one whose action forwards to a Notificaties API, receives it.
	 *
	 * @param array<string, mixed> $notification The notification.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-write-lands-in-openregister-and-announces-req-oaf-004
	 */
	public function announce(array $notification): void {
		$this->container->get(EventService::class)->emitCloudEvent(
			type: 'nl.vng.objecten.object.' . (string)($notification['actie'] ?? 'update'),
			source: '/apps/integriq/api/v2/objects',
			subject: (string)($notification['hoofdObject'] ?? ''),
			data: $notification
		);
	}//end announce()

	/**
	 * The key behind a credential reference, or null.
	 *
	 * @param string $reference The reference.
	 *
	 * @return string|null The key.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-token-carries-a-permission-per-objecttype-req-oaf-005
	 */
	public function readCredential(string $reference): ?string {
		try {
			$broker = $this->service(name: self::BROKER);
			// Positional: the broker is only known as an object with this method.
			$key = $broker->resolveInjectable($reference, BrokeredCallService::APP_ID, null, null);
		} catch (Throwable $exception) {
			// The class only: the message of a broker refusal can carry the reference.
			$this->logger->warning('Integriq objecten: a token credential could not be resolved (' . $exception::class . ').');
			return null;
		}

		$key = trim((string)$key);
		if ($key === '') {
			return null;
		}

		return $key;
	}//end readCredential()

	/**
	 * Integriq's own declarations of one schema, read in the system context.
	 *
	 * An unreadable configuration answers no declarations, so every route
	 * answers 401 or 404: the facade then serves nothing rather than guessing.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The declarations.
	 *
	* @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function declarations(string $schema): array {
		try {
			$found = $this->objectService()->findAll(
				['filters' => ['register' => self::REGISTER, 'schema' => $schema]],
				false,
				false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('Integriq objecten: the ' . $schema . ' declarations could not be read (' . $exception::class . ').');
			return [];
		}

		return $this->rows(found: $found);
	}//end declarations()

	/**
	 * Rendered rows from whatever the object service answered.
	 *
	 * @param mixed $found A list, or a `results` envelope, of entities or arrays.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(mixed $found): array {
		if (is_array($found) === true && array_key_exists('results', $found) === true) {
			$found = $found['results'];
		}

		$rows = [];
		foreach ((array)$found as $entity) {
			if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
				$entity = $entity->jsonSerialize();
			}

			if (is_array($entity) === true) {
				$rows[] = $entity;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * Run one call as the principal, restoring the prior user after.
	 *
	 * @param string   $principal The principal.
	 * @param callable $callback  The call.
	 *
	 * @return mixed What the call answered.
	 *
	 * @throws RuntimeException When the principal does not resolve to a user.
	 */
	private function runAs(string $principal, callable $callback): mixed {
		$user = null;
		if (trim($principal) !== '') {
			$user = $this->userManager->get($principal);
		}

		if ($user === null) {
			$this->logger->warning('Integriq objecten: a token names a principal that is not a user of this instance; nothing was read or written.');
			throw new RuntimeException('This token\'s principal is not a user of this instance, so nothing is read or written as it.');
		}

		$prior = $this->userSession->getUser();
		// Volatile, so the principal never reaches a PHP session (see FlowOwner::runAs()).
		$this->userSession->setVolatileActiveUser($user);

		try {
			return $callback();
		} finally {
			$this->userSession->setVolatileActiveUser($prior);
		}
	}//end runAs()

	/**
	 * OpenRegister's object service.
	 *
	 * @return object The service.
	 */
	private function objectService(): object {
		return $this->service(name: self::OBJECT_SERVICE);
	}//end objectService()

	/**
	 * One service from the container.
	 *
	 * @param string $name The class.
	 *
	 * @return object The service.
	 *
	 * @throws RuntimeException When OpenRegister does not provide it.
	 */
	private function service(string $name): object {
		$service = $this->container->get($name);
		if (is_object($service) === false) {
			throw new RuntimeException(sprintf('OpenRegister does not provide %s.', $name));
		}

		return $service;
	}//end service()
}//end class
