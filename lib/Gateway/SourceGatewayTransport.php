<?php

/**
 * The default gateway transport: the source and call service already here.
 *
 * @category Service
 * @package  OCA\Integriq\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Gateway;

use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\ConnectionStoreInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * No gateway adapter opens a connection of its own. They all come here, so
 * every statutory route lands in the same call log with the same shape.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-corv-and-ggk-ship-as-sector-gateways-req-sg-003
 */
class SourceGatewayTransport implements GatewayTransport {
	/**
	 * Constructor.
	 *
	 * @param ConnectionStoreInterface $connectionStore Source lookup by slug.
	 * @param CallServiceInterface $callService The shared outbound call engine.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly ConnectionStoreInterface $connectionStore,
		private readonly CallServiceInterface $callService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send one payload over a gateway's configured source.
	 *
	 * @param string $gatewayId The gateway.
	 * @param array<string,mixed> $payload What to send.
	 * @param array<string,mixed> $config Carries `source` and `endpoint`.
	 *
	 * @return GatewayDelivery What happened.
	 */
	public function send(string $gatewayId, array $payload, array $config = []): GatewayDelivery {
		$slug = (string)($config['source'] ?? $gatewayId);
		$transport = (string)($config['transport'] ?? 'https');

		$source = $this->connectionStore->findSourceBySlug(slug: $slug);
		if ($source instanceof ObjectEntity === false) {
			return GatewayDelivery::notSent(
				$gatewayId,
				sprintf('The gateway "%s" has no source configured under "%s". Nothing was sent.', $gatewayId, $slug)
			);
		}

		try {
			$callLog = $this->callService->call(
				source: $source,
				endpoint: (string)($config['endpoint'] ?? ''),
				method: (string)($config['method'] ?? 'POST'),
				config: ['json' => $payload]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'gateway.transport.failed',
				['gateway' => $gatewayId, 'error' => $e->getMessage()]
			);

			return GatewayDelivery::refused($gatewayId, $e->getMessage(), $transport);
		}

		return $this->deliveryFor(sent: new GatewaySend(gatewayId: $gatewayId, transport: $transport), callLog: $callLog);
	}//end send()

	/**
	 * What the gateway's answer means: refused outside 2xx (with its detail or
	 * error), else delivered with the identifier it gave.
	 *
	 * @param GatewaySend  $sent    The gateway and transport the payload went over.
	 * @param ObjectEntity $callLog The call log of the send.
	 *
	 * @return GatewayDelivery What happened.
	 */
	private function deliveryFor(GatewaySend $sent, ObjectEntity $callLog): GatewayDelivery {
		$data = $callLog->getObject();
		$status = (int)($data['statusCode'] ?? 0);
		$body = $this->answerBody(body: ($data['response']['body'] ?? null));

		if ($status < 200 || $status > 299) {
			return GatewayDelivery::refused(
				$sent->gatewayId,
				(string)($body['detail'] ?? $body['error'] ?? sprintf('The gateway answered HTTP %d.', $status)),
				$sent->transport
			);
		}

		return GatewayDelivery::delivered(
			$sent->gatewayId,
			$this->identifierIn(body: $body),
			$sent->transport
		);
	}//end deliveryFor()

	/**
	 * The answer's body as a map: JSON text decoded, anything else empty.
	 *
	 * @param mixed $body The response body.
	 *
	 * @return array<string,mixed> The body.
	 */
	private function answerBody(mixed $body): array {
		if (is_string($body) === true) {
			$body = json_decode($body, true);
		}

		if (is_array($body) === false) {
			return [];
		}

		return $body;
	}//end answerBody()

	/**
	 * The identifier the gateway gave: `identificatie`, else `identifier`, else `id`.
	 *
	 * @param array<string,mixed> $body The answer's body.
	 *
	 * @return string|null The identifier, or null when it gave none.
	 */
	private function identifierIn(array $body): ?string {
		$identifier = ($body['identificatie'] ?? $body['identifier'] ?? $body['id'] ?? null);
		if ($identifier === null) {
			return null;
		}

		return (string)$identifier;
	}//end identifierIn()
}//end class
