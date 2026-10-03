<?php

/**
 * Integriq ValidationOutcome.
 *
 * The result of checking one message against one message schema.
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

/**
 * Valid, or a list of errors each with a JSON pointer path and a message.
 *
 * The path is the place in the message (`/bsn`, `/adres/postcode`); an error
 * about the schema itself, or an XML error, has the path `/`.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-xml-is-validated-without-network-access-req-msv-004
 */
final class ValidationOutcome {

	/**
	 * Constructor.
	 *
	 * @param list<array{path: string, message: string}> $errors The errors; empty means valid.
	 */
	private function __construct(
		private readonly array $errors,
	) {

	}//end __construct()

	/**
	 * A message that matches its schema.
	 *
	 * @return self
	 */
	public static function valid(): self {
		return new self(errors: []);
	}//end valid()

	/**
	 * A message that does not match, or could not be checked.
	 *
	 * @param list<array{path: string, message: string}> $errors At least one error.
	 *
	 * @return self
	 */
	public static function failed(array $errors): self {
		if ($errors === []) {
			$errors = [['path' => '/', 'message' => 'The message could not be checked']];
		}

		return new self(errors: array_values($errors));
	}//end failed()

	/**
	 * One error.
	 *
	 * @param string $message The message.
	 * @param string $path    The path, `/` by default.
	 *
	 * @return self
	 */
	public static function failure(string $message, string $path = '/'): self {
		return new self(errors: [['path' => $path, 'message' => $message]]);
	}//end failure()

	/**
	 * Whether the message matches.
	 *
	 * @return bool
	 */
	public function isValid(): bool {
		return $this->errors === [];
	}//end isValid()

	/**
	 * Every error.
	 *
	 * @return list<array{path: string, message: string}>
	 */
	public function errors(): array {
		return $this->errors;
	}//end errors()

	/**
	 * The first errors, for a refusal body (design D3 lists twenty).
	 *
	 * @param int $limit How many.
	 *
	 * @return list<array{path: string, message: string}>
	 */
	public function firstErrors(int $limit): array {
		return array_slice($this->errors, 0, max(0, $limit));
	}//end firstErrors()

	/**
	 * The distinct paths with an error.
	 *
	 * @return list<string>
	 */
	public function paths(): array {
		return array_values(array_unique(array_column($this->errors, 'path')));
	}//end paths()
}//end class
