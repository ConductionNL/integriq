<?php

/**
 * Integriq XML Response.
 *
 * Response class that serialises array payloads to XML and writes the
 * appropriate Content-Type / Content-Disposition headers.
 *
 * @category Http
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Controller;

use OCA\Integriq\Util\ArrayToXml;
use OCP\AppFramework\Http\Response;

/**
 * A response for XML data.
 *
 * @psalm-suppress                               PropertyNotSetInConstructor
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class XMLResponse extends Response {

	/**
	 * The data to be returned.
	 *
	 * @var array<string, mixed>
	 * @psalm-var array<string, mixed>
	 */
	protected array $data;

	/**
	 * Custom render callback.
	 *
	 * @var callable|null
	 * @psalm-var callable(array<string, mixed>): string|null
	 */
	protected $renderCallback = null;

	/**
	 * Constructor for XMLResponse.
	 *
	 * @param array<string, mixed>|string $data The data to convert to XML.
	 * @param int $status HTTP status code, defaults to 200.
	 * @param array<string, string> $headers Custom headers to add to the response.
	 * @param string|null $path The request path to determine if download header should be added.
	 *
	 * @psalm-param array<string, mixed>|string $data
	 * @psalm-param int $status
	 * @psalm-param array<string, string> $headers
	 * @psalm-param string|null $path
	 */
	public function __construct($data = [], int $status = 200, array $headers = [], ?string $path = null) {
		parent::__construct(status: $status);

		// Set response data.
		if (is_array($data) === true) {
			$this->data = $data;
		} else {
			$this->data = ['content' => $data];
		}

		// Set headers.
		foreach ($headers as $name => $value) {
			$this->addHeader(name: $name, value: $value);
		}

		// Set content type header.
		$this->addHeader(name: 'Content-Type', value: 'application/xml; charset=utf-8');

		// Only add Content-Disposition header if path ends with .xml.
		if ($path !== null
			&& str_ends_with($path, '.xml') === true
			&& isset($this->getHeaders()['Content-Disposition']) === false
		) {
			$this->addHeader(name: 'Content-Disposition', value: 'attachment; filename="export.xml"');
		}

	}//end __construct()

	/**
	 * Get the data for rendering.
	 *
	 * @return array<string, mixed> The data for rendering.
	 * @psalm-return array<string, mixed>
	 *
	 * @spec openspec/specs/xml-response/spec.md
	 */
	protected function getData(): array {
		return ['value' => $this->data];
	}//end getData()

	/**
	 * Set custom render callback.
	 *
	 * @param callable $callback Function that takes data array and returns XML string.
	 *
	 * @return $this
	 *
	 * @psalm-param callable(array<string, mixed>): string $callback
	 *
	 * @spec openspec/specs/xml-response/spec.md
	 */
	public function setRenderCallback(callable $callback): self {
		$this->renderCallback = $callback;
		return $this;
	}//end setRenderCallback()

	/**
	 * Returns the rendered XML.
	 *
	 * @return string The rendered XML.
	 *
	 * @spec openspec/specs/xml-response/spec.md
	 */
	public function render(): string {
		if ($this->renderCallback !== null) {
			return ($this->renderCallback)($this->getData());
		}

		$data = $this->getData()['value'];

		// Check if data contains an @root key; if so, use it directly.
		if (isset($data['@root']) === true) {
			return $this->arrayToXml(data: $data);
		}

		// Use default root tag.
		return $this->arrayToXml(data: ['value' => $data], rootTag: 'response');
	}//end render()

	/**
	 * Convert an array to XML.
	 *
	 * @param array<string, mixed> $data The data to convert.
	 * @param string|null $rootTag Optional root tag name (overrides @root in data).
	 *
	 * @return string The XML string or empty string on failure.
	 *
	 * @psalm-param  array<string, mixed> $data
	 * @psalm-return string
	 *
	 * @spec openspec/specs/xml-response/spec.md
	 */
	public function arrayToXml(array $data, ?string $rootTag = null): string {
		return (new ArrayToXml())->convert(data: $data, rootTag: $rootTag);
	}//end arrayToXml()
}//end class
