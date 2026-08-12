<?php

/**
 * Stub for OCA\OpenRegister\Db\Mapping.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub satisfies `use` statements and unit-test
 * hydration calls for the OR Mapping value object so MappingService tests can
 * run without a full Nextcloud server installation.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Minimal stub for OCA\OpenRegister\Db\Mapping.
 *
 * Exposes the getters MappingService consumes (getName, getMapping,
 * getPassThrough, getUnset, getCast) and the hydrate(array): self helper.
 */
class Mapping extends Entity {

	/** @var string|null */
	protected $name = null;

	/** @var array */
	protected $mapping = [];

	/** @var bool */
	protected $passThrough = false;

	/** @var array */
	protected $unset = [];

	/** @var array */
	protected $cast = [];

	/**
	 * @return string|null
	 */
	public function getName(): ?string {
		return $this->name;
	}//end getName()

	/**
	 * @return array
	 */
	public function getMapping(): array {
		return ($this->mapping ?? []);
	}//end getMapping()

	/**
	 * @return bool
	 */
	public function getPassThrough(): bool {
		return (bool)$this->passThrough;
	}//end getPassThrough()

	/**
	 * @return array
	 */
	public function getUnset(): array {
		return ($this->unset ?? []);
	}//end getUnset()

	/**
	 * @return array
	 */
	public function getCast(): array {
		return ($this->cast ?? []);
	}//end getCast()

	/**
	 * Hydrate the value object from an associative array.
	 *
	 * @param array $object The data to hydrate from.
	 *
	 * @return static
	 */
	public function hydrate(array $object): static {
		if (isset($object['name']) === true) {
			$this->name = (string)$object['name'];
		}

		if (array_key_exists('mapping', $object) === true) {
			$this->mapping = (array)($object['mapping'] ?? []);
		}

		if (array_key_exists('passThrough', $object) === true) {
			$this->passThrough = (bool)$object['passThrough'];
		}

		if (array_key_exists('unset', $object) === true) {
			$this->unset = (array)($object['unset'] ?? []);
		}

		if (array_key_exists('cast', $object) === true) {
			$this->cast = (array)($object['cast'] ?? []);
		}

		return $this;
	}//end hydrate()

}//end class
