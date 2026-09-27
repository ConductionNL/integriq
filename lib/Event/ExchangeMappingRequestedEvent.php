<?php

/**
 * Integriq Exchange Mapping Requested Event.
 *
 * The typed command an app dispatches to store one of its own exchange
 * mappings as an integriq mapping row.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "Store this mapping under my prefix", with a slot for the answer.
 *
 * ADR-041. Upserts by slug; the slug must start with `<ownerApp>-` so one app
 * cannot overwrite another app's or integriq's own mappings.
 *
 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
 */
class ExchangeMappingRequestedEvent extends Event {

	/**
	 * The mapping row's id once stored.
	 *
	 * @var string|null
	 */
	private ?string $mappingId = null;

	/**
	 * Why the mapping was not stored, when it was not.
	 *
	 * @var array{code: string, reason: string}|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string              $ownerApp    The asking app's id.
	 * @param string              $slug        The slug, starting with `<ownerApp>-`.
	 * @param string              $name        A label.
	 * @param string              $description What the mapping does.
	 * @param array<string,mixed> $mapping     Output key to source path or Twig template.
	 * @param array<string,mixed> $cast        Per-field cast directives.
	 * @param array<int,string>   $unset       Fields to drop from the output.
	 * @param bool                $passThrough Whether unmapped fields flow through.
	 */
	public function __construct(
		private readonly string $ownerApp,
		private readonly string $slug,
		private readonly string $name,
		private readonly string $description,
		private readonly array $mapping,
		private readonly array $cast = [],
		private readonly array $unset = [],
		private readonly bool $passThrough = false,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The asking app.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function getOwnerApp(): string {
		return $this->ownerApp;

	}//end getOwnerApp()

	/**
	 * The mapping's slug.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function getSlug(): string {
		return $this->slug;

	}//end getSlug()

	/**
	 * The mapping's label.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function getName(): string {
		return $this->name;

	}//end getName()

	/**
	 * What the mapping does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function getDescription(): string {
		return $this->description;

	}//end getDescription()

	/**
	 * The mapping rules.
	 *
	 * @return array<string,mixed> The rules.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function getMapping(): array {
		return $this->mapping;

	}//end getMapping()

	/**
	 * The cast directives.
	 *
	 * @return array<string,mixed> The casts.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function getCast(): array {
		return $this->cast;

	}//end getCast()

	/**
	 * The fields to drop.
	 *
	 * @return array<int,string> The field paths.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function getUnset(): array {
		return $this->unset;

	}//end getUnset()

	/**
	 * Whether unmapped fields flow through.
	 *
	 * @return bool The flag.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function getPassThrough(): bool {
		return $this->passThrough;

	}//end getPassThrough()

	/**
	 * The stored mapping's id.
	 *
	 * @return string|null The id.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function getMappingId(): ?string {
		return $this->mappingId;

	}//end getMappingId()

	/**
	 * Record the stored mapping's id.
	 *
	 * @param string $mappingId The id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function setMappingId(string $mappingId): void {
		$this->mappingId = $mappingId;

	}//end setMappingId()

	/**
	 * The structured refusal.
	 *
	 * @return array{code: string, reason: string}|null The refusal.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function getRefusal(): ?array {
		return $this->refusal;

	}//end getRefusal()

	/**
	 * Refuse, saying why.
	 *
	 * @param string $code   `slug-foreign`, `mapping-empty` or `store-failed`.
	 * @param string $reason What an operator can act on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function refuse(string $code, string $reason): void {
		$this->refusal = ['code' => $code, 'reason' => $reason];

	}//end refuse()
}//end class
