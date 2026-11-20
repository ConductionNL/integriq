<?php

/**
 * Integriq StreamConnectionRecords
 *
 * The register side of gateway connections: finds the stream endpoint whose
 * route covers a connection, and writes the call log row of each answer.
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

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads stream endpoints and writes the call log of gateway connections.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 */
class StreamConnectionRecords {

	/**
	 * Constructor for StreamConnectionRecords.
	 *
	 * @param ObjectService      $objects Reads stream endpoints and writes the call log.
	 * @param LoggerInterface    $logger  Records what could not be read or written.
	 * @param StreamRouteMatcher $matcher Matches a connection target against a stream route.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly LoggerInterface $logger,
		private readonly StreamRouteMatcher $matcher = new StreamRouteMatcher(),
	) {

	}//end __construct()

	/**
	 * The stream endpoint of this protocol whose route covers the target.
	 *
	 * @param string $protocol The protocol.
	 * @param string $target   The path or topic.
	 *
	 * @return ObjectEntity|null
	 */
	public function streamEndpoint(string $protocol, string $target): ?ObjectEntity {
		try {
			// Unscoped: the caller is a web server, which has no organisation; a
			// stream endpoint is the app's own route description.
			$found = $this->objects->findAll(
				config: [
					'filters' => [
						'register' => 'integriq',
						'schema' => 'endpoint',
						'targetType' => GatewayConnectionAuthorizer::TARGET_TYPE,
						'streamProtocol' => $protocol,
					],
					'limit' => 500,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: stream endpoints could not be read', ['exception' => $e->getMessage()]);
			return null;
		}

		foreach (($found['results'] ?? $found) as $endpoint) {
			if ($endpoint instanceof ObjectEntity === false) {
				continue;
			}

			$data = $endpoint->getObject();
			if (($data['targetType'] ?? '') !== GatewayConnectionAuthorizer::TARGET_TYPE || ($data['streamProtocol'] ?? '') !== $protocol) {
				continue;
			}

			if ($this->matcher->matches(protocol: $protocol, route: (string)($data['streamRoute'] ?? ''), target: $target) === true) {
				return $endpoint;
			}
		}

		return null;

	}//end streamEndpoint()

	/**
	 * Write one call log row for the connection.
	 *
	 * @param array<string, mixed> $ask      What the server sent.
	 * @param ObjectEntity|null    $endpoint The stream endpoint.
	 * @param ObjectEntity|null    $consumer The consumer.
	 * @param array                $decision The answer.
	 *
	 * @return void
	 */
	public function log(array $ask, ?ObjectEntity $endpoint, ?ObjectEntity $consumer, array $decision): void {
		$message = 'Connection refused: ' . $decision['reason'];
		if ($decision['status'] === Http::STATUS_OK) {
			$message = 'Connection allowed';
		}

		$row = [
			'statusCode' => $decision['status'],
			'statusMessage' => $message,
			'direction' => 'inbound',
			'protocol' => (string)$ask['protocol'],
			'created' => (new DateTime())->format('c'),
		];
		$connectionId = trim((string)($ask['connectionId'] ?? ''));
		if ($connectionId !== '') {
			$row['connectionId'] = substr($connectionId, 0, 255);
		}

		if ($endpoint !== null) {
			$row['endpoint'] = $this->uuidOf(entity: $endpoint);
		}

		if ($consumer !== null) {
			$row['consumer'] = $this->uuidOf(entity: $consumer);
		}

		try {
			// The call_log schema is admin-only (REQ-OCD-012); the asking server is not.
			$this->objects->saveObject(
				object: $row,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'integriq', schema: 'call_log')),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: failed to record a stream connection call_log: ' . $e->getMessage());
		}

	}//end log()

	/**
	 * An entity's uuid.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return string
	 */
	public function uuidOf(ObjectEntity $entity): string {
		return (string)($entity->getUuid() ?? ($entity->getObject()['uuid'] ?? ''));

	}//end uuidOf()
}//end class
