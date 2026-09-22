<?php

/**
 * An in-memory IMemcache with real `add` and `cad` semantics.
 *
 * A `createMock(IMemcache::class)` answers whatever the test tells it to,
 * which makes a single-use assertion unfalsifiable: the double would say
 * "already present" exactly when the test wanted it to. This fake implements
 * the two operations the guards depend on for real, so a guard that stopped
 * calling them would fail.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/idp-broker-envelope-runtime/specs/digid-eherkenning-auth-adapter/spec.md#requirement-single-use-artefacts-refuse-rather-than-degrade
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCP\IMemcache;

/**
 * A shared cache that behaves like one.
 */
class FakeMemcache implements IMemcache {

	/**
	 * The entries.
	 *
	 * @var array<string,mixed>
	 */
	public array $entries = [];

	/**
	 * Whether this cache backend can be used here. It can: it is in memory.
	 *
	 * @return boolean Always true.
	 */
	public static function isAvailable(): bool {
		return true;
	}//end isAvailable()

	/**
	 * Read one entry.
	 *
	 * @param string $key The key.
	 *
	 * @return mixed The value, or null.
	 */
	public function get($key) {
		return ($this->entries[$key] ?? null);
	}//end get()

	/**
	 * Write one entry.
	 *
	 * @param string $key The key.
	 * @param mixed $value The value.
	 * @param integer $ttl The lifetime.
	 *
	 * @return boolean Always true.
	 */
	public function set($key, $value, $ttl = 0) {
		$this->entries[$key] = $value;
		return true;
	}//end set()

	/**
	 * Whether an entry exists.
	 *
	 * @param string $key The key.
	 *
	 * @return boolean True when it does.
	 */
	public function hasKey($key) {
		return array_key_exists($key, $this->entries);
	}//end hasKey()

	/**
	 * Remove one entry.
	 *
	 * @param string $key The key.
	 *
	 * @return boolean Always true.
	 */
	public function remove($key) {
		unset($this->entries[$key]);
		return true;
	}//end remove()

	/**
	 * Remove every entry under a prefix.
	 *
	 * @param string $prefix The prefix.
	 *
	 * @return boolean Always true.
	 */
	public function clear($prefix = '') {
		foreach (array_keys($this->entries) as $key) {
			if ($prefix === '' || str_starts_with((string)$key, $prefix) === true) {
				unset($this->entries[$key]);
			}
		}

		return true;
	}//end clear()

	/**
	 * Write only when the key is free. This is the atomic one.
	 *
	 * @param string $key The key.
	 * @param mixed $value The value.
	 * @param integer $ttl The lifetime.
	 *
	 * @return boolean True when it was written, false when the key was taken.
	 */
	public function add($key, $value, $ttl = 0) {
		if (array_key_exists($key, $this->entries) === true) {
			return false;
		}

		$this->entries[$key] = $value;
		return true;
	}//end add()

	/**
	 * Increment.
	 *
	 * @param string $key The key.
	 * @param integer $step The step.
	 *
	 * @return integer|boolean The new value.
	 */
	public function inc($key, $step = 1) {
		$this->entries[$key] = ((int)($this->entries[$key] ?? 0) + $step);
		return $this->entries[$key];
	}//end inc()

	/**
	 * Decrement.
	 *
	 * @param string $key The key.
	 * @param integer $step The step.
	 *
	 * @return integer|boolean The new value, or false when absent.
	 */
	public function dec($key, $step = 1) {
		if (array_key_exists($key, $this->entries) === false) {
			return false;
		}

		$this->entries[$key] = ((int)$this->entries[$key] - $step);
		return $this->entries[$key];
	}//end dec()

	/**
	 * Compare and set.
	 *
	 * @param string $key The key.
	 * @param mixed $old The expected value.
	 * @param mixed $new The new value.
	 *
	 * @return boolean True when it was replaced.
	 */
	public function cas($key, $old, $new) {
		if (($this->entries[$key] ?? null) !== $old) {
			return false;
		}

		$this->entries[$key] = $new;
		return true;
	}//end cas()

	/**
	 * Compare and delete. This is the other atomic one.
	 *
	 * @param string $key The key.
	 * @param mixed $old The expected value.
	 *
	 * @return boolean True when it was removed.
	 */
	public function cad($key, $old) {
		if (array_key_exists($key, $this->entries) === false || $this->entries[$key] !== $old) {
			return false;
		}

		unset($this->entries[$key]);
		return true;
	}//end cad()

	/**
	 * Delete when the value differs, or when the key is absent.
	 *
	 * @param string $key The key.
	 * @param mixed $old The value that must NOT be there.
	 *
	 * @return boolean True when it was removed or was already absent.
	 */
	public function ncad(string $key, mixed $old): bool {
		if (array_key_exists($key, $this->entries) === false) {
			return true;
		}

		if ($this->entries[$key] === $old) {
			return false;
		}

		unset($this->entries[$key]);
		return true;
	}//end ncad()

}//end class
