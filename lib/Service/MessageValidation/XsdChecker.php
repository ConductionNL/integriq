<?php

/**
 * Integriq XsdChecker.
 *
 * Checks an XML message against an XSD with network and file access off.
 *
 * @category Service
 * @package  OCA\Integriq\Service\MessageValidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-xml-is-validated-without-network-access-req-msv-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\MessageValidation;

use DOMDocument;
use DOMElement;
use OCA\Integriq\Util\SafeXmlParser;

/**
 * XSD checks that never leave the message schema (REQ-MSV-004).
 *
 * Both documents load through {@see SafeXmlParser::loadDom()} (no external
 * entities, LIBXML_NONET). An `xs:import`, `xs:include`, `xs:redefine` or
 * `xs:override` of a remote location is reported by name before validation,
 * and validation itself runs with libxml's entity loader pinned to one that
 * loads nothing, so a relative location is not read from the server's disk
 * either.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-xml-is-validated-without-network-access-req-msv-004
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ValidationOutcome's named constructors build a value object;
 *   SafeXmlParser::loadDom is the app's one hardened XML loader.
 */
class XsdChecker {

	/**
	 * The XML Schema namespace.
	 *
	 * @var string
	 */
	private const XSD_NAMESPACE = 'http://www.w3.org/2001/XMLSchema';

	/**
	 * The elements that pull in another schema document.
	 *
	 * @var list<string>
	 */
	private const REFERENCING_ELEMENTS = ['import', 'include', 'redefine', 'override'];

	/**
	 * Check an XML message against an XSD.
	 *
	 * @param string $xsd     The XSD document.
	 * @param mixed  $payload The message; must be XML text.
	 *
	 * @return ValidationOutcome
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-xml-is-validated-without-network-access-req-msv-004
	 */
	public function check(string $xsd, mixed $payload): ValidationOutcome {
		if (is_string($payload) === false) {
			return ValidationOutcome::failure(message: 'An XML message must be text');
		}

		$previousErrors = libxml_use_internal_errors(true);
		libxml_clear_errors();

		try {
			$schemaDom = new DOMDocument();
			if (SafeXmlParser::loadDom(dom: $schemaDom, data: $xsd) === false) {
				return ValidationOutcome::failure(message: 'The XSD document does not parse: ' . self::libxmlMessages());
			}

			$remote = self::remoteLocations(schema: $schemaDom);
			if ($remote !== []) {
				return ValidationOutcome::failed(
					errors: array_map(
						static fn (string $location): array => [
							'path' => '/',
							'message' => 'The XSD refers to ' . $location . ', which is not fetched: store that schema as its own message schema',
						],
						$remote
					)
				);
			}

			$message = new DOMDocument();
			if (SafeXmlParser::loadDom(dom: $message, data: $payload) === false) {
				return ValidationOutcome::failure(message: 'The XML message does not parse: ' . self::libxmlMessages());
			}

			libxml_clear_errors();
			if (self::validateOffline(message: $message, xsd: $xsd) === true) {
				return ValidationOutcome::valid();
			}

			return ValidationOutcome::failed(errors: self::libxmlErrors());
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previousErrors);
		}//end try
	}//end check()

	/**
	 * Validate with libxml's entity loader pinned to one that loads nothing.
	 *
	 * @param DOMDocument $message The message.
	 * @param string      $xsd     The XSD document.
	 *
	 * @return bool Whether the message is valid.
	 */
	private static function validateOffline(DOMDocument $message, string $xsd): bool {
		$previousLoader = libxml_get_external_entity_loader();
		libxml_set_external_entity_loader(static fn (): null => null);
		// An unusable schema raises a PHP warning ("Invalid Schema") on top of
		// the libxml errors this checker reports; keep it out of the server log.
		set_error_handler(static fn (): bool => true, E_WARNING);

		try {
			return $message->schemaValidateSource($xsd);
		} finally {
			restore_error_handler();
			libxml_set_external_entity_loader($previousLoader);
		}
	}//end validateOffline()

	/**
	 * The remote locations an XSD refers to.
	 *
	 * @param DOMDocument $schema The XSD.
	 *
	 * @return list<string>
	 */
	private static function remoteLocations(DOMDocument $schema): array {
		$locations = [];
		foreach (self::REFERENCING_ELEMENTS as $name) {
			foreach ($schema->getElementsByTagNameNS(self::XSD_NAMESPACE, $name) as $element) {
				if ($element instanceof DOMElement === false) {
					continue;
				}

				$location = trim($element->getAttribute('schemaLocation'));
				if (str_contains($location, '://') === true) {
					$locations[] = $location;
				}
			}
		}

		return $locations;
	}//end remoteLocations()

	/**
	 * The collected libxml errors as outcome errors.
	 *
	 * @return list<array{path: string, message: string}>
	 */
	private static function libxmlErrors(): array {
		$errors = [];
		foreach (libxml_get_errors() as $error) {
			$errors[] = ['path' => '/', 'message' => 'Line ' . $error->line . ': ' . trim($error->message)];
		}

		return $errors;
	}//end libxmlErrors()

	/**
	 * The collected libxml errors as one sentence.
	 *
	 * @return string
	 */
	private static function libxmlMessages(): string {
		$messages = array_column(self::libxmlErrors(), 'message');
		if ($messages === []) {
			return 'unknown error';
		}

		return implode('; ', $messages);
	}//end libxmlMessages()
}//end class
