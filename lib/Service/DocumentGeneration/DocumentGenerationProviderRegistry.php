<?php

/**
 * Integriq Document Generation Provider Registry.
 *
 * Resolves a `providerId` from a source configuration to its binding, and
 * refuses a name nothing answers to.
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
 * The bindings this instance ships, by provider id.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
 */
class DocumentGenerationProviderRegistry {

	/**
	 * Constructor.
	 *
	 * @param LogDocumentGenerationProvider $logProvider The sandbox binding.
	 * @param SmartDocumentsProvider $smartDocuments The SmartDocuments binding.
	 * @param XentialProvider $xentialProvider The Xential binding.
	 */
	public function __construct(
		private readonly LogDocumentGenerationProvider $logProvider,
		private readonly SmartDocumentsProvider $smartDocuments,
		private readonly XentialProvider $xentialProvider,
	) {

	}//end __construct()

	/**
	 * Every binding this instance ships.
	 *
	 * @return array<int, DocumentGenerationProviderInterface> The bindings.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
	 */
	public function all(): array {
		return [$this->logProvider, $this->smartDocuments, $this->xentialProvider];

	}//end all()

	/**
	 * Resolve the binding a source configuration names.
	 *
	 * @param array $sourceConfiguration The source's `configuration` object.
	 *
	 * @return DocumentGenerationProviderInterface The binding.
	 *
	 * @throws DocumentGenerationException When the configuration names no binding, or names one that does not exist.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
	 */
	public function resolve(array $sourceConfiguration): DocumentGenerationProviderInterface {
		$providerId = trim((string)($sourceConfiguration['providerId'] ?? ''));
		if ($providerId === '') {
			throw new DocumentGenerationException(
				message: 'This document generation source names no providerId. '
					. 'Set one of: ' . implode(', ', $this->providerIds()) . '.'
			);
		}

		foreach ($this->all() as $provider) {
			if ($provider->getProviderId() === $providerId) {
				return $provider;
			}
		}

		throw new DocumentGenerationException(
			message: 'No document generation binding called "' . $providerId . '". '
				. 'This instance ships: ' . implode(', ', $this->providerIds()) . '.'
		);

	}//end resolve()

	/**
	 * The identifiers of every shipped binding.
	 *
	 * @return array<int, string> The provider ids.
	 */
	private function providerIds(): array {
		return array_map(
			static fn (DocumentGenerationProviderInterface $provider): string => $provider->getProviderId(),
			$this->all()
		);

	}//end providerIds()
}//end class
