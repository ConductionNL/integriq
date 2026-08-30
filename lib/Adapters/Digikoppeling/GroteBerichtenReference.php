<?php

/**
 * Integriq — Digikoppeling Grote Berichten out-of-band payload reference.
 *
 * Grote Berichten (large messages) are not carried inline: the SOAP/ebMS2
 * message carries a reference (URL + checksum) and the payload is fetched or
 * served separately, with its checksum verified on retrieval (REQ-DK-004). This
 * value object models that reference and enforces the checksum on retrieval,
 * usable by both the WUS and ebMS2 profiles.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Digikoppeling;

use OCA\Integriq\Exception\DigikoppelingException;

/**
 * An out-of-band Grote Berichten payload reference (URL + checksum).
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
final class GroteBerichtenReference {

	/**
	 * The checksum algorithm used for Grote Berichten payloads.
	 *
	 * @var string
	 */
	public const ALGORITHM = 'sha256';

	/**
	 * Constructor.
	 *
	 * @param string $url The out-of-band payload URL.
	 * @param string $checksum The lowercase hex checksum of the payload.
	 * @param int $sizeBytes The declared payload size in bytes.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $checksum,
		public readonly int $sizeBytes,
	) {
	}//end __construct()

	/**
	 * Build a reference for a payload that is being sent out-of-band.
	 *
	 * @param string $url The URL the payload will be served from.
	 * @param string $payload The raw payload bytes.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: Grote Berichten out-of-band large-payload transfer (REQ-DK-004)
	 */
	public static function forPayload(string $url, string $payload): self {
		return new self(
			url: $url,
			checksum: hash(self::ALGORITHM, $payload),
			sizeBytes: strlen($payload)
		);

	}//end forPayload()

	/**
	 * Serialise the reference for embedding in a message (never the payload).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md
	 */
	public function toMessagePart(): array {
		return [
			'href' => $this->url,
			'algorithm' => self::ALGORITHM,
			'checksum' => $this->checksum,
			'sizeBytes' => $this->sizeBytes,
		];

	}//end toMessagePart()

	/**
	 * Verify a retrieved payload against this reference's checksum.
	 *
	 * @param string $payload The payload bytes fetched from {@see $url}.
	 *
	 * @return string The verified payload (returned for fluent use).
	 *
	 * @throws DigikoppelingException On a checksum mismatch (transport error).
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: Grote Berichten out-of-band large-payload transfer (REQ-DK-004)
	 */
	public function verifyPayload(string $payload): string {
		$actual = hash(self::ALGORITHM, $payload);
		if (hash_equals($this->checksum, $actual) === false) {
			throw new DigikoppelingException(
				message:
				'Grote Berichten payload checksum mismatch — rejected as a transport error.'
			);
		}

		return $payload;
	}//end verifyPayload()
}//end class
