<?php

/**
 * Tests for DsoStamConsumerListener: one dso-stam consumer per instance.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\DsoStamConsumerListener;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A second dso-stam consumer is refused; the first, and edits of it, pass.
 */
class DsoStamConsumerListenerTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * Set up an empty world.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resetWorld();
	}//end setUp()

	/**
	 * The listener over the world, for an object in `$schemaSlug`.
	 *
	 * @param string $schemaSlug The slug the saved object's schema resolves to.
	 *
	 * @return DsoStamConsumerListener
	 */
	private function listener(string $schemaSlug = 'consumer'): DsoStamConsumerListener {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->slugged('integriq'));
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->slugged($schemaSlug));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new DsoStamConsumerListener(
			connection: $this->buildWorldConnection(objectService: $this->buildWorldObjectService()),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			l10n: $l10n,
			logger: new NullLogger()
		);
	}//end listener()

	/**
	 * An object with a slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return object
	 */
	private function slugged(string $slug): object {
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
	}//end slugged()

	/**
	 * A consumer entity as OpenRegister hands it to the listener.
	 *
	 * @param string $uuid The uuid.
	 * @param string $type The authorizationType.
	 *
	 * @return ObjectEntity
	 */
	private function consumer(string $uuid, string $type = 'dso-stam'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister(1);
		$entity->setSchema(2);
		$entity->setObject(['name' => 'DSO-LV (STAM)', 'authorizationType' => $type, 'userId' => 'dso-intake']);

		return $entity;
	}//end consumer()

	/**
	 * A second dso-stam consumer is refused with an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/consumer-management/spec.md#scenario-a-second-dso-stam-consumer-is-refused
	 */
	public function testASecondDsoStamConsumerIsRefused(): void {
		$this->addDsoConsumer(userId: 'dso-intake', uuid: 'consumer-first');

		$event = new ObjectCreatingEvent($this->consumer('consumer-second'));
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('dso_connection_exists', $event->getErrors()['code']);
		$this->assertStringContainsString('Only one DSO connection', $event->getErrors()['message']);

	}//end testASecondDsoStamConsumerIsRefused()

	/**
	 * The first dso-stam consumer is saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function testTheFirstDsoStamConsumerPasses(): void {
		$event = new ObjectCreatingEvent($this->consumer('consumer-first'));
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());

	}//end testTheFirstDsoStamConsumerPasses()

	/**
	 * Editing the one dso-stam consumer is not a second one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function testEditingTheExistingConsumerPasses(): void {
		$this->addDsoConsumer(userId: 'dso-intake', uuid: 'consumer-first');

		$event = new ObjectUpdatingEvent($this->consumer('consumer-first'), $this->consumer('consumer-first'));
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testEditingTheExistingConsumerPasses()

	/**
	 * Other consumers, and dso-stam objects outside the consumer schema, pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function testOtherTypesAndSchemasPass(): void {
		$this->addDsoConsumer(userId: 'dso-intake', uuid: 'consumer-first');

		$apiKey = new ObjectCreatingEvent($this->consumer('consumer-apikey', 'apiKey'));
		$this->listener()->handle($apiKey);
		$this->assertFalse($apiKey->isPropagationStopped());

		$elsewhere = new ObjectCreatingEvent($this->consumer('not-a-consumer'));
		$this->listener(schemaSlug: 'source')->handle($elsewhere);
		$this->assertFalse($elsewhere->isPropagationStopped());

	}//end testOtherTypesAndSchemasPass()
	/**
	 * A second open-formulieren consumer is refused the same way.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/consumer-management/spec.md#scenario-a-second-open-formulieren-consumer-is-refused
	 */
	public function testASecondOpenFormulierenConsumerIsRefused(): void {
		$this->addOpenFormulierenConsumer(userId: 'of-intake', uuid: 'consumer-of-first');
		$event = new ObjectCreatingEvent($this->consumer('consumer-of-second', 'open-formulieren'));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('openformulieren_connection_exists', $event->getErrors()['code']);
		$this->assertStringContainsString('Only one Open Formulieren connection', $event->getErrors()['message']);

	}//end testASecondOpenFormulierenConsumerIsRefused()

	/**
	 * One connection of each kind may exist side by side.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/consumer-management/spec.md#scenario-a-second-open-formulieren-consumer-is-refused
	 */
	public function testAnOpenFormulierenConsumerNextToADsoConsumerPasses(): void {
		$this->addDsoConsumer(userId: 'dso-intake', uuid: 'consumer-dso');
		$event = new ObjectCreatingEvent($this->consumer('consumer-of-first', 'open-formulieren'));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testAnOpenFormulierenConsumerNextToADsoConsumerPasses()

	/**
	 * A second consumer of a webhook on the consumer model is refused; another webhook's passes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-one-consumer-per-webhook
	 */
	public function testASecondWebhookConsumerIsRefused(): void {
		$this->worldConsumers['consumer-peppol'] = ['name' => 'Peppol', 'authorizationType' => 'peppol-webhook', 'userId' => 'p'];

		$second = new ObjectCreatingEvent($this->consumer('consumer-peppol-2', 'peppol-webhook'));
		$this->listener()->handle($second);
		$this->assertTrue($second->isPropagationStopped());
		$this->assertSame('webhook_connection_exists', $second->getErrors()['code']);
		$this->assertStringContainsString('Only one Peppol connection', $second->getErrors()['message']);

		$other = new ObjectCreatingEvent($this->consumer('consumer-rod', 'rod-webhook'));
		$this->listener()->handle($other);
		$this->assertFalse($other->isPropagationStopped());

	}//end testASecondWebhookConsumerIsRefused()
}//end class
