<?php
/**
 * WOO-552 — container-side integratie-test.
 *
 * Draait tegen de EXACTE PHP-runtime die nextcloud.local ook gebruikt:
 * dezelfde OpenConnector autoload, dezelfde class-files, dezelfde
 * dependencies. Bewijst dat de fix niet alleen in het testframework
 * werkt, maar ook in de omgeving waar de app straks documenten
 * download.
 *
 * Uitvoeren:
 *   docker exec master-nextcloud-1 php \
 *     /var/www/html/apps-extra/openconnector/tests/manual/nextcloud-local-integration.php
 *
 * Deze file is met opzet in tests/manual/ gezet — die map wordt door
 * PHPUnit (phpunit.xml wijst naar tests/unit) niet opgepikt en zit
 * dus niet in de reguliere test-run. Het is een one-off verificatie
 * die je met de hand triggert.
 */

require '/var/www/html/apps-extra/openconnector/vendor/autoload.php';

use OCA\OpenConnector\Service\SynchronizationService;
use Psr\Log\NullLogger;

echo "═══════════════════════════════════════════════════════════════════\n";
echo " WOO-552 — Container-side integratie-test (nextcloud.local runtime)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "PHP:              " . PHP_VERSION . "\n";
echo "Host:             " . gethostname() . "\n";
echo "Autoload:         /var/www/html/apps-extra/openconnector/vendor/autoload.php\n";
echo "Class-file:       " . (new ReflectionClass(SynchronizationService::class))->getFileName() . "\n\n";

// Reach into the private parser via reflection — same trick als in
// tests/Unit/Service/SynchronizationServiceContentDispositionTest.php,
// maar hier tegen de door NC geladen class-file.
$reflection = new ReflectionClass(SynchronizationService::class);
$service = $reflection->newInstanceWithoutConstructor();
$loggerProperty = $reflection->getProperty('logger');
$loggerProperty->setAccessible(true);
$loggerProperty->setValue($service, new NullLogger());
$method = $reflection->getMethod('parseContentDispositionFilename');
$method->setAccessible(true);

$scenarios = [
    'OUD — filename only (ASCII, regressie-check)' => [
        'header' => 'attachment; filename="bestand.pdf"',
        'expected' => 'bestand.pdf',
    ],
    'NIEUW — RFC 6266 ASCII' => [
        'header' => "attachment; filename=\"bestand.pdf\"; filename*=UTF-8''bestand.pdf",
        'expected' => 'bestand.pdf',
    ],
    'NIEUW — RFC 6266 Unicode (diakriet, naïef.pdf)' => [
        'header' => "attachment; filename=\"naief.pdf\"; filename*=UTF-8''na%C3%AFef.pdf",
        'expected' => 'naïef.pdf',
    ],
    'RFC 6266 — onbekend charset in filename*' => [
        'header' => "attachment; filename=\"safe.pdf\"; filename*=ISO-8859-1''na%EFef.pdf",
        'expected' => 'safe.pdf',
    ],
    'RFC 6266 — unquoted filename' => [
        'header' => 'attachment; filename=onboarding.pdf',
        'expected' => 'onboarding.pdf',
    ],
    'RFC 6266 — case-insensitive FILENAME*' => [
        'header' => "inline; FILENAME*=UTF-8''report-2026.pdf",
        'expected' => 'report-2026.pdf',
    ],
];

printf("%-52s %-18s %-18s %s\n", 'SCENARIO', 'VERWACHT', 'GEKREGEN', 'RESULTAAT');
echo str_repeat('─', 105) . "\n";

$allOk = true;

foreach ($scenarios as $name => $s) {
    $got = $method->invoke($service, $s['header']);
    $ok = $got === $s['expected'];
    if (!$ok) {
        $allOk = false;
    }

    printf(
        "%-52s %-18s %-18s %s\n",
        substr($name, 0, 52),
        substr($s['expected'], 0, 18),
        substr((string) $got, 0, 18),
        $ok ? '✓ PASS' : '✗ FAIL'
    );
}

echo "\n";
echo $allOk
    ? "✓ Alle scenario's PASS — de nextcloud.local PHP-runtime draait de WOO-552 fix correct.\n"
    : "✗ Eén of meer scenario's zijn FAIL — de fix is nog niet actief in deze runtime.\n";

exit($allOk ? 0 : 1);
