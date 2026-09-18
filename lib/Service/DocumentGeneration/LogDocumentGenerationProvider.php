<?php

/**
 * Integriq Log Document Generation Provider.
 *
 * Sandbox binding: no network call, no credential, a placeholder PDF that
 * names the template and the data hash so a development install can see the
 * whole path work.
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
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#scenario-the-log-binding-answers-a-placeholder
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DocumentGeneration;

use OCA\Integriq\Exception\DocumentGenerationException;

/**
 * The development binding. It renders a placeholder and says it is one.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#scenario-the-log-binding-answers-a-placeholder
 */
class LogDocumentGenerationProvider implements DocumentGenerationProviderInterface {

	/**
	 * The templates this binding pretends to have.
	 *
	 * @var array<int, array{id: string, name: string}>
	 */
	private const TEMPLATES = [
		['id' => 'mock-beschikking', 'name' => 'Beschikking (placeholder)'],
		['id' => 'mock-brief', 'name' => 'Brief (placeholder)'],
		['id' => 'mock-besluit', 'name' => 'Besluit (placeholder)'],
	];

	/**
	 * Per-process counter for synthetic job ids.
	 *
	 * @var integer
	 */
	private static int $counter = 0;

	/**
	 * The rendered placeholders of this process, keyed by job id.
	 *
	 * @var array<string, string>
	 */
	private array $documents = [];

	/**
	 * {@inheritDoc}
	 *
	 * @return string The `log` identifier.
	 */
	public function getProviderId(): string {
		return 'log';

	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The display name.
	 */
	public function getProviderName(): string {
		return 'Sandbox / log (no network call)';

	}//end getProviderName()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> An empty schema: this binding needs no configuration.
	 */
	public function getConfigSchema(): array {
		return ['type' => 'object', 'properties' => []];

	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * The sandbox binding needs nothing, so there is nothing to refuse.
	 *
	 * @param array $sourceConfiguration Unused.
	 *
	 * @return void
	 */
	public function assertActivatable(array $sourceConfiguration): void {

	}//end assertActivatable()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused.
	 *
	 * @return array<int, array{id: string, name: string}> Three placeholder templates.
	 */
	public function listTemplates(array $sourceConfiguration): array {
		return self::TEMPLATES;

	}//end listTemplates()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused.
	 * @param string $templateId The template asked for.
	 * @param array $data The merge data, hashed into the placeholder and then dropped.
	 *
	 * @return RenderOutcome A rendered placeholder.
	 */
	public function render(array $sourceConfiguration, string $templateId, array $data): RenderOutcome {
		self::$counter++;
		$jobId = 'MOCK-DOC-' . self::$counter;
		$hash = hash('sha256', json_encode($data));

		$this->documents[$jobId] = sprintf(
			"%%PDF-1.4 placeholder\nTemplate: %s\nData hash: %s\nProduced by the integriq log binding, not by a vendor.\n",
			$templateId,
			$hash
		);

		return RenderOutcome::rendered(
			providerJobId: $jobId,
			fileReference: 'log:' . $jobId,
			detail: 'Placeholder rendered for template ' . $templateId
		);

	}//end render()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused.
	 * @param string $providerJobId The synthetic job id.
	 *
	 * @return RenderOutcome The same rendered outcome, or a refusal when this process never made it.
	 */
	public function status(array $sourceConfiguration, string $providerJobId): RenderOutcome {
		if (array_key_exists($providerJobId, $this->documents) === false) {
			// Deliberately `failed`, not `unreachable`: this binding always
			// answers, so "I have no such job" is an answer.
			return RenderOutcome::failed(
				detail: 'The log binding holds no render called ' . $providerJobId,
				providerJobId: $providerJobId
			);
		}

		return RenderOutcome::rendered(
			providerJobId: $providerJobId,
			fileReference: 'log:' . $providerJobId,
			detail: 'Placeholder held in memory for this process'
		);

	}//end status()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused.
	 * @param string $fileReference The `log:<jobId>` reference.
	 *
	 * @return string The placeholder bytes.
	 *
	 * @throws DocumentGenerationException When this process rendered no such document.
	 */
	public function fetch(array $sourceConfiguration, string $fileReference): string {
		$jobId = substr($fileReference, strlen('log:'));
		if (array_key_exists($jobId, $this->documents) === false) {
			throw new DocumentGenerationException(
				message: 'The log binding holds no document at ' . $fileReference
					. '. Its placeholders live in one process and do not survive it.'
			);
		}

		return $this->documents[$jobId];

	}//end fetch()
}//end class
