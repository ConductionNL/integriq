<?php

/**
 * Tests for OpenFormulierenMappingGuardListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/open-formulieren-submits-into-its-case/tasks.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\OpenFormulierenMappingGuardListener;
use OCA\Integriq\Service\OpenFormulieren\MappingCheck;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Activating a form mapping with findings is refused on OpenRegister's save path.
 */
#[CoversClass(OpenFormulierenMappingGuardListener::class)]
class OpenFormulierenMappingGuardListenerTest extends TestCase {

	/**
	 * The listener over a check that answers these findings.
	 *
	 * @param list<array<string, mixed>> $findings   The check's findings.
	 * @param string                     $schemaSlug The saved object's schema slug.
	 * @param bool                       $checked    Whether the check must run.
	 *
	 * @return OpenFormulierenMappingGuardListener
	 */
	private function listener(array $findings, string $schemaSlug = 'openformulieren_form_mapping', bool $checked = true): OpenFormulierenMappingGuardListener {
		$check = $this->createMock(MappingCheck::class);
		$check->expects($checked === true ? $this->once() : $this->never())->method('findings')->willReturn($findings);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->slugObject('integriq'));
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->slugObject($schemaSlug));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new OpenFormulierenMappingGuardListener($check, $registerMapper, $schemaMapper, $l10n, new NullLogger());
	}//end listener()

	/**
	 * An object with a slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return object
	 */
	private function slugObject(string $slug): object {
		return new class($slug) {
			/**
			 * Constructor.
			 *
			 * @param string $slug The slug.
			 */
			public function __construct(private string $slug) {
			}

			/**
			 * The slug.
			 *
			 * @return string
			 */
			public function getSlug(): string {
				return $this->slug;
			}
		};
	}//end slugObject()

	/**
	 * A mapping object.
	 *
	 * @param array<string, mixed> $data The mapping.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('mapping-1');
		$entity->setRegister(1);
		$entity->setSchema(2);
		$entity->setObject($data + ['formSlug' => 'melding', 'fieldMapping' => []]);

		return $entity;
	}//end entity()

	/**
	 * Activating a mapping without the case type is refused with 422 and the finding.
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#scenario-a-mapping-without-the-case-type-is-not-activated
	 *
	 * @return void
	 */
	public function testActivatingAMappingWithFindingsIsRefused(): void {
		$finding = ['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'caseType is required'];
		$event = new ObjectUpdatingEvent($this->entity(['isEnabled' => true]));

		$this->listener([$finding])->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('form_mapping_invalid', $event->getErrors()['code']);
		$this->assertSame(422, $event->getErrors()['status']);
		$this->assertSame([$finding], $event->getErrors()['findings']);
		$this->assertStringContainsString('required-unmapped on caseType', $event->getErrors()['message']);
	}//end testActivatingAMappingWithFindingsIsRefused()

	/**
	 * A valid mapping is saved; so is a disabled one, unchecked.
	 *
	 * @return void
	 */
	public function testAValidOrDisabledMappingIsSaved(): void {
		$valid = new ObjectCreatingEvent($this->entity([]));
		$this->listener([])->handle($valid);
		$this->assertFalse($valid->isPropagationStopped());

		$disabled = new ObjectCreatingEvent($this->entity(['isEnabled' => false]));
		$this->listener([['code' => 'x']], checked: false)->handle($disabled);
		$this->assertFalse($disabled->isPropagationStopped());
	}//end testAValidOrDisabledMappingIsSaved()

	/**
	 * Another schema's object is not checked.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsNotChecked(): void {
		$event = new ObjectCreatingEvent($this->entity([]));

		$this->listener([['code' => 'x']], schemaSlug: 'dso_activity_mapping', checked: false)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsNotChecked()
}//end class
