<?php

/**
 * Contract of the ConfigurationService.
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

/**
 * Public operations of {@see ConfigurationService} that other components call.
 *
 * The method signatures repeat those of ConfigurationService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
interface ConfigurationServiceInterface {
	/**
	 * Get all entities associated with a specific configuration ID, indexed by their slug.
	 *
	 * @param string $configurationId The ID of the configuration to get entities for.
	 *
	 * @return array<string,array> Array containing all entities grouped by type and indexed by slug.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public function getEntitiesByConfiguration(string $configurationId): array;

	/**
	 * Export all entities associated with a specific configuration ID to JSON.
	 *
	 * Entities are organized by components following OAS structure.
	 *
	 * @param string $configurationId The ID of the configuration to export.
	 *
	 * @return array<string,array> JSON-serializable array containing all entities.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public function exportConfiguration(string $configurationId): array;

	/**
	 * Export all entities (endpoints and synchronizations) connected to a specific register.
	 *
	 * Entities are organized by their type and indexed by slug.
	 * Also includes related rules, mappings, and sources.
	 *
	 * @param string $registerId The ID of the register to export entities for.
	 * @param bool $includeEndpoints Whether to include endpoints in the export (default: true).
	 * @param bool $includeSynchronizations Whether to include synchronizations in the export (default: true).
	 * @param bool $searchSource Whether to search in source fields for synchronizations (default: true).
	 * @param bool $searchTarget Whether to search in target fields for synchronizations (default: true).
	 *
	 * @return array<string,array> JSON-serializable array containing all connected entities.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public function exportRegister(
		string $registerId,
		bool $includeEndpoints = true,
		bool $includeSynchronizations = true,
		bool $searchSource = true,
		bool $searchTarget = true,
	): array;

	/**
	 * Import a complete configuration from an OAS array.
	 * Components are processed in the correct order to maintain dependencies:
	 * 1. Sources (no dependencies)
	 * 2. Mappings (depends on sources)
	 * 3. Rules (depends on sources)
	 * 4. Endpoints (depends on sources and mappings)
	 * 5. Synchronizations (depends on sources, mappings, and endpoints)
	 * 6. Jobs (depends on synchronizations, endpoints, and sources)
	 *
	 * The function preserves all relationships and target types as specified in the OAS,
	 * allowing for flexible configuration imports that may target different types of entities.
	 *
	 * @param array $oas The OpenAPI Specification array containing components.
	 *
	 * @return array<string,array> Array containing all imported entities grouped by type.
	 *
	 * @throws \InvalidArgumentException If required components are missing or invalid.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public function importConfiguration(array $oas): array;
}
