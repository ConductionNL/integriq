<?php

/**
 * An OpenRegister object entity double that answers getUri().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The ObjectEntity stub has no uri column; this one derives it from the uuid
 * and sits in register 1, schema 2.
 */
class UriObjectEntity extends ObjectEntity {

	/**
	 * @var string|null
	 */
	protected $register = '1';

	/**
	 * @var string|null
	 */
	protected $schema = '2';

	/**
	 * @return string The object's uri.
	 */
	public function getUri(): string {
		return 'https://example.org/things/' . $this->getUuid();
	}//end getUri()
}//end class
