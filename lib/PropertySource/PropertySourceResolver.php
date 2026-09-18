<?php

/**
 * Resolves a property whose schema declares a registry source.
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

use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Suggest and resolve keep their separate guarantees, and every answer says
 * where it came from.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-suggest-and-resolve-are-separate-calls-with-separate-guarantees-req-rfs-002
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-live-means-a-stated-staleness-budget-req-rfs-004
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-an-unreachable-source-degrades-to-a-labelled-last-value-req-rfs-005
 */
class PropertySourceResolver {
	/**
	 * Cache namespace for resolved registry values.
	 */
	private const CACHE_PREFIX = 'integriq-property-source';

	/**
	 * The distributed cache holding the last known value per identifier.
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param PropertySourceRegistry $registry Registry of bindings.
	 * @param ICacheFactory $cacheFactory Cache factory.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly PropertySourceRegistry $registry,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
	}//end __construct()

	/**
	 * Type-ahead while a field is being filled in.
	 *
	 * A suggestion is never an answer: it carries an identifier that the
	 * caller must resolve before the value is stored.
	 *
	 * @param string $providerId Provider id the property declares.
	 * @param string $query Partial query.
	 * @param array<string,mixed> $config Declared provider configuration.
	 *
	 * @return array<int,array<string,mixed>> Suggestions.
	 *
	 * @throws \OCA\Integriq\PropertySource\Exception\UnknownPropertySourceException When no provider answers to the id.
	 */
	public function suggest(string $providerId, string $query, array $config = []): array {
		$provider = $this->registry->get($providerId);

		try {
			$suggestions = $provider->suggest($query, $config);
		} catch (SourceUnreachableException $e) {
			$this->logger->warning('property-source.suggest.unreachable', ['provider' => $providerId]);
			return [];
		}

		return array_map(
			static function (array $suggestion) use ($providerId): array {
				$suggestion['provider'] = $providerId;
				$suggestion['authoritative'] = false;
				return $suggestion;
			},
			$suggestions
		);
	}//end suggest()

	/**
	 * One authoritative read of a property value.
	 *
	 * @param string $providerId Provider id the property declares.
	 * @param string $identifier Identifier at the source.
	 * @param array<string,mixed> $config Declared provider configuration.
	 * @param bool $fresh Bypass the cache and reach the source.
	 *
	 * @return ResolvedValue The value and its provenance.
	 *
	 * @throws \OCA\Integriq\PropertySource\Exception\UnknownPropertySourceException When no provider answers to the id.
	 * @throws \OCA\Integriq\PropertySource\Exception\MissingSourceConfigurationException When the binding has no source.
	 */
	public function resolve(string $providerId, string $identifier, array $config = [], bool $fresh = false): ResolvedValue {
		$provider = $this->registry->get($providerId);
		$budget = (int)($provider->describe()['stalenessBudget'] ?? 0);
		$key = $this->cacheKey($providerId, $identifier);
		$entry = $this->readEntry($key);
		$now = time();

		if ($fresh === false && $entry !== null) {
			$age = ($now - $entry['readAt']);
			if ($age <= $budget) {
				return ResolvedValue::fromCache($entry['value'], $providerId, $identifier, $entry['readAt'], $age);
			}
		}

		try {
			$value = $provider->resolve($identifier, $config);
		} catch (SourceUnreachableException $e) {
			$this->logger->warning(
				'property-source.resolve.unreachable',
				[
					'provider' => $providerId,
					'identifier' => $identifier,
					'cached' => ($entry !== null),
				]
			);

			if ($entry !== null) {
				return ResolvedValue::fromCache(
					$entry['value'],
					$providerId,
					$identifier,
					$entry['readAt'],
					($now - $entry['readAt']),
					true
				);
			}

			return ResolvedValue::unreachable($providerId, $identifier);
		}//end try

		$this->cache->set($key, ['value' => $value, 'readAt' => $now]);

		return ResolvedValue::fromSource($value, $providerId, $identifier, $now);
	}//end resolve()

	/**
	 * Turn a suggestion into a stored value.
	 *
	 * The suggestion's own payload is discarded: only its identifier survives
	 * into the authoritative read.
	 *
	 * @param string $providerId Provider id the property declares.
	 * @param array<string,mixed> $suggestion A suggestion returned by suggest().
	 * @param array<string,mixed> $config Declared provider configuration.
	 *
	 * @return ResolvedValue The resolved value and its provenance.
	 *
	 * @throws \InvalidArgumentException When the suggestion carries no identifier.
	 */
	public function resolveSuggestion(string $providerId, array $suggestion, array $config = []): ResolvedValue {
		$identifier = (string)($suggestion['identifier'] ?? '');
		if ($identifier === '') {
			throw new \InvalidArgumentException('A suggestion without an identifier cannot be stored as a value.');
		}

		return $this->resolve($providerId, $identifier, $config, true);
	}//end resolveSuggestion()

	/**
	 * A value a person typed rather than looked up.
	 *
	 * @param array<string,mixed> $value The typed value.
	 *
	 * @return ResolvedValue Manual provenance, no provider id.
	 */
	public function manual(array $value): ResolvedValue {
		return ResolvedValue::manual($value);
	}//end manual()

	/**
	 * Read a cache entry, ignoring anything that is not the shape we wrote.
	 *
	 * @param string $key Cache key.
	 *
	 * @return array{value:array<string,mixed>,readAt:int}|null The entry, or null.
	 */
	private function readEntry(string $key): ?array {
		$entry = $this->cache->get($key);
		if (is_array($entry) === false || is_array(($entry['value'] ?? null)) === false || isset($entry['readAt']) === false) {
			return null;
		}

		return ['value' => $entry['value'], 'readAt' => (int)$entry['readAt']];
	}//end readEntry()

	/**
	 * Cache key for one identifier at one provider.
	 *
	 * @param string $providerId Provider id.
	 * @param string $identifier Identifier at the source.
	 *
	 * @return string Cache key.
	 */
	private function cacheKey(string $providerId, string $identifier): string {
		return $providerId . ':' . sha1($identifier);
	}//end cacheKey()
}//end class
