<?php

/**
 * The run-log link a synchronization-shaped node earns.
 *
 * WHY A TRAIT AND NOT FOUR COPIES. Four nodes — paginate, commit, sweep and the
 * legacy run — all say the same thing in their log entries: "this step acted on
 * synchronization X". The link an operator wants from any of them is identical,
 * and four copies of it would be four places for the route to rot when the SPA
 * renames a page.
 *
 * ⚠️ `$entry` IS UNTRUSTED. `FlowController::logActions()` is `NoAdminRequired`
 * and passes the caller's POST body through verbatim — it is not read back from
 * a stored run log. So NOTHING here resolves an id: no mapper call, no
 * existence check, no name lookup. The reference is echoed straight into an
 * href exactly as the caller supplied it, which is the only way this can be
 * free of the IDOR the interface warns about. A link built from a lookup would
 * disclose, by its mere presence, that a record the caller may not read exists.
 * That is also why there is no "and here is its name" affordance: knowing the
 * name would require reading the record.
 *
 * @category Service
 * @package  OCA\Integriq\Flow
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

/**
 * Deep-links a run-log entry back to the synchronization the step acted on.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
trait SynchronizationLogActions {

	/**
	 * How deep to look for the reference before giving up.
	 *
	 * The payload sits under the step's CONFIGURED output key, and a log entry
	 * carries no config, so the key cannot be known here — the value has to be
	 * found rather than addressed. A bound keeps a deeply nested or cyclic
	 * sample from turning one log render into a walk of the whole document.
	 *
	 * @var int
	 */
	private const SEARCH_DEPTH = 4;

	/**
	 * The keys that name a synchronization in these nodes' output.
	 *
	 * Both spellings are live: `synchronization` in the page and sweep
	 * summaries, `synchronizationId` on a committed contract payload.
	 *
	 * @var array<int, string>
	 */
	private const REFERENCE_KEYS = ['synchronization', 'synchronizationId'];

	/**
	 * The links this log entry earns.
	 *
	 * @param array<string, mixed> $entry One entry from the run's log.
	 *
	 * @return array<int, array{label: string, href: string}> The links.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function logActions(array $entry): array {
		$reference = $this->synchronizationIn(entry: $entry);
		if ($reference === '') {
			// Nothing to point at. The interface is explicit that this must be
			// an empty array and not a link to the synchronizations LIST: a
			// link that lands somewhere unrelated is followed once, and after
			// that none of them are trusted.
			return [];
		}

		// The SPA is a HASH router, so a deep link is a fragment on the app
		// root and not a server route. `/synchronizations/:id` is a real page
		// (manifest id `SynchronizationDetail`), and it is registered AFTER the
		// literal `/synchronizations/contracts` and `/synchronizations/logs`,
		// so a reference can never shadow one of those.
		$root = rtrim($this->urlGenerator->linkToRoute('openconnector.ui.dashboard', ['path' => '']), '/');

		return [
			[
				'label' => $this->l10n->t('Open the synchronization'),
				'href' => $root . '#/synchronizations/' . rawurlencode($reference),
			],
		];

	}//end logActions()

	/**
	 * Find the synchronization reference somewhere in a log entry's items.
	 *
	 * @param array<string, mixed> $entry The log entry.
	 *
	 * @return string The reference, or an empty string when there is none.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function synchronizationIn(array $entry): string {
		// `output` first: it is what the step PRODUCED, and on a step that
		// failed early there may be no output at all — hence the fall back to
		// what it received, which for these nodes carries the same reference.
		foreach (['output', 'input'] as $side) {
			$items = (array)(((array)($entry[$side] ?? []))['items'] ?? []);
			foreach ($items as $item) {
				$found = $this->referenceIn(
					value: ((array)$item)['json'] ?? null,
					depth: 0
				);
				if ($found !== '') {
					return $found;
				}
			}
		}

		return '';

	}//end synchronizationIn()

	/**
	 * Depth-bounded search for a reference key holding a non-empty scalar.
	 *
	 * @param mixed $value The value to search.
	 * @param int   $depth How deep this call already is.
	 *
	 * @return string The reference, or an empty string.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function referenceIn(mixed $value, int $depth): string {
		if (is_array($value) === false || $depth > self::SEARCH_DEPTH) {
			return '';
		}

		foreach (self::REFERENCE_KEYS as $key) {
			$candidate = ($value[$key] ?? null);
			if (is_scalar($candidate) === true && trim((string)$candidate) !== '') {
				return trim((string)$candidate);
			}
		}

		foreach ($value as $nested) {
			$found = $this->referenceIn(value: $nested, depth: ($depth + 1));
			if ($found !== '') {
				return $found;
			}
		}

		return '';

	}//end referenceIn()
}//end trait
