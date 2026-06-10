<?php
/**
 * Regression tests locking the null-coercion behaviour of
 * Synchronization::hydrate() after the OpenRegister cutover.
 *
 * OpenRegister serialises an optional, never-set array key (conditions /
 * followUps / actions) as a literal null. The non-nullable getters and
 * jsonSerialize() would type-error on such a null, so hydrate() coerces any
 * null JSON-typed field to an empty array. These tests pin that coercion.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Db;

use OCA\OpenConnector\Db\Synchronization;
use PHPUnit\Framework\TestCase;

/**
 * Null-coercion regression coverage for Synchronization::hydrate().
 */
final class SynchronizationHydrateTest extends TestCase
{

    /**
     * hydrate() coerces a null `conditions`/`followUps`/`actions` to an empty
     * array so the non-nullable getters stay well-typed.
     *
     * @return void
     */
    public function testHydrateCoercesNullJsonArrayFieldsToEmptyArray(): void
    {
        $sync = new Synchronization();
        $sync->hydrate(
            [
                'name'       => 'demo',
                'conditions' => null,
                'followUps'  => null,
                'actions'    => null,
            ]
        );

        $this->assertSame([], $sync->getConditions());
        $this->assertSame([], $sync->getFollowUps());
        $this->assertSame([], $sync->getActions());

    }//end testHydrateCoercesNullJsonArrayFieldsToEmptyArray()


    /**
     * hydrate() leaves an explicitly provided array value untouched.
     *
     * @return void
     */
    public function testHydratePreservesProvidedArrayValues(): void
    {
        $sync = new Synchronization();
        $sync->hydrate(
            [
                'name'       => 'demo',
                'conditions' => [['field' => 'x']],
                'followUps'  => [['id' => 'f1']],
                'actions'    => [['action' => 'run']],
            ]
        );

        $this->assertSame([['field' => 'x']], $sync->getConditions());
        $this->assertSame([['id' => 'f1']], $sync->getFollowUps());
        $this->assertSame([['action' => 'run']], $sync->getActions());

    }//end testHydratePreservesProvidedArrayValues()


    /**
     * jsonSerialize() round-trips cleanly after hydrating from a null-bearing
     * OpenRegister object (no type error, empty arrays surface).
     *
     * @return void
     */
    public function testJsonSerializeAfterNullHydrateExposesEmptyArrays(): void
    {
        $sync = new Synchronization();
        $sync->hydrate(['name' => 'demo', 'conditions' => null]);

        $serialized = $sync->jsonSerialize();

        $this->assertSame([], $serialized['conditions']);
        $this->assertSame([], $serialized['followUps']);
        $this->assertSame([], $serialized['actions']);

    }//end testJsonSerializeAfterNullHydrateExposesEmptyArrays()

}//end class
