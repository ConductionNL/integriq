<?php

namespace OCA\OpenConnector\Http;

use OCP\AppFramework\Http\Response;
use OCP\DB\QueryBuilder\IQueryBuilder;
use DOMDocument;
use DOMElement;
use DOMText;

/**
 * A response for XML data
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
/**
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class XMLResponse extends Response
{
	/** 
	 * @var array<string, mixed> The data to be returned 
	 * @psalm-var array<string, mixed>
	 */
	protected array $data;
	
	/** 
	 * @var callable|null Custom render callback 
	 * @psalm-var callable(array<string, mixed>): string|null
	 */
	protected $renderCallback = null;

	/**
	 * Constructor for XMLResponse
	 *
	 * @param array<string, mixed>|string $data The data to convert to XML
	 * @param int $status HTTP status code, defaults to 200
	 * @param array<string, string> $headers Custom headers to add to the response
	 * @param string|null $path The request path to determine if download header should be added
	 * 
	 * @psalm-param array<string, mixed>|string $data
	 * @psalm-param int $status
	 * @psalm-param array<string, string> $headers
	 * @psalm-param string|null $path
	 */
	public function __construct($data = [], int $status = 200, array $headers = [], ?string $path = null)
	{
		parent::__construct($status);
		
		// Set response data
		$this->data = is_array($data) ? $data : ['content' => $data];
		
		// Set headers
		foreach ($headers as $name => $value) {
			$this->addHeader($name, $value);
		}
		
		// Set content type header
		$this->addHeader('Content-Type', 'application/xml; charset=utf-8');
		
		// Only add Content-Disposition header if path ends with .xml
		if ($path !== null && str_ends_with($path, '.xml') === true && isset($this->getHeaders()['Content-Disposition']) === false) {
			$this->addHeader('Content-Disposition', 'attachment; filename="export.xml"');
		}
	}

	/**
	 * Get the data for rendering
	 * 
	 * @return array<string, mixed> The data for rendering
	 * @psalm-return array<string, mixed>
	 */
	protected function getData(): array
	{
		return ['value' => $this->data];
	}

	/**
	 * Set custom render callback
	 *
	 * @param callable $callback Function that takes data array and returns XML string 
	 * @return $this
	 * 
	 * @psalm-param callable(array<string, mixed>): string $callback
	 */
	public function setRenderCallback(callable $callback): self
	{
		$this->renderCallback = $callback;
		return $this;
	}

	/**
	 * Returns the rendered XML
	 *
	 * @return string The rendered XML
	 */
	public function render(): string
	{
		if ($this->renderCallback !== null) {
			return ($this->renderCallback)($this->getData());
		}
		
		$data = $this->getData()['value'];
		
		// Check if data contains an @root key, if so use it directly
		if (isset($data['@root']) === true) {
			return $this->arrayToXml($data);
		}
		
		// Use default root tag
		return $this->arrayToXml(['value' => $data], 'response');
	}

	/**
	 * Convert an array to XML
	 *
	 * @param array<string, mixed> $data The data to convert
	 * @param string|null $rootTag Optional root tag name (overrides @root in data)
	 * @return string The XML string or empty string on failure
	 * 
	 * @psalm-param array<string, mixed> $data
	 * @psalm-return string
	 */
	public function arrayToXml(array $data, ?string $rootTag = null): string
	{
		// Extract root tag from data or use provided root tag
		$rootName = $rootTag ?? ($data['@root'] ?? 'root');
		
		// Remove @root if it exists in data since we've extracted it
		if (isset($data['@root']) === true) {
			unset($data['@root']);
		}
		
		// Create new DOM document
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;
		
		// Create root element
		$root = $dom->createElement($rootName);
		if ($root === false) {
			// Failed to create root element
			return '';
		}
		
		$dom->appendChild($root);
		
		// Build XML structure
		$this->buildXmlElement($dom, $root, $data);
		
		// Get XML output
		$xmlOutput = $dom->saveXML() ?: '';
		
		// Directly replace decimal CR entities with hexadecimal
		$xmlOutput = str_replace('&#13;', '&#xD;', $xmlOutput);
		
		// Format empty tags to have a space before the closing bracket
		$xmlOutput = preg_replace('/<([^>]*)\/>/','<$1 />', $xmlOutput);
		
		return $xmlOutput;
	}

	/**
	 * Build an XML element with attributes and children in order
	 * 
	 * @param DOMDocument $dom The document
	 * @param DOMElement $element The element to populate
	 * @param array<string, mixed> $data The data to convert
	 * @return void
	 * 
	 * @psalm-param DOMDocument $dom
	 * @psalm-param DOMElement $element
	 * @psalm-param array<string, mixed> $data
	 */
	private function buildXmlElement(DOMDocument $dom, DOMElement $element, array $data): void
	{
		// Process attributes first and maintain their order
		if (isset($data['@attributes']) === true && is_array($data['@attributes']) === true) {
			foreach ($data['@attributes'] as $attrKey => $attrValue) {
				// Convert attribute value to string and set it
				$element->setAttribute($attrKey, (string)$attrValue);
			}
			unset($data['@attributes']);
		}
		
		// Process text content
		if (isset($data['#text']) === true) {
			$element->appendChild($this->createSafeTextNode($dom, (string)$data['#text']));
			unset($data['#text']);
		}
		
		// Process child elements
		foreach ($data as $key => $value) {
			// Normalize key name
			$key = ltrim($key, '@');
			$key = is_numeric($key) ? "item$key" : $key;
			
			if (is_array($value) === true) {
				// Handle indexed arrays (multiple elements with same name)
				if (isset($value[0]) === true && is_array($value[0]) === true) {
					foreach ($value as $item) {
						$this->createChildElement($dom, $element, $key, $item);
					}
					continue;
				}

				// Handle associative arrays (complex elements)
				$this->createChildElement($dom, $element, $key, $value);
				continue;
			}

			// Handle simple value elements
			$this->createChildElement($dom, $element, $key, $value);
		}
	}

	/**
	 * Create a child element and populate it
	 * 
	 * @param DOMDocument $dom The document
	 * @param DOMElement $parentElement The parent element
	 * @param string $tagName The tag name for the child element
	 * @param array<string, mixed>|string|object $data The data for the child element
	 * @return void
	 * 
	 * @psalm-param DOMDocument $dom
	 * @psalm-param DOMElement $parentElement
	 * @psalm-param string $tagName
	 * @psalm-param array<string, mixed>|string|object $data
	 */
	private function createChildElement(DOMDocument $dom, DOMElement $parentElement, string $tagName, $data): void
	{
		$childElement = $dom->createElement($tagName);
		if ($childElement === false) {
			return;
		}
		
		$parentElement->appendChild($childElement);
		
		if (is_array($data) === true) {
			$this->buildXmlElement($dom, $childElement, $data);
			return;
		}

		// Handle objects that might not be convertible to string directly
		if (is_object($data) === true) {
			// For QueryBuilder objects or objects without __toString(), create a placeholder
			$data = method_exists($data, '__toString') === true && !($data instanceof IQueryBuilder)
				? (string)$data
				: '[Object of class ' . get_class($data) . ']';
		}

		$childElement->appendChild($this->createSafeTextNode($dom, (string)$data));
	}
	
	/**
	 * Process text content safely
	 * 
	 * @param DOMDocument $dom The document
	 * @param string $text The text to create a node for
	 * @return \DOMNode The created node
	 * 
	 * @psalm-param DOMDocument $dom
	 * @psalm-param string $text
	 * @psalm-return \DOMNode
	 */
	private function createSafeTextNode(DOMDocument $dom, string $text): \DOMNode
	{
		// Decode any HTML entities to prevent double encoding
		// First decode things like &amp; into &
		$decodedText = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		// Then decode again to handle cases like &#039; into '
		$decodedText = html_entity_decode($decodedText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		
		// Create a text node with the processed text
		// Carriage returns will be encoded as decimal entities (&#13;) which are
		// later converted to hexadecimal (&#xD;) in the arrayToXml method
		return $dom->createTextNode($decodedText);
	}
}
