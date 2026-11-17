<?php

/**
 * Integriq FeedDeclarationShape.
 *
 * The first app to declare feeds, shillinq, writes its `lib/Settings/feeds.json`
 * as `datasets`, each bound to its administration by
 * `"binding": {"administrationId": "credential.administrationId"}`, with a
 * `_meta` block and a `version`. Integriq's published shape is `feeds`, each
 * with `"scope": {"parameter": ..., "field": ...}`. Without this step the
 * whole shillinq file is refused as malformed and none of its four datasets
 * is served. This class turns the dataset shape into the feed shape before
 * the file is checked, and leaves a file in the feed shape untouched.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Feed
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
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Feed;

/**
 * Reads a declaration written as `datasets` with a `binding` as `feeds` with a `scope`.
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */
final class FeedDeclarationShape {

	/**
	 * The keys of the dataset shape that describe the file, not a feed.
	 *
	 * @var array<int, string>
	 */
	private const FILE_NOTES = ['_meta', 'version'];

	/**
	 * The declaration in the feed shape.
	 *
	 * A file with `datasets` and no `feeds` gets them as `feeds`, without its
	 * `_meta` and `version`. A dataset with a `binding` of exactly one scope
	 * field and no `scope` is bound by that field: the consumer names it as a
	 * query parameter of the same name. A binding of more fields is kept, so
	 * the validator refuses it by name instead of serving it half-bound.
	 * Anything else is returned as it came.
	 *
	 * @param mixed $data The decoded file.
	 *
	 * @return mixed The decoded file in the feed shape.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public static function normalize(mixed $data): mixed {
		if (is_array($data) === false
			|| array_key_exists('feeds', $data) === true
			|| array_key_exists('datasets', $data) === false
		) {
			return $data;
		}

		$datasets = $data['datasets'];
		unset($data['datasets']);
		foreach (self::FILE_NOTES as $note) {
			unset($data[$note]);
		}

		if (is_array($datasets) === true && array_is_list($datasets) === true) {
			$datasets = array_map(static fn (mixed $dataset): mixed => self::feedOf(dataset: $dataset), $datasets);
		}

		$data['feeds'] = $datasets;
		return $data;
	}//end normalize()

	/**
	 * One dataset in the feed shape.
	 *
	 * @param mixed $dataset The dataset.
	 *
	 * @return mixed The dataset with its one-field binding as a scope.
	 */
	private static function feedOf(mixed $dataset): mixed {
		if (is_array($dataset) === false
			|| array_key_exists('scope', $dataset) === true
			|| is_array(($dataset['binding'] ?? null)) === false
			|| count($dataset['binding']) !== 1
		) {
			return $dataset;
		}

		$field = (string)array_key_first($dataset['binding']);
		unset($dataset['binding']);
		$dataset['scope'] = ['parameter' => $field, 'field' => $field];
		return $dataset;
	}//end feedOf()
}//end class
