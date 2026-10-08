<?php

/**
 * Stub for OCA\OpenRegister\Db\ConsumerMapper.
 *
 * OpenRegister's AuthorizationService takes its own consumer table's mapper as
 * a required constructor argument. integriq always passes its own consumer
 * source, so the mapper is never read: this stand-in only lets the real
 * service be constructed in pure-unit mode. When OpenRegister is installed the
 * real mapper wins (the bootstrap guards with class_exists()).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal stub for OCA\OpenRegister\Db\ConsumerMapper.
 */
class ConsumerMapper {
}
