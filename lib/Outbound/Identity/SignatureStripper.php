<?php

/**
 * Integriq SignatureStripper.
 *
 * Stripping is a rendering decision, so it changes the timeline entry and
 * never the stored message. A delivery dispute may need the message exactly
 * as it arrived, and a rendering decision that loses evidence is not a
 * rendering decision any more.
 *
 * Only a detected signature or disclaimer block is removed. Anything the
 * detector is not sure about stays, because removing a line somebody wrote is
 * worse than keeping four lines nobody reads.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
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
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

/**
 * Removes quoted signature and disclaimer blocks from a timeline entry.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class SignatureStripper {

	/**
	 * Lines that open a disclaimer block, in Dutch and in English.
	 *
	 * @var array<int,string>
	 */
	private const DISCLAIMER_MARKERS = [
		'disclaimer',
		'dit bericht is uitsluitend bestemd',
		'deze e-mail is uitsluitend bestemd',
		'this message is intended',
		'this e-mail and any attachments',
		'the information contained in this',
		'aan dit bericht kunnen geen rechten worden ontleend',
		'p denk aan het milieu',
	];

	/**
	 * Strip one message for the timeline.
	 *
	 * @param string $message The message as it arrived.
	 *
	 * @return array{text:string,stripped:bool,original:string} The entry text, whether anything
	 *         was removed, and the original, which the entry offers on demand.
	 */
	public function strip(string $message): array {
		$normalised = str_replace(["\r\n", "\r"], "\n", $message);
		$lines = explode("\n", $normalised);

		$cut = $this->signatureCut(lines: $lines);
		$disclaimerCut = $this->disclaimerCut(lines: $lines);
		if ($disclaimerCut !== null && ($cut === null || $disclaimerCut < $cut)) {
			$cut = $disclaimerCut;
		}

		if ($cut === null) {
			return ['text' => $message, 'stripped' => false, 'original' => $message];
		}

		$kept = rtrim(implode("\n", array_slice($lines, 0, $cut)));

		return ['text' => $kept, 'stripped' => true, 'original' => $message];

	}//end strip()

	/**
	 * Where the RFC 3676 signature separator is, if anywhere.
	 *
	 * @param array<int,string> $lines The message lines.
	 *
	 * @return int|null The line index the signature starts at, or null.
	 */
	private function signatureCut(array $lines): ?int {
		foreach ($lines as $index => $line) {
			if (rtrim($line) === '--' || $line === '-- ') {
				return (int)$index;
			}
		}

		return null;

	}//end signatureCut()

	/**
	 * Where a disclaimer block starts, if anywhere.
	 *
	 * @param array<int,string> $lines The message lines.
	 *
	 * @return int|null The line index, or null.
	 */
	private function disclaimerCut(array $lines): ?int {
		foreach ($lines as $index => $line) {
			$normalised = strtolower(trim($line));
			if ($normalised === '') {
				continue;
			}

			foreach (self::DISCLAIMER_MARKERS as $marker) {
				if (str_starts_with($normalised, $marker) === true) {
					return (int)$index;
				}
			}
		}

		return null;

	}//end disclaimerCut()

}//end class
