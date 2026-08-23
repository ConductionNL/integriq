<?php

/**
 * Unit tests for CallService::renderEndpointPath() (ocon#1069).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twig\Loader\ArrayLoader;
use UnexpectedValueException;

/**
 * A `targetType: api` endpoint's upstream path is a template rendered from the
 * inbound request, so its effective value is only knowable at execute time.
 * These tests pin both halves of that: substitution WORKS, and the rendered
 * result is contained — an absolute URL, a scheme-relative reference or a
 * `../` traversal is refused AFTER substitution, not merely before.
 */
class CallServiceEndpointPathTest extends TestCase {

	/**
	 * @var CallService
	 */
	private CallService $service;

	/**
	 * Build the service with fully mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$this->service = new CallService(
			ObjectServiceMockBuilder::make($this),
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
		);

	}//end setUp()

	/**
	 * An endpoint without placeholders is returned byte for byte — the
	 * pre-#1069 behaviour of every existing source call is unchanged.
	 *
	 * @return void
	 */
	public function testPlaceholderlessPathIsUnchanged(): void {
		$this->assertSame(
			'/repos/conduction/openconnector/issues',
			$this->service->renderEndpointPath('/repos/conduction/openconnector/issues', [])
		);

	}//end testPlaceholderlessPathIsUnchanged()

	/**
	 * An empty endpoint stays empty: a source-root call is legitimate.
	 *
	 * @return void
	 */
	public function testEmptyPathStaysEmpty(): void {
		$this->assertSame('', $this->service->renderEndpointPath('', ['owner' => 'x']));

	}//end testEmptyPathStaysEmpty()

	/**
	 * The reported defect, fixed: OpenAPI-style single-brace segments
	 * substitute instead of being sent literally.
	 *
	 * @return void
	 */
	public function testSingleBracePlaceholdersSubstitute(): void {
		$this->assertSame(
			'/repos/conduction/openconnector/issues/42/labels',
			$this->service->renderEndpointPath(
				'/repos/{owner}/{repo}/issues/{issue}/labels',
				[
					'owner' => 'conduction',
					'repo' => 'openconnector',
					'issue' => 42,
				]
			)
		);

	}//end testSingleBracePlaceholdersSubstitute()

	/**
	 * The app's own `{{ }}` spelling — the one `endpointArray` uses — works
	 * identically, including a dotted scope lookup.
	 *
	 * @return void
	 */
	public function testTwigPlaceholdersSubstitute(): void {
		$this->assertSame(
			'/repos/conduction/issues/7',
			$this->service->renderEndpointPath(
				'/repos/{{ owner }}/issues/{{ path.issue }}',
				[
					'owner' => 'conduction',
					'path' => ['issue' => 7],
				]
			)
		);

	}//end testTwigPlaceholdersSubstitute()

	/**
	 * A substituted value is percent-encoded for URL-path context, so an
	 * inbound value can never contribute a path separator of its own.
	 *
	 * @return void
	 */
	public function testSubstitutedValuesArePercentEncoded(): void {
		$this->assertSame(
			'/issues/a%20b%26c',
			$this->service->renderEndpointPath('/issues/{issue}', ['issue' => 'a b&c'])
		);

	}//end testSubstitutedValuesArePercentEncoded()

	/**
	 * A value trying to smuggle extra path segments is encoded, not honoured.
	 *
	 * @return void
	 */
	public function testSubstitutedSlashesAreEncodedNotHonoured(): void {
		$this->assertSame(
			'/issues/1%2Fadmin%2Fsecrets',
			$this->service->renderEndpointPath('/issues/{issue}', ['issue' => '1/admin/secrets'])
		);

	}//end testSubstitutedSlashesAreEncodedNotHonoured()

	/**
	 * An absolute URL is refused after substitution — it would replace the
	 * Source location entirely and turn the proxy into an open redirector.
	 *
	 * @return void
	 */
	public function testAbsoluteUrlAfterSubstitutionIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->service->renderEndpointPath('https://evil.test/repos/{owner}', ['owner' => 'conduction']);

	}//end testAbsoluteUrlAfterSubstitutionIsRefused()

	/**
	 * An inbound value that TRIES to be an absolute URL cannot become one: the
	 * percent-encoding of layer 1 neutralises the scheme separator, so the
	 * value stays a single opaque path segment.
	 *
	 * @return void
	 */
	public function testInboundValueCannotBecomeAnAbsoluteUrl(): void {
		$this->assertSame(
			'/labels/https%3A%2F%2Fevil.test',
			$this->service->renderEndpointPath('/labels/{target}', ['target' => 'https://evil.test'])
		);

	}//end testInboundValueCannotBecomeAnAbsoluteUrl()

	/**
	 * A scheme-relative `//host` after substitution is refused. This is the
	 * case that only a POST-substitution check can catch: the literal
	 * `/{{ a }}/{{ b }}` is perfectly contained.
	 *
	 * @return void
	 */
	public function testSchemeRelativeAfterSubstitutionIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->service->renderEndpointPath('{{ a }}{{ b }}/labels', ['a' => '/', 'b' => '/evil.test']);

	}//end testSchemeRelativeAfterSubstitutionIsRefused()

	/**
	 * A `../` traversal after substitution is refused.
	 *
	 * @return void
	 */
	public function testPathTraversalAfterSubstitutionIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->service->renderEndpointPath('/repos/{{ owner }}/issues', ['owner' => '../../admin']);

	}//end testPathTraversalAfterSubstitutionIsRefused()

	/**
	 * A control character in the rendered path is refused — an endpoint must
	 * not be able to smuggle a second request line past the HTTP client.
	 *
	 * @return void
	 */
	public function testControlCharacterInTheRenderedPathIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->service->renderEndpointPath("/issues/{{ id }}\r\nX-Injected: 1", ['id' => '1']);

	}//end testControlCharacterInTheRenderedPathIsRefused()

	/**
	 * A control character arriving in an inbound VALUE is neutralised by
	 * layer 1 rather than reaching the wire: it is percent-encoded, so it
	 * travels as data inside one path segment.
	 *
	 * @return void
	 */
	public function testControlCharacterInAnInboundValueIsEncoded(): void {
		$this->assertSame(
			'/issues/1%0D%0AX-Injected%3A%201',
			$this->service->renderEndpointPath('/issues/{id}', ['id' => "1\r\nX-Injected: 1"])
		);

	}//end testControlCharacterInAnInboundValueIsEncoded()

	/**
	 * An unsatisfied placeholder is a failure, not an empty segment. Silently
	 * collapsing `/repos/{{owner}}/issues` to `/repos//issues` would dispatch a
	 * DIFFERENT upstream request and report success.
	 *
	 * @return void
	 */
	public function testUnsatisfiedPlaceholderIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->service->renderEndpointPath('/repos/{owner}/issues', ['repo' => 'x']);

	}//end testUnsatisfiedPlaceholderIsRefused()

	/**
	 * An empty substituted value is refused for the same reason — it produces
	 * an empty path segment.
	 *
	 * @return void
	 */
	public function testEmptySubstitutedValueIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->service->renderEndpointPath('/repos/{owner}/issues', ['owner' => '']);

	}//end testEmptySubstitutedValueIsRefused()

	/**
	 * The path template cannot reach a credential: the authentication
	 * functions the `configuration` renderer exposes are absent from the
	 * sandbox, so calling one is a refusal rather than a token in a URL that
	 * is then written verbatim to the CallLog.
	 *
	 * @return void
	 */
	public function testAuthenticationFunctionsAreNotAvailableToAPathTemplate(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->service->renderEndpointPath('/issues/{{ oauthToken(source) }}', ['source' => []]);

	}//end testAuthenticationFunctionsAreNotAvailableToAPathTemplate()

	/**
	 * A malformed template is refused rather than dispatched half-rendered.
	 *
	 * @return void
	 */
	public function testUnparseableTemplateIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->service->renderEndpointPath('/issues/{{ unclosed', ['id' => 1]);

	}//end testUnparseableTemplateIsRefused()
}//end class
