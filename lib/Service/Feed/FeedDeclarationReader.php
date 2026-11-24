<?php

/**
 * Integriq FeedDeclarationReader.
 *
 * Reads `lib/Settings/feeds.json` from an enabled app, checks it and each
 * declared dataset against the schemas they name, and says why a file or a
 * dataset is refused.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Feed
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
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Feed;

use OCA\Integriq\Service\FeedDeclarationService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and checks the feed declarations of apps.
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */
class FeedDeclarationReader {

	/**
	 * Constructor.
	 *
	 * @param IAppManager               $appManager   Lists enabled apps and finds their files.
	 * @param FeedDeclarationValidator  $validator    Checks a declaration file.
	 * @param FeedSchemaFieldsInterface $schemaFields Says which fields a schema declares.
	 * @param LoggerInterface           $logger       Logs a skipped declaration file.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly FeedDeclarationValidator $validator,
		private readonly FeedSchemaFieldsInterface $schemaFields,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The ids of the enabled apps.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function enabledApps(): array {
		return array_values(array_map('strval', $this->appManager->getEnabledApps()));
	}//end enabledApps()

	/**
	 * The feeds an app declares: none for an app that is not enabled, null
	 * when its file cannot be read.
	 *
	 * @param string             $app     The app.
	 * @param array<int, string> $enabled The enabled apps.
	 * @param array              $summary The running summary.
	 *
	 * @return array|null The feed entries.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function entriesOf(string $app, array $enabled, array &$summary): ?array {
		if (in_array($app, $enabled, true) === false) {
			return [];
		}

		return $this->readDeclaration(app: $app, summary: $summary);
	}//end entriesOf()

	/**
	 * Why a dataset is refused, or null when it can be served.
	 *
	 * @param mixed   $entry The dataset.
	 * @param integer $index Its position in the file.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function refusalOf(mixed $entry, int $index): ?string {
		$errors = $this->validator->feedErrors(feed: $entry, index: $index);
		if ($errors !== []) {
			return implode('; ', $errors);
		}

		$declared = $this->schemaFields->fieldsOf(register: (string)$entry['register'], schema: (string)$entry['schema']);
		if ($declared === null) {
			return sprintf('the register "%s" has no schema "%s"', $entry['register'], $entry['schema']);
		}

		$needed = array_merge($entry['fields'], [$entry['scope']['field']], array_keys(($entry['filter'] ?? [])));
		$missing = array_values(array_unique(array_diff($needed, $declared)));
		if ($missing !== []) {
			return sprintf('the schema "%s" does not declare %s', $entry['schema'], implode(', ', $missing));
		}

		return null;
	}//end refusalOf()

	/**
	 * Read and check an app's declaration file.
	 *
	 * @param string               $app     The app id.
	 * @param array<string, mixed> $summary The running summary; a refused file is recorded in it.
	 *
	 * @return array<int, mixed>|null The datasets; [] when the app ships no file; null when the file is refused.
	 */
	private function readDeclaration(string $app, array &$summary): ?array {
		$file = $this->declarationPath(app: $app);
		if ($file === null) {
			return [];
		}

		$data = (new FeedDeclarationShape())->normalize(data: json_decode((string)file_get_contents($file), true));
		$errors = $this->declarationErrors(app: $app, data: $data);
		if ($errors !== []) {
			$summary['refused'][$app] = implode('; ', $errors);
			$this->logger->error(
				'Integriq skipped the feeds.json of {declaringApp}: {errors}',
				['app' => 'integriq', 'declaringApp' => $app, 'errors' => implode('; ', $errors)]
			);
			return null;
		}

		return $data['feeds'];
	}//end readDeclaration()

	/**
	 * The problems of a decoded declaration file: not JSON, malformed, or
	 * claiming another app's id. Call right after json_decode().
	 *
	 * @param string $app  The declaring app.
	 * @param mixed  $data The decoded file.
	 *
	 * @return array<int, string> The problems.
	 */
	private function declarationErrors(string $app, mixed $data): array {
		$errors = $this->validator->fileErrors(data: $data);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ['it is not valid JSON (' . json_last_error_msg() . ')'];
		}

		if ($errors === [] && isset($data['app']) === true && $data['app'] !== $app) {
			return [sprintf('it claims the app id "%s"', $data['app'])];
		}

		return $errors;
	}//end declarationErrors()

	/**
	 * The readable declaration file of an app, or null when it has none.
	 *
	 * @param string $app The app id.
	 *
	 * @return string|null
	 */
	private function declarationPath(string $app): ?string {
		$path = $this->appFile(app: $app);
		if ($path !== null && is_file($path) === true && is_readable($path) === true) {
			return $path;
		}

		return null;
	}//end declarationPath()

	/**
	 * Where an app's declaration file would be, or null when the app has no path.
	 *
	 * @param string $app The app id.
	 *
	 * @return string|null
	 */
	private function appFile(string $app): ?string {
		try {
			return rtrim($this->appManager->getAppPath($app), '/') . '/' . FeedDeclarationService::DECLARATION_FILE;
		} catch (Throwable) {
			return null;
		}
	}//end appFile()
}//end class
