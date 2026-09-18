<?php

/**
 * Integriq Xential Document Generation Provider.
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
 * Xential, reached over its REST API.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
 */
class XentialProvider extends AbstractRestDocumentGenerationProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return string The `xential` identifier.
	 */
	public function getProviderId(): string {
		return 'xential';

	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The display name.
	 */
	public function getProviderName(): string {
		return 'Xential';

	}//end getProviderName()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The templates path.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
	 */
	protected function templatesPath(): string {
		return '/api/template/list';

	}//end templatesPath()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The render path.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
	 */
	protected function renderPath(): string {
		return '/api/document/start';

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
		return '/api/document/' . rawurlencode($providerJobId) . '/status';

	}//end statusPath()

	/**
	 * {@inheritDoc}
	 *
	 * Xential takes the merge data as one data object beside the template
	 * name, and names the output format differently.
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
			'template' => $templateId,
			'data' => $data,
			'outputFormat' => 'pdf',
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
			['id' => 'xt-beschikking', 'name' => 'Beschikking'],
			['id' => 'xt-brief', 'name' => 'Brief'],
			['id' => 'xt-rapport', 'name' => 'Rapport'],
		];

	}//end fixtureTemplates()
}//end class
