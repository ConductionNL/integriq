<?php

/**
 * Host key confirmation: show the fingerprint, pin only what the server still presents.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\FileServerSourcesController;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Unit\Service\Adapter\FileTransfer\FileTransferAdapterTestCase;
use OCP\IL10N;
use OCP\IRequest;

class FileServerSourcesControllerTest extends FileTransferAdapterTestCase {

	/**
	 * Saved sources.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The controller over one stored source.
	 *
	 * @param array<string,mixed> $stored The stored source.
	 *
	 * @return FileServerSourcesController The controller.
	 */
	private function controller(array $stored): FileServerSourcesController {
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('find')->willReturnCallback(fn ($id) => ObjectServiceMockBuilder::objectEntity($this, $stored, (string)$id));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'source-1');
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new FileServerSourcesController('integriq', $this->createMock(IRequest::class), $objects, $this->adapter('sftp'), $this->adapter('ftps'), $l10n);
	}//end controller()

	/**
	 * A new source's test shows the fingerprint; confirming it pins it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testAnAdministratorPinsTheFingerprintTheTestShowed(): void {
		$controller = $this->controller($this->source(['hostKeyFingerprint' => '']));

		$shown = $controller->test('source-1')->getData();
		$this->assertSame(self::PIN, $shown['fingerprint']);
		$this->assertFalse($shown['connected']);

		$response = $controller->pin('source-1', $shown['fingerprint']);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::PIN, $this->saved[0]['hostKeyFingerprint']);
	}//end testAnAdministratorPinsTheFingerprintTheTestShowed()

	/**
	 * A fingerprint the server does not present is never pinned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testAFingerprintTheServerDoesNotPresentIsRefused(): void {
		$response = $this->controller($this->source(['hostKeyFingerprint' => '']))->pin('source-1', 'SHA256:ZZZdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame([], $this->saved);
	}//end testAFingerprintTheServerDoesNotPresentIsRefused()

	/**
	 * Only SFTP and FTPS sources have a host key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function testAnApiSourceIsRefused(): void {
		$response = $this->controller($this->source(['type' => 'api']))->test('source-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([], $this->server->calls);
	}//end testAnApiSourceIsRefused()
}//end class
