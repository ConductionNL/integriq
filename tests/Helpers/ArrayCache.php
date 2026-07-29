<?php
/**
 * ArrayCache — in-memory ICache fake for LTI service tests.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Helpers;

use OCP\ICache;

/**
 * Minimal in-memory ICache implementation with real get/set/remove
 * semantics (TTL is tracked but not expired — tests assert presence/absence
 * explicitly rather than relying on wall-clock expiry).
 */
class ArrayCache implements ICache
{

    /** @var array<string, mixed> */
    public array $store = [];


    public function get($key)
    {
        return ($this->store[$key] ?? null);
    }//end get()


    public function set($key, $value, $ttl=0)
    {
        $this->store[$key] = $value;
        return true;
    }//end set()


    public function hasKey($key)
    {
        return isset($this->store[$key]);
    }//end hasKey()


    public function remove($key)
    {
        unset($this->store[$key]);
        return true;
    }//end remove()


    public function clear($prefix='')
    {
        $this->store = [];
        return true;
    }//end clear()


    public static function isAvailable(): bool
    {
        return true;
    }//end isAvailable()
}//end class
