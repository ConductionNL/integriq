<?php

/**
 * SourceRequestedListener Unit Tests
 *
 * The find-or-create seam a sibling app uses to turn a plain base URL into a
 * Source: idempotent by derived slug, refusing anything that is not a bare
 * http(s) base URL on a host this instance may call, and recording who asked.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\SourceRequestedEvent;
use OCA\Integriq\EventListener\SourceRequestedListener;
use OCA\Integriq\Service\ConnectionStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use OCP\Security\IRemoteHostValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for SourceRequestedListener.
 *
 * @covers \OCA\Integriq\EventListener\SourceRequestedListener
 * @covers \OCA\Integriq\Event\SourceRequestedEvent
 */
class SourceRequestedListenerTest extends TestCase {

	/**
	 * The Source store.
	 *
	 * @var ConnectionStore&MockObject
	 */
	private $store;

	/**
	 * The remote-host rule.
	 *
	 * @var IRemoteHostValidator&MockObject
	 */
	private $hostValidator;

	/**
	 * The audit log.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * The listener under test.
	 *
	 * @var SourceRequestedListener
	 */
	private SourceRequestedListener $listener;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->getMockBuilder(ConnectionStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['findSourceBySlug', 'createSource'])
			->getMock();
		$this->hostValidator = $this->createMock(IRemoteHostValidator::class);
		$this->hostValidator->method('isValid')->willReturn(true);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new SourceRequestedListener(
			store: $this->store,
			hostValidator: $this->hostValidator,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * A request for an unknown base URL creates an enabled, credential-free Source that says who asked.
	 *
	 * @return void
	 */
	public function testCreatesTheSourceWhenNoneExists(): void {
		$this->store->method('findSourceBySlug')->with(slug: 'url-https-hooks-example-org-8443')->willReturn(null);

		$payload = null;
		$this->store->expects($this->once())->method('createSource')->willReturnCallback(
			function (array $data) use (&$payload): ObjectEntity {
				$payload = $data;
				return $this->entity(uuid: 'src-1');
			}
		);
		$this->logger->expects($this->once())->method('info')->with(
			$this->stringContains('created'),
			$this->callback(static fn (array $context): bool => $context['sourceApp'] === 'dossiq' && $context['userId'] === 'alice')
		);

		$event = $this->request(url: 'HTTPS://Hooks.Example.org:8443/', timeout: 5);
		$this->listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertTrue($event->wasCreated());
		$this->assertSame('src-1', $event->getSourceId());
		$this->assertSame('url-https-hooks-example-org-8443', $event->getSourceSlug());
		$this->assertSame('https://hooks.example.org:8443', $payload['location']);
		$this->assertSame('url-https-hooks-example-org-8443', $payload['slug']);
		$this->assertTrue($payload['isEnabled']);
		$this->assertSame('api', $payload['type']);
		$this->assertSame(['timeout' => 5], $payload['configuration']);
		$this->assertStringContainsString('dossiq', $payload['description']);
		$this->assertStringContainsString('alice', $payload['description']);
		foreach (['auth', 'apikey', 'password', 'secret', 'jwt', 'headers'] as $credential) {
			$this->assertArrayNotHasKey($credential, $payload);
		}
	}//end testCreatesTheSourceWhenNoneExists()

	/**
	 * Asking twice returns the Source the first request made, and creates nothing.
	 *
	 * @return void
	 */
	public function testReturnsTheExistingSourceWithoutCreating(): void {
		$this->store->method('findSourceBySlug')->willReturn($this->entity(uuid: 'src-9'));
		$this->store->expects($this->never())->method('createSource');

		$event = $this->request(url: 'http://hooks.example.org');
		$this->listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertFalse($event->wasCreated());
		$this->assertSame('src-9', $event->getSourceId());
		$this->assertSame('url-http-hooks-example-org', $event->getSourceSlug());
	}//end testReturnsTheExistingSourceWithoutCreating()

	/**
	 * Anything but a bare http(s) base URL is refused before the store is touched.
	 *
	 * @return void
	 */
	public function testRefusesAnythingButABareHttpBaseUrl(): void {
		$this->store->expects($this->never())->method('findSourceBySlug');
		$this->store->expects($this->never())->method('createSource');

		foreach (['ftp://hooks.example.org', 'https://', 'not a url', 'https://u:p@hooks.example.org', 'https://hooks.example.org/path', 'https://hooks.example.org?x=1'] as $url) {
			$event = $this->request(url: $url);
			$this->listener->handle($event);

			$this->assertFalse($event->isHandled(), $url);
			$this->assertNotNull($event->getRefusal(), $url);
			$this->assertNull($event->getSourceId(), $url);
		}
	}//end testRefusesAnythingButABareHttpBaseUrl()

	/**
	 * A host Nextcloud's remote-host rule refuses (a local or private address) is refused.
	 *
	 * @return void
	 */
	public function testRefusesAHostTheInstanceMayNotCall(): void {
		$validator = $this->createMock(IRemoteHostValidator::class);
		$validator->expects($this->once())->method('isValid')->with('127.0.0.1')->willReturn(false);
		$this->store->expects($this->never())->method('createSource');
		$listener = new SourceRequestedListener(store: $this->store, hostValidator: $validator, logger: $this->logger);

		$event = $this->request(url: 'http://127.0.0.1');
		$listener->handle($event);

		$this->assertFalse($event->isHandled());
		$this->assertStringContainsString('127.0.0.1', (string)$event->getRefusal());
	}//end testRefusesAHostTheInstanceMayNotCall()

	/**
	 * A store failure leaves the event unhandled with the reason, so the consumer fails closed.
	 *
	 * @return void
	 */
	public function testStoreFailureLeavesTheEventUnhandled(): void {
		$this->store->method('findSourceBySlug')->willThrowException(new RuntimeException('register unavailable'));

		$event = $this->request(url: 'https://hooks.example.org');
		$this->listener->handle($event);

		$this->assertFalse($event->isHandled());
		$this->assertStringContainsString('register unavailable', (string)$event->getRefusal());
	}//end testStoreFailureLeavesTheEventUnhandled()

	/**
	 * A foreign event is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresForeignEvents(): void {
		$this->store->expects($this->never())->method('findSourceBySlug');
		$this->listener->handle(new class extends Event {
		});
	}//end testIgnoresForeignEvents()

	/**
	 * The listener is registered against the event, so a dispatch reaches it.
	 *
	 * Read from the app's registration code because the registration runs
	 * inline in `register()`, which a unit test cannot boot.
	 *
	 * @return void
	 */
	public function testTheListenerIsRegisteredForTheEvent(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		$this->assertStringContainsString(
			'addServiceListener(eventName: SourceRequestedEvent::class, className: SourceRequestedListener::class)',
			$source
		);
	}//end testTheListenerIsRegisteredForTheEvent()

	/**
	 * Build a request.
	 *
	 * @param string   $url     The base URL.
	 * @param int|null $timeout The timeout.
	 *
	 * @return SourceRequestedEvent
	 */
	private function request(string $url, ?int $timeout = null): SourceRequestedEvent {
		return new SourceRequestedEvent(
			sourceApp: 'dossiq',
			baseUrl: $url,
			purpose: 'the webhook steps of its case flows',
			timeoutSeconds: $timeout,
			userId: 'alice',
		);
	}//end request()

	/**
	 * A stored Source.
	 *
	 * @param string $uuid Its uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['name' => 'hooks.example.org']);

		return $entity;
	}//end entity()
}//end class
