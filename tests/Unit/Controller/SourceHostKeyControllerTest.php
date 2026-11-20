<?php

/**
 * Unit tests for SourceHostKeyController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SourceHostKeyController;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\HostKeyPin;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\SourceHostKeyStore;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Show the presented fingerprint; pin only what the server still presents.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
#[CoversClass(SourceHostKeyController::class)]
#[UsesClass(HostKeyPin::class)]
#[UsesClass(SourceHostKeyStore::class)]
#[UsesClass(RemoteFileException::class)]
final class SourceHostKeyControllerTest extends TestCase {

	private const PRESENTED = 'SHA256:presentedKey';

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * @var RemoteFileClient&MockObject
	 */
	private RemoteFileClient $client;

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The source objects saved, as passed.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->client  = $this->createMock(RemoteFileClient::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * The controller under test, over one stored source.
	 *
	 * @param array<string, mixed>|null $object The source's data, or null for none.
	 *
	 * @return SourceHostKeyController
	 */
	private function controller(?array $object): SourceHostKeyController {
		if ($object === null) {
			$this->objects->method('find')->willThrowException(new DoesNotExistException('no source'));
		} else {
			$entity = new ObjectEntity();
			$entity->setUuid('source-1');
			$entity->setObject($object);
			$this->objects->method('find')->willReturnCallback(
				function (string $id, ?ObjectFindOptions $options=null, bool $_rbac=true) use ($entity): ?ObjectEntity {
					$this->assertSame('source-1', $id);
					$this->assertInstanceOf(ObjectFindOptions::class, $options);
					$this->assertSame(['integriq', 'source'], [$options->register, $options->schema], 'the source schema of the integriq register');
					$this->assertFalse($_rbac, 'read without RBAC: the endpoint is admin-only');
					return $entity;
				}
			);
		}

		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options=new ObjectSaveOptions()): ObjectEntity {
				$this->saved[] = ['object' => $object, 'options' => $options];
				return new ObjectEntity();
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters));

		return new SourceHostKeyController(
			appName: 'integriq',
			request: $this->request,
			sources: new SourceHostKeyStore(orObjectService: $this->objects, logger: $this->createMock(LoggerInterface::class)),
			client: $this->client,
			l: $l10n
		);
	}//end controller()

	/**
	 * An SFTP source with an optional pin.
	 *
	 * @param string $pin The pinned fingerprint, '' for none.
	 *
	 * @return array<string, mixed>
	 */
	private function sftp(string $pin=''): array {
		$configuration = ['rootPath' => '/outgoing', 'username' => 'partner'];
		if ($pin !== '') {
			$configuration['hostKeyFingerprint'] = $pin;
		}

		return ['name' => 'Partner', 'type' => 'sftp', 'location' => 'sftp.example.nl', 'configuration' => $configuration];
	}//end sftp()

	/**
	 * A new source shows the presented key and no pin; a pinned one says whether it matches.
	 *
	 * @return void
	 */
	public function testShowGivesThePresentedKeyBesideThePin(): void {
		$this->client->method('presentedHostKey')->willReturnCallback(
			function (array $source): string {
				$this->assertSame('sftp.example.nl', $source['location'], 'the stored source is read');
				return self::PRESENTED;
			}
		);

		$new = $this->controller(object: $this->sftp())->show(id: 'source-1');
		$this->assertSame(Http::STATUS_OK, $new->getStatus());
		$this->assertSame(['pinned' => null, 'presented' => self::PRESENTED, 'matches' => false], $new->getData());
		$this->assertSame([], $this->saved, 'showing never saves');
	}//end testShowGivesThePresentedKeyBesideThePin()

	/**
	 * A pin written without the prefix or with padding still matches.
	 *
	 * @return void
	 */
	public function testShowMatchesANormalisedPin(): void {
		$this->client->method('presentedHostKey')->willReturn(self::PRESENTED);

		$response = $this->controller(object: $this->sftp(pin: 'presentedKey='))->show(id: 'source-1');

		$this->assertSame(['pinned' => self::PRESENTED, 'presented' => self::PRESENTED, 'matches' => true], $response->getData());
	}//end testShowMatchesANormalisedPin()

	/**
	 * A confirmed fingerprint the server presents is saved into the source's configuration, keeping the rest.
	 *
	 * @return void
	 */
	public function testPinSavesTheConfirmedPresentedKey(): void {
		$this->request->method('getParam')->with('fingerprint', '')->willReturn(' SHA256:presentedKey ');
		$this->client->method('presentedHostKey')->willReturn(self::PRESENTED);

		$response = $this->controller(object: $this->sftp())->pin(id: 'source-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['pinned' => self::PRESENTED], $response->getData());
		$this->assertCount(1, $this->saved);
		$this->assertSame(
			['rootPath' => '/outgoing', 'username' => 'partner', 'hostKeyFingerprint' => self::PRESENTED],
			$this->saved[0]['object']['configuration']
		);
		$options = $this->saved[0]['options'];
		$this->assertInstanceOf(ObjectSaveOptions::class, $options);
		$this->assertSame(['integriq', 'source', 'source-1'], [$options->target->register, $options->target->schema, $options->uuid], 'register, schema and the same uuid');
	}//end testPinSavesTheConfirmedPresentedKey()

	/**
	 * A confirmed fingerprint that is not what the server presents now is refused and nothing is saved.
	 *
	 * @return void
	 */
	public function testPinRefusesAFingerprintTheServerDoesNotPresent(): void {
		$this->request->method('getParam')->willReturn('SHA256:somethingElse');
		$this->client->method('presentedHostKey')->willReturn(self::PRESENTED);

		$response = $this->controller(object: $this->sftp(pin: 'SHA256:old'))->pin(id: 'source-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			'The server now presents SHA256:presentedKey, not the fingerprint you confirmed (SHA256:somethingElse). Nothing was saved; check it again.',
			$response->getData()['error']
		);
		$this->assertSame([], $this->saved);
	}//end testPinRefusesAFingerprintTheServerDoesNotPresent()

	/**
	 * No fingerprint, an unknown source, a non-SFTP source and an unreadable server are each refused without saving.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->request->method('getParam')->willReturn('');
		$this->client->expects($this->never())->method('presentedHostKey');
		$response = $this->controller(object: $this->sftp())->pin(id: 'source-1');
		$this->assertSame([Http::STATUS_BAD_REQUEST, 'Give the fingerprint you checked with the partner.'], [$response->getStatus(), $response->getData()['error']]);

		$this->setUp();
		$this->client->expects($this->never())->method('presentedHostKey');
		$response = $this->controller(object: null)->show(id: 'source-1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

		$this->setUp();
		$this->client->expects($this->never())->method('presentedHostKey');
		$response = $this->controller(object: ['type' => 'ftps'] + $this->sftp())->show(id: 'source-1');
		$this->assertSame([Http::STATUS_BAD_REQUEST, 'Only an SFTP source has a host key to pin.'], [$response->getStatus(), $response->getData()['error']]);

		$this->setUp();
		$this->request->method('getParam')->willReturn(self::PRESENTED);
		$this->client->method('presentedHostKey')->willThrowException(new RemoteFileException('The host sftp.example.nl is not allowed.'));
		$response = $this->controller(object: $this->sftp())->pin(id: 'source-1');
		$this->assertSame([Http::STATUS_BAD_GATEWAY, 'The host sftp.example.nl is not allowed.'], [$response->getStatus(), $response->getData()['error']]);

		$this->assertSame([], $this->saved);
	}//end testRefusals()

	/**
	 * A failed save is a 500 naming why.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsReported(): void {
		$this->request->method('getParam')->willReturn(self::PRESENTED);
		$this->client->method('presentedHostKey')->willReturn(self::PRESENTED);
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('register is read-only'));

		$response = $this->controller(object: $this->sftp())->pin(id: 'source-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('The host key was not saved: register is read-only', $response->getData()['error']);
	}//end testAFailedSaveIsReported()

	/**
	 * Both endpoints are admin-only.
	 *
	 * @return void
	 */
	public function testBothEndpointsAreAdminOnly(): void {
		foreach (['show', 'pin'] as $method) {
			$attributes = (new ReflectionMethod(SourceHostKeyController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $method);
			$this->assertSame([IntegriqAdmin::class], $attributes[0]->getArguments(), $method);
		}
	}//end testBothEndpointsAreAdminOnly()
}//end class
