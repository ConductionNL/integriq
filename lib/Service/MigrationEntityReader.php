<?php

/**
 * Reads one entity for a migration generator, or refuses in that generator's voice.
 *
 * Shared by the task-3.3 generators because "resolve this uuid/slug/reference, and
 * turn every way that can fail into a refusal carrying its reasons" is the same
 * three branches in both, and a second copy is a second place for one of them to
 * be forgotten — the branch most easily forgotten being `find()` returning NULL,
 * which is not an exception and would otherwise sail on as an empty record.
 *
 * The read is deliberately unscoped (`_rbac: false`, `_multitenancy: false`): a
 * migration is an administrative read of configuration, run from occ where there
 * is no session at all, and a scoped read there returns nothing rather than
 * failing.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\EntityNotMigratableException;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use Throwable;

/**
 * Resolves a migration generator's input entity, refusing rather than half-reading it.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
final class MigrationEntityReader {

	/**
	 * The register every migratable entity lives in.
	 *
	 * @var string
	 */
	// Frozen on the old id: this is the OpenRegister REGISTER SLUG, not the app id.
	// OpenRegister matches registers by slug; renaming it orphans every stored object.
	private const REGISTER = 'openconnector';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads the entity out of OpenRegister.
	 * @param IL10N $l10n Translations.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Read one entity's serialised record, or refuse.
	 *
	 * The returned record always carries a `uuid`: OpenRegister keeps it on the
	 * entity rather than in the object body, and a record without one cannot be
	 * traced back from the flow it produced.
	 *
	 * @param string $reference The entity's uuid, slug or reference.
	 * @param string $schema The schema to read it from ("job", "rule", "endpoint").
	 * @param string $subject The refusal's subject, for the exception.
	 * @param string $hint An extra sentence explaining why this entity is needed.
	 *
	 * @return array The entity's serialised record.
	 *
	 * @throws EntityNotMigratableException When the entity cannot be read.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function read(string $reference, string $schema, string $subject, string $hint = ''): array {
		$trimmed = trim($reference);
		if ($trimmed === '') {
			throw new EntityNotMigratableException(
				subject: $subject,
				message: $this->l10n->t('A %1$s reference is required.', [$schema]),
				reasons: [trim($this->l10n->t('No %1$s was named.', [$schema]) . ' ' . $hint)]
			);
		}

		try {
			$entity = $this->objectService->find(
				id: $trimmed,
				register: self::REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			throw new EntityNotMigratableException(
				subject: $subject,
				message: $this->l10n->t('The %1$s "%2$s" could not be read.', [$schema, $trimmed]),
				reasons: [$exception->getMessage()],
				previous: $exception
			);
		}

		if ($entity === null) {
			throw new EntityNotMigratableException(
				subject: $subject,
				message: $this->l10n->t('The %1$s "%2$s" could not be read.', [$schema, $trimmed]),
				reasons: [
					$this->l10n->t('No %1$s with that uuid, slug or reference exists.', [$schema]),
				]
			);
		}

		$record = (array)$entity->getObject();
		if (($record['uuid'] ?? null) === null) {
			$record['uuid'] = $entity->getUuid();
		}

		return $record;
	}//end read()
}//end class
