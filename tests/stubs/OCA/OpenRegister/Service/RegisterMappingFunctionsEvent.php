<?php

/**
 * Declaration-only stub for OpenRegister's RegisterMappingFunctionsEvent.
 *
 * Stands in for the real class when OpenRegister is not installed (standalone
 * CI), so psalm can resolve MappingFunctionRegistrationListener and the
 * addServiceListener call in Application.
 *
 * Mirrors the real class's surface exactly and no further. The real
 * registerFunction() also allowlists the name for the Twig sandbox; that is
 * OpenRegister's concern and is deliberately NOT modelled here — a stub that
 * pretends to do more than declare a signature is how a green local run turns
 * into a red CI leg.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\EventDispatcher\Event;
use Twig\TwigFunction;

/**
 * Minimal RegisterMappingFunctionsEvent stub for standalone static analysis.
 */
class RegisterMappingFunctionsEvent extends Event
{

    /**
     * Contribute one Twig function to the mapping engine.
     *
     * @param TwigFunction $function The function to expose to mapping templates.
     *
     * @return void
     */
    public function registerFunction(TwigFunction $function): void
    {

    }//end registerFunction()

    /**
     * Every contributed function.
     *
     * @return array<int, TwigFunction> The functions.
     */
    public function getFunctions(): array
    {
        return [];

    }//end getFunctions()

    /**
     * The names to add to the sandbox allowlist.
     *
     * @return array<int, string> The allowed function names.
     */
    public function getAllowedNames(): array
    {
        return [];

    }//end getAllowedNames()
}//end class
