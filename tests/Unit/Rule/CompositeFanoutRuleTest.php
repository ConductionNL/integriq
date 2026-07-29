<?php
/**
 * Unit tests for CompositeFanoutRule.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Rule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Rule;

use Exception;
use OCA\OpenConnector\Rule\CompositeFanoutRule;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the dialect-agnostic composite transactional fan-out rule.
 *
 * @spec openspec/changes/vng-klantinteracties-adapter/specs/rule-pipeline/spec.md#req-rule-006
 */
class CompositeFanoutRuleTest extends TestCase
{


    /**
     * Build a rule ObjectEntity carrying the given composite-fanout configuration.
     *
     * @param array $configuration The `configuration.compositeFanout` payload.
     *
     * @return ObjectEntity
     */
    private function makeRule(array $configuration): ObjectEntity
    {
        $rule = new ObjectEntity();
        $rule->setObject(['name' => 'vng-maak-klantcontact-composite', 'configuration' => ['compositeFanout' => $configuration]]);
        return $rule;
    }//end makeRule()


    /**
     * Build a created ObjectEntity stub with the given uuid and body.
     *
     * @param string $uuid The object uuid.
     * @param array  $body The object body.
     *
     * @return ObjectEntity
     */
    private function makeObject(string $uuid, array $body): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setObject($body);
        return $object;
    }//end makeObject()


    /**
     * A parent + one child body are both created atomically, and the response is the created parent.
     *
     * @return void
     */
    public function testCreatesParentAndChildAtomically(): void
    {
        $orObjectService = $this->createMock(ORObjectService::class);
        $logger          = $this->createMock(LoggerInterface::class);

        $parentObject = $this->makeObject('parent-uuid', ['onderwerp' => 'Vraag over afvalpas']);
        $childObject  = $this->makeObject('child-uuid', ['rol' => 'klant', 'ticket' => 'parent-uuid']);

        $orObjectService->expects($this->exactly(2))
            ->method('saveObject')
            ->willReturnOnConsecutiveCalls($parentObject, $childObject);

        $rule = $this->makeRule(
            [
                'parent'   => ['bodyKey' => 'klantcontact', 'register' => 'pipelinq', 'schema' => 'ticket'],
                'children' => [
                    ['bodyKey' => 'betrokkene', 'register' => 'pipelinq', 'schema' => 'contact', 'parentField' => 'ticket'],
                ],
            ]
        );

        $data = [
            'body' => [
                'klantcontact' => ['onderwerp' => 'Vraag over afvalpas'],
                'betrokkene'   => ['rol' => 'klant'],
            ],
        ];

        $subject = new CompositeFanoutRule($orObjectService, $logger);
        $result  = $subject->apply(rule: $rule, data: $data);

        $this->assertSame(['uuid' => 'parent-uuid', 'onderwerp' => 'Vraag over afvalpas'], $result['body']);
    }//end testCreatesParentAndChildAtomically()


    /**
     * A child write failure rolls back every write made so far (parent included) and raises one error.
     *
     * @return void
     */
    public function testChildFailureRollsBackWholeComposite(): void
    {
        $orObjectService = $this->createMock(ORObjectService::class);
        $logger          = $this->createMock(LoggerInterface::class);

        $parentObject = $this->makeObject('parent-uuid', ['onderwerp' => 'x']);

        $orObjectService->expects($this->exactly(2))
            ->method('saveObject')
            ->willReturnOnConsecutiveCalls(
                $parentObject,
                $this->throwException(new Exception('validation failed'))
            );

        // Rollback must delete exactly the one object that was created (the parent).
        $orObjectService->expects($this->once())
            ->method('deleteObject')
            ->with('parent-uuid', 'pipelinq', 'ticket')
            ->willReturn(true);

        $rule = $this->makeRule(
            [
                'parent'   => ['bodyKey' => 'klantcontact', 'register' => 'pipelinq', 'schema' => 'ticket'],
                'children' => [
                    ['bodyKey' => 'digitaalAdres', 'register' => 'pipelinq', 'schema' => 'contactPoint', 'parentField' => 'ticket'],
                ],
            ]
        );

        $data = [
            'body' => [
                'klantcontact'  => ['onderwerp' => 'x'],
                'digitaalAdres' => ['adres' => 'not-a-valid-address'],
            ],
        ];

        $subject = new CompositeFanoutRule($orObjectService, $logger);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/rolled back/');
        $subject->apply(rule: $rule, data: $data);
    }//end testChildFailureRollsBackWholeComposite()


    /**
     * A child can reference an already-written sibling child (parentRef) rather than the top-level parent.
     *
     * @return void
     */
    public function testChildCanReferenceSiblingChildViaParentRef(): void
    {
        $orObjectService = $this->createMock(ORObjectService::class);
        $logger          = $this->createMock(LoggerInterface::class);

        $parentObject     = $this->makeObject('parent-uuid', []);
        $betrokkeneObject = $this->makeObject('betrokkene-uuid', []);
        $adresObject      = $this->makeObject('adres-uuid', []);

        $captured = [];
        $orObjectService->method('saveObject')
            ->willReturnCallback(
                function ($object, $register, $schema) use (&$captured, $parentObject, $betrokkeneObject, $adresObject) {
                    $captured[] = $object;
                    return match (count($captured)) {
                        1 => $parentObject,
                        2 => $betrokkeneObject,
                        default => $adresObject,
                    };
                }
            );

        $rule = $this->makeRule(
            [
                'parent'   => ['bodyKey' => 'klantcontact', 'register' => 'pipelinq', 'schema' => 'ticket'],
                'children' => [
                    ['bodyKey' => 'betrokkene', 'register' => 'pipelinq', 'schema' => 'contact', 'parentField' => 'ticket'],
                    ['bodyKey' => 'digitaalAdres', 'register' => 'pipelinq', 'schema' => 'contactPoint', 'parentField' => 'contact', 'parentRef' => 'betrokkene'],
                ],
            ]
        );

        $data = [
            'body' => [
                'klantcontact'  => [],
                'betrokkene'    => ['rol' => 'klant'],
                'digitaalAdres' => ['adres' => '0612345678'],
            ],
        ];

        $subject = new CompositeFanoutRule($orObjectService, $logger);
        $subject->apply(rule: $rule, data: $data);

        // The 3rd saveObject call (digitaalAdres) must carry the betrokkene's uuid, not the parent's.
        $this->assertSame('betrokkene-uuid', $captured[2]['contact']);
    }//end testChildCanReferenceSiblingChildViaParentRef()


    /**
     * A missing required child raises an error and the parent write is rolled back.
     *
     * @return void
     */
    public function testMissingRequiredChildRollsBackParent(): void
    {
        $orObjectService = $this->createMock(ORObjectService::class);
        $logger          = $this->createMock(LoggerInterface::class);

        $parentObject = $this->makeObject('parent-uuid', []);
        $orObjectService->expects($this->once())->method('saveObject')->willReturn($parentObject);
        $orObjectService->expects($this->once())->method('deleteObject')->with('parent-uuid', 'pipelinq', 'ticket')->willReturn(true);

        $rule = $this->makeRule(
            [
                'parent'   => ['bodyKey' => 'klantcontact', 'register' => 'pipelinq', 'schema' => 'ticket'],
                'children' => [
                    ['bodyKey' => 'betrokkene', 'register' => 'pipelinq', 'schema' => 'contact', 'required' => true],
                ],
            ]
        );

        $data = ['body' => ['klantcontact' => []]];

        $subject = new CompositeFanoutRule($orObjectService, $logger);

        $this->expectException(Exception::class);
        $subject->apply(rule: $rule, data: $data);
    }//end testMissingRequiredChildRollsBackParent()


    /**
     * A missing parent configuration throws before any write is attempted.
     *
     * @return void
     */
    public function testMissingParentConfigurationThrows(): void
    {
        $orObjectService = $this->createMock(ORObjectService::class);
        $logger          = $this->createMock(LoggerInterface::class);

        $orObjectService->expects($this->never())->method('saveObject');

        $rule = $this->makeRule([]);

        $subject = new CompositeFanoutRule($orObjectService, $logger);

        $this->expectException(Exception::class);
        $subject->apply(rule: $rule, data: ['body' => []]);
    }//end testMissingParentConfigurationThrows()


}//end class
