<?php

/**
 * The packaged SharePoint to publication set, and what an administrator's choices make of it.
 *
 * `lib/Settings/configurations/sharepoint-publications.json` declares one
 * source, one mapping, one fetch_file rule and one synchronization with
 * `%placeholder%` values. This class checks that declaration (the install
 * guard) and fills it with the credential, site, library, folder, catalogue
 * and target an administrator picked, as a document the configuration import
 * takes as it is.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DocumentCms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-each-dossier-folder-becomes-a-draft-publication-with-its-documents-req-sppi-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DocumentCms;

use InvalidArgumentException;
use OCA\Integriq\Service\Zgw\ZgwSetCatalogue;

/**
 * Reads, guards and instantiates the `sharepoint-publications` set.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSetCatalogue is a catalogue of constants.
 */
class SharePointPublicationSet {

	/**
	 * The set's slug.
	 *
	 * @var string
	 */
	public const SLUG = 'sharepoint-publications';

	/**
	 * The choices a setup must make; `folderId` empty means the library's root folder.
	 *
	 * @var string[]
	 */
	public const REQUIRED_CHOICES = ['credentialId', 'siteId', 'driveId', 'catalog', 'register', 'schema'];

	/**
	 * The fields the mapping may never write: a past publication date makes a publication live.
	 *
	 * @var string[]
	 */
	public const FORBIDDEN_MAPPED_FIELDS = ['publicationDate', 'published'];

	/**
	 * The component buckets of the set, as the configuration import names them.
	 *
	 * @var string[]
	 */
	private const BUCKETS = ['sources', 'mappings', 'rules', 'synchronizations'];

	/**
	 * Constructor.
	 *
	 * @param string|null $path The set's JSON file; the packaged one when null.
	 *
	 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-each-dossier-folder-becomes-a-draft-publication-with-its-documents-req-sppi-002
	 */
	public function __construct(
		private readonly ?string $path=null,
	) {
	}//end __construct()

	/**
	 * The set's declaration as packaged.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When the file is missing or is not a JSON object.
	 *
	 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-each-dossier-folder-becomes-a-draft-publication-with-its-documents-req-sppi-002
	 */
	public function template(): array {
		$path = ($this->path ?? dirname(__DIR__, 3) . '/Settings/configurations/' . self::SLUG . '.json');
		$raw  = @file_get_contents($path);
		if ($raw === false) {
			throw new InvalidArgumentException(sprintf('The set file "%s" cannot be read.', $path));
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			throw new InvalidArgumentException(sprintf('The set file "%s" is not a JSON object.', $path));
		}

		return $decoded;
	}//end template()

	/**
	 * Why the declaration is not shippable, if it is not.
	 *
	 * A set that names a fleet app, holds anything but a broker reference as
	 * authentication, writes a publication date, or lists a component it does
	 * not declare would install and then fail or publish silently.
	 *
	 * @param array<string, mixed> $template The set's declaration.
	 *
	 * @return string[] The refusals, empty when the declaration is sound.
	 *
	 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-each-dossier-folder-becomes-a-draft-publication-with-its-documents-req-sppi-002
	 */
	public function refuseTemplate(array $template): array {
		$refusals   = $this->fleetAppRefusals(template: $template);
		$components = ($template['components'] ?? []);
		if (is_array($components) === false) {
			return array_merge($refusals, ['The set declares no components.']);
		}

		return array_merge(
			$refusals,
			$this->undeclaredRefusals(template: $template, components: $components),
			$this->authenticationRefusals(components: $components),
			$this->mappedFieldRefusals(components: $components)
		);
	}//end refuseTemplate()

	/**
	 * The refusals for every listed synchronization, mapping or rule the set does not declare.
	 *
	 * @param array<string, mixed> $template   The declaration.
	 * @param array<string, mixed> $components Its components.
	 *
	 * @return string[]
	 */
	private function undeclaredRefusals(array $template, array $components): array {
		$refusals = [];
		foreach (['synchronizations', 'mappings', 'rules'] as $bucket) {
			foreach ((array)($template[$bucket] ?? []) as $slug) {
				if (isset($components[$bucket][$slug]) === false) {
					$refusals[] = sprintf('The set lists the %s "%s" but does not declare it.', rtrim($bucket, 's'), $slug);
				}
			}
		}

		return $refusals;
	}//end undeclaredRefusals()

	/**
	 * The refusals for every source that authenticates with anything but a broker reference.
	 *
	 * @param array<string, mixed> $components The declaration's components.
	 *
	 * @return string[]
	 */
	private function authenticationRefusals(array $components): array {
		$refusals = [];
		foreach ((array)($components['sources'] ?? []) as $slug => $source) {
			$authentication = (array)($source['configuration']['authentication'] ?? []);
			if (array_keys($authentication) !== ['credentialRef']) {
				$refusals[] = sprintf('The source "%s" must authenticate with a broker reference only.', $slug);
			}
		}

		return $refusals;
	}//end authenticationRefusals()

	/**
	 * The refusals for every mapping that writes a field which would publish on the first run.
	 *
	 * @param array<string, mixed> $components The declaration's components.
	 *
	 * @return string[]
	 */
	private function mappedFieldRefusals(array $components): array {
		$refusals = [];
		foreach ((array)($components['mappings'] ?? []) as $slug => $mapping) {
			foreach (self::FORBIDDEN_MAPPED_FIELDS as $field) {
				if (array_key_exists($field, (array)($mapping['mapping'] ?? [])) === true) {
					$refusals[] = sprintf('The mapping "%s" writes "%s", which would publish on the first run.', $slug, $field);
				}
			}
		}

		return $refusals;
	}//end mappedFieldRefusals()

	/**
	 * The import document for one setup: the set filled with the administrator's choices.
	 *
	 * Every slug gets a suffix from the site, library and folder, so a second
	 * folder makes its own source, mapping, rule and synchronization instead of
	 * overwriting the first.
	 *
	 * @param array<string, mixed> $choices credentialId, siteId, driveId, folderId, folderName, catalog,
	 *                                      register, schema and requiresApproval.
	 *
	 * @return array{components: array<string, array<string, array<string, mixed>>>, slugs: array<string, string>}
	 *
	 * @throws InvalidArgumentException When a choice is missing or the declaration is refused.
	 *
	 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
	 */
	public function build(array $choices): array {
		$values   = $this->checkedChoices(choices: $choices);
		$template = $this->template();
		$refusals = $this->refuseTemplate(template: $template);
		if ($refusals !== []) {
			throw new InvalidArgumentException(implode(' ', $refusals));
		}

		$renames    = $this->renames(template: $template, values: $values);
		$components = $this->filledComponents(template: $template, replacements: $this->replacements(values: $values), renames: $renames);

		$syncSlug = $renames[(string)($template['synchronizations'][0] ?? '')] ?? null;
		if ($syncSlug !== null) {
			$components['synchronizations'][$syncSlug]['sourceConfig']['requiresApproval'] = $values['requiresApproval'];
		}

		return ['components' => $components, 'slugs' => $renames];
	}//end build()

	/**
	 * The trimmed choices, refused when one is missing or the credential is not a uuid.
	 *
	 * @param array<string, mixed> $choices The submitted choices.
	 *
	 * @return array<string, mixed> The values of choiceValues().
	 *
	 * @throws InvalidArgumentException When a choice is missing or the credential is not a uuid.
	 */
	private function checkedChoices(array $choices): array {
		$values  = $this->choiceValues(choices: $choices);
		$missing = array_values(array_filter(self::REQUIRED_CHOICES, static fn (string $key): bool => $values[$key] === ''));
		if ($missing !== []) {
			throw new InvalidArgumentException('Choose ' . implode(', ', $missing) . ' before saving.');
		}

		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $values['credentialId']) !== 1) {
			throw new InvalidArgumentException('The credential must be a brokered credential\'s uuid.');
		}

		return $values;
	}//end checkedChoices()

	/**
	 * Every declared slug with the suffix of the chosen site, library and folder.
	 *
	 * @param array<string, mixed> $template The declaration.
	 * @param array<string, mixed> $values   The checked choices.
	 *
	 * @return array<string, string> Old slug to new slug.
	 */
	private function renames(array $template, array $values): array {
		$suffix  = substr(hash('sha256', $values['siteId'] . '|' . $values['driveId'] . '|' . $values['folderId']), 0, 8);
		$renames = [];
		foreach (self::BUCKETS as $bucket) {
			foreach (array_keys((array)($template['components'][$bucket] ?? [])) as $slug) {
				$renames[$slug] = $slug . '-' . $suffix;
			}
		}

		return $renames;
	}//end renames()

	/**
	 * The placeholder values: every choice, the encoded drive id and the list endpoint.
	 *
	 * @param array<string, mixed> $values The checked choices.
	 *
	 * @return array<string, string> Placeholder to value.
	 */
	private function replacements(array $values): array {
		$replacements = [];
		foreach ($values as $key => $value) {
			$replacements['%' . $key . '%'] = (string)$value;
		}

		$replacements['%driveIdPath%']  = rawurlencode($values['driveId']);
		$replacements['%listEndpoint%'] = $this->listEndpoint(driveId: $values['driveId'], folderId: $values['folderId']);

		return $replacements;
	}//end replacements()

	/**
	 * The declared components, filled and renamed, per bucket.
	 *
	 * @param array<string, mixed>  $template     The declaration.
	 * @param array<string, string> $replacements Placeholder to value.
	 * @param array<string, string> $renames      Old slug to new slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function filledComponents(array $template, array $replacements, array $renames): array {
		$components = [];
		foreach (self::BUCKETS as $bucket) {
			foreach ((array)($template['components'][$bucket] ?? []) as $slug => $entity) {
				$components[$bucket][$renames[$slug]] = $this->fill(value: $entity, replacements: $replacements, renames: $renames);
			}
		}

		return $components;
	}//end filledComponents()

	/**
	 * The trimmed choices, every key present.
	 *
	 * @param array<string, mixed> $choices The submitted choices.
	 *
	 * @return array{credentialId: string, siteId: string, driveId: string, folderId: string, folderName: string,
	 *               catalog: string, register: string, schema: string, requiresApproval: bool}
	 */
	private function choiceValues(array $choices): array {
		$values = [];
		foreach (array_merge(self::REQUIRED_CHOICES, ['folderId', 'folderName']) as $key) {
			$value = ($choices[$key] ?? '');
			$values[$key] = '';
			if (is_scalar($value) === true) {
				$values[$key] = trim((string)$value);
			}
		}

		if ($values['folderName'] === '') {
			$values['folderName'] = 'library root';
		}

		$values['requiresApproval'] = (filter_var(($choices['requiresApproval'] ?? false), FILTER_VALIDATE_BOOLEAN) === true);

		return $values;
	}//end choiceValues()

	/**
	 * The Graph path listing the chosen folder's children.
	 *
	 * @param string $driveId  The library's drive id.
	 * @param string $folderId The folder's item id, empty for the root.
	 *
	 * @return string
	 */
	private function listEndpoint(string $driveId, string $folderId): string {
		if ($folderId === '') {
			return sprintf('/drives/%s/root/children', rawurlencode($driveId));
		}

		return sprintf('/drives/%s/items/%s/children', rawurlencode($driveId), rawurlencode($folderId));
	}//end listEndpoint()

	/**
	 * A component with its placeholders filled and its slug references renamed.
	 *
	 * @param mixed                 $value        The component or one of its values.
	 * @param array<string, string> $replacements Placeholder to value.
	 * @param array<string, string> $renames      Old slug to new slug.
	 *
	 * @return mixed
	 */
	private function fill(mixed $value, array $replacements, array $renames): mixed {
		if (is_array($value) === true) {
			$filled = [];
			foreach ($value as $key => $item) {
				$filled[$key] = $this->fill(value: $item, replacements: $replacements, renames: $renames);
			}

			return $filled;
		}

		if (is_string($value) === false) {
			return $value;
		}

		if (isset($renames[$value]) === true) {
			return $renames[$value];
		}

		return strtr($value, $replacements);
	}//end fill()

	/**
	 * The refusals for every fleet app id the declaration names.
	 *
	 * @param array<string, mixed> $template The declaration.
	 *
	 * @return string[]
	 */
	private function fleetAppRefusals(array $template): array {
		$serialised = strtolower((string)json_encode($template));
		$refusals   = [];
		foreach (ZgwSetCatalogue::FLEET_APPS as $app) {
			if (preg_match('/\b' . preg_quote($app, '/') . '\b/', $serialised) === 1) {
				$refusals[] = sprintf('The set names the fleet app "%s"; the operator binds the target register and schema instead.', $app);
			}
		}

		return $refusals;
	}//end fleetAppRefusals()
}//end class
