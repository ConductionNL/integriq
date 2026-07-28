<?php
/**
 * OpenConnector FlowToken.
 *
 * Mutable container that carries the original + amended request / response /
 * sync-input / sync-output payloads across the rule pipeline.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service\Helper;

use OCA\OpenConnector\Util\SafeXmlParser;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Container for original + amended payloads passed through the rule pipeline.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
class FlowToken
{

    /**
     * Original request snapshot.
     *
     * @var array
     */
    private array $requestOriginal;

    /**
     * Amended request snapshot.
     *
     * @var array
     */
    private array $requestAmended;

    /**
     * Original response snapshot.
     *
     * @var array
     */
    private array $responseOriginal;

    /**
     * Amended response snapshot.
     *
     * @var array
     */
    private array $responseAmended;

    /**
     * Original sync input snapshot.
     *
     * @var array
     */
    private array $syncInputOriginal;

    /**
     * Amended sync input snapshot.
     *
     * @var array
     */
    private array $syncInputAmended;

    /**
     * Original sync output snapshot.
     *
     * @var array
     */
    private array $syncOutputOriginal;

    /**
     * Amended sync output snapshot.
     *
     * @var array
     */
    private array $syncOutputAmended;

    /**
     * Constructor.
     *
     * @param $requestOriginal    Original inbound request or pre-built array payload.
     * @param $responseOriginal   Original outbound response or pre-built array payload.
     * @param array       $syncInputOriginal  Original sync input snapshot.
     * @param array       $syncOutputOriginal Original sync output snapshot.
     * @param string|null $path               Request path used when serialising a Request.
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function __construct(
        IRequest|array $requestOriginal=[],
        Response|array $responseOriginal=[],
        array $syncInputOriginal=[],
        array $syncOutputOriginal=[],
        ?string $path=null
    ) {
        $this->setRequestOriginal(requestOriginal: $requestOriginal, path: $path);
        $this->setRequestAmended(requestAmended: $this->getRequestOriginal());

        $this->setResponseOriginal(responseOriginal: $responseOriginal);
        $this->setResponseAmended(responseAmended: $this->getResponseOriginal());

        $this->setSyncInputOriginal(syncInputOriginal: $syncInputOriginal);
        $this->setSyncInputAmended(syncInputAmended: $this->getSyncInputOriginal());

        $this->setSyncOutputOriginal(syncOutputOriginal: $syncOutputOriginal);
        $this->setSyncOutputAmended(syncOutputAmended: $this->getSyncOutputOriginal());

    }//end __construct()

    /**
     * Filter $_SERVER for HTTP_* headers, optionally including proxy headers.
     *
     * @param array   $server       Server array (typically $request->server).
     * @param boolean $proxyHeaders Whether to include X-Forwarded-* / X-Real-IP / X-Original-URI.
     *
     * @return array Map of lowercase header name to value.
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    private function getHeaders(array $server, bool $proxyHeaders=false): array
    {
        $headers = array_filter(
            array: $server,
            callback: function (string $key) use ($proxyHeaders) {
                if (str_starts_with($key, 'HTTP_') === false) {
                    return false;
                } else if ($proxyHeaders === false
                    && (str_starts_with(haystack: $key, needle: 'HTTP_X_FORWARDED') === true
                    || $key === 'HTTP_X_REAL_IP' || $key === 'HTTP_X_ORIGINAL_URI')
                ) {
                    return false;
                }

                return true;
            },
            mode: ARRAY_FILTER_USE_KEY
        );

        $keys = array_keys($headers);

        return array_combine(
            array_map(
                callback: function ($key) {
                    return strtolower(string: substr(string: $key, offset: 5));
                },
                array: $keys
                    ),
            $headers
        );

    }//end getHeaders()

    /**
     * Gets the raw content for a http request from the input stream.
     *
     * @return string The raw content body for a http request.
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    private function getRawContent(): string
    {
        return file_get_contents(filename: 'php://input');

    }//end getRawContent()

    /**
     * Check if content appears to be XML.
     *
     * @param string $content Content to check.
     *
     * @return boolean True if content is valid XML.
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    private function looksLikeXml(string $content): bool
    {
        // Suppress XML errors.
        libxml_use_internal_errors(true);

        // Use the safe parser so the XXE loader cannot leak in from SOAPService.
        $result = (SafeXmlParser::parse($content) !== false);

        // Clear any XML errors.
        libxml_clear_errors();

        return $result;

    }//end looksLikeXml()

    /**
     * Parse raw content into structured data based on content type.
     *
     * @param $request The inbound request used to determine content type and fallback parameters.
     *
     * @return mixed Parsed data (array for JSON/XML) or original string.
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    private function parseContent(IRequest $request): mixed
    {
        $contentType = $request->getHeader('Content-Type');

        if (str_contains($contentType, 'multipart/form-data') === true) {
            [$post, $files] = request_parse_body();

            $parsedFiles = array_map(
                    function ($file) {
                        return file_get_contents($file['tmp_name']);
                    },
                    $files
                    );

            return array_merge($post, $parsedFiles);
        }

        $content = $this->getRawContent();

        // Try JSON decode first.
        $json = json_decode($content, true);
        if ($json !== null) {
            return $json;
        }

        // Try XML decode if content type suggests XML or content looks like XML.
        if ($contentType === 'application/xml' || $contentType === 'text/xml'
            || ($contentType === '' && $this->looksLikeXml(content: $content) === true)
        ) {
            libxml_use_internal_errors(true);
            $xml = SafeXmlParser::parse($content);
            libxml_clear_errors();

            if ($xml !== false) {
                return json_decode(json_encode($xml), true);
            }
        }

        // Return original content as fallback.
        return $request->getParams();

    }//end parseContent()

    /**
     * Set the original request, normalising Request objects into an array shape.
     *
     * @param $requestOriginal The original request payload.
     * @param string|null $path            Path used when serialising a Request.
     *
     * @return array The stored array shape.
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function setRequestOriginal(array|IRequest $requestOriginal, ?string $path=null): array
    {
        if ($requestOriginal instanceof IRequest) {
            $request         = $requestOriginal;
            $requestOriginal = [
                'method'     => $request->getMethod(),
                'headers'    => $this->getHeaders(server: $_SERVER, proxyHeaders: true),
                'parameters' => array_merge($request->getParams(), $this->parseContent(request: $request)),
                'path'       => $path,
            ];
        }

        $this->requestOriginal = $requestOriginal;

        return $this->requestOriginal;

    }//end setRequestOriginal()

    /**
     * Get the original request snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function getRequestOriginal(): array
    {
        return $this->requestOriginal;

    }//end getRequestOriginal()

    /**
     * Set the amended request snapshot.
     *
     * @param array $requestAmended Amended request snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function setRequestAmended(array $requestAmended): array
    {
        $this->requestAmended = $requestAmended;

        return $this->requestAmended;

    }//end setRequestAmended()

    /**
     * Get the amended request snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function getRequestAmended(): array
    {
        return $this->requestAmended;

    }//end getRequestAmended()

    /**
     * Set the original response, normalising Response objects into an array shape.
     *
     * @param array|Response $responseOriginal Original response or pre-built array payload.
     *
     * @return array The stored array shape.
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function setResponseOriginal(array|Response $responseOriginal): array
    {
        if ($responseOriginal instanceof Response) {
            if (method_exists($responseOriginal, 'getData') === true) {
                $data = $responseOriginal->getData();
            } else {
                $data = [];
            }

            $responseOriginal = [
                'data'    => $data,
                'headers' => $responseOriginal->getHeaders(),
                'status'  => $responseOriginal->getStatus(),
                'cookies' => $responseOriginal->getCookies(),
            ];
        }

        $this->responseOriginal = $responseOriginal;

        return $responseOriginal;

    }//end setResponseOriginal()

    /**
     * Get the original response snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function getResponseOriginal(): array
    {
        return $this->responseOriginal;

    }//end getResponseOriginal()

    /**
     * Set the amended response snapshot.
     *
     * @param array $responseAmended Amended response snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function setResponseAmended(array $responseAmended): array
    {
        $this->responseAmended = $responseAmended;

        return $this->responseAmended;

    }//end setResponseAmended()

    /**
     * Get the amended response snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function getResponseAmended(): array
    {
        return $this->responseAmended;

    }//end getResponseAmended()

    /**
     * Set the original sync input snapshot.
     *
     * @param array $syncInputOriginal Original sync input snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function setSyncInputOriginal(array $syncInputOriginal): array
    {
        $this->syncInputOriginal = $syncInputOriginal;

        return $this->syncInputOriginal;

    }//end setSyncInputOriginal()

    /**
     * Get the original sync input snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function getSyncInputOriginal(): array
    {
        return $this->syncInputOriginal;

    }//end getSyncInputOriginal()

    /**
     * Set the amended sync input snapshot.
     *
     * @param array $syncInputAmended Amended sync input snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function setSyncInputAmended(array $syncInputAmended): array
    {
        $this->syncInputAmended = $syncInputAmended;
        return $this->syncInputAmended;

    }//end setSyncInputAmended()

    /**
     * Get the amended sync input snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function getSyncInputAmended(): array
    {
        return $this->syncInputAmended;

    }//end getSyncInputAmended()

    /**
     * Set the original sync output snapshot.
     *
     * @param array $syncOutputOriginal Original sync output snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function setSyncOutputOriginal(array $syncOutputOriginal): array
    {
        $this->syncOutputOriginal = $syncOutputOriginal;
        return $this->syncOutputOriginal;

    }//end setSyncOutputOriginal()

    /**
     * Get the original sync output snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function getSyncOutputOriginal(): array
    {
        return $this->syncOutputOriginal;

    }//end getSyncOutputOriginal()

    /**
     * Set the amended sync output snapshot.
     *
     * @param array $syncOutputAmended Amended sync output snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function setSyncOutputAmended(array $syncOutputAmended): array
    {
        $this->syncOutputAmended = $syncOutputAmended;
        return $this->syncOutputAmended;

    }//end setSyncOutputAmended()

    /**
     * Get the amended sync output snapshot.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function getSyncOutputAmended(): array
    {
        return $this->syncOutputAmended;

    }//end getSyncOutputAmended()

    /**
     * Serialise the FlowToken into an array suitable for json encoding.
     *
     * @return array
     *
     * @spec openspec/specs/flow-token-helper/spec.md
     */
    public function __serialize(): array
    {
        return [
            'requestOriginal'    => $this->requestOriginal,
            'requestAmended'     => $this->requestAmended,
            'responseOriginal'   => $this->responseOriginal,
            'responseAmended'    => $this->responseAmended,
            'syncInputOriginal'  => $this->syncInputOriginal,
            'syncInputAmended'   => $this->syncInputAmended,
            'syncOutputOriginal' => $this->syncOutputOriginal,
            'syncOutputAmended'  => $this->syncOutputAmended,
        ];

    }//end __serialize()
}//end class
