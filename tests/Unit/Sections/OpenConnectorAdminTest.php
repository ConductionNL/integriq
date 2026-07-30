<?php

/**
 * OpenConnector Admin Section — AppHost adapter tests.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Sections
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Sections;

use OCA\OpenConnector\Sections\OpenConnectorAdmin;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the pre-adoption metadata (id, name, icon, priority) of the
 * AppHost-adopted admin section.
 */
class OpenConnectorAdminTest extends TestCase
{
    /**
     * Build a section instance with mocked collaborators.
     *
     * @param string $name Section display name, as the leaf factory
     *                     translates it before construction.
     *
     * @return OpenConnectorAdmin
     */
    private function makeSection(string $name='Open Connector'): OpenConnectorAdmin
    {
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')->with('openconnector', 'app-dark.svg')->willReturn('/img/app-dark.svg');

        return new OpenConnectorAdmin(
            sectionId: 'openconnector',
            name: $name,
            appId: 'openconnector',
            iconFile: 'app-dark.svg',
            priority: 97,
            urlGenerator: $urlGenerator
        );
    }//end makeSection()

    /**
     * The section id must stay `openconnector`, matching the pre-adoption value.
     *
     * @return void
     */
    public function testSectionIdUnchanged(): void
    {
        $this->assertSame('openconnector', $this->makeSection()->getID());
    }//end testSectionIdUnchanged()

    /**
     * The priority must stay `97`, matching the pre-adoption value.
     *
     * @return void
     */
    public function testPriorityUnchanged(): void
    {
        $this->assertSame(97, $this->makeSection()->getPriority());
    }//end testPriorityUnchanged()

    /**
     * The display name is whatever the leaf factory passes in — this is how
     * translation is preserved even though the generic base class has no
     * l10n hook of its own (the factory calls `IL10N::t()` before
     * constructing the section; see Application::registerAppHostAdminSettings()).
     *
     * @return void
     */
    public function testNameIsPassedThroughUntranslatedByTheGenericItself(): void
    {
        $this->assertSame('Open Connector (translated)', $this->makeSection(name: 'Open Connector (translated)')->getName());
    }//end testNameIsPassedThroughUntranslatedByTheGenericItself()

    /**
     * The icon path resolves via the app's own image path (pre-adoption icon
     * file `app-dark.svg` unchanged).
     *
     * @return void
     */
    public function testIconResolvesViaAppImagePath(): void
    {
        $this->assertSame('/img/app-dark.svg', $this->makeSection()->getIcon());
    }//end testIconResolvesViaAppImagePath()
}//end class
