<?php

/**
 * Integriq LOM record reader.
 *
 * Maps one IMS LOM 1.2 record from Edurep to a normalised learning material
 * record: identity, makers, licence, access rights, levels and subjects.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

/**
 * Reads LOM records into material records.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */
final class LomRecordReader {
	public const SOURCE = 'edurep';

	private const CONTRIBUTOR_CARDS = 'c:lifecycle/c:contribute[c:role/c:value/c:langstring="%s"]/c:centity/c:vcard';
	private const TAXON_PATH = 'c:classification[c:purpose/c:value/c:langstring="%s"]/c:taxonpath/c:taxon/';

	/**
	 * The NL-LOM educational context codes a record's levels are kept to.
	 *
	 * @var list<string>
	 */
	public const LEVELS = ['PO', 'VO', 'SO', 'BVE', 'HBO', 'WO', 'HO'];

	/**
	 * First word of an educational level label to its context code.
	 *
	 * @var array<string,string>
	 */
	private const LEVEL_WORDS = [
		'primair' => 'PO',
		'voortgezet' => 'VO',
		'speciaal' => 'SO',
		'mbo' => 'BVE',
		'hbo' => 'HBO',
		'wo' => 'WO',
		'hoger' => 'HO',
	];

	/**
	 * Access rights values a record may carry.
	 *
	 * @var list<string>
	 */
	private const ACCESS_RIGHTS = ['OpenAccess', 'RestrictedAccess', 'ClosedAccess'];

	/**
	 * Video page patterns to the embed URL template of their player.
	 *
	 * @var array<string,string>
	 */
	private const EMBED_PATTERNS = [
		'~(?:youtube\.com/watch\?(?:[^#]*&)?v=|youtu\.be/)([\w-]{11})~' => 'https://www.youtube-nocookie.com/embed/%s',
		'~vimeo\.com/(\d+)~' => 'https://player.vimeo.com/video/%s',
	];

	/**
	 * Read one record.
	 *
	 * @param string $recordIdentifier The SRU record identifier, `<collection>:<identifier>`.
	 * @param string $xml The LOM record's XML.
	 *
	 * @return MaterialRecord|null The record, or null when the XML is no LOM record.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public function read(string $recordIdentifier, string $xml): ?MaterialRecord {
		$root = (new SafeXml())->root(xml: $xml, namespace: LomDocument::NAMESPACE_URI);
		if ($root === null || $root->localName !== 'lom') {
			return null;
		}

		$lom = new LomDocument(root: $root);
		$fields = array_merge(
			$this->identity(lom: $lom, recordIdentifier: $recordIdentifier),
			$this->rights(lom: $lom),
			['authors' => $this->authors(lom: $lom), 'levels' => $this->levels(lom: $lom)],
			['disciplines' => $lom->values(path: sprintf(self::TAXON_PATH, 'discipline') . 'c:entry/c:langstring')]
		);
		$fields['files'] = $this->files(lom: $lom, accessRights: $fields['accessRights']);

		return new MaterialRecord(fields: $fields);
	}//end read()

	/**
	 * The licence code from a rights value and the URL in the rights description.
	 *
	 * `no` means all rights reserved; `yes` means rights apply and the description says which, so the URL decides.
	 *
	 * @param string $value The `copyrightandotherrestrictions` value.
	 * @param string|null $url The URL in the rights description.
	 *
	 * @return LicenseCode The code.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) LicenseCode's named constructors are pure functions over the value.
	 */
	public function license(string $value, ?string $url): LicenseCode {
		if (strtolower(trim($value)) === 'no') {
			return LicenseCode::RIGHTS_RESERVED;
		}

		$code = (LicenseCode::fromEdurep(value: $value) ?? LicenseCode::fromUrl(url: (string)$url));

		return ($code ?? LicenseCode::UNKNOWN);
	}//end license()

	/**
	 * The embed URL of a video page on a known player, or null.
	 *
	 * @param string $url The page URL.
	 *
	 * @return string|null The embed URL.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public function embedUrl(string $url): ?string {
		foreach (self::EMBED_PATTERNS as $pattern => $template) {
			if (preg_match($pattern, $url, $match) === 1) {
				return sprintf($template, $match[1]);
			}
		}

		return null;
	}//end embedUrl()

	/**
	 * Identity, location and type fields.
	 *
	 * @param LomDocument $lom The record.
	 * @param string $recordIdentifier The SRU record identifier.
	 *
	 * @return array<string,mixed> The fields.
	 */
	private function identity(LomDocument $lom, string $recordIdentifier): array {
		[$collection, $identifier] = array_pad(explode(':', $recordIdentifier, 2), 2, '');
		$url = $lom->first(path: 'c:technical/c:location');
		$entries = $lom->values(path: 'c:general/c:catalogentry/c:entry/c:langstring');

		return [
			'sourceId' => ($entries[0] ?? $identifier),
			'title' => $lom->first(path: 'c:general/c:title/c:langstring'),
			'description' => $lom->first(path: 'c:general/c:description/c:langstring'),
			'url' => $url,
			'embedUrl' => $this->embedUrl(url: $url),
			'materialType' => $lom->first(path: 'c:technical/c:format'),
			'language' => $lom->first(path: 'c:general/c:language'),
			'collection' => $collection,
			'published' => $this->published(lom: $lom),
			'source' => self::SOURCE,
		];
	}//end identity()

	/**
	 * Licence, licence URL and access rights.
	 *
	 * @param LomDocument $lom The record.
	 *
	 * @return array{license: LicenseCode, licenseUrl: string|null, accessRights: string|null} The fields.
	 */
	private function rights(LomDocument $lom): array {
		$description = $lom->first(path: 'c:rights/c:description/c:langstring');
		$url = null;
		if (preg_match('~https?://\S+~', $description, $match) === 1) {
			$url = $match[0];
		}

		$access = array_values(array_intersect($lom->values(path: sprintf(self::TAXON_PATH, 'access rights') . 'c:id'), self::ACCESS_RIGHTS));

		return [
			'license' => $this->license(value: $lom->first(path: 'c:rights/c:copyrightandotherrestrictions/c:value/c:langstring'), url: $url),
			'licenseUrl' => $url,
			'accessRights' => ($access[0] ?? null),
		];
	}//end rights()

	/**
	 * The makers: the authors, or the publishers when the record names no author.
	 *
	 * @param LomDocument $lom The record.
	 *
	 * @return list<string> The names.
	 */
	private function authors(LomDocument $lom): array {
		$authors = $this->namesInRole(lom: $lom, role: 'author');
		if ($authors !== []) {
			return $authors;
		}

		return $this->namesInRole(lom: $lom, role: 'publisher');
	}//end authors()

	/**
	 * The vCard `FN` names of the contributors in one role.
	 *
	 * @param LomDocument $lom The record.
	 * @param string $role The LOM contribute role.
	 *
	 * @return list<string> The names.
	 */
	private function namesInRole(LomDocument $lom, string $role): array {
		$names = [];
		foreach ($lom->values(path: sprintf(self::CONTRIBUTOR_CARDS, $role)) as $card) {
			if (preg_match('/^FN:(.+)$/m', $card, $match) === 1) {
				$names[] = trim($match[1]);
			}
		}

		return array_values(array_unique($names));
	}//end namesInRole()

	/**
	 * The context codes from the educational context and the educational level labels.
	 *
	 * @param LomDocument $lom The record.
	 *
	 * @return list<string> The codes.
	 */
	private function levels(LomDocument $lom): array {
		$codes = $lom->values(path: 'c:educational/c:context/c:value/c:langstring');
		foreach ($lom->values(path: sprintf(self::TAXON_PATH, 'educational level') . 'c:entry/c:langstring') as $label) {
			$word = strtolower((string)preg_replace('/^(\p{L}+).*$/us', '$1', $label));
			$codes[] = (self::LEVEL_WORDS[$word] ?? '');
		}

		return array_values(array_intersect(self::LEVELS, $codes));
	}//end levels()

	/**
	 * The record's locations as files, when its format is a file rather than a page or a player.
	 *
	 * @param LomDocument $lom The record.
	 * @param string|null $accessRights The record's access rights.
	 *
	 * @return list<array{url: string, format: string, accessRights: string|null}> The files.
	 */
	private function files(LomDocument $lom, ?string $accessRights): array {
		$format = $lom->first(path: 'c:technical/c:format');
		if (preg_match('~^(application/(?!x-wikiwijs|x-toets)|image/|audio/|text/plain)~i', $format) !== 1) {
			return [];
		}

		$files = [];
		foreach ($lom->values(path: 'c:technical/c:location') as $location) {
			$files[] = ['url' => $location, 'format' => $format, 'accessRights' => $accessRights];
		}

		return $files;
	}//end files()

	/**
	 * The date of the first contribution, as `YYYY-MM-DD`.
	 *
	 * @param LomDocument $lom The record.
	 *
	 * @return string|null The date, or null when the record has none.
	 */
	private function published(LomDocument $lom): ?string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $lom->first(path: 'c:lifecycle/c:contribute/c:date/c:datetime'), $match) === 1) {
			return $match[0];
		}

		return null;
	}//end published()
}//end class
