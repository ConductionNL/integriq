<?php

/**
 * Integriq Document Generation Provider Interface.
 *
 * The seam a vendor document generation service is reached through. A new
 * vendor is added by implementing this interface, never by editing
 * DocumentGenerationService.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DocumentGeneration
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
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DocumentGeneration;

use OCA\Integriq\Exception\DocumentGenerationException;

/**
 * One document generation binding: list templates, render, poll, fetch.
 *
 * Filinq owns document generation for the fleet (ADR-075) and calls this seam
 * as one of its template backends. No case app talks to a vendor.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
 */
interface DocumentGenerationProviderInterface {

	/**
	 * Stable machine identifier for this binding (`log`, `smartdocuments`, `xential`).
	 *
	 * @return string The provider identifier.
	 */
	public function getProviderId(): string;

	/**
	 * Human-readable display name for this binding.
	 *
	 * @return string The provider display name.
	 */
	public function getProviderName(): string;

	/**
	 * The JSON Schema describing this provider's `configuration` object.
	 *
	 * @return array<string, mixed> A JSON Schema (object) fragment.
	 */
	public function getConfigSchema(): array;

	/**
	 * Refuse a source that cannot render, naming what is missing.
	 *
	 * Called when an operator activates a source, so a vendor binding without
	 * a credential reference is refused there rather than at the first render,
	 * where the refusal would arrive as a failed beschikking.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 *
	 * @return void
	 *
	 * @throws DocumentGenerationException When the source cannot render.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#scenario-a-source-without-credentials-cannot-activate
	 */
	public function assertActivatable(array $sourceConfiguration): void;

	/**
	 * The vendor's templates for this source.
	 *
	 * Read from the vendor at call time. Integriq stores no copy of a vendor
	 * template: the vendor's template administration is the vendor's, and a
	 * copy here would be wrong the first time somebody edits it there.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 *
	 * @return array<int, array{id: string, name: string}> The vendor's templates.
	 *
	 * @throws DocumentGenerationException When the source is unconfigured or the vendor cannot be reached.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
	 */
	public function listTemplates(array $sourceConfiguration): array;

	/**
	 * Ask the vendor to render one template with one set of data.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 * @param string $templateId The vendor's own template id.
	 * @param array $data The merge data for this render. Never stored by integriq.
	 *
	 * @return RenderOutcome What the vendor answered, including `unreachable` when it answered nothing.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	public function render(array $sourceConfiguration, string $templateId, array $data): RenderOutcome;

	/**
	 * Ask the vendor what became of a render it took.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 * @param string $providerJobId The vendor's job id.
	 *
	 * @return RenderOutcome The current outcome.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	public function status(array $sourceConfiguration, string $providerJobId): RenderOutcome;

	/**
	 * Fetch the bytes of a rendered document.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 * @param string $fileReference The reference a `rendered` outcome carried.
	 *
	 * @return string The document bytes.
	 *
	 * @throws DocumentGenerationException When the document cannot be fetched.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	public function fetch(array $sourceConfiguration, string $fileReference): string;
}//end interface
