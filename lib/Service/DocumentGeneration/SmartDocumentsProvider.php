<?php

/**
 * Integriq SmartDocuments Document Generation Provider.
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

/**
 * SmartDocuments, reached over its REST API.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
 */
class SmartDocumentsProvider extends AbstractRestDocumentGenerationProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return string The `smartdocuments` identifier.
	 */
	public function getProviderId(): string {
		return 'smartdocuments';

	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The display name.
	 */
	public function getProviderName(): string {
		return 'SmartDocuments';

	}//end getProviderName()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The templates path.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
	 */
	protected function templatesPath(): string {
		return '/templates';

	}//end templatesPath()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The render path.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
	 */
	protected function renderPath(): string {
		return '/documents';

	}//end renderPath()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $providerJobId The vendor's job id.
	 *
	 * @return string The status path.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
	 */
	protected function statusPath(string $providerJobId): string {
		return '/documents/' . rawurlencode($providerJobId) . '/status';

	}//end statusPath()

	/**
	 * {@inheritDoc}
	 *
	 * SmartDocuments takes the merge data as a flat set of fields beside the
	 * template selection.
	 *
	 * @param string $templateId The vendor's template id.
	 * @param array $data The merge data.
	 *
	 * @return array<string, mixed> The request body.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
	 */
	protected function renderEnvelope(string $templateId, array $data): array {
		return [
			'selection' => ['templateId' => $templateId],
			'fields' => $data,
			'format' => 'pdf',
		];

	}//end renderEnvelope()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, array{id: string, name: string}> The fixture templates.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
	 */
	protected function fixtureTemplates(): array {
		return [
			['id' => 'sd-beschikking', 'name' => 'Beschikking'],
			['id' => 'sd-brief', 'name' => 'Standaardbrief'],
			['id' => 'sd-besluit', 'name' => 'Besluit met bijlagen'],
		];

	}//end fixtureTemplates()
}//end class
