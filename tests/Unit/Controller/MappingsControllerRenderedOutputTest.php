<?php

/**
 * The mapping test answers the rendered text of an XML or CSV mapping beside the array.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-declares-its-output-format-req-mfl-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\MappingsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\SourceMappingService;
use OCA\Integriq\Util\MappingOutput;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Controller\MappingsController
 * @uses   \OCA\Integriq\Util\MappingOutput
 * @uses   \OCA\Integriq\Util\ArrayToXml
 */
class MappingsControllerRenderedOutputTest extends TestCase {

	/**
	 * Run the mapping test with the given mapping payload.
	 *
	 * @param array<string, mixed> $mapping The mapping payload.
	 *
	 * @return array<string, mixed> The response data.
	 */
	private function test(array $mapping): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['inputObject' => ['name' => 'Jan'], 'mapping' => $mapping]);

		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$service = $this->createMock(MappingService::class);
		$service->method('executeMapping')->willReturn(['naam' => 'Jan']);
		$service->expects($this->once())->method('renderOutput')->with($mapping, ['naam' => 'Jan'])
			->willReturnCallback(static fn (array $mapping, array $result): array => (new MappingOutput())->render(mapping: $mapping, result: $result));

		$sourceMapping = $this->createMock(SourceMappingService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn(null);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$controller = new MappingsController(
			'integriq',
			$request,
			$service,
			$sourceMapping,
			$l,
			$session,
			$this->createMock(ActionAuthService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class)
		);

		$response = $controller->test(objectService: $sourceMapping);
		self::assertSame(200, $response->getStatus());

		return $response->getData();
	}//end test()

	/**
	 * An XML mapping answers the XML document with its root next to the array.
	 *
	 * @return void
	 */
	public function testAnXmlMappingAnswersTheRenderedDocument(): void {
		$data = $this->test(mapping: ['mapping' => ['naam' => '{{ name }}'], 'outputFormat' => 'xml', 'outputOptions' => ['rootElement' => 'persoon']]);

		self::assertSame(['naam' => 'Jan'], $data['resultObject']);
		self::assertSame('xml', $data['renderedOutput']['format']);
		self::assertSame('application/xml', $data['renderedOutput']['contentType']);
		self::assertStringContainsString("<persoon>\n  <naam>Jan</naam>\n</persoon>", $data['renderedOutput']['body']);
	}//end testAnXmlMappingAnswersTheRenderedDocument()

	/**
	 * A mapping without an output format answers no rendered text, as before.
	 *
	 * @return void
	 */
	public function testAJsonMappingAnswersNoRenderedText(): void {
		$data = $this->test(mapping: ['mapping' => ['naam' => '{{ name }}']]);

		self::assertSame(['naam' => 'Jan'], $data['resultObject']);
		self::assertArrayNotHasKey('renderedOutput', $data);
	}//end testAJsonMappingAnswersNoRenderedText()
}//end class
