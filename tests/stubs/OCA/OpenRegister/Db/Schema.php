<?php

/**
 * Stub for OCA\OpenRegister\Db\Schema: only what integriq's unit tests read.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * A schema with a slug.
 */
class Schema {

	/**
	 * The slug.
	 *
	 * @var string
	 */
	private string $slug = '';

	/**
	 * The slug.
	 *
	 * @return string
	 */
	public function getSlug(): string {
		return $this->slug;
	}//end getSlug()

	/**
	 * Set the slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return void
	 */
	public function setSlug(string $slug): void {
		$this->slug = $slug;
	}//end setSlug()
}//end class
