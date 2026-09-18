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

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
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
	 * @param ConnectionStore $connectionStore Source lookup by slug.
	 * @param CallService $callService The shared outbound call engine.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly ConnectionStore $connectionStore,
		private readonly CallService $callService,
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
				config: ['body' => $payload]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'gateway.transport.failed',
				['gateway' => $gatewayId, 'error' => $e->getMessage()]
			);

			return GatewayDelivery::refused($gatewayId, $e->getMessage(), $transport);
		}

		$data = $callLog->getObject();
		$status = (int)($data['statusCode'] ?? 0);
		$body = ($data['response']['body'] ?? null);
		if (is_string($body) === true) {
			$body = json_decode($body, true);
		}

		if (is_array($body) === false) {
			$body = [];
		}

		if ($status < 200 || $status > 299) {
			return GatewayDelivery::refused(
				$gatewayId,
				(string)($body['detail'] ?? $body['error'] ?? sprintf('The gateway answered HTTP %d.', $status)),
				$transport
			);
		}

		$identifier = ($body['identificatie'] ?? $body['identifier'] ?? $body['id'] ?? null);

		return GatewayDelivery::delivered(
			$gatewayId,
			($identifier !== null ? (string)$identifier : null),
			$transport
		);
	}//end send()
}//end class
