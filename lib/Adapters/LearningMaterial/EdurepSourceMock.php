<?php

/**
 * Integriq Edurep learning material source (mock).
 *
 * The Edurep source on the recorded responses: no network access, ever.
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

use Psr\Log\LoggerInterface;

/**
 * Recorded Edurep source.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
 */
final class EdurepSourceMock extends EdurepSource {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logs skipped records.
	 * @param string|null $fixtureDirectory The fixture directory, the app's tests/fixtures/edurep when null.
	 */
	public function __construct(LoggerInterface $logger, ?string $fixtureDirectory = null) {
		parent::__construct(transport: new EdurepFixtureTransport(directory: $fixtureDirectory), logger: $logger);
	}//end __construct()
}//end class
