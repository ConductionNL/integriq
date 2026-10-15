#!/usr/bin/env php
<?php
/**
 * Removes packages that Nextcloud core already ships from a no-dev vendor tree.
 *
 * Runs from composer's post-install-cmd and does nothing unless composer runs
 * with --no-dev (COMPOSER_DEV_MODE=0), which is how the release workflow
 * installs. Composer prepends every app's autoloader in front of core's
 * 3rdparty autoloader, so a vendored copy of a core package replaces core's
 * copy for the whole instance: integriq's symfony/console 7.4 made every occ
 * command of every other app fatal on NC 32 (#2433).
 *
 * The package list lives in composer.json under extra.nextcloud-core-provided.
 * After removal the autoloader is dumped again so no classmap, PSR-4 or
 * files entry points at a removed package.
 *
 * Usage: php scripts/prune-core-provided-vendor.php [<project-dir>] [--no-dump]
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Scripts
 * @package  OCA\Integriq\Scripts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/integriq
 */

declare(strict_types=1);

/**
 * Removes the given packages from a vendor tree and its installed metadata.
 *
 * @param string   $vendorDir Absolute vendor directory.
 * @param string[] $packages  Package names to remove.
 *
 * @return string[] Names of the packages that were installed and are now removed.
 */
function pruneCoreProvidedPackages(string $vendorDir, array $packages): array {
	$installedJson = $vendorDir.'/composer/installed.json';
	$installed     = json_decode((string) file_get_contents($installedJson), true, 512, JSON_THROW_ON_ERROR);
	$drop          = array_flip($packages);
	$removed       = [];

	$kept = [];
	foreach ($installed['packages'] as $package) {
		if (isset($drop[$package['name']]) === true) {
			$removed[] = $package['name'];
			continue;
		}

		$kept[] = $package;
	}

	$installed['packages']           = $kept;
	$installed['dev-package-names'] = array_values(
		array_diff($installed['dev-package-names'] ?? [], $removed)
	);
	file_put_contents(
		$installedJson,
		json_encode($installed, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))."\n"
	);

	$installedPhp = $vendorDir.'/composer/installed.php';
	if (is_file($installedPhp) === true) {
		$versions = include $installedPhp;
		foreach ($removed as $name) {
			unset($versions['versions'][$name]);
		}

		file_put_contents($installedPhp, '<?php return '.var_export($versions, true).";\n");
	}

	foreach ($removed as $name) {
		removeDirectory($vendorDir.'/'.$name);
		$vendorNamespace = dirname($vendorDir.'/'.$name);
		if (is_dir($vendorNamespace) === true && count((array) scandir($vendorNamespace)) === 2) {
			rmdir($vendorNamespace);
		}
	}

	return $removed;
}//end pruneCoreProvidedPackages()

/**
 * Recursively removes a directory.
 *
 * @param string $path Directory to remove.
 *
 * @return void
 */
function removeDirectory(string $path): void {
	if (is_dir($path) === false || is_link($path) === true) {
		if (file_exists($path) === true || is_link($path) === true) {
			unlink($path);
		}

		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($items as $item) {
		if ($item->isDir() === true && $item->isLink() === false) {
			rmdir($item->getPathname());
			continue;
		}

		unlink($item->getPathname());
	}

	rmdir($path);
}//end removeDirectory()

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
	return;
}

if (getenv('COMPOSER_DEV_MODE') !== '0') {
	echo "prune-core-provided-vendor: dev install, vendor left as is\n";
	exit(0);
}

$args       = array_slice($argv, 1);
$noDump     = in_array('--no-dump', $args, true);
$positional = array_values(array_filter($args, static fn (string $arg): bool => str_starts_with($arg, '--') === false));
$projectDir = rtrim(($positional[0] ?? dirname(__DIR__)), '/');

$composerJson = json_decode((string) file_get_contents($projectDir.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$packages     = $composerJson['extra']['nextcloud-core-provided'] ?? [];
$vendorDir    = $projectDir.'/'.($composerJson['config']['vendor-dir'] ?? 'vendor');

$removed = pruneCoreProvidedPackages($vendorDir, $packages);
echo 'prune-core-provided-vendor: removed '.count($removed).' package(s) Nextcloud core provides: '.implode(', ', $removed)."\n";

if ($noDump === true) {
	exit(0);
}

$composer = getenv('COMPOSER_BINARY');
$command  = ($composer !== false && $composer !== '') ? escapeshellarg(PHP_BINARY).' '.escapeshellarg($composer) : 'composer';
passthru(
	$command.' dump-autoload --no-dev --classmap-authoritative --no-scripts --working-dir='.escapeshellarg($projectDir),
	$exitCode
);
exit($exitCode);
