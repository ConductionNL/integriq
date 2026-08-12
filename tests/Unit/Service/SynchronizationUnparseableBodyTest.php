<?php

/**
 * A 200 that is not a success.
 *
 * ocon#1190. When a source answered 200 with a body no parser could read —
 * overwhelmingly an HTML login or redirect page returned to a fetch with no
 * session — `fetchSinglePageData()` returned an empty page with no `failed`
 * flag. Upstream that is indistinguishable from a source that genuinely had
 * nothing: the run reports success, `found: 0`, and nothing is transferred.
 * No error, no alert, and any monitoring keyed on run status sees a clean run.
 *
 * The hard part is not detecting the login page. It is detecting it WITHOUT
 * breaking pagination: `[]` and `{}` decode cleanly to an empty array, and
 * that is exactly how a paginated source says "no more pages". So the tests
 * below assert both directions, and the empty-page cases are the ones that
 * would catch an over-eager fix.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\Tables\TablesSyncAdapter;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unparseable-body handling in fetchSinglePageData().
 */
class SynchronizationUnparseableBodyTest extends TestCase
{

    /**
     * Error-level messages logged during the last fetch.
     *
     * @var string[]
     */
    private array $errors = [];


    /**
     * Fetch one page whose response carries the given body.
     *
     * Drives the REAL `fetchSinglePageData()` — the decision under test is the
     * one it makes about a body, so a double of it would test nothing.
     *
     * @param string $body    The raw response body.
     * @param array  $source  Optional source overrides.
     *
     * @return array The page data.
     */
    private function fetchPageWithBody(string $body, array $source=[]): array
    {
        $this->errors = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            function (string $message, array $context=[]): void {
                $this->errors[] = $message.' '.json_encode($context);
            }
        );

        // CallService returns an OpenRegister call-LOG object and the service
        // reads the response out of its object body. A REAL ObjectEntity, not
        // a mock: its accessors go through `Entity::__call`, and PHPUnit
        // refuses to stub magic methods (see ObjectServiceMockBuilder's note
        // on #1015). Shaped exactly as `callLogResponse()` and
        // `callLogStatusCode()` read it.
        $callLog = new \OCA\OpenRegister\Db\ObjectEntity();
        $callLog->setObject(
            [
                'statusCode' => 200,
                'response'   => [
                    'body'       => $body,
                    'encoding'   => 'UTF-8',
                    'statusCode' => 200,
                    'headers'    => [],
                ],
            ]
        );

        $callService = $this->createMock(CallService::class);
        $callService->method('call')->willReturn($callLog);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $service = new SynchronizationService(
            $callService,
            $this->createMock(MappingService::class),
            $this->createMock(ContainerInterface::class),
            ObjectServiceMockBuilder::make($this),
            $this->createMock(ObjectService::class),
            $logger,
            $this->createMock(SynchronizationLogService::class),
            $appConfig,
            $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
            $this->createMock(TablesSyncAdapter::class),
        );

        $method = new ReflectionMethod(SynchronizationService::class, 'fetchSinglePageData');
        $method->setAccessible(true);

        return $method->invoke(
            $service,
            // `_transient` is the service's own escape hatch for an ad-hoc
            // source (REQ-012): it skips the OpenRegister lookup and bridges
            // the array straight into the ObjectEntity shape CallService
            // consumes. Used here so the test exercises body handling rather
            // than source resolution.
            array_merge(['id' => 'src-1', '_transient' => true, 'uuid' => 'src-1', 'configuration' => []], $source),
            'https://example.test/api/objects',
            [],
            ['id' => 'sync-1', 'sourceConfig' => []]
        );

    }//end fetchPageWithBody()


    /**
     * An HTML login page is a FAILED page, not an empty one.
     *
     * @return void
     */
    public function testAnHtmlLoginPageFailsThePage(): void
    {
        $login = '<!DOCTYPE html><html><head><title>Login</title></head>'
            .'<body><form action="/login" method="post"><input name="user"></form></body></html>';

        $page = $this->fetchPageWithBody($login);

        $this->assertTrue(($page['failed'] ?? false), 'an unreadable body must fail the page');
        $this->assertSame([], $page['objects']);

    }//end testAnHtmlLoginPageFailsThePage()


    /**
     * The operator is told it looked like HTML, and told to check credentials.
     *
     * A `failed` flag with a generic message sends whoever reads it hunting an
     * empty source. Naming the shape is what turns this into an actionable
     * report.
     *
     * @return void
     */
    public function testTheLogNamesTheHtmlShapeAndTheCredentials(): void
    {
        $this->fetchPageWithBody('<!DOCTYPE html><html><body>Please log in</body></html>');

        $this->assertCount(1, $this->errors, 'the failure must be logged');
        $this->assertStringContainsString('HTML', $this->errors[0]);
        $this->assertStringContainsString('credentials', $this->errors[0]);

    }//end testTheLogNamesTheHtmlShapeAndTheCredentials()


    /**
     * A login page that is WELL-FORMED XML is still a failed page.
     *
     * This case is why the HTML guard exists, and it was found by writing the
     * test: `<html><body>Please log in</body></html>` is valid XML, so the XML
     * parser succeeded on it, `$result` came back non-empty, and the fetch
     * walked on into object extraction to die there on "cannot determine the
     * position of objects in the return body" — a confusing complaint about
     * the source's shape when the real problem is that nobody was logged in.
     *
     * The first HTML test above does NOT cover this: its markup has an
     * unclosed `<input>`, so it fails XML parsing and reaches the failure
     * branch by a different route. Two shapes, two paths, one verdict.
     *
     * @return void
     */
    public function testAWellFormedXmlLoginPageAlsoFailsThePage(): void
    {
        $page = $this->fetchPageWithBody('<html><body>Please log in</body></html>');

        $this->assertTrue(($page['failed'] ?? false), 'valid-XML HTML must not be parsed as data');
        $this->assertSame([], $page['objects']);
        $this->assertCount(1, $this->errors);
        $this->assertStringContainsString('HTML', $this->errors[0]);

    }//end testAWellFormedXmlLoginPageAlsoFailsThePage()


    /**
     * Genuine XML is still parsed as XML.
     *
     * The counterpart to the guard above: it must skip XML for HTML documents
     * ONLY. Without this, `looksLikeHtmlDocument()` could widen to match any
     * angle-bracketed body and silently take every XML source offline.
     *
     * @return void
     */
    public function testGenuineXmlIsStillParsed(): void
    {
        $thrown = null;

        try {
            $this->fetchPageWithBody('<?xml version="1.0"?><results><item><id>a</id></item></results>');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        // The XML parsed. This minimal fixture declares no object position, so
        // extraction then complains about that — which is the point: reaching
        // object extraction at all proves the body was READ rather than
        // rejected as unreadable. What must NOT happen is the new failure
        // branch, and the empty error log is what rules that out.
        $this->assertSame([], $this->errors, 'an XML body must not be reported as unreadable');
        $this->assertNotNull($thrown, 'the fixture declares no object position, so extraction should complain');
        $this->assertStringContainsString('position of objects', $thrown->getMessage());

    }//end testGenuineXmlIsStillParsed()


    /**
     * An empty JSON ARRAY is the end of pagination, not a failure.
     *
     * This is the test an over-eager fix breaks. `[]` decodes cleanly; it is
     * how every paginated source says "no more pages". Failing it would turn
     * the last page of every sync in the fleet into an error.
     *
     * @return void
     */
    public function testAnEmptyJsonArrayIsNotAFailure(): void
    {
        $page = $this->fetchPageWithBody('[]');

        $this->assertFalse(($page['failed'] ?? false), 'an empty page must remain an empty page');
        $this->assertSame([], $this->errors);

    }//end testAnEmptyJsonArrayIsNotAFailure()


    /**
     * An empty JSON OBJECT is likewise the end of pagination.
     *
     * @return void
     */
    public function testAnEmptyJsonObjectIsNotAFailure(): void
    {
        $page = $this->fetchPageWithBody('{}');

        $this->assertFalse(($page['failed'] ?? false));
        $this->assertSame([], $this->errors);

    }//end testAnEmptyJsonObjectIsNotAFailure()


    /**
     * A completely empty body stays an empty page, as it always did.
     *
     * A source that returns nothing at all has said nothing readable either,
     * so it is the one case where "unparseable" must NOT mean failed — this
     * pins the pre-existing behaviour against the new branch.
     *
     * @return void
     */
    public function testAnEmptyBodyIsNotAFailure(): void
    {
        $page = $this->fetchPageWithBody('   ');

        $this->assertFalse(($page['failed'] ?? false));
        $this->assertSame([], $this->errors);

    }//end testAnEmptyBodyIsNotAFailure()


    /**
     * Non-HTML garbage fails too, and does NOT claim to be HTML.
     *
     * The failure is about being unreadable, not about being HTML; the shape
     * only sharpens the message. Without this, `looksLikeHtmlDocument()` could
     * return true unconditionally and every test above would still pass.
     *
     * @return void
     */
    public function testNonHtmlGarbageFailsWithoutClaimingHtml(): void
    {
        $page = $this->fetchPageWithBody('%PDF-1.7 binary garbage that is not a document we can read');

        $this->assertTrue(($page['failed'] ?? false));
        $this->assertCount(1, $this->errors);
        $this->assertStringNotContainsString('HTML', $this->errors[0]);

    }//end testNonHtmlGarbageFailsWithoutClaimingHtml()


    /**
     * A body that PARSES is untouched by any of this.
     *
     * @return void
     */
    public function testAParseableBodyIsUnaffected(): void
    {
        $page = $this->fetchPageWithBody('{"results":[{"id":"a"},{"id":"b"}]}');

        $this->assertFalse(($page['failed'] ?? false));
        $this->assertSame([], $this->errors);
        $this->assertNotSame([], $page['result']);

    }//end testAParseableBodyIsUnaffected()
}//end class
