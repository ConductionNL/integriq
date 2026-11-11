<?php

/**
 * The family relations of one person, read from the BRP for a check that needs them.
 *
 * @category Provider
 * @package  OCA\Integriq\PropertySource\Provider
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

namespace OCA\Integriq\PropertySource\Provider;

use OCA\Integriq\PropertySource\Exception\MissingSourceConfigurationException;
use OCA\Integriq\PropertySource\Exception\PropertySourceException;
use OCA\Integriq\PropertySource\RegistrySourceGateway;
use Psr\Log\LoggerInterface;

/**
 * Who a person's partner, parents and children are, by burgerservicenummer.
 *
 * This is personal data about people who are not part of the question, so
 * the read is narrower than a property source on purpose:
 *
 *  - It runs only when the source carries `configuration.relationFields: true`.
 *    A municipality sets that after its BRP authorisation covers the relation
 *    numbers. Without it the registry is not asked at all.
 *  - It asks for the three relation numbers and no other field.
 *  - It does not go through PropertySourceResolver, whose cache is keyed on
 *    the person alone and would hand the relations to a later reader who
 *    asked for a name.
 *  - The call log gets a record without any body: who asked, when, which
 *    source and the outcome (decision 229). The log line this class writes
 *    carries no number and no part of the answer.
 *
 * The answer is a plain array, so an app that reaches this class by name at
 * runtime needs none of integriq's types.
 *
 * @spec openspec/changes/brp-relations-read/specs/registry-field-source/spec.md#requirement-brp-relations-are-read-only-where-the-source-says-the-municipality-may
 */
class BrpRelationReader {
	/**
	 * The key under a source's `configuration` that switches the read on.
	 */
	public const SETTING = 'relationFields';

	/**
	 * The only fields asked for.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = ['partners.burgerservicenummer', 'ouders.burgerservicenummer', 'kinderen.burgerservicenummer'];

	/**
	 * Haal Centraal's list name per relation, in the order they are answered.
	 *
	 * @var array<string, string>
	 */
	private const RELATIONS = ['partners' => 'partner', 'ouders' => 'parent', 'kinderen' => 'child'];

	/**
	 * The source does not carry the setting.
	 */
	public const REASON_NOT_ENABLED = 'relations-not-enabled';

	/**
	 * No source is configured under the slug.
	 */
	public const REASON_NO_SOURCE = 'no-source';

	/**
	 * The registry did not answer about this person.
	 */
	public const REASON_UNREACHABLE = 'unreachable';

	/**
	 * What was asked for is not a burgerservicenummer.
	 */
	public const REASON_INVALID_IDENTIFIER = 'invalid-identifier';

	/**
	 * Constructor.
	 *
	 * @param RegistrySourceGateway $gateway The one way to reach a source.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly RegistrySourceGateway $gateway,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read the relations of one person.
	 *
	 * `available` false means the question was not answered, and `reason`
	 * says why. It never means "no relations": a caller that decides on the
	 * answer must treat it as unknown. `available` true with an empty list is
	 * the registry saying it holds no partner, parent or child with a number.
	 *
	 * @param string $burgerservicenummer The person.
	 * @param array<string,mixed> $config `source` overrides the source slug.
	 *
	 * @return array{available:bool,reason:string,relations:array<int,array{relation:string,burgerservicenummer:string}>}
	 *
	 * @spec openspec/changes/brp-relations-read/specs/registry-field-source/spec.md#requirement-brp-relations-are-read-only-where-the-source-says-the-municipality-may
	 */
	public function read(string $burgerservicenummer, array $config = []): array {
		$sourceSlug = (string)($config['source'] ?? BrpPropertySource::SOURCE_SLUG);

		$refusal = $this->refusalFor(burgerservicenummer: $burgerservicenummer, sourceSlug: $sourceSlug);
		if ($refusal !== null) {
			return $this->notAvailable(reason: $refusal, sourceSlug: $sourceSlug);
		}

		$person = $this->personFor(burgerservicenummer: $burgerservicenummer, sourceSlug: $sourceSlug);
		if (is_string($person) === true) {
			return $this->notAvailable(reason: $person, sourceSlug: $sourceSlug);
		}

		$this->logger->info('property-source.brp.relations.read', ['source' => $sourceSlug, 'outcome' => 'answered']);

		return ['available' => true, 'reason' => '', 'relations' => $this->relationsOf(person: $person)];
	}//end read()

	/**
	 * Why the registry is not asked at all, if it is not: not a burgerservicenummer,
	 * no source, or a source without the relations setting.
	 *
	 * @param string $burgerservicenummer The number asked.
	 * @param string $sourceSlug          The source.
	 *
	 * @return string|null The reason, or null when the registry may be asked.
	 */
	private function refusalFor(string $burgerservicenummer, string $sourceSlug): ?string {
		if (preg_match('/^\d{9}$/', $burgerservicenummer) !== 1) {
			return self::REASON_INVALID_IDENTIFIER;
		}

		$configuration = $this->gateway->configuration(sourceSlug: $sourceSlug);
		if ($configuration === null) {
			return self::REASON_NO_SOURCE;
		}

		if (($configuration[self::SETTING] ?? false) !== true) {
			return self::REASON_NOT_ENABLED;
		}

		return null;
	}//end refusalFor()

	/**
	 * The person the registry answers for the number.
	 *
	 * @param string $burgerservicenummer The number asked.
	 * @param string $sourceSlug          The source.
	 *
	 * @return array|string The person, or the reason there is none.
	 */
	private function personFor(string $burgerservicenummer, string $sourceSlug): array|string {
		$body = $this->fetched(burgerservicenummer: $burgerservicenummer, sourceSlug: $sourceSlug);
		if (is_string($body) === true) {
			return $body;
		}

		$person = current(array_filter((array)($body['personen'] ?? []), 'is_array'));
		if ($person === false) {
			return self::REASON_UNREACHABLE;
		}

		return $person;
	}//end personFor()

	/**
	 * Ask the registry for the person's relation numbers, without recording bodies.
	 *
	 * @param string $burgerservicenummer The number asked.
	 * @param string $sourceSlug          The source.
	 *
	 * @return array|string The registry's answer, or the reason it gave none.
	 */
	private function fetched(string $burgerservicenummer, string $sourceSlug): array|string {
		try {
			return $this->gateway->readWithoutBodies(
				BrpPropertySource::ID,
				$sourceSlug,
				'/personen',
				[],
				['type' => 'RaadpleegMetBurgerservicenummer', 'burgerservicenummer' => [$burgerservicenummer], 'fields' => self::FIELDS]
			);
		} catch (MissingSourceConfigurationException) {
			return self::REASON_NO_SOURCE;
		} catch (PropertySourceException) {
			// The exception's message is not logged: a registry may echo the question.
			return self::REASON_UNREACHABLE;
		}
	}//end fetched()

	/**
	 * The relations in a person as Haal Centraal answers it.
	 *
	 * A relation without a number, such as a partner who is not in the BRP,
	 * cannot be matched to anyone and is left out.
	 *
	 * @param array<mixed> $person The person.
	 *
	 * @return array<int,array{relation:string,burgerservicenummer:string}>
	 */
	private function relationsOf(array $person): array {
		$relations = [];
		foreach (self::RELATIONS as $list => $relation) {
			foreach ((array)($person[$list] ?? []) as $related) {
				$number = '';
				if (is_array($related) === true) {
					$number = (string)($related['burgerservicenummer'] ?? '');
				}

				if ($number !== '') {
					$relations[] = ['relation' => $relation, 'burgerservicenummer' => $number];
				}
			}
		}

		return $relations;
	}//end relationsOf()

	/**
	 * The answer for a question that was not answered.
	 *
	 * @param string $reason One of the REASON_ constants.
	 * @param string $sourceSlug The source asked, for the log line.
	 *
	 * @return array{available:bool,reason:string,relations:array<int,array{relation:string,burgerservicenummer:string}>}
	 */
	private function notAvailable(string $reason, string $sourceSlug): array {
		$this->logger->info('property-source.brp.relations.read', ['source' => $sourceSlug, 'outcome' => $reason]);

		return ['available' => false, 'reason' => $reason, 'relations' => []];
	}//end notAvailable()
}//end class
