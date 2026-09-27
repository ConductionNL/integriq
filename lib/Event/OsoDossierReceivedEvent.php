<?php

/**
 * Integriq OsoDossierReceived Event.
 *
 * Dispatched whenever an inbound OSO overstapdossier is received and
 * parsed, carrying the exact field shape `oso-inbound-contract`'s
 * `OsoImportDossier` expects. learniq's own listener (not part of this
 * change) materialises it into `OsoImportDossier` — integriq never writes
 * learniq's schema directly, per D3.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * An inbound OSO overstapdossier, parsed and ready for learniq's
 * OsoImportDossier materialisation listener to consume.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
 */
class OsoDossierReceivedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $sourceSchoolBrin The sending school's BRIN.
	 * @param string $learnerEckId The pupil's pseudonymous ECK iD.
	 * @param array $categories The dossier's `{category, included, data}` entries.
	 * @param array|null $draftProfile Proposed `{givenName, familyName, birthDate, eckId, schoolId}`
	 *                                 snapshot, or null when the dossier carries no learner identity fields.
	 * @param array $attachmentRefs The dossier's nc:files attachment paths.
	 */
	public function __construct(
		private readonly string $sourceSchoolBrin,
		private readonly string $learnerEckId,
		private readonly array $categories,
		private readonly ?array $draftProfile,
		private readonly array $attachmentRefs = [],
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The sending school's BRIN.
	 *
	 * @return string BRIN.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
	 */
	public function getSourceSchoolBrin(): string {
		return $this->sourceSchoolBrin;
	}//end getSourceSchoolBrin()

	/**
	 * The pupil's pseudonymous ECK iD.
	 *
	 * @return string ECK iD.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
	 */
	public function getLearnerEckId(): string {
		return $this->learnerEckId;
	}//end getLearnerEckId()

	/**
	 * The dossier's `{category, included, data}` entries.
	 *
	 * @return array Categories.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
	 */
	public function getCategories(): array {
		return $this->categories;
	}//end getCategories()

	/**
	 * Proposed learner profile snapshot, or null when the dossier carries no learner identity fields.
	 *
	 * @return array|null Draft profile fields.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
	 */
	public function getDraftProfile(): ?array {
		return $this->draftProfile;
	}//end getDraftProfile()

	/**
	 * The dossier's nc:files attachment paths.
	 *
	 * @return array Attachment refs.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
	 */
	public function getAttachmentRefs(): array {
		return $this->attachmentRefs;
	}//end getAttachmentRefs()
}//end class
