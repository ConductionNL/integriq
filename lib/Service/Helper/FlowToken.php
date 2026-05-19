<?php

namespace OCA\OpenConnector\Service\Helper;

use OC\AppFramework\Http\Request;
use OCP\AppFramework\Http\Response;

/**
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
class FlowToken
{
    private array $requestOriginal;
    private array $requestAmended;
    private array $responseOriginal;
    private array $responseAmended;

    private array $syncInputOriginal;
    private array $syncInputAmended;
    private array $syncOutputOriginal;
    private array $syncOutputAmended;

    public function __construct(
        Request|array $requestOriginal = [],
        Response|array $responseOriginal = [],
        array $syncInputOriginal = [],
        array $syncOutputOriginal = [],
        ?string $path = null
    ) {
        $this->setRequestOriginal(requestOriginal: $requestOriginal, path: $path);
        $this->setRequestAmended($this->getRequestOriginal());

        $this->setResponseOriginal($responseOriginal);
        $this->setResponseAmended($this->getResponseOriginal());

        $this->setSyncInputOriginal($syncInputOriginal);
        $this->setSyncInputAmended($this->getSyncInputOriginal());

        $this->setSyncOutputOriginal($syncOutputOriginal);
        $this->setSyncOutputAmended($this->getSyncOutputOriginal());
    }

    private function getHeaders(array $server, bool $proxyHeaders = false): array
    {
        $headers = array_filter(
            array: $server,
            callback: function (string $key) use ($proxyHeaders) {
                if (str_starts_with($key, 'HTTP_') === false) {
                    return false;
                } else if ($proxyHeaders === false
                    && (str_starts_with(haystack: $key, needle: 'HTTP_X_FORWARDED') === true
                        || $key === 'HTTP_X_REAL_IP' || $key === 'HTTP_X_ORIGINAL_URI'
                    )
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
                array: $keys),
            $headers
        );
    }

    /**
     * Gets the raw content for a http request from the input stream.
     *
     * @return string The raw content body for a http request
     */
    private function getRawContent(): string
    {
        return file_get_contents(filename: 'php://input');
    }

    /**
     * Check if content appears to be XML
     *
     * @param string $content Content to check
     * @return bool True if content is valid XML
     */
    private function looksLikeXml(string $content): bool
    {
        // Suppress XML errors
        libxml_use_internal_errors(true);

        // Attempt to parse the content as XML
        $result = simplexml_load_string($content) !== false;

        // Clear any XML errors
        libxml_clear_errors();

        return $result;
    }

    /**
     * Parse raw content into structured data based on content type
     *
     * @param string $content The raw content to parse
     * @param string|null $contentType Optional content type hint
     * @return mixed Parsed data (array for JSON/XML) or original string
     */
    private function parseContent(Request $request): mixed
    {
        $contentType = $request->getHeader('Content-Type');

        if (str_contains($contentType, 'multipart/form-data') === true) {
            [$post, $files] = request_parse_body();

            $parsedFiles = array_map(function ($file) { return file_get_contents($file['tmp_name']); }, $files);

            return array_merge($post, $parsedFiles);
        }

        $content = $this->getRawContent();

        // Try JSON decode first
        $json = json_decode($content, true);
        if ($json !== null) {
            return $json;
        }

        // Try XML decode if content type suggests XML or content looks like XML
        if ($contentType === 'application/xml' || $contentType === 'text/xml' ||
            ($contentType === null && $this->looksLikeXml($content) === true)) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content);
            libxml_clear_errors();

            if ($xml !== false) {
                return json_decode(json_encode($xml), true);
            }
        }

        // Return original content as fallback
        return $request->getParams();
    }

    public function setRequestOriginal(array|Request $requestOriginal, ?string $path = null): array
    {
        if ($requestOriginal instanceof Request) {
            $request = $requestOriginal;
            $requestOriginal = [
                'method' => $request->getMethod(),
                'headers' => $this->getHeaders($request->server, true),
                'parameters' => array_merge($request->getParams(), $this->parseContent($request)),
                'path' => $path,
            ];
        }
        $this->requestOriginal = $requestOriginal;

        return $this->requestOriginal;
    }

    public function getRequestOriginal(): array
    {
        return $this->requestOriginal;
    }

    public function setRequestAmended(array $requestAmended): array
    {
        $this->requestAmended = $requestAmended;

        return $this->requestAmended;
    }

    public function getRequestAmended(): array
    {
        return $this->requestAmended;
    }

    public function setResponseOriginal(array|Response $responseOriginal): array
    {
        if ($responseOriginal instanceof Response) {
            $responseOriginal = [
                'data' => method_exists($responseOriginal, 'getData') ? $responseOriginal->getData() : [],
                'headers' => $responseOriginal->getHeaders(),
                'status' => $responseOriginal->getStatus(),
                'cookies' => $responseOriginal->getCookies(),
            ];
        }

        $this->responseOriginal = $responseOriginal;

        return $responseOriginal;
    }

    public function getResponseOriginal(): array
    {
        return $this->responseOriginal;
    }

    public function setResponseAmended(array $responseAmended): array
    {
        $this->responseAmended = $responseAmended;

        return $this->responseAmended;
    }

    public function getResponseAmended(): array
    {
        return $this->responseAmended;
    }

    public function setSyncInputOriginal(array $syncInputOriginal): array
    {
        $this->syncInputOriginal = $syncInputOriginal;

        return $this->syncInputOriginal;
    }

    public function getSyncInputOriginal(): array
    {
        return $this->syncInputOriginal;
    }

    public function setSyncInputAmended(array $syncInputAmended): array
    {
        return $this->syncInputAmended = $syncInputAmended;
    }

    public function getSyncInputAmended(): array
    {
        return $this->syncInputAmended;
    }

    public function setSyncOutputOriginal(array $syncOutputOriginal): array
    {
        return $this->syncOutputOriginal = $syncOutputOriginal;
    }

    public function getSyncOutputOriginal(): array
    {
        return $this->syncOutputOriginal;
    }

    public function setSyncOutputAmended(array $syncOutputAmended): array
    {
        return $this->syncOutputAmended = $syncOutputAmended;
    }

    public function getSyncOutputAmended(): array
    {
        return $this->syncOutputAmended;
    }

    public function __serialize(): array
    {
        return [
            'requestOriginal' => $this->requestOriginal,
            'requestAmended' => $this->requestAmended,
            'responseOriginal' => $this->responseOriginal,
            'responseAmended' => $this->responseAmended,
            'syncInputOriginal' => $this->syncInputOriginal,
            'syncInputAmended' => $this->syncInputAmended,
            'syncOutputOriginal' => $this->syncOutputOriginal,
            'syncOutputAmended' => $this->syncOutputAmended,
        ];
    }
}
