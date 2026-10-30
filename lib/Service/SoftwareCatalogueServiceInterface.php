<?php

/**
 * Contract of the SoftwareCatalogueService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use React\Promise\PromiseInterface;

/**
 * Public operations of {@see SoftwareCatalogueService} that other components call.
 */
interface SoftwareCatalogueServiceInterface {
	/**
	 * Extend all views for a model.
	 *
	 * @param integer|string $modelId The id of the model for which the views should be extended.
	 *
	 * @return PromiseInterface The resulting promises.
	 *
	 * @throws \Psr\Container\ContainerExceptionInterface Container failure.
	 * @throws \Psr\Container\NotFoundExceptionInterface Container miss.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function extendModel(int|string $modelId): PromiseInterface;

	/**
	 * Extends a view by fetching it from the object store and processing its nodes in parallel.
	 *
	 * @param array $viewPromise The view to extend.
	 * @param array $modelPromise The model used for extension.
	 *
	 * @return PromiseInterface A promise that resolves when all nodes have been processed.
	 *
	 * @throws DoesNotExistException If the view or model does not exist in the object store.
	 *
	 * @psalm-return PromiseInterface<void>
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function extendView(array $viewPromise, array $modelPromise): PromiseInterface;

	/**
	 * Handles a new organization in the software catalog.
	 *
	 * @param ObjectEntity $organization The organization object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the operation fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function handleNewOrganization(ObjectEntity $organization): void;

	/**
	 * Handles a new contact in the software catalog.
	 *
	 * @param ObjectEntity $contact The contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the operation fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function handleNewContact(ObjectEntity $contact): void;

	/**
	 * Handles contact updates in the software catalog.
	 *
	 * @param ObjectEntity $contact The updated contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the operation fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function handleContactUpdate(ObjectEntity $contact): void;

	/**
	 * Handles contact deletion in the software catalog.
	 *
	 * @param ObjectEntity $contact The deleted contact object.
	 *
	 * @return void
	 *
	 * @throws \Exception If the operation fails.
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	public function handleContactDeletion(ObjectEntity $contact): void;
}
