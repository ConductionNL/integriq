<?php

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\OpenConnector\Service\SynchronizationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for the RFC 6266 Content-Disposition parser added in WOO-552.
 *
 * The parser lives as a private method on {@see SynchronizationService}
 * because it is only ever consumed by that one caller. We reach into it
 * via reflection so we can validate the RFC 6266 header shapes without
 * standing up the full service graph (mappers, session, container, etc.).
 *
 * Scenarios covered (DoD in WOO-552):
 *   a) `filename` only              — legacy header, must keep working.
 *   b) `filename*` only, UTF-8      — must decode pct-encoded value.
 *   c) both present                 — `filename*` wins per RFC 6266 §4.3.
 *   d) `filename*` Unicode pct-decode — diakriet round-trips correctly.
 *   e) `filename*` unsupported charset — falls back to plain `filename`.
 *
 * @package OCA\OpenConnector\Tests\Unit\Service
 */
class SynchronizationServiceContentDispositionTest extends TestCase
{
    /**
     * Invoke a private method on SynchronizationService without building
     * the full service graph. We only exercise pure string parsing here,
     * so ReflectionClass::newInstanceWithoutConstructor() is sufficient —
     * the parser does not touch any constructor-injected dependency other
     * than the optional logger, which we inject via reflection for the
     * charset-fallback path.
     */
    private function invokeParser(string $headerValue): ?string
    {
        $reflection = new ReflectionClass(SynchronizationService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        // Populate the readonly logger property so the unsupported-charset
        // fallback in decodeRfc5987ExtendedValue() can call ->info(...).
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($service, $this->createMock(LoggerInterface::class));

        $method = $reflection->getMethod('parseContentDispositionFilename');
        $method->setAccessible(true);

        return $method->invoke($service, $headerValue);
    }

    public function testFilenameOnlyAsciiRoundTrips(): void
    {
        $header = 'attachment; filename="bestand.pdf"';
        $this->assertSame('bestand.pdf', $this->invokeParser($header));
    }

    public function testFilenameStarOnlyUtf8IsDecoded(): void
    {
        // RFC 5987 extended value form: charset '' language '' pct-encoded.
        $header = "attachment; filename*=UTF-8''bestand.pdf";
        $this->assertSame('bestand.pdf', $this->invokeParser($header));
    }

    public function testFilenameStarWinsOverPlainFilenameWhenBothPresent(): void
    {
        // This is the exact xxllnc post-2026-08-19 shape that broke the
        // pre-WOO-552 naive `explode('=', $header)` extractor.
        $header = 'attachment; filename="fallback.pdf"; filename*=UTF-8\'\'preferred.pdf';
        $this->assertSame('preferred.pdf', $this->invokeParser($header));
    }

    public function testFilenameStarWithUnicodePctEncodingDecodesToUtf8(): void
    {
        // "na\xC3\xAFef.pdf" pct-encoded — the diakriet-carrying case that
        // motivated xxllnc to adopt filename* in the first place.
        $header = "attachment; filename=\"naief.pdf\"; filename*=UTF-8''na%C3%AFef.pdf";
        $this->assertSame('naïef.pdf', $this->invokeParser($header));
    }

    public function testFilenameStarWithUnsupportedCharsetFallsBackToFilename(): void
    {
        // Charsets other than UTF-8 (e.g. legacy ISO-8859-1) are not
        // decoded; RFC 5987 mandates support only for UTF-8, so we fall
        // back to the plain `filename` parameter which is guaranteed ASCII.
        $header = "attachment; filename=\"safe.pdf\"; filename*=ISO-8859-1''na%EFef.pdf";
        $this->assertSame('safe.pdf', $this->invokeParser($header));
    }

    public function testFilenameStarWithUnsupportedCharsetAndNoFilenameReturnsNull(): void
    {
        // Defensive: if the extended value is unusable AND there is no
        // plain `filename`, the parser must return null so the caller
        // knows to fall back to its URL/MIME-based path.
        $header = "attachment; filename*=ISO-8859-1''na%EFef.pdf";
        $this->assertNull($this->invokeParser($header));
    }

    public function testFilenameParameterNameIsCaseInsensitive(): void
    {
        // RFC 6266 explicitly allows case-insensitive parameter names.
        $header = 'attachment; FileName="bestand.pdf"';
        $this->assertSame('bestand.pdf', $this->invokeParser($header));
    }

    public function testFilenameStarParameterNameIsCaseInsensitive(): void
    {
        $header = "attachment; FILENAME*=UTF-8''bestand.pdf";
        $this->assertSame('bestand.pdf', $this->invokeParser($header));
    }

    public function testUnquotedFilenameIsAccepted(): void
    {
        // Token form (no surrounding quotes) is permitted by RFC 6266
        // when the filename contains no separators — real-world servers
        // do emit this shape.
        $header = 'attachment; filename=bestand.pdf';
        $this->assertSame('bestand.pdf', $this->invokeParser($header));
    }

    public function testHeaderWithoutAnyFilenameReturnsNull(): void
    {
        // `inline` disposition with no filename parameter — the parser is
        // only called when the caller has already seen the substring
        // "filename" in the header, but even so we assert the null path
        // to guard against future refactors of the calling contract.
        $header = 'inline';
        $this->assertNull($this->invokeParser($header));
    }

    public function testFilenameWithSemicolonInsideQuotedValuePreservesFilename(): void
    {
        // RFC 6266 §4 quoted-string grammar: a `;` between quotes is part
        // of the value, not a parameter separator. A naive
        // `explode(';', $header)` corrupts this to just `foo`; the
        // quoted-string-aware tokenizer preserves the full filename.
        $header = 'attachment; filename="foo;bar.pdf"';
        $this->assertSame('foo;bar.pdf', $this->invokeParser($header));
    }

    public function testFilenameWithPathTraversalPayloadIsReturnedVerbatim(): void
    {
        // Contract: the parser extracts the filename as declared upstream;
        // path-separator / `..` sanitization is the responsibility of the
        // downstream FileService::saveFile() writer. Locking this contract
        // guards against a future refactor silently sanitizing here (which
        // would hide malicious input from the writer's audit surface).
        $header = 'attachment; filename="../../etc/passwd"';
        $this->assertSame('../../etc/passwd', $this->invokeParser($header));
    }

    public function testFilenameWithWhitespaceAroundEqualsIsAccepted(): void
    {
        // Well-behaved servers do not emit whitespace around `=`, but the
        // tokenizer's `trim()` handles it gracefully. Locks the behaviour
        // so a future refactor does not silently regress it.
        $header = 'attachment; filename = "bestand.pdf"';
        $this->assertSame('bestand.pdf', $this->invokeParser($header));
    }
}
