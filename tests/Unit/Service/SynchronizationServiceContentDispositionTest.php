<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
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
 *   f) parameter names are case-insensitive (`FILENAME*`, `Filename`).
 *   g) unquoted `filename` token and whitespace around `=`.
 *   h) `;` inside a quoted-string stays part of the filename.
 *   i) RFC 9110 quoted-pairs (`\"`, `\\`) are unescaped and an escaped
 *      quote does not end the quoted-string (PR #1840 review).
 *   j) path-traversal payload is returned verbatim — the guard is in
 *      Nextcloud core (`Filesystem::isValidPath()`), not in this parser.
 *   k) empty `filename` / empty, duplicated or quoted `filename*` fall
 *      back instead of yielding `''` or `null` (PR #1840 re-review).
 *   l) lowercase `content-disposition` header key (HTTP/2) is recognised.
 *   m) `filename*` decoding to invalid UTF-8 / control chars, or a whitespace-
 *      only `filename`, falls back (PR #1840 round 4); header lookup skips
 *      unusable values and tolerates a non-array `headers` entry.
 *
 * @package OCA\Integriq\Tests\Unit\Service
 */
class SynchronizationServiceContentDispositionTest extends TestCase
{
    /**
     * Invoke a private method on SynchronizationService without building
     * the full service graph. We only exercise pure string parsing here,
     * so ReflectionClass::newInstanceWithoutConstructor() is sufficient —
     * the parser does not touch any constructor-injected dependency other
     * than the constructor-injected logger, which we inject via reflection for the
     * charset-fallback path.
     */
    private function invokeParser(string $headerValue): ?string
    {
        return $this->invokePrivate('parseContentDispositionFilename', [$headerValue]);
    }

    /**
     * Invoke any private method on a constructor-less SynchronizationService.
     */
    private function invokePrivate(string $method, array $args): mixed
    {
        $reflection = new ReflectionClass(SynchronizationService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        // Populate the readonly logger property so the fallback paths in
        // decodeRfc5987ExtendedValue() can call ->info(...).
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($service, $this->createMock(LoggerInterface::class));

        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($service, $args);
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

    public function testFilenameWithSemicolonAndSpaceInsideQuotedValuePreservesFilename(): void
    {
        // Barry's concrete example on PR #1840. Locks the exact string he
        // raised so the guarantee is explicit in the test suite, not only
        // implied by the more abstract `foo;bar.pdf` case above.
        $header = 'attachment; filename="rapport; versie 2.pdf"';
        $this->assertSame('rapport; versie 2.pdf', $this->invokeParser($header));
    }

    public function testFilenameWithPathTraversalPayloadIsReturnedVerbatim(): void
    {
        // Contract: the parser extracts the filename as declared upstream and
        // does NOT sanitize path separators / `..` — and neither does
        // OpenRegister's FileService::saveFile() → CreateFileHandler. The
        // guard lives in Nextcloud core: Folder::getFullPath() →
        // Filesystem::isValidPath() rejects `/../` with NotPermittedException,
        // so such a sync aborts instead of writing outside the folder.
        // Locking this contract guards against a future refactor silently
        // sanitizing here (which would hide malicious input from the
        // writer's audit surface).
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

    public function testFilenameWithEscapedQuotesInsideQuotedValueIsUnescaped(): void
    {
        // Review case from PR #1840: an RFC 9110 §5.6.4 quoted-pair. The
        // escaped `\"` must not toggle the tokenizer's quote state (or the
        // `;` inside would split the value) and must come back as a
        // literal `"` in the filename.
        $header = 'attachment; filename="rapport \"final\"; versie 2.pdf"';
        $this->assertSame('rapport "final"; versie 2.pdf', $this->invokeParser($header));
    }

    public function testFilenameWithEscapedBackslashInsideQuotedValueIsUnescaped(): void
    {
        // `\\` is the quoted-pair for a single backslash.
        $header = 'attachment; filename="map\\\\bestand.pdf"';
        $this->assertSame('map\\bestand.pdf', $this->invokeParser($header));
    }

    public function testEscapedQuoteDoesNotLeakIntoFollowingParameter(): void
    {
        // The parameter after an escaped-quote value must still be seen as
        // a separate segment, so `filename*` keeps winning (RFC 6266 §4.3).
        $header = 'attachment; filename="a \"b\"; c.pdf"; filename*=UTF-8\'\'r%C3%A9sum%C3%A9.pdf';
        $this->assertSame('résumé.pdf', $this->invokeParser($header));
    }

    public function testEmptyFilenameStarDoesNotClobberPlainFilename(): void
    {
        // An empty `filename*` (`UTF-8''` without value-chars) must not win
        // over a usable plain `filename` — `''` means "absent", not a name.
        $header = 'attachment; filename="good.pdf"; filename*=UTF-8\'\'';
        $this->assertSame('good.pdf', $this->invokeParser($header));
    }

    public function testEmptyQuotedFilenameReturnsNull(): void
    {
        // `filename=""` yields null so the caller's URL/MIME fallback runs
        // instead of an empty filename reaching the file writer.
        $this->assertNull($this->invokeParser('attachment; filename=""'));
    }

    public function testEmptyUnquotedFilenameReturnsNull(): void
    {
        $this->assertNull($this->invokeParser('attachment; filename='));
    }

    public function testUndecodableSecondFilenameStarKeepsDecodedFirst(): void
    {
        // Duplicate parameters are non-conformant, but a later undecodable
        // `filename*` must not overwrite a value that already decoded fine.
        $header = 'attachment; filename*=UTF-8\'\'good.pdf; filename*=ISO-8859-1\'\'bad.pdf';
        $this->assertSame('good.pdf', $this->invokeParser($header));
    }

    public function testQuotedFilenameStarIsUnquotedBeforeDecoding(): void
    {
        // RFC 5987 ext-values are never quoted, but sloppy servers emit them
        // anyway; the quotes must end up neither in the charset nor the name.
        $header = 'attachment; filename*="UTF-8\'\'x.pdf"';
        $this->assertSame('x.pdf', $this->invokeParser($header));
    }

    public function testLowercaseContentDispositionHeaderKeyIsRecognised(): void
    {
        // PSR-7 keeps header casing as received and HTTP/2 sends lowercase
        // field names, so `content-disposition` must reach the parser too.
        $response = ['headers' => ['content-disposition' => ['attachment; filename="x.pdf"']]];
        $result = $this->createMock(ObjectEntity::class);
        $this->assertSame('x.pdf', $this->invokePrivate('getFilenameFromHeaders', [$response, $result]));
    }

    public function testFilenameStarDecodingToInvalidUtf8FallsBackToPlainFilename(): void
    {
        // rawurldecode() never fails: a truncated multibyte escape yields a
        // byte that is not valid UTF-8. That is not a usable filename, so the
        // valid plain `filename` must win.
        $header = 'attachment; filename="good.pdf"; filename*=UTF-8\'\'%C3';
        $this->assertSame('good.pdf', $this->invokeParser($header));
    }

    public function testFilenameStarWithNulByteFallsBackToPlainFilename(): void
    {
        $header = 'attachment; filename="good.pdf"; filename*=UTF-8\'\'a%00.pdf';
        $this->assertSame('good.pdf', $this->invokeParser($header));
    }

    public function testWhitespaceOnlyFilenameReturnsNull(): void
    {
        $this->assertNull($this->invokeParser('attachment; filename="   "'));
    }

    public function testHeaderLookupSkipsEmptyValueListAndFindsDuplicateKey(): void
    {
        // An empty value list under one casing must not hide a usable value
        // under another casing of the same header.
        $response = ['headers' => ['Content-Disposition' => [], 'content-disposition' => ['attachment; filename="x.pdf"']]];
        $result = $this->createMock(ObjectEntity::class);
        $this->assertSame('x.pdf', $this->invokePrivate('getFilenameFromHeaders', [$response, $result]));
    }

    public function testNonArrayHeadersEntryFallsBackToUrlWithoutThrowing(): void
    {
        // A persisted call log may carry a scalar under `headers`; that must
        // behave like "no headers" (URL fallback), not throw a TypeError.
        $result = $this->createMock(ObjectEntity::class);
        $result->method('getObject')->willReturn(['request' => ['url' => 'https://xxllnc.example/api/v1/file/download']]);
        $this->assertSame('download', $this->invokePrivate('getFilenameFromHeaders', [['headers' => 'none'], $result]));
    }

    public function testLowercaseContentTypeHeaderDrivesExtensionFallback(): void
    {
        $response = ['headers' => ['content-type' => ['application/pdf; charset=binary']]];
        $result = $this->createMock(ObjectEntity::class);
        $result->method('getObject')->willReturn(['request' => ['url' => 'https://xxllnc.example/api/v1/file/download']]);
        $this->assertSame('download.pdf', $this->invokePrivate('getFilenameFromHeaders', [$response, $result]));
    }
}
