<?php

/**
 * Unit tests for the stream that relays an MCP answer to the caller.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Endpoint;

use OCA\Integriq\Service\Endpoint\McpRelayStream;
use OCP\AppFramework\Http\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each chunk goes to the output and is flushed at once; nothing can be read back.
 *
 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 */
#[CoversClass(McpRelayStream::class)]
class McpRelayStreamTest extends TestCase {

	/**
	 * Every chunk is written and flushed in order; an empty chunk is neither.
	 *
	 * @return void
	 */
	public function testEachChunkIsWrittenAndFlushedAtOnce(): void {
		$events = [];
		$output = $this->createMock(IOutput::class);
		$output->method('setOutput')->willReturnCallback(static function (string $chunk) use (&$events): void {
			$events[] = 'write ' . $chunk;
		});
		$stream = new McpRelayStream(output: $output, flush: static function () use (&$events): void {
			$events[] = 'flush';
		});

		$this->assertSame(5, $stream->write('one\n'));
		$this->assertSame(0, $stream->write(''));
		$this->assertSame(5, $stream->write('two\n'));

		$this->assertSame(['write one\n', 'flush', 'write two\n', 'flush'], $events);
		$this->assertSame(10, $stream->written());
		$this->assertSame(10, $stream->tell());
	}//end testEachChunkIsWrittenAndFlushedAtOnce()

	/**
	 * The stream is write-only and refuses a write once closed.
	 *
	 * @return void
	 */
	public function testTheStreamIsWriteOnlyAndClosable(): void {
		$stream = new McpRelayStream(output: $this->createMock(IOutput::class), flush: static function (): void {
		});

		$this->assertTrue($stream->isWritable());
		$this->assertFalse($stream->isReadable());
		$this->assertFalse($stream->isSeekable());
		$this->assertSame('', $stream->getContents());
		$this->assertSame('', (string)$stream);
		$this->assertNull($stream->getSize());

		$stream->close();
		$this->assertFalse($stream->isWritable());
		$this->expectException(RuntimeException::class);
		$stream->write('late');
	}//end testTheStreamIsWriteOnlyAndClosable()
}//end class
