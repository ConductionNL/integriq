<?php

/**
 * Endpoint reference translator.
 *
 * Translates a synchronization's source or target reference between ids and
 * slugs by the endpoint type it names.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ConfigurationHandlers
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

namespace OCA\Integriq\Service\ConfigurationHandlers;

/**
 * One direction of the global mappings applied to endpoint references: an
 * api or database endpoint is a source; a register/schema endpoint is a
 * `register/schema` pair whose parts translate on their own, each falling
 * back to itself.
 *
 * @spec openspec/specs/configuration-export-import/spec.md
 */
final class EndpointReferenceTranslator {
	/**
	 * Endpoint types whose reference is a source.
	 *
	 * @var string[]
	 */
	private const SOURCE_TYPES = ['api', 'database'];

	/**
	 * One direction of the mappings, per type.
	 *
	 * @var array<string,mixed>
	 */
	private array $lookup = [];

	/**
	 * A translator over one direction of the mappings.
	 *
	 * @param array<string,mixed> $lookup One direction of the mappings, per type.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public static function withLookup(array $lookup): self {
		$translator = new self();
		$translator->lookup = $lookup;

		return $translator;
	}//end withLookup()

	/**
	 * Translate one reference by its endpoint type; any other type keeps it.
	 *
	 * The types compare loosely, as the switch this replaces did.
	 *
	 * @param mixed $type      The endpoint type.
	 * @param mixed $reference The source or target reference.
	 *
	 * @return mixed The translated reference.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public function translate(mixed $type, mixed $reference): mixed {
		if (in_array($type, self::SOURCE_TYPES, false) === true) {
			return $this->translateSource(reference: $reference);
		}

		if (in_array($type, ['register/schema'], false) === true) {
			return $this->translateRegisterSchema(reference: $reference);
		}

		return $reference;
	}//end translate()

	/**
	 * A source reference, translated when the mappings know it.
	 *
	 * @param mixed $reference The source reference.
	 *
	 * @return mixed
	 */
	private function translateSource(mixed $reference): mixed {
		if (isset($this->lookup['source'][$reference]) === true) {
			return $this->lookup['source'][$reference];
		}

		return $reference;
	}//end translateSource()

	/**
	 * A `register/schema` reference with each part translated on its own;
	 * a reference without a slash is kept.
	 *
	 * @param mixed $reference The register/schema reference.
	 *
	 * @return mixed
	 */
	private function translateRegisterSchema(mixed $reference): mixed {
		if (str_contains($reference, '/') === false) {
			return $reference;
		}

		[$register, $schema] = explode('/', $reference);

		return ($this->lookup['register'][$register] ?? $register) . '/' . ($this->lookup['schema'][$schema] ?? $schema);
	}//end translateRegisterSchema()
}//end class
