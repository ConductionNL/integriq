<?php

/**
 * Stub for OCA\Tables\Model\Public\Row.
 *
 * `tables` is an optional Nextcloud App Store app not present in the
 * standalone composer dev-environment (and not installed in the checked
 * server checkout either — see discovery.md). This stub mirrors the real
 * class's shape, verified against the public `nextcloud/tables` source
 * (`lib/Model/Public/Row.php`, `main` branch, fetched during
 * nextcloud-event-hub's implementation), so unit tests can construct and
 * inspect real instances without the app installed.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Tables\Model\Public;

/**
 * Minimal stub for OCA\Tables\Model\Public\Row.
 */
final class Row {

	/**
	 * Constructor.
	 *
	 * @param integer $tableId The table id.
	 * @param integer $rowId The row id.
	 * @param array|null $previousValues Column values before the change (update events only).
	 * @param array|null $values Current column values.
	 * @param array|null $dataByAlias Column values keyed by technical name.
	 */
	public function __construct(
		public int $tableId,
		public int $rowId,
		public ?array $previousValues = null,
		public ?array $values = null,
		public ?array $dataByAlias = null,
	) {

	}//end __construct()
}//end class
