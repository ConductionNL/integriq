<?php

/**
 * Reads the objecttypes leaf apps declare (objecten-api-facade, design D8).
 *
 * A leaf app publishes an objecttype by shipping `lib/Settings/objecttypes.json`
 * in its own app directory, never by a controller of its own and never by
 * writing into integriq's register. This reads that file from every enabled
 * app and hands the declarations on, each tagged with the app that declared
 * it. It checks the file's shape only; the declaration rules are
 * {@see ObjecttypeRegistry}'s, applied the same way to a configured
 * objecttype.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-leaf-app-declares-the-objecttypes-it-publishes-req-oaf-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Objecten;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collects the declared objecttypes of every enabled app.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-leaf-app-declares-the-objecttypes-it-publishes-req-oaf-006
 */
class ObjecttypeDeclarationReader {

	/**
	 * Where an app keeps its declaration, relative to its app path.
	 *
	 * @var string
	 */
	public const DECLARATION_FILE = 'lib/Settings/objecttypes.json';

	/**
	 * Constructor.
	 *
	 * @param IAppManager     $appManager Lists the enabled apps and their paths.
	 * @param LoggerInterface $logger     Records a file that is skipped.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every enabled app's declared objecttypes, in app id order.
	 *
	 * Each declaration carries `declaredBy`, the app it came from. A file that
	 * is not valid JSON, or has no `objecttypes` list, is skipped whole and
	 * logged: the other declarations in it are not guessed at. An entry that
	 * is not an object is passed on as it is, so the registry refuses it with
	 * its reason instead of it vanishing here.
	 *
	 * @return array<int, mixed> The declarations.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-leaf-app-declares-the-objecttypes-it-publishes-req-oaf-006
	 */
	public function read(): array {
		$apps = array_map('strval', $this->appManager->getEnabledApps());
		sort($apps);

		$declarations = [];
		foreach ($apps as $appId) {
			foreach ($this->readApp(appId: $appId) as $declaration) {
				if (is_array($declaration) === true) {
					$declaration['declaredBy'] = $appId;
				}

				$declarations[] = $declaration;
			}
		}

		return $declarations;
	}//end read()

	/**
	 * One app's declarations, or none.
	 *
	 * @param string $appId The app.
	 *
	 * @return array<int, mixed> The declarations in its file.
	 */
	private function readApp(string $appId): array {
		try {
			$path = rtrim($this->appManager->getAppPath($appId), '/') . '/' . self::DECLARATION_FILE;
		} catch (Throwable $exception) {
			return [];
		}

		if (is_file($path) === false) {
			return [];
		}

		$content = json_decode((string)file_get_contents($path), true);
		if (is_array($content) === false || is_array(($content['objecttypes'] ?? null)) === false || array_is_list($content['objecttypes']) === false) {
			$this->logger->warning(
				'Integriq objecten: the objecttype declaration of {app} is skipped, it is not JSON with an objecttypes list.',
				['app' => $appId]
			);
			return [];
		}

		return $content['objecttypes'];
	}//end readApp()
}//end class
