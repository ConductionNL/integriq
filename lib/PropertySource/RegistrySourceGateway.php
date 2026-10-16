<?php

/**
 * The one way a property-source binding reaches its registry.
 *
 * @category Service
 * @package  OCA\Integriq\PropertySource
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

namespace OCA\Integriq\PropertySource;

use OCA\Integriq\PropertySource\Exception\MissingSourceConfigurationException;
use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A binding never opens its own HTTP connection. It asks here, and this goes
 * through the configured source and the shared call service, per ADR-005 and
 * ADR-011.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006
 */
class RegistrySourceGateway {
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
	 * Read from a configured source.
	 *
	 * @param string $providerId Provider id asking, for the failure message.
	 * @param string $sourceSlug Slug of the configured source.
	 * @param string $endpoint Endpoint on that source.
	 * @param array<string,mixed> $query Query parameters.
	 * @param array<string,mixed>|null $json A JSON body. Given, the call is a POST: some registries take
	 *                                       the question in the body so an identifier stays out of the URL.
	 *
	 * @return array<string,mixed> Decoded response body.
	 *
	 * @throws MissingSourceConfigurationException When no source is configured under the slug.
	 * @throws SourceUnreachableException When the source did not answer with a usable body.
	 *
	 * @spec openspec/changes/registry-bindings-speak-the-registry-api/specs/registry-field-source/spec.md#requirement-a-registry-binding-asks-the-way-its-registry-answers
	 */
	public function read(string $providerId, string $sourceSlug, string $endpoint, array $query = [], ?array $json = null): array {
		$source = $this->source(providerId: $providerId, sourceSlug: $sourceSlug);
		$question = $this->question(query: $query, json: $json);

		return $this->answer(
			providerId: $providerId,
			sourceSlug: $sourceSlug,
			call: fn (): ObjectEntity => $this->callService->call(
				source: $source,
				endpoint: $endpoint,
				method: $question['method'],
				config: $question['config']
			)
		);
	}//end read()

	/**
	 * Read from a configured source and record the call without any body.
	 *
	 * For an answer that must never be stored, such as a person's family
	 * relations. The call log gets a record that names the source, the user,
	 * the moment and the outcome, so an administrator can show who consulted
	 * the registry. It holds no request body, no response body and no replay
	 * request, also while an investigation window is open on the source.
	 * Send every identifier in the body: the URL path is recorded.
	 *
	 * @param string $providerId Provider id asking, for the failure message.
	 * @param string $sourceSlug Slug of the configured source.
	 * @param string $endpoint Endpoint on that source.
	 * @param array<string,mixed> $query Query parameters. They are sent and not recorded.
	 * @param array<string,mixed>|null $json A JSON body. Given, the call is a POST.
	 *
	 * @return array<string,mixed> Decoded response body.
	 *
	 * @throws MissingSourceConfigurationException When no source is configured under the slug.
	 * @throws SourceUnreachableException When the source did not answer with a usable body.
	 *
	 * @spec openspec/changes/brp-relations-read/specs/registry-field-source/spec.md#requirement-brp-relations-are-read-only-where-the-source-says-the-municipality-may
	 */
	public function readWithoutBodies(string $providerId, string $sourceSlug, string $endpoint, array $query = [], ?array $json = null): array {
		$source = $this->source(providerId: $providerId, sourceSlug: $sourceSlug);
		$question = $this->question(query: $query, json: $json);

		return $this->answer(
			providerId: $providerId,
			sourceSlug: $sourceSlug,
			call: fn (): ObjectEntity => $this->callService->call(
				source: $source,
				endpoint: $endpoint,
				method: $question['method'],
				config: $question['config'],
				withholdBodies: true
			)
		);
	}//end readWithoutBodies()

	/**
	 * The configured source under a slug.
	 *
	 * @param string $providerId Provider id asking, for the failure message.
	 * @param string $sourceSlug Slug of the configured source.
	 *
	 * @return ObjectEntity The source.
	 *
	 * @throws MissingSourceConfigurationException When no source is configured under the slug.
	 */
	private function source(string $providerId, string $sourceSlug): ObjectEntity {
		$source = $this->connectionStore->findSourceBySlug(slug: $sourceSlug);
		if ($source instanceof ObjectEntity === false) {
			throw new MissingSourceConfigurationException(providerId: $providerId, sourceSlug: $sourceSlug);
		}

		return $source;
	}//end source()

	/**
	 * The HTTP method and call configuration of a question.
	 *
	 * @param array<string,mixed> $query Query parameters.
	 * @param array<string,mixed>|null $json A JSON body. Given, the call is a POST.
	 *
	 * @return array{method:string,config:array<string,mixed>}
	 */
	private function question(array $query, ?array $json): array {
		if ($json === null) {
			return ['method' => 'GET', 'config' => ['query' => $query]];
		}

		return ['method' => 'POST', 'config' => ['query' => $query, 'body' => (string)json_encode($json)]];
	}//end question()

	/**
	 * Make the call and read the decoded body out of what the call engine hands back.
	 *
	 * @param string $providerId Provider id asking, for the failure message.
	 * @param string $sourceSlug Slug of the configured source.
	 * @param callable():ObjectEntity $call The call to make.
	 *
	 * @return array<string,mixed> Decoded response body.
	 *
	 * @throws SourceUnreachableException When the source did not answer with a usable body.
	 */
	private function answer(string $providerId, string $sourceSlug, callable $call): array {
		try {
			$callLog = $call();
		} catch (Throwable $e) {
			$this->logger->warning(
				'property-source.gateway.transport',
				[
					'provider' => $providerId,
					'source' => $sourceSlug,
					'error' => $e->getMessage(),
				]
			);
			throw new SourceUnreachableException(providerId: $providerId, message: sprintf('Source "%s" did not answer: %s', $sourceSlug, $e->getMessage()));
		}

		$data = $callLog->getObject();
		$status = (int)($data['statusCode'] ?? 0);
		if ($status < 200 || $status > 299) {
			throw new SourceUnreachableException(
				providerId: $providerId,
				message: sprintf('Source "%s" answered HTTP %d.', $sourceSlug, $status)
			);
		}

		$body = ($data['response']['body'] ?? null);
		if (is_string($body) === true) {
			$body = json_decode($body, true);
		}

		if (is_array($body) === false) {
			throw new SourceUnreachableException(
				providerId: $providerId,
				message: sprintf('Source "%s" answered a body this binding cannot read.', $sourceSlug)
			);
		}

		return $body;
	}//end answer()

	/**
	 * The `configuration` of a configured source, for a binding that reads a
	 * setting an administrator keeps on the source.
	 *
	 * @param string $sourceSlug Slug of the configured source.
	 *
	 * @return array<string,mixed>|null The configuration, or null when no source is configured under the slug.
	 *
	 * @spec openspec/changes/brp-relations-read/specs/registry-field-source/spec.md#requirement-brp-relations-are-read-only-where-the-source-says-the-municipality-may
	 */
	public function configuration(string $sourceSlug): ?array {
		$source = $this->connectionStore->findSourceBySlug(slug: $sourceSlug);
		if ($source instanceof ObjectEntity === false) {
			return null;
		}

		$configuration = ($source->getObject()['configuration'] ?? []);
		if (is_array($configuration) === false) {
			return [];
		}

		return $configuration;
	}//end configuration()
}//end class
