<?php

/**
 * Stub for OCA\Forms\Db\Form.
 *
 * `forms` is an optional Nextcloud App Store app not present in the
 * standalone composer dev-environment (and not installed in the checked
 * server checkout either — see discovery.md). This minimal stub exposes
 * only the accessors this app's `NextcloudFormsEventListener` reads
 * (`getId()`, `getTitle()`), verified against the public `nextcloud/forms`
 * source (`lib/Db/Form.php`, `main` branch, fetched during
 * nextcloud-event-hub's implementation) — the real class extends NC's
 * `OCP\AppFramework\Db\Entity` and gets `getId()`/`getTitle()` via magic
 * `@method` accessors; this stub declares them directly for a dependency-free
 * test double.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Forms\Db;

/**
 * Minimal stub for OCA\Forms\Db\Form.
 */
class Form {

	/**
	 * Constructor.
	 *
	 * @param integer $id The form id.
	 * @param string $title The form title.
	 */
	public function __construct(
		private int $id,
		private string $title = '',
	) {

	}//end __construct()

	/**
	 * Get the form id.
	 *
	 * @return integer
	 */
	public function getId(): int {
		return $this->id;
	}//end getId()

	/**
	 * Get the form title.
	 *
	 * @return string
	 */
	public function getTitle(): string {
		return $this->title;
	}//end getTitle()

	/**
	 * Read the form as a plain array (mirrors the real class's `read()`).
	 *
	 * @return array
	 */
	public function read(): array {
		return [
			'id' => $this->id,
			'title' => $this->title,
		];

	}//end read()
}//end class
