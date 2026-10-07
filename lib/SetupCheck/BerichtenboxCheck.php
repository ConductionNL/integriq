<?php

/**
 * Integriq — the Berichtenbox setup check.
 *
 * @category SetupCheck
 * @package  OCA\Integriq\SetupCheck
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\SetupCheck;

use DateTimeImmutable;
use OCA\Integriq\Service\DigitalPost\BerichtenboxHealth;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Warns about letters still waiting for a Logius result after 24 hours, and
 * about a certificate that is missing, unusable or expires within 30 days. It
 * changes nothing: a waiting letter keeps its status (D7).
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-logius-results-decide-the-status-and-a-berichtenbox-letter-is-never-read-req-dpa-011
 */
class BerichtenboxCheck implements ISetupCheck {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the health service lazily, so the check runs without OpenRegister.
	 * @param IL10N $l10n The texts.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The category on the overview page.
	 *
	 * @return string
	 */
	public function getCategory(): string {
		return 'system';
	}//end getCategory()

	/**
	 * The name on the overview page.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->l10n->t('Integriq: MijnOverheid Berichtenbox');
	}//end getName()

	/**
	 * Run the check.
	 *
	 * @return SetupResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SetupResult's named constructors are the only way Nextcloud offers to build one.
	 */
	public function run(): SetupResult {
		try {
			$findings = $this->container->get(BerichtenboxHealth::class)->findings(now: new DateTimeImmutable());
		} catch (Throwable) {
			return SetupResult::success($this->l10n->t('The Berichtenbox needs OpenRegister, which is not available.'));
		}

		if ($findings['sources'] === 0) {
			return SetupResult::success($this->l10n->t('No Berichtenbox source is configured.'));
		}

		$problems = [];
		if ($findings['waiting'] > 0) {
			$problems[] = $this->l10n->n(
				'%n Berichtenbox letter has waited more than 24 hours for a result from Logius. Its status stays sent; check the ebMS adapter and the Leveranciersportaal.',
				'%n Berichtenbox letters have waited more than 24 hours for a result from Logius. Their status stays sent; check the ebMS adapter and the Leveranciersportaal.',
				$findings['waiting']
			);
		}

		foreach ($findings['certificates'] as $certificate) {
			$problems[] = match ($certificate['problem']) {
				'missing' => $this->l10n->t('Berichtenbox source %s has no PKIoverheid certificate. Upload it under Administration settings, Integriq.', [$certificate['slug']]),
				'expires-soon' => $this->l10n->t('The PKIoverheid certificate of Berichtenbox source %1$s expires on %2$s. Upload its successor before then, or every letter is refused.', [$certificate['slug'], $certificate['validTo']]),
				default => $this->l10n->t('The PKIoverheid certificate of Berichtenbox source %1$s cannot be used (%2$s). Every letter is refused until a usable one is uploaded.', [$certificate['slug'], $certificate['problem']]),
			};
		}

		if ($problems === []) {
			return SetupResult::success($this->l10n->t('Every Berichtenbox source has a usable certificate, and no letter waits for a result.'));
		}

		return SetupResult::warning(implode(' ', $problems));
	}//end run()
}//end class
