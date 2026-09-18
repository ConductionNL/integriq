<?php

/**
 * Shared plumbing for a subscription binding over a seeded source.
 *
 * @category Provider
 * @package  OCA\Integriq\Service\Registry
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

namespace OCA\Integriq\Service\Registry;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A binding resolves its seeded source and calls it through the shared call
 * engine. It never opens a connection of its own, and it never turns a
 * refusal into a silent nothing.
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */
abstract class AbstractSourceSubscriptionProvider implements SubscriptionProviderInterface {
	/**
	 * Constructor.
	 *
	 * @param ConnectionStore $connectionStore Source lookup by slug.
	 * @param CallService $callService The shared outbound call engine.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		protected readonly ConnectionStore $connectionStore,
		protected readonly CallService $callService,
		protected readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The slug of the seeded source this binding reads and writes through.
	 *
	 * @return string Source slug.
	 */
	abstract protected function sourceSlug(): string;

	/**
	 * Call the seeded source.
	 *
	 * @param string $endpoint Endpoint on the source.
	 * @param string $method HTTP method.
	 * @param array<string,mixed> $config Call configuration, for example query or body.
	 *
	 * @return array{status:int,body:array<string,mixed>,error:string} The outcome.
	 */
	protected function callSource(string $endpoint, string $method, array $config = []): array {
		$source = $this->connectionStore->findSourceBySlug(slug: $this->sourceSlug());
		if ($source instanceof ObjectEntity === false) {
			return [
				'status' => 0,
				'body' => [],
				'error' => sprintf('No source is configured under "%s".', $this->sourceSlug()),
			];
		}

		try {
			$callLog = $this->callService->call(
				source: $source,
				endpoint: $endpoint,
				method: $method,
				config: $config
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'registry-subscription.call.failed',
				[
					'registry' => $this->registryId(),
					'endpoint' => $endpoint,
					'error' => $e->getMessage(),
				]
			);

			return ['status' => 0, 'body' => [], 'error' => $e->getMessage()];
		}//end try

		$data = $callLog->getObject();
		$status = (int)($data['statusCode'] ?? 0);
		$body = ($data['response']['body'] ?? null);
		if (is_string($body) === true) {
			$body = json_decode($body, true);
		}

		if (is_array($body) === false) {
			$body = [];
		}

		$error = '';
		if ($status < 200 || $status > 299) {
			$error = (string)($body['title'] ?? $body['detail'] ?? $body['error'] ?? sprintf('The source answered HTTP %d.', $status));
		}

		return ['status' => $status, 'body' => $body, 'error' => $error];
	}//end callSource()
}//end class
