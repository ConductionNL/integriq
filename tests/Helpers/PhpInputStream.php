<?php

/**
 * A `php://input` that serves a chosen request body.
 *
 * The webhook controllers read the raw body with
 * `file_get_contents('php://input')`, which is empty under the PHPUnit CLI.
 * This wrapper replaces the `php` stream protocol until the body has been
 * read once, then puts the built-in protocol back. It is one-shot on purpose:
 * PHPUnit builds its assertion diffs in `php://memory`, and a wrapper still
 * registered at that point made a failing assertion read as a pass. Any
 * other `php://` stream opened before the body is read passes through.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

/**
 * Stream wrapper for the `php` protocol.
 */
class PhpInputStream {

	/**
	 * The body `php://input` serves.
	 *
	 * @var string
	 */
	public static string $body = '';

	/**
	 * The stream context, set by PHP.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * The passed-through stream for every other php:// path.
	 *
	 * @var resource|null
	 */
	private $inner = null;

	/**
	 * The read position in the body.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * Whether this stream serves the body.
	 *
	 * @var bool
	 */
	private bool $isInput = false;

	/**
	 * Serve `body` on php://input until restore() is called.
	 *
	 * @param string $body The raw request body.
	 *
	 * @return void
	 */
	public static function serve(string $body): void {
		self::$body = $body;
		if (in_array('php', stream_get_wrappers(), true) === true) {
			stream_wrapper_unregister('php');
		}

		stream_wrapper_register('php', self::class);

	}//end serve()

	/**
	 * Put the built-in php:// protocol back.
	 *
	 * @return void
	 */
	public static function restore(): void {
		self::$body = '';
		if (in_array('php', stream_get_wrappers(), true) === true) {
			stream_wrapper_unregister('php');
		}

		stream_wrapper_restore('php');

	}//end restore()

	/**
	 * Open a php:// path.
	 *
	 * @param string      $path       The path.
	 * @param string      $mode       The mode.
	 * @param int         $options    The options.
	 * @param string|null $openedPath Unused.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		if (strtolower($path) === 'php://input') {
			$this->isInput = true;
			$this->position = 0;
			return true;
		}

		stream_wrapper_restore('php');
		$inner = fopen($path, $mode);
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', self::class);
		if ($inner === false) {
			return false;
		}

		$this->inner = $inner;
		return true;

	}//end stream_open()

	/**
	 * Read from the stream.
	 *
	 * @param int $count Bytes wanted.
	 *
	 * @return string|false
	 */
	public function stream_read(int $count): string|false {
		if ($this->isInput === false) {
			return fread($this->inner, $count);
		}

		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;

	}//end stream_read()

	/**
	 * Write to the stream.
	 *
	 * @param string $data The data.
	 *
	 * @return int
	 */
	public function stream_write(string $data): int {
		if ($this->isInput === true) {
			return 0;
		}

		return (int)fwrite($this->inner, $data);

	}//end stream_write()

	/**
	 * Whether the stream is at its end.
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		if ($this->isInput === false) {
			return feof($this->inner);
		}

		return $this->position >= strlen(self::$body);

	}//end stream_eof()

	/**
	 * Stat the stream.
	 *
	 * @return array<int|string, int>|false
	 */
	public function stream_stat(): array|false {
		if ($this->isInput === false) {
			return fstat($this->inner);
		}

		return ['size' => strlen(self::$body)];

	}//end stream_stat()

	/**
	 * Close the stream.
	 *
	 * @return void
	 */
	public function stream_close(): void {
		if ($this->inner !== null) {
			fclose($this->inner);
		}

		if ($this->isInput === true) {
			// One-shot: the body is read, give PHP its own php:// back.
			self::restore();
		}

	}//end stream_close()
}//end class
