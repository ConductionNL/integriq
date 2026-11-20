<?php

/**
 * Integriq PersonalDataFields: the personal data field list as other parts of the app use it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

use OCP\IAppConfig;

/**
 * The one entry the outbound log and the settings page use, so the masker and
 * the list behind it stay inside the service layer.
 *
 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
 */
final class PersonalDataFields {

	/**
	 * What a masked value reads as.
	 *
	 * @var string
	 */
	public const PLACEHOLDER = PersonalDataMasker::PLACEHOLDER;

	/**
	 * The app setting holding the names an administrator added.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = PersonalDataFieldList::CONFIG_KEY;

	/**
	 * The list behind this entry.
	 *
	 * @var PersonalDataFieldList
	 */
	private readonly PersonalDataFieldList $list;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig|null $appConfig Reads the names an administrator added; without it only the defaults apply.
	 */
	public function __construct(?IAppConfig $appConfig = null) {
		$this->list = new PersonalDataFieldList(appConfig: $appConfig);
	}//end __construct()

	/**
	 * A value with its personal data masked; see {@see PersonalDataFieldList::maskPersonalData()}.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The masked copy.
	 *
	 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function maskPersonalData(mixed $value): mixed {
		return $this->list->maskPersonalData(value: $value);
	}//end maskPersonalData()

	/**
	 * Whether a field name is declared personal data.
	 *
	 * @param string $name The field name.
	 *
	 * @return bool True when its value is masked.
	 *
	 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function isPersonalDataName(string $name): bool {
		return $this->list->isPersonalDataName(name: $name);
	}//end isPersonalDataName()

	/**
	 * The names an administrator added.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function addedPersonalDataFields(): array {
		return $this->list->addedPersonalDataFields();
	}//end addedPersonalDataFields()

	/**
	 * The default names.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public static function defaults(): array {
		return PersonalDataFieldList::DEFAULT_FIELDS;
	}//end defaults()

	/**
	 * A field name as the list compares it; see {@see PersonalDataMasker::normalise()}.
	 *
	 * @param string $name The field name.
	 *
	 * @return string The normalised name.
	 *
	 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public static function normalise(string $name): string {
		return PersonalDataMasker::normalise(name: $name);
	}//end normalise()
}//end class
