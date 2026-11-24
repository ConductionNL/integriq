<?php

/**
 * The personal data field list: the default names plus an administrator's additions.
 *
 * Every logged body is masked over this list (REQ-PDM-001): the call log, a
 * trace snapshot and an outbound message record.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service\Security;

use OCA\Integriq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * Knows which field names are personal data and masks them in a logged copy.
 *
 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
 */
class PersonalDataFieldList {

	/**
	 * App config key holding the field names an administrator added to the
	 * personal data list, as a JSON list of strings.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'personal_data_fields';

	/**
	 * The personal data fields masked in every logged body: BSN, name,
	 * address, date of birth, e-mail address and telephone number, in the
	 * spellings of StUF, the Haal Centraal APIs and common English JSON.
	 * Compared after {@see PersonalDataMasker::normalise()}, so `inp.bsn`
	 * and `e-mail` are listed once.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_FIELDS = [
		// BSN.
		'bsn',
		'inp.bsn',
		'burgerservicenummer',
		// Name.
		'geslachtsnaam',
		'voornamen',
		'voornaam',
		'voorletters',
		'voorvoegselGeslachtsnaam',
		'achternaam',
		'aanschrijfnaam',
		'aanhef',
		'firstName',
		'lastName',
		'givenName',
		'familyName',
		'fullName',
		'surname',
		// Address.
		'adres',
		'address',
		'verblijfadres',
		'verblijfplaats',
		'straatnaam',
		'straat',
		'street',
		'streetName',
		'huisnummer',
		'huisletter',
		'huisnummertoevoeging',
		'houseNumber',
		'postcode',
		'postalCode',
		'zipCode',
		'woonplaats',
		'woonplaatsnaam',
		// Date of birth.
		'geboortedatum',
		'dateOfBirth',
		'birthDate',
		// E-mail address.
		'email',
		'emailadres',
		'emailAddress',
		// Telephone number.
		'telefoonnummer',
		'telefoon',
		'mobielnummer',
		'phone',
		'phoneNumber',
		'telephone',
		'mobile',
	];

	/**
	 * The masker over the default and the added personal data fields, built once.
	 *
	 * @var PersonalDataMasker|null
	 */
	private ?PersonalDataMasker $personalDataMasker = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig|null $appConfig Reads the personal data fields an administrator added;
	 *                                   without it only the default list applies.
	 */
	public function __construct(
		private readonly ?IAppConfig $appConfig = null,
	) {

	}//end __construct()

	/**
	 * Every personal data field name: the default list plus the administrator's additions.
	 *
	 * @return array<int, string> The names, unique, in their declared spelling.
	 *
	 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function personalDataFieldNames(): array {
		return array_values(array_unique(array_merge(self::DEFAULT_FIELDS, $this->addedPersonalDataFields())));
	}//end personalDataFieldNames()

	/**
	 * The field names an administrator added to the personal data list.
	 *
	 * A stored value that is not a JSON list of strings adds nothing.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function addedPersonalDataFields(): array {
		if ($this->appConfig === null) {
			return [];
		}

		$stored = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, '[]'), true);
		if (is_array($stored) === false) {
			return [];
		}

		$names = [];
		foreach ($stored as $name) {
			if (is_string($name) === true && trim($name) !== '') {
				$names[] = trim($name);
			}
		}

		return array_values(array_unique($names));
	}//end addedPersonalDataFields()

	/**
	 * A logged value with its personal data masked: an array by key at any
	 * depth, a string as a JSON, XML or URL body. Never call this on what is
	 * sent; it is for the copy that is stored.
	 *
	 * @param mixed $value The logged value.
	 *
	 * @return mixed The masked copy.
	 *
	 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function maskPersonalData(mixed $value): mixed {
		return $this->personalData()->mask(value: $value);
	}//end maskPersonalData()

	/**
	 * Whether a field name is declared personal data.
	 *
	 * @param string $name The name.
	 *
	 * @return boolean True when its value is masked in a log.
	 *
	 * @spec openspec/changes/archive/2026-11-20-call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function isPersonalDataName(string $name): bool {
		return $this->personalData()->isPersonalDataName(name: $name);
	}//end isPersonalDataName()

	/**
	 * The masker over every personal data field name.
	 *
	 * @return PersonalDataMasker The masker.
	 */
	private function personalData(): PersonalDataMasker {
		if ($this->personalDataMasker === null) {
			$this->personalDataMasker = new PersonalDataMasker(names: $this->personalDataFieldNames());
		}

		return $this->personalDataMasker;
	}//end personalData()
}//end class
