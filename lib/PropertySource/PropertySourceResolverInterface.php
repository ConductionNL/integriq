<?php

/**
 * Contract of the PropertySourceResolver.
 *
 * @category Contract
 * @package  OCA\Integriq\PropertySource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\PropertySource;

/**
 * Public operations of {@see PropertySourceResolver} that other components call.
 *
 * The method signatures repeat those of PropertySourceResolver unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface PropertySourceResolverInterface {
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
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PropertySourceResolver::suggest()
	 */
	public function suggest(string $providerId, string $query, array $config = []): array;

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
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PropertySourceResolver::resolve()
	 */
	public function resolve(string $providerId, string $identifier, array $config = [], bool $fresh = false): ResolvedValue;

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
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PropertySourceResolver::resolveSuggestion()
	 */
	public function resolveSuggestion(string $providerId, array $suggestion, array $config = []): ResolvedValue;

	/**
	 * A value a person typed rather than looked up.
	 *
	 * @param array<string,mixed> $value The typed value.
	 *
	 * @return ResolvedValue Manual provenance, no provider id.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PropertySourceResolver::manual()
	 */
	public function manual(array $value): ResolvedValue;
}
