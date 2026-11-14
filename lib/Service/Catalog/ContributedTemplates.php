<?php

/**
 * Integriq ContributedTemplates.
 *
 * The connector templates extension apps contribute through
 * RegisterConnectorExtensionsEvent::addConnectorTemplates(). Each contributed
 * directory is read with the same ConnectorTemplateLibrary as integriq's own
 * library, and every card carries the app that provided it. A template whose
 * slug is already taken, by integriq's library or by an app read earlier, is
 * refused and recorded, so `occ integriq:extensions` lists it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Catalog
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Catalog;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Service\Extension\ConnectorExtensionCollector;

/**
 * Store cards and payloads of the templates extension apps contributed.
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
 */
class ContributedTemplates {

	/**
	 * Integriq's own template library.
	 */
	public const BUILT_IN_DIR = __DIR__ . '/../../Settings/connector-templates';

	/**
	 * The accepted templates, by bare slug, once read.
	 *
	 * @var array<string, array{card: array<string, mixed>, library: ConnectorTemplateLibrary}>|null
	 */
	private ?array $accepted = null;

	/**
	 * @param ConnectorExtensionCollector $collector  Asks the extension apps once and records what they gave.
	 * @param string|null                 $builtInDir Integriq's own library, whose slugs a contribution may not take.
	 */
	public function __construct(
		private readonly ConnectorExtensionCollector $collector,
		private readonly ?string $builtInDir = null,
	) {
	}//end __construct()

	/**
	 * One Store card per accepted contributed template, with `providedBy` set to its app.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
	 */
	public function cards(): array {
		return array_values(array_map(static fn (array $entry): array => $entry['card'], $this->accepted()));
	}//end cards()

	/**
	 * The source payload of an accepted contributed template, or null.
	 *
	 * @param string $slug The template slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
	 */
	public function payload(string $slug): ?array {
		$entry = ($this->accepted()[$slug] ?? null);
		if ($entry === null) {
			return null;
		}

		return $entry['library']->payload(slug: $slug);
	}//end payload()

	/**
	 * Read every contributed directory once, accepting the first holder of each slug.
	 *
	 * @return array<string, array{card: array<string, mixed>, library: ConnectorTemplateLibrary}>
	 */
	private function accepted(): array {
		if ($this->accepted !== null) {
			return $this->accepted;
		}

		$taken = [];
		foreach ((new ConnectorTemplateLibrary(directory: ($this->builtInDir ?? self::BUILT_IN_DIR)))->cards() as $card) {
			$taken[(string)$card['sourceTemplateSlug']] = AppIdentity::APP_ID;
		}

		$this->accepted = [];
		foreach ($this->collector->collect()->getConnectorTemplates() as $contribution) {
			$this->accept(directory: (string)$contribution['directory'], appId: (string)$contribution['appId'], taken: $taken);
		}

		return $this->accepted;
	}//end accepted()

	/**
	 * Accept the templates of one contributed directory whose slugs are free.
	 *
	 * @param string                $directory The contributed directory.
	 * @param string                $appId     The app that contributed it.
	 * @param array<string, string> $taken     Slug to the app holding it; extended in place.
	 *
	 * @return void
	 */
	private function accept(string $directory, string $appId, array &$taken): void {
		$library = new ConnectorTemplateLibrary(directory: $directory);
		foreach ($library->cards() as $card) {
			$slug = (string)$card['sourceTemplateSlug'];
			if (isset($taken[$slug]) === true) {
				$this->collector->refused(seam: ConnectorExtensionCollector::SEAM_TEMPLATES, id: $slug, appId: $appId, item: $library, keptApp: $taken[$slug]);
				continue;
			}

			$taken[$slug] = $appId;
			$this->accepted[$slug] = ['card' => $card + ['providedBy' => $appId], 'library' => $library];
			$this->collector->accepted(seam: ConnectorExtensionCollector::SEAM_TEMPLATES, id: $slug, appId: $appId, item: $library);
		}
	}//end accept()
}//end class
