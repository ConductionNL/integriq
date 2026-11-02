<?php

/**
 * Contract of the DocumentGenerationProviderRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\DocumentGeneration
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

namespace OCA\Integriq\Service\DocumentGeneration;

use OCA\Integriq\Exception\DocumentGenerationException;

/**
 * Public operations of {@see DocumentGenerationProviderRegistry} that other components call.
 */
interface DocumentProviderRegistryInterface {
	/**
	 * Every binding this instance ships.
	 *
	 * @return array<int, DocumentGenerationProviderInterface> The bindings.
	 *
	 * @spec openspec/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
	 */
	public function all(): array;

	/**
	 * Resolve the binding a source configuration names.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 *
	 * @return DocumentGenerationProviderInterface The binding.
	 *
	 * @throws DocumentGenerationException When the configuration names no binding, or names one that does not exist.
	 *
	 * @spec openspec/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
	 */
	public function resolve(array $sourceConfiguration): DocumentGenerationProviderInterface;
}
