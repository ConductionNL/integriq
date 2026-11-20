<?php

/**
 * Test bootstrap of the example channel.
 *
 * Loads IntegrIQ's own test bootstrap (its Composer autoloader, the OCP classes
 * and the contract test cases under OCA\Integriq\Tests\Contract), then this
 * app's classes. INTEGRIQ_PATH names an IntegrIQ checkout; by default this
 * skeleton is expected at examples/connector-extension inside one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

$integriq = (getenv('INTEGRIQ_PATH') ?: dirname(__DIR__, 3));
require $integriq . '/tests/bootstrap.php';

/** @var \Composer\Autoload\ClassLoader $autoloader set by IntegrIQ's bootstrap */
$autoloader->addPsr4('OCA\\IntegriqExampleChannel\\', dirname(__DIR__) . '/lib/');
$autoloader->addPsr4('OCA\\IntegriqExampleChannel\\Tests\\', __DIR__ . '/');
