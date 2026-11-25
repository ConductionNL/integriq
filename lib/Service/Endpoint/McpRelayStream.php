<?php

/**
 * Integriq McpRelayStream.
 *
 * A write-only PSR-7 stream that hands every chunk it is given to the caller's
 * output at once, so an MCP answer (an event stream included) reaches the
 * client as the upstream sends it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

use OCP\AppFramework\Http\IOutput;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Relays the upstream body to the caller's output, flushing each chunk.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 *
 * PSR-7's StreamInterface fixes the public methods and their parameters; a
 * write-only relay ignores seek offsets and read lengths it cannot honour.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
final class McpRelayStream implements StreamInterface {

	/**
	 * The number of bytes relayed so far.
	 *
	 * @var int
	 */
	private int $written = 0;

	/**
	 * Whether the stream was closed.
	 *
	 * @var bool
	 */
	private bool $closed = false;

	/**
	 * Constructor.
	 *
	 * @param IOutput       $output The caller's output.
	 * @param callable|null $flush Pushes what was written to the client; null flushes PHP's output, a test passes a no-op.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function __construct(
		private readonly IOutput $output,
		private $flush = null,
	) {
	}//end __construct()

	/**
	 * Relay a chunk to the caller and flush it.
	 *
	 * @param string $string The chunk.
	 *
	 * @return int The number of bytes relayed.
	 *
	 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function write(string $string): int {
		if ($this->closed === true) {
			throw new RuntimeException('The relay stream is closed.');
		}

		if ($string === '') {
			return 0;
		}

		$this->output->setOutput($string);
		($this->flush ?? static function (): void {
			if (ob_get_level() > 0) {
				ob_flush();
			}

			flush();
		})();
		$this->written += strlen($string);

		return strlen($string);
	}//end write()

	/**
	 * The number of bytes relayed.
	 *
	 * @return int The count.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function written(): int {
		return $this->written;
	}//end written()

	/**
	 * Nothing to read back: the body went to the caller.
	 *
	 * @return string An empty string.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function __toString(): string {
		return '';
	}//end __toString()

	/**
	 * Close the stream.
	 *
	 * @return void
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function close(): void {
		$this->closed = true;
	}//end close()

	/**
	 * There is no underlying resource.
	 *
	 * @return null
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function detach() {
		$this->closed = true;
		return null;
	}//end detach()

	/**
	 * The size is not known.
	 *
	 * @return int|null Null.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function getSize(): ?int {
		return null;
	}//end getSize()

	/**
	 * The position is the number of bytes relayed.
	 *
	 * @return int The position.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function tell(): int {
		return $this->written;
	}//end tell()

	/**
	 * A relay never ends on its own.
	 *
	 * @return bool False.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function eof(): bool {
		return false;
	}//end eof()

	/**
	 * A relay cannot seek.
	 *
	 * @return bool False.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function isSeekable(): bool {
		return false;
	}//end isSeekable()

	/**
	 * A relay cannot seek.
	 *
	 * @param int $offset The offset.
	 * @param int $whence The whence.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Always.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function seek(int $offset, int $whence = SEEK_SET): void {
		throw new RuntimeException('The relay stream cannot seek.');
	}//end seek()

	/**
	 * A relay cannot rewind; a no-op so a retry does not fail on it.
	 *
	 * @return void
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function rewind(): void {
	}//end rewind()

	/**
	 * A relay can be written while it is open.
	 *
	 * @return bool True while open.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function isWritable(): bool {
		return $this->closed === false;
	}//end isWritable()

	/**
	 * A relay cannot be read.
	 *
	 * @return bool False.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function isReadable(): bool {
		return false;
	}//end isReadable()

	/**
	 * Nothing to read.
	 *
	 * @param int $length The length.
	 *
	 * @return string An empty string.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function read(int $length): string {
		return '';
	}//end read()

	/**
	 * Nothing to read.
	 *
	 * @return string An empty string.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function getContents(): string {
		return '';
	}//end getContents()

	/**
	 * No metadata.
	 *
	 * @param string|null $key The key.
	 *
	 * @return array<string, mixed>|null Null for a key, an empty list otherwise.
	 *
	* @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function getMetadata(?string $key = null) {
		if ($key === null) {
			return [];
		}

		return null;
	}//end getMetadata()
}//end class
