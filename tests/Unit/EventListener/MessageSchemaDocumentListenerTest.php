<?php

/**
 * Tests for the listener that refuses a message schema whose document does not parse.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-message-schema-is-stored-once-and-referenced-req-msv-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\MessageSchemaDocumentListener;
use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\OpenApiChecker;
use OCA\Integriq\Service\MessageValidation\XsdChecker;
use OCA\Integriq\Service\MessageValidationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The refusal runs on OpenRegister's own create and update path.
 *
 * The events are the real OpenRegister classes (tests/stubs holds copies) and
 * the checkers are the real ones, so a refusal here is the stopPropagation() +
 * setErrors() that MagicMapper turns into a refused save with nothing stored.
 */
class MessageSchemaDocumentListenerTest extends TestCase {

	/**
	 * Build the listener; the mappers resolve to the given slugs.
	 *
	 * @param string $schemaSlug The schema slug the object resolves to.
	 *
	 * @return MessageSchemaDocumentListener
	 */
	private function listener(string $schemaSlug = 'message_schema'): MessageSchemaDocumentListener {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$registerMapper->method('find')->willReturn($this->slugObject('integriq'));
		$schemaMapper->method('find')->willReturn($this->slugObject($schemaSlug));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$json = new JsonSchemaChecker();
		return new MessageSchemaDocumentListener(
			validator: new MessageValidationService($json, new XsdChecker(), new OpenApiChecker($json)),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			l10n: $l10n,
			logger: new NullLogger(),
		);
	}//end listener()

	/**
	 * A slug-bearing value standing in for a Register or Schema.
	 *
	 * @param string $slug The slug.
	 *
	 * @return object
	 */
	private function slugObject(string $slug): object {
		return new class($slug) {
			/**
			 * @param string $slug The slug.
			 */
			public function __construct(private string $slug) {
			}

			/**
			 * @return string
			 */
			public function getSlug(): string {
				return $this->slug;
			}
		};
	}//end slugObject()

	/**
	 * An object entity carrying the given data.
	 *
	 * @param array<string,mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('3d1c5a8e-2b7f-4c90-8e61-5f0a9b2c4d17');
		$entity->setRegister(1);
		$entity->setSchema(2);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * An XSD that is not well-formed XML is refused with the parser's message.
	 *
	 * @return void
	 */
	public function testABrokenXsdIsRefusedWithTheParserMessage(): void {
		$event = new ObjectCreatingEvent($this->entity(['name' => 'Partner', 'kind' => 'xsd', 'version' => '1', 'document' => '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="a">']));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		$this->assertSame(400, $errors['status']);
		$this->assertSame('message_schema_document_invalid', $errors['code']);
		$this->assertStringContainsString('The XSD document does not parse', (string)$errors['message']);
		$this->assertMatchesRegularExpression('/(Premature end|not closed|EndTag|Couldn.t find end)/i', (string)$errors['message']);
	}//end testABrokenXsdIsRefusedWithTheParserMessage()

	/**
	 * Well-formed XML that is not an XSD is refused too.
	 *
	 * @return void
	 */
	public function testWellFormedXmlThatIsNotAnXsdIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(['name' => 'Partner', 'kind' => 'xsd', 'version' => '1', 'document' => '<persoon><bsn/></persoon>']));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('xs:schema', (string)$event->getErrors()['message']);
	}//end testWellFormedXmlThatIsNotAnXsdIsRefused()

	/**
	 * A JSON Schema that is not JSON, or not an object, is refused on update as well.
	 *
	 * @return void
	 */
	public function testABrokenJsonSchemaIsRefusedOnUpdate(): void {
		$old = $this->entity(['name' => 'Person', 'kind' => 'json-schema', 'version' => '1', 'document' => '{"type":"object"}']);
		$new = $this->entity(['name' => 'Person', 'kind' => 'json-schema', 'version' => '2', 'document' => '{"type": "object",']);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('The JSON Schema document does not parse', (string)$event->getErrors()['message']);

		$scalar = new ObjectUpdatingEvent($this->entity(['name' => 'Person', 'kind' => 'json-schema', 'version' => '3', 'document' => '"just text"']), $old);
		$this->listener()->handle($scalar);
		$this->assertTrue($scalar->isPropagationStopped());
	}//end testABrokenJsonSchemaIsRefusedOnUpdate()

	/**
	 * An OpenAPI document that parses but describes no API is refused.
	 *
	 * @return void
	 */
	public function testAnOpenApiDocumentWithoutPathsIsRefused(): void {
		$broken = new ObjectCreatingEvent($this->entity(['name' => 'Zaken', 'kind' => 'openapi', 'version' => '1', 'document' => "openapi: 3.0.0\npaths: [unclosed"]));
		$this->listener()->handle($broken);
		$this->assertTrue($broken->isPropagationStopped());
		$this->assertStringContainsString('The OpenAPI document does not parse', (string)$broken->getErrors()['message']);

		$noPaths = new ObjectCreatingEvent($this->entity(['name' => 'Zaken', 'kind' => 'openapi', 'version' => '1', 'document' => '{"info":{"title":"x"}}']));
		$this->listener()->handle($noPaths);
		$this->assertTrue($noPaths->isPropagationStopped());
	}//end testAnOpenApiDocumentWithoutPathsIsRefused()

	/**
	 * Documents that parse for their kind are saved untouched.
	 *
	 * @return void
	 */
	public function testDocumentsThatParseAreSaved(): void {
		$documents = [
			'xsd' => '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="persoon" type="xs:string"/></xs:schema>',
			'json-schema' => '{"type":"object","required":["bsn"]}',
			'openapi' => "openapi: 3.0.0\ninfo:\n  title: Zaken\n  version: '1'\npaths: {}\n",
		];
		foreach ($documents as $kind => $document) {
			$event = new ObjectCreatingEvent($this->entity(['name' => 'Ok', 'kind' => $kind, 'version' => '1', 'document' => $document]));
			$this->listener()->handle($event);
			$this->assertFalse($event->isPropagationStopped(), $kind);
		}
	}//end testDocumentsThatParseAreSaved()

	/**
	 * A register-schema kind has no document to parse, and other schemas are not looked at.
	 *
	 * @return void
	 */
	public function testARegisterSchemaKindAndOtherSchemasPass(): void {
		$register = new ObjectCreatingEvent($this->entity(['name' => 'Zaak', 'kind' => 'register-schema', 'version' => '1', 'registerSchema' => 'procest/zaak']));
		$this->listener()->handle($register);
		$this->assertFalse($register->isPropagationStopped());

		$other = new ObjectCreatingEvent($this->entity(['kind' => 'xsd', 'document' => '<broken']));
		$this->listener(schemaSlug: 'mapping')->handle($other);
		$this->assertFalse($other->isPropagationStopped());
	}//end testARegisterSchemaKindAndOtherSchemasPass()

	/**
	 * The caller: Application registers the listener on both stoppable save events.
	 *
	 * Application::register() needs a running Nextcloud container, so the
	 * registration statements are read from its source; without them the
	 * listener above is a guard with no call site.
	 *
	 * @return void
	 */
	public function testApplicationRegistersTheListenerOnCreateAndUpdate(): void {
		$source = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');

		$this->assertStringContainsString('use OCA\Integriq\EventListener\MessageSchemaDocumentListener;', $source);
		foreach (['ObjectCreatingEvent', 'ObjectUpdatingEvent'] as $event) {
			$this->assertStringContainsString(
				'$dispatcher->addServiceListener(eventName: ' . $event . '::class, className: MessageSchemaDocumentListener::class);',
				$source,
				$event
			);
		}
	}//end testApplicationRegistersTheListenerOnCreateAndUpdate()
}//end class
