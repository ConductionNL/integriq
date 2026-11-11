<?php

/**
 * Integriq DSO Parser Service
 *
 * Parses DSO-verzoek XML/JSON payloads into structured data.
 * Validates payloads against the STAM schema definition.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Portal\PortalRequestParserInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for parsing and validating DSO-verzoek payloads.
 *
 * Extracts aanvrager, locatie, activiteiten, bijlagen, and projectbeschrijving
 * from DSO-LV STAM koppelvlak payloads (JSON or XML).
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- the total is the parser's rules, now one named step each
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class DSOParserService implements PortalRequestParserInterface {

	/**
	 * Required fields in a DSO-verzoek payload.
	 *
	 * WIRE NAMES. These are the field names the Digitaal Stelsel Omgevingswet
	 * sends, checked against an inbound payload we do not control, so they are
	 * the stated exemption to the English-only rule.
	 *
	 * The vocabulary pass had translated exactly one of the six —
	 * `indieningsdatum` -> `submissionDate` — while its five siblings stayed as
	 * they were. That makes the field permanently absent, so every DSO verzoek
	 * fails validation on a required field the DSO has never sent. A rename that
	 * touches one member of a wire contract and not the rest is easy to miss in
	 * review precisely because the surrounding lines still look right.
	 *
	 * @var array
	 */
	private const REQUIRED_FIELDS = [
		'verzoekId',
		'type',
		'indieningsdatum',
		'aanvrager',
		'locatie',
		'activiteiten',
	];

	/**
	 * Valid verzoek types per STAM schema.
	 *
	 * @var array
	 */
	private const VALID_TYPES = [
		'aanvraag',
		'melding',
		'informatieverzoek',
		'vooroverleg',
	];

	/**
	 * DSOParserService constructor.
	 *
	 * @param LoggerInterface $logger Logger for error handling.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Validate a DSO-verzoek payload against the STAM schema.
	 *
	 * Returns an array of validation errors. An empty array means the payload is valid.
	 *
	 * @param array $payload The verzoek payload data.
	 *
	 * @return array Array of validation error objects with 'field', 'error', and 'message' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-3
	 */
	public function validatePayload(array $payload): array {
		return array_merge(
			$this->missingFields(payload: $payload),
			$this->typeErrors(payload: $payload),
			$this->activiteitenErrors(payload: $payload),
			$this->formatErrors(payload: $payload)
		);
	}//end validatePayload()

	/**
	 * One error per required field that is missing, empty or null.
	 *
	 * @param array $payload The request payload.
	 *
	 * @return array<int, array{field: string, error: string, message: string}> The errors.
	 */
	private function missingFields(array $payload): array {
		$errors = [];
		foreach (self::REQUIRED_FIELDS as $field) {
			if ($this->isMissing(payload: $payload, field: $field) === true) {
				$errors[] = [
					'field' => $field,
					'error' => 'required_field_missing',
					'message' => ucfirst($field) . ' is verplicht',
				];
			}
		}

		return $errors;
	}//end missingFields()

	/**
	 * Whether a field is absent, empty or null.
	 *
	 * @param array  $payload The request payload.
	 * @param string $field   The field.
	 *
	 * @return bool True when it is missing.
	 */
	private function isMissing(array $payload, string $field): bool {
		return isset($payload[$field]) === false
			|| $payload[$field] === ''
			|| $payload[$field] === null;
	}//end isMissing()

	/**
	 * The error for a type outside the enum, if any.
	 *
	 * @param array $payload The request payload.
	 *
	 * @return array<int, array{field: string, error: string, message: string}> The errors.
	 */
	private function typeErrors(array $payload): array {
		if (isset($payload['type']) === false || in_array($payload['type'], self::VALID_TYPES, true) === true) {
			return [];
		}

		return [
			[
				'field' => 'type',
				'error' => 'invalid_enum_value',
				'message' => 'Type must be one of: ' . implode(', ', self::VALID_TYPES),
			],
		];
	}//end typeErrors()

	/**
	 * The error for activiteiten that is not an array, if any.
	 *
	 * @param array $payload The request payload.
	 *
	 * @return array<int, array{field: string, error: string, message: string}> The errors.
	 */
	private function activiteitenErrors(array $payload): array {
		if (isset($payload['activiteiten']) === false || is_array($payload['activiteiten']) === true) {
			return [];
		}

		return [
			[
				'field' => 'activiteiten',
				'error' => 'invalid_type',
				'message' => 'Activiteiten must be an array',
			],
		];
	}//end activiteitenErrors()

	/**
	 * The BSN and submission date values whose format is wrong.
	 *
	 * @param array $payload The verzoek payload data.
	 *
	 * @return array Validation error objects, in check order.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-3
	 */
	private function formatErrors(array $payload): array {
		return array_merge($this->bsnErrors(payload: $payload), $this->dateErrors(payload: $payload));
	}//end formatErrors()

	/**
	 * The error for an aanvrager BSN that fails the 11-proef, if any.
	 *
	 * @param array $payload The request payload.
	 *
	 * @return array<int, array{field: string, error: string, message: string}> The errors.
	 */
	private function bsnErrors(array $payload): array {
		if (isset($payload['aanvrager']['bsn']) === false || $this->validateBSN(bsn: $payload['aanvrager']['bsn']) === true) {
			return [];
		}

		return [
			[
				'field' => 'aanvrager.bsn',
				'error' => 'invalid_bsn',
				'message' => 'BSN does not pass the 11-proef validation',
			],
		];
	}//end bsnErrors()

	/**
	 * The error for a submission date not in ISO 8601, if any.
	 *
	 * Subscript and reported `field` are the DSO's own wire name, because the
	 * caller matches the error against the payload it sent.
	 *
	 * @param array $payload The request payload.
	 *
	 * @return array<int, array{field: string, error: string, message: string}> The errors.
	 */
	private function dateErrors(array $payload): array {
		if (isset($payload['indieningsdatum']) === false || $this->validateISODate(date: $payload['indieningsdatum']) === true) {
			return [];
		}

		return [
			[
				'field' => 'indieningsdatum',
				'error' => 'invalid_date_format',
				'message' => 'The submission date must be in ISO 8601 format (YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS)',
			],
		];
	}//end dateErrors()

	/**
	 * Parse a DSO-verzoek payload into structured data.
	 *
	 * Extracts and normalizes all verzoek fields including aanvrager,
	 * locatie, activiteiten, and bijlagen references.
	 *
	 * @param array $payload The raw verzoek payload.
	 *
	 * @return array The parsed and structured verzoek data.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
	 */
	public function parseRequest(array $payload): array {
		$bouwkosten = null;
		if (isset($payload['bouwkosten']) === true) {
			$bouwkosten = (float)$payload['bouwkosten'];
		}

		$request = [
			'verzoekId' => ($payload['verzoekId'] ?? null),
			'bronorganisatie' => ($payload['bronorganisatie'] ?? null),
			'type' => ($payload['type'] ?? null),
			// KEY is the internal name every downstream service reads; the
			// SUBSCRIPT is the DSO's wire name. This line is the whole mapping.
			'submissionDate' => ($payload['indieningsdatum'] ?? null),
			'aanvrager' => $this->parseApplicant(applicant: ($payload['aanvrager'] ?? [])),
			'locatie' => $this->parseLocation(location: ($payload['locatie'] ?? [])),
			'activiteiten' => $this->parseActiviteiten(activiteiten: ($payload['activiteiten'] ?? [])),
			'bouwkosten' => $bouwkosten,
			'bijlagen' => $this->parseAttachments(attachments: ($payload['bijlagen'] ?? [])),
			'status' => 'ontvangen',
			'environment' => ($payload['environment'] ?? 'productie'),
			'stamApiVersion' => ($payload['stamApiVersion'] ?? null),
		];

		if (isset($payload['projectbeschrijving']) === true) {
			$request['projectbeschrijving'] = $payload['projectbeschrijving'];
		}

		return $request;
	}//end parseRequest()

	/**
	 * Validate a BSN (Burger Service Nummer) using the 11-proef.
	 *
	 * The 11-proef validation multiplies each digit by a weight factor
	 * and checks that the sum is divisible by 11.
	 *
	 * NOTE: This is intentionally duplicated from OpenRegister's BsnFormat.
	 * Integriq cannot depend on OpenRegister being installed since it
	 * connects to external systems independently. If the canonical implementation
	 * changes, this method must be updated to match.
	 *
	 * @param string $bsn The BSN to validate.
	 *
	 * @return bool True if the BSN passes the 11-proef.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-3
	 *
	 * @see \OCA\OpenRegister\Formats\BsnFormat::validate() Canonical BSN validation (ADR-011)
	 */
	public function validateBSN(string $bsn): bool {
		// BSN must be 8 or 9 digits.
		$bsn = ltrim(string: $bsn, characters: '0');
		$bsn = str_pad(string: $bsn, length: 9, pad_string: '0', pad_type: STR_PAD_LEFT);

		if (preg_match(pattern: '/^\d{9}$/', subject: $bsn) !== 1) {
			return false;
		}

		// 11-proef: multiply each digit by its weight factor.
		$sum = 0;
		$weights = [
			9,
			8,
			7,
			6,
			5,
			4,
			3,
			2,
			-1,
		];

		for ($i = 0; $i < 9; $i++) {
			$sum += ((int)$bsn[$i] * $weights[$i]);
		}

		return ($sum % 11 === 0 && $sum !== 0);
	}//end validateBSN()

	/**
	 * Validate an ISO 8601 date string.
	 *
	 * @param string $date The date string to validate.
	 *
	 * @return bool True if the date is valid ISO 8601.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-3
	 */
	public function validateISODate(string $date): bool {
		$parsed = \DateTime::createFromFormat('Y-m-d', $date);
		if ($parsed !== false && $parsed->format('Y-m-d') === $date) {
			return true;
		}

		$parsed = \DateTime::createFromFormat('Y-m-d\TH:i:s', $date);
		if ($parsed !== false) {
			return true;
		}

		$parsed = \DateTime::createFromFormat(\DateTime::ATOM, $date);
		if ($parsed !== false) {
			return true;
		}

		return false;
	}//end validateISODate()

	/**
	 * Parse the aanvrager (initiatiefnemer) block.
	 *
	 * @param array $applicant The raw aanvrager data.
	 *
	 * @return array The parsed aanvrager data.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
	 */
	private function parseApplicant(array $applicant): array {
		return [
			'bsn' => ($applicant['bsn'] ?? null),
			'kvkNummer' => ($applicant['kvkNummer'] ?? null),
			'vestigingsnummer' => ($applicant['vestigingsnummer'] ?? null),
			'naam' => ($applicant['naam'] ?? null),
			'bedrijfsnaam' => ($applicant['bedrijfsnaam'] ?? null),
			'adres' => ($applicant['adres'] ?? null),
			'contactgegevens' => ($applicant['contactgegevens'] ?? null),
		];

	}//end parseAanvrager()

	/**
	 * Parse the locatie block.
	 *
	 * Handles BAG-adresgegevens and GML-geometrie conversion.
	 *
	 * @param array $location The raw locatie data.
	 *
	 * @return array The parsed locatie data.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
	 */
	private function parseLocation(array $location): array {
		$parsed = [
			'bagAdres' => ($location['bagAdres'] ?? null),
			'kadastraleAanduiding' => ($location['kadastraleAanduiding'] ?? null),
			'geometrie' => null,
		];

		// Convert GML to GeoJSON if present.
		if (isset($location['gmlGeometrie']) === true) {
			$parsed['geometrie'] = $this->convertGMLToGeoJSON(gml: $location['gmlGeometrie']);
		}

		return $parsed;
	}//end parseLocatie()

	/**
	 * Parse the activiteiten array.
	 *
	 * Keeps the identifiers a STAM Project Activiteit carries (STAM v6.0.2
	 * section 3.7): `imowId`, `activityId` (the Activiteit-id), `activityName`
	 * and `volgnr`, plus the onderliggende activiteit as `underlying`. These
	 * are integriq's own JSON field names. The old `code` (or `activiteitCode`)
	 * is read as `activityId` and `omschrijving` as `activityName`, so earlier
	 * pushes keep working. Which STAM XML elements carry these values is not
	 * verified yet (change dso-activity-mapping-table, task 1.1).
	 *
	 * @param array $activiteiten The raw activiteiten data.
	 *
	 * @return array<int, array<string, mixed>> The parsed activiteiten.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-verzoek-payload-parsing-req-dso-004
	 */
	private function parseActiviteiten(array $activiteiten): array {
		$parsed = [];
		foreach ($activiteiten as $activity) {
			if (is_array($activity) === false) {
				continue;
			}

			$entry = $this->activityIdentifiers(activity: $activity);
			$entry['volgnr'] = $this->scalarText(value: ($activity['volgnr'] ?? null));
			$entry['underlying'] = null;
			if (is_array($activity['underlying'] ?? null) === true) {
				$entry['underlying'] = $this->activityIdentifiers(activity: $activity['underlying']);
			}

			$parsed[] = $entry;
		}

		return $parsed;
	}//end parseActiviteiten()

	/**
	 * The three identifying fields of one activity, `code` and `omschrijving` as fallbacks.
	 *
	 * @param array<string, mixed> $activity The raw activity.
	 *
	 * @return array{imowId: string|null, activityId: string|null, activityName: string|null} The fields.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-1.2
	 */
	private function activityIdentifiers(array $activity): array {
		return [
			'imowId' => $this->scalarText(value: ($activity['imowId'] ?? null)),
			'activityId' => $this->scalarText(
				value: ($activity['activityId'] ?? ($activity['code'] ?? ($activity['activiteitCode'] ?? null)))
			),
			'activityName' => $this->scalarText(value: ($activity['activityName'] ?? ($activity['omschrijving'] ?? null))),
		];
	}//end activityIdentifiers()

	/**
	 * A scalar as trimmed text, null when absent, empty or not a scalar.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text.
	 */
	private function scalarText(mixed $value): ?string {
		if (is_scalar($value) === false || trim((string)$value) === '') {
			return null;
		}

		return trim((string)$value);
	}//end scalarText()

	/**
	 * Parse the bijlagen references into a fixed `{name, url}` shape.
	 *
	 * The STAM payload names a bijlage with `naam` and serves it at `url`. When
	 * `naam` is absent the last segment of the URL path is the name. An entry
	 * without a URL is kept with an empty `url`, so the download job records it
	 * as failed instead of losing it.
	 *
	 * @param mixed $attachments The raw bijlagen data.
	 *
	 * @return array<int, array{name: string, url: string}> The parsed references.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/tasks.md#task-1.2
	 */
	private function parseAttachments(mixed $attachments): array {
		if (is_array($attachments) === false) {
			return [];
		}

		$parsed = [];
		foreach (array_values($attachments) as $index => $attachment) {
			if (is_array($attachment) === false) {
				continue;
			}

			$url = trim((string)($attachment['url'] ?? ''));
			$parsed[] = ['name' => $this->attachmentName(attachment: $attachment, position: ($index + 1)), 'url' => $url];
		}

		return $parsed;
	}//end parseAttachments()

	/**
	 * An attachment's name: its own, else its URL's file name, else `bijlage-<position>`.
	 *
	 * @param array $attachment The attachment.
	 * @param int   $position   Its position among the bijlagen, from 1.
	 *
	 * @return string The name.
	 */
	private function attachmentName(array $attachment, int $position): string {
		$name = $this->givenOrUrlName(attachment: $attachment);
		if ($name === '' || $name === '.' || $name === '/') {
			return 'bijlage-' . $position;
		}

		return $name;
	}//end attachmentName()

	/**
	 * The attachment's own name, else the file name in its URL.
	 *
	 * @param array $attachment The attachment.
	 *
	 * @return string The name, possibly empty.
	 */
	private function givenOrUrlName(array $attachment): string {
		$name = trim((string)($attachment['naam'] ?? ''));
		$url = trim((string)($attachment['url'] ?? ''));
		if ($name === '' && $url !== '') {
			return basename((string)parse_url($url, PHP_URL_PATH));
		}

		return $name;
	}//end givenOrUrlName()

	/**
	 * Convert a GML geometry string to GeoJSON.
	 *
	 * Handles common GML point format (gml:pos). For full GML polygon
	 * and complex geometry support a dedicated geometry library is needed.
	 *
	 * @param string $gml The GML geometry string.
	 *
	 * @return array|null The GeoJSON geometry object, or null if conversion fails.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
	 *
	 * @SuppressWarnings(PHPMD.UndefinedVariable) $matches is populated by reference by preg_match.
	 */
	private function convertGMLToGeoJSON(string $gml): ?array {
		$matchCount = preg_match(
			pattern: '/<gml:pos>([\d.]+)\s+([\d.]+)<\/gml:pos>/',
			subject: $gml,
			matches: $matches
		);

		if ($matchCount === 1) {
			return [
				'type' => 'Point',
				'coordinates' => [
					(float)$matches[2],
					(float)$matches[1],
				],
			];
		}

		$this->logger->info('DSO: GML conversion not fully implemented for complex geometries');
		return null;
	}//end convertGMLToGeoJSON()
}//end class
