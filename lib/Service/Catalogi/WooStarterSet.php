<?php

/**
 * The Woo starter set: one document type per Woo information category that has none.
 *
 * The categories come from the TOOI informatiecategorie scheme in OpenRegister's
 * concept register, never from a copy. A category counts as present when an
 * existing type carries its TOOI URI or its label. The run creates only what
 * is missing, never changes or deletes an existing type, and refuses with
 * `tooi-scheme-missing` when the scheme cannot be read.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Catalogi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Catalogi;

/**
 * Seeds the missing Woo document types.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
 */
class WooStarterSet {

	/**
	 * Resolves the binding.
	 *
	 * @var callable(): ?CatalogiBinding
	 */
	private $bindingReader;

	/**
	 * Reads the stored types.
	 *
	 * @var callable(CatalogiBinding): array<int, array<string, mixed>>
	 */
	private $typesReader;

	/**
	 * Creates one stored type.
	 *
	 * @var callable(CatalogiBinding, array<string, mixed>): void
	 */
	private $creator;

	/**
	 * Reads the Woo categories, null when the scheme is unreadable.
	 *
	 * @var callable(): ?array
	 */
	private $categoriesReader;

	/**
	 * Constructor.
	 *
	 * @param callable(): ?CatalogiBinding                                $bindingReader    Resolves the binding.
	 * @param callable(CatalogiBinding): array<int, array<string, mixed>> $typesReader      Reads the stored types.
	 * @param callable(CatalogiBinding, array<string, mixed>): void       $creator          Creates one stored type.
	 * @param callable(): ?array                                          $categoriesReader The Woo categories (`uri`, `label`).
	 */
	public function __construct(
		callable $bindingReader,
		callable $typesReader,
		callable $creator,
		callable $categoriesReader,
	) {
		$this->bindingReader = $bindingReader;
		$this->typesReader = $typesReader;
		$this->creator = $creator;
		$this->categoriesReader = $categoriesReader;

	}//end __construct()

	/**
	 * Create the missing types.
	 *
	 * @return array{status: int, body: array<string, mixed>} `created` and `skipped`, or the refusal code.
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
	 */
	public function run(): array {
		$binding = ($this->bindingReader)();
		if ($binding instanceof CatalogiBinding === false) {
			return (new CatalogiProblem(status: 404, code: 'catalogue-not-configured', title: 'No catalogue is configured.'))->answer();
		}

		$categories = ($this->categoriesReader)();
		if (is_array($categories) === false || $categories === []) {
			return (new CatalogiProblem(
				status: 409,
				code: 'tooi-scheme-missing',
				title: 'The TOOI informatiecategorie scheme cannot be read; nothing was created.'
			))->answer();
		}

		$present = $this->presentCategories(binding: $binding);
		$created = 0;
		$skipped = 0;
		foreach ($categories as $category) {
			$uri = (string)($category['uri'] ?? '');
			$label = (string)($category['label'] ?? '');
			if (isset($present[$uri]) === true || isset($present[mb_strtolower($label)]) === true) {
				++$skipped;
				continue;
			}

			($this->creator)(
				$binding,
				$binding->storedFrom(resource: [
					'omschrijving' => $label,
					'informatieobjectcategorie' => $label,
					'informatieobjectcategorieUri' => $uri,
					'vertrouwelijkheidaanduiding' => 'openbaar',
					'concept' => false,
				])
			);
			++$created;
		}//end foreach

		return ['status' => 200, 'body' => ['created' => $created, 'skipped' => $skipped]];
	}//end run()

	/**
	 * The categories existing types already carry, by URI and lower-cased label.
	 *
	 * @param CatalogiBinding $binding The binding.
	 *
	 * @return array<string, true>
	 */
	private function presentCategories(CatalogiBinding $binding): array {
		$present = [];
		foreach (($this->typesReader)($binding) as $object) {
			$mapped = $binding->mapType(object: $object);
			if ($mapped['informatieobjectcategorieUri'] !== '') {
				$present[$mapped['informatieobjectcategorieUri']] = true;
			}

			if ($mapped['informatieobjectcategorie'] !== '') {
				$present[mb_strtolower($mapped['informatieobjectcategorie'])] = true;
			}
		}

		return $present;
	}//end presentCategories()

}//end class
