<?php

/**
 * One statutory gateway, and everything it has to say about itself.
 *
 * @category ValueObject
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

use InvalidArgumentException;

/**
 * A gateway says which law it serves, how far it claims to meet it, what that
 * claim rests on, and where its endpoint sits. A claim is a claim: it is never
 * rendered as a certificate.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-its-standard-and-its-conformance-claim-req-sg-001
 */
final class GatewayDescriptor implements GatewayDescriptorInterface {
	/**
	 * The instance believes it meets the standard.
	 */
	public const CLAIM_CONFORMANT = 'conformant';

	/**
	 * Part of the standard is met, and the evidence says which part.
	 */
	public const CLAIM_PARTIAL = 'partial';

	/**
	 * Nothing is met yet, and the entry says so rather than staying silent.
	 */
	public const CLAIM_PLANNED = 'planned';

	/**
	 * Every claim level an entry may carry.
	 *
	 * @var array<int,string>
	 */
	public const CLAIM_LEVELS = [self::CLAIM_CONFORMANT, self::CLAIM_PARTIAL, self::CLAIM_PLANNED];

	/**
	 * What an undeclared jurisdiction renders as. It is never a default that
	 * reads like an answer.
	 */
	public const JURISDICTION_UNKNOWN = 'unknown';

	/**
	 * Constructor.
	 *
	 * @param string $id The gateway id.
	 * @param string $label The gateway's name.
	 * @param string $standard The named standard or law it serves.
	 * @param string $claimLevel One of the CLAIM_* constants.
	 * @param string $claimEvidence What the claim rests on.
	 * @param string|null $jurisdiction Where the endpoint sits, null when undeclared.
	 * @param array<int,string> $wmebvMet Wmebv obligations this route meets itself.
	 * @param array<int,array{obligation:string,consumerDuty:string}> $wmebvHandedToConsumer Obligations handed on, each naming the duty.
	 * @param string $transport The transport a call over this gateway uses.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $label,
		private readonly string $standard,
		private readonly string $claimLevel,
		private readonly string $claimEvidence,
		private readonly ?string $jurisdiction = null,
		private readonly array $wmebvMet = [],
		private readonly array $wmebvHandedToConsumer = [],
		private readonly string $transport = 'https',
	) {
	}//end __construct()

	/**
	 * Read an entry from its declaration, refusing one that cannot be rendered.
	 *
	 * @param array<string,mixed> $entry The declared entry.
	 *
	 * @return self The descriptor.
	 *
	 * @throws InvalidArgumentException When a required field is missing or wrong.
	 */
	public static function fromArray(array $entry): self {
		$id = self::idOf(entry: $entry);
		$standard = self::standardOf(entry: $entry, id: $id);
		$claimLevel = self::claimLevelOf(entry: $entry, id: $id);
		$claimEvidence = self::claimEvidenceOf(entry: $entry, claimLevel: $claimLevel);

		return new self(
			$id,
			(string)($entry['label'] ?? $id),
			$standard,
			$claimLevel,
			$claimEvidence,
			self::jurisdictionOf(entry: $entry),
			array_values((array)($entry['wmebvMet'] ?? [])),
			array_values((array)($entry['wmebvHandedToConsumer'] ?? [])),
			(string)($entry['transport'] ?? 'https')
		);
	}//end fromArray()

	/**
	 * The entry's id, which it must have.
	 *
	 * @param array<string,mixed> $entry The declared entry.
	 *
	 * @return string The id.
	 *
	 * @throws InvalidArgumentException When the entry has no id.
	 */
	private static function idOf(array $entry): string {
		$id = (string)($entry['id'] ?? '');
		if ($id === '') {
			throw new InvalidArgumentException('A gateway entry without an id cannot be registered.');
		}

		return $id;
	}//end idOf()

	/**
	 * The standard the entry implements, which it must declare.
	 *
	 * @param array<string,mixed> $entry The declared entry.
	 * @param string              $id    The entry's id.
	 *
	 * @return string The standard.
	 *
	 * @throws InvalidArgumentException When the entry declares no standard.
	 */
	private static function standardOf(array $entry, string $id): string {
		$standard = (string)($entry['standard'] ?? '');
		if ($standard === '') {
			throw new InvalidArgumentException(
				sprintf('The gateway entry "%s" declares no standard, so it cannot be registered.', $id)
			);
		}

		return $standard;
	}//end standardOf()

	/**
	 * The entry's claim level, which must be one of CLAIM_LEVELS.
	 *
	 * @param array<string,mixed> $entry The declared entry.
	 * @param string              $id    The entry's id.
	 *
	 * @return string The claim level.
	 *
	 * @throws InvalidArgumentException When the claim level is not a known one.
	 */
	private static function claimLevelOf(array $entry, string $id): string {
		$claimLevel = (string)($entry['claimLevel'] ?? '');
		if (in_array($claimLevel, self::CLAIM_LEVELS, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'The gateway entry "%s" declares no usable claimLevel. It must be one of %s.',
					$id,
					implode(', ', self::CLAIM_LEVELS)
				)
			);
		}

		return $claimLevel;
	}//end claimLevelOf()

	/**
	 * The evidence for the entry's claim, which it must name.
	 *
	 * @param array<string,mixed> $entry      The declared entry.
	 * @param string              $claimLevel The entry's claim level.
	 *
	 * @return string The evidence.
	 *
	 * @throws InvalidArgumentException When the entry names no evidence.
	 */
	private static function claimEvidenceOf(array $entry, string $claimLevel): string {
		$claimEvidence = (string)($entry['claimEvidence'] ?? '');
		if (trim($claimEvidence) === '') {
			throw new InvalidArgumentException(
				sprintf('The gateway entry "%s" claims %s and names no evidence for it.', (string)($entry['id'] ?? ''), $claimLevel)
			);
		}

		return $claimEvidence;
	}//end claimEvidenceOf()

	/**
	 * The entry's jurisdiction as text, or null when it declares none.
	 *
	 * @param array<string,mixed> $entry The declared entry.
	 *
	 * @return string|null The jurisdiction.
	 */
	private static function jurisdictionOf(array $entry): ?string {
		$jurisdiction = ($entry['jurisdiction'] ?? null);
		if ($jurisdiction === null || $jurisdiction === '') {
			return null;
		}

		return (string)$jurisdiction;
	}//end jurisdictionOf()

	/**
	 * The gateway id.
	 *
	 * @return string Gateway id.
	 */
	public function getId(): string {
		return $this->id;
	}//end getId()

	/**
	 * The named standard or law this gateway serves.
	 *
	 * @return string Standard.
	 */
	public function getStandard(): string {
		return $this->standard;
	}//end getStandard()

	/**
	 * The transport a call over this gateway uses.
	 *
	 * @return string Transport.
	 */
	public function getTransport(): string {
		return $this->transport;
	}//end getTransport()

	/**
	 * Where the endpoint sits, or `unknown` when it was never declared.
	 *
	 * @return string Jurisdiction.
	 */
	public function getJurisdiction(): string {
		return ($this->jurisdiction ?? self::JURISDICTION_UNKNOWN);
	}//end getJurisdiction()

	/**
	 * The Wmebv obligations this route meets itself.
	 *
	 * @return array<int,string> Obligations.
	 */
	public function getWmebvMet(): array {
		return $this->wmebvMet;
	}//end getWmebvMet()

	/**
	 * The Wmebv obligations this route hands to its consumer.
	 *
	 * @return array<int,array{obligation:string,consumerDuty:string}> Obligations and the duty each carries.
	 */
	public function getWmebvHandedToConsumer(): array {
		return $this->wmebvHandedToConsumer;
	}//end getWmebvHandedToConsumer()

	/**
	 * The entry as the catalogue and the overview render it.
	 *
	 * @return array<string,mixed> Serialisable entry.
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'label' => $this->label,
			'kind' => 'gateway',
			'standard' => $this->standard,
			'claim' => [
				'level' => $this->claimLevel,
				'evidence' => $this->claimEvidence,
				// Said in the data rather than left to a screen to remember.
				// A self-declared claim is not a certificate, and this field is
				// what a renderer prints beside the level.
				'wording' => sprintf(
					'Self-declared claim: %s. It rests on: %s. This is a claim, not a certification.',
					$this->claimLevel,
					$this->claimEvidence
				),
				'certified' => false,
			],
			'jurisdiction' => $this->getJurisdiction(),
			'jurisdictionDeclared' => ($this->jurisdiction !== null),
			'transport' => $this->transport,
			'wmebv' => [
				'met' => $this->wmebvMet,
				'handedToConsumer' => $this->wmebvHandedToConsumer,
			],
		];
	}//end toArray()
}//end class
