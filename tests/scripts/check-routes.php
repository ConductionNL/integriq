<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI gate: every route declared in appinfo/routes.php must point at an
 * existing public method on the named controller. Catches the class of
 * bug that surfaced during the chain-C live E2E run, where the
 * controller-shrink agent deleted action methods (statistics, test) but
 * left their routes orphaned in routes.php — causing 500 errors with
 * `Method ::X() does not exist`.
 *
 * Wired into composer check:strict via `composer check:routes`.
 *
 * Exits 0 (PASS) when every route's controller method exists.
 * Exits 1 (FAIL) and prints the orphan routes when any are found.
 */

$routesFile = __DIR__ . '/../../appinfo/routes.php';
if (!file_exists($routesFile)) {
    fwrite(STDERR, "check:routes: appinfo/routes.php not found\n");
    exit(2);
}

$config = require $routesFile;
$routes = $config['routes'] ?? [];
$resources = $config['resources'] ?? [];

$missing = [];

// Resource auto-routes: Nextcloud expands each `resources` entry into 5
// CRUD routes (index/show/create/update/destroy) on the named controller.
// If any of those methods are missing, the auto-generated route 500s on
// hit — which is exactly the trap chain-C fell into.
$resourceActions = ['index', 'show', 'create', 'update', 'destroy'];
foreach ($resources as $name => $_def) {
    $candidates = array_unique([
        $name . 'Controller.php',
        ucfirst($name) . 'Controller.php',
        strtoupper($name) . 'Controller.php',
    ]);
    $class = null;
    foreach ($candidates as $candidate) {
        $candidatePath = __DIR__ . '/../../lib/Controller/' . $candidate;
        if (file_exists($candidatePath)) {
            $class = $candidatePath;
            break;
        }
    }
    if ($class === null) {
        $missing[] = "resource '$name' → tried " . implode(' / ', $candidates) . ' (none exists)';
        continue;
    }
    $src = file_get_contents($class);
    foreach ($resourceActions as $action) {
        if (!preg_match('/public function ' . $action . '\b/', $src)) {
            $missing[] = "resource '$name' → method " . basename($class, '.php') . "::$action() does not exist";
        }
    }
}

foreach ($routes as $route) {
    if (!isset($route['name'])) {
        continue;
    }
    if (!str_contains($route['name'], '#')) {
        continue;
    }
    [$ctl, $method] = explode('#', $route['name'], 2);
    // Nextcloud's route → controller resolution is case-insensitive on the
    // controller segment. Try common transforms: ucfirst (lowerCamel →
    // PascalCase) and all-uppercase (e.g. dso → DSO).
    $candidates = array_unique([
        ucfirst($ctl) . 'Controller.php',
        strtoupper($ctl) . 'Controller.php',
    ]);
    $class = null;
    foreach ($candidates as $candidate) {
        $candidatePath = __DIR__ . '/../../lib/Controller/' . $candidate;
        if (file_exists($candidatePath)) {
            $class = $candidatePath;
            break;
        }
    }
    if ($class === null) {
        $missing[] = $route['name'] . ' → tried ' . implode(' / ', $candidates) . ' (none exists)';
        continue;
    }
    $src = file_get_contents($class);
    if (!preg_match('/public function ' . preg_quote($method, '/') . '\b/', $src)) {
        $missing[] = $route['name'] . ' → method ' . ucfirst($ctl) . 'Controller::' . $method . '() does not exist';
    }
}

if ($missing !== []) {
    echo "check:routes: FAIL — orphan route(s) pointing at deleted method(s):\n";
    foreach ($missing as $m) {
        echo '  - ' . $m . PHP_EOL;
    }
    echo "\nFix options: re-add the method on the controller, or remove the orphan route from appinfo/routes.php.\n";
    exit(1);
}

echo 'check:routes: PASS — all ' . count($routes) . " routes point at existing controller methods.\n";
exit(0);
