<?php

/**
 * Integriq SynchronizationRunConfigForm.
 *
 * The config form fields of the `openconnector.synchronization-run` step.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
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
 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use OCP\IL10N;

/**
 * Builds the config form of {@see SynchronizationRunNode}; built by that node.
 */
final class SynchronizationRunConfigForm {

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations for labels and help texts.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * The config form fields of the step, in display order.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	public function fields(): array {
		return [
			$this->synchronizationField(),
			$this->forceField(),
			$this->outputField(),
			$this->maxItemsField(),
			$this->onErrorField(),
		];
	}//end fields()

	/**
	 * The "synchronization" field of the config form.
	 *
	 * @return array<string, mixed>
	 */
	private function synchronizationField(): array {
		return [
			'key' => 'synchronization',
			'label' => $this->l10n->t('Synchronization'),
			'type' => 'select',
			'help' => $this->l10n->t('The configured synchronization this step runs, with its own source, mapping and target.'),
			'required' => true,
			'optionsFrom' => '/apps/openregister/api/objects/integriq/synchronization',
		];
	}//end synchronizationField()

	/**
	 * The "force" field of the config form.
	 *
	 * @return array<string, mixed>
	 */
	private function forceField(): array {
		return [
			'key' => 'force',
			'label' => $this->l10n->t('Force a full pass'),
			'type' => 'boolean',
			'help' => $this->l10n->t(
				'Ignores the unchanged-object skip and re-processes everything the source '
				. 'returns. Slower; use it after changing a mapping.'
			),
		];
	}//end forceField()

	/**
	 * The "output" field of the config form.
	 *
	 * @return array<string, mixed>
	 */
	private function outputField(): array {
		return [
			'key' => 'output',
			'label' => $this->l10n->t('Field to store the summary in'),
			'type' => 'text',
			'help' => $this->l10n->t(
				'With a field name, the incoming item is preserved and the run summary is '
				. 'added under it. Empty means the summary replaces the item.'
			),
		];
	}//end outputField()

	/**
	 * The "maxItems" field of the config form.
	 *
	 * @return array<string, mixed>
	 */
	private function maxItemsField(): array {
		return [
			'key' => 'maxItems',
			'label' => $this->l10n->t('Item ceiling'),
			'type' => 'number',
			'help' => $this->l10n->t('The most synchronised objects this step may emit as items. The summary always reports the full run.'),
		];
	}//end maxItemsField()

	/**
	 * The "onError" field of the config form.
	 *
	 * @return array<string, mixed>
	 */
	private function onErrorField(): array {
		return [
			'key' => 'onError',
			'label' => $this->l10n->t('When the run fails'),
			'type' => 'text',
			'help' => $this->l10n->t(
				'"stop" (default) fails the step; "continue" records the error on the item '
				. 'and carries on; "dead_letter" routes the item to the flow\'s dead-letter '
				. 'handling.'
			),
		];
	}//end onErrorField()
}//end class
