<?php
/**
 * OpenConnector SOAP service.
 *
 * Basic SOAP client for communicating with SOAP sources using a WSDL. Manages
 * execution of SOAP requests using the Guzzle HTTP client for the actual
 * HTTP transport.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service;

use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Util\SafeXmlParser;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Soap\Engine\Engine;
use Soap\Engine\SimpleEngine;
use Soap\ExtSoapEngine\AbusedClient;
use Soap\ExtSoapEngine\ExtSoapOptions;
use Soap\ExtSoapEngine\ExtSoapDriver;
use Soap\ExtSoapEngine\Transport\ExtSoapClientTransport;
use Soap\ExtSoapEngine\Transport\TraceableTransport;
use Soap\ExtSoapEngine\Wsdl\InMemoryWsdlProvider;
use Soap\ExtSoapEngine\Wsdl\PermanentWsdlLoaderProvider;
use Soap\ExtSoapEngine\Wsdl\TemporaryWsdlLoaderProvider;
use Soap\Psr18Transport\Psr18Transport;
use Soap\Psr18Transport\Wsdl\Psr18Loader;
use Soap\Wsdl\Loader\StreamWrapperLoader;
use stdClass;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Basic SOAP client for communicating with SOAP sources using a WSDL.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 *
 * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-4
 */
class SOAPService
{

    /**
     * The GuzzleClient used by the SOAP engine.
     *
     * @var Client
     */
    private Client $client;

    /**
     * Constructor.
     *
     * @param CookieJar $cookieJar A cookie jar to pass on cookies between SOAP requests.
     */
    public function __construct(private readonly CookieJar $cookieJar)
    {

    }//end __construct()

    /**
     * Fetch the SOAP version to work in.
     *
     * @param string|int|null $soapVersion The specified soap version according to the configuration.
     *
     * @return int The soap version as specified in constants.
     *
     * @throws BadRequestHttpException When an unsupported numeric soap version is supplied.
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-4
     */
    private function getSoapVersion(string|int|null $soapVersion): int
    {
        if (is_int($soapVersion) === true && $soapVersion > 0 && $soapVersion < 3) {
            return $soapVersion;
        }

        if (is_int($soapVersion) === true) {
            throw new BadRequestHttpException(
                message: 'improper configuration, only soap 1.1 and 1.2 are supported'
            );
        }

        switch ($soapVersion) {
            case '1.1':
            case '1_1':
            case 'soap1.1':
            case 'soap1_1':
            case 'soap_1_1':
            case 'SOAP_1_1':
                return SOAP_1_1;
            case '1.2':
            case '1_2':
            case 'soap1.2':
            case 'soap1_2':
            case 'soap_1_2':
            case 'SOAP_1_2':
            default:
                return SOAP_1_2;
        }

    }//end getSoapVersion()

    /**
     * Setup a SOAP engine for a source.
     *
     * @param ObjectEntity $source       The source ObjectEntity.
     * @param array        $passedConfig The config to setup the HTTP client with.
     *
     * @return Engine The resulting soap engine.
     *
     * @throws \SoapFault When the SOAP client cannot be created.
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-4
     */
    public function setupEngine(ObjectEntity $source, array $passedConfig): Engine
    {
        $sourceData = $source->getObject();
        $config     = $sourceData['configuration'] ?? [];

        if (isset($config['wsdl']) === false) {
            throw new Exception('No wsdl provided');
        }

        $passedConfig['cookies'] = $this->cookieJar;

        $this->client = new Client($passedConfig);
        $wsdl         = $config['wsdl'];
        $soapVersion  = $config['soapVersion'] ?? null;
        $permanent    = ($config['permanentWsdl'] === 'true' || $config['permanentWsdl'] === true);

        unset($passedConfig['wsdl'], $passedConfig['soapVersion']);

        if ($permanent === true) {
            $wsdlProvider = new PermanentWsdlLoaderProvider(new Psr18Loader($this->client, new HttpFactory()));
        } else {
            $wsdlProvider = new TemporaryWsdlLoaderProvider(new Psr18Loader($this->client, new HttpFactory()));
        }

        try {
            $engine       = new SimpleEngine(
                $driver   = ExtSoapDriver::createFromClient(
                    $soap = $client = AbusedClient::createFromOptions(
                        ExtSoapOptions::defaults(
                            $wsdl,
                            [
                                'cache_wsdl'   => WSDL_CACHE_NONE,
                                'trace'        => true,
                                'location'     => $sourceData['location'] ?? '',
                                'soap_version' => $this->getSoapVersion(soapVersion: $soapVersion),
                            ]
                        )
                            ->withWsdlProvider($wsdlProvider)
                            ->disableWsdlCache()
                    )
                ),
                Psr18Transport::createForClient($this->client),
            );
        } catch (\SoapFault $fault) {
            throw $fault;
        }//end try

        return $engine;

    }//end setupEngine()

    /**
     * Parse an XML snippet with its own dynamic XSD.
     *
     * Both the DOMDocument load (used for optional schema validation) and the
     * SimpleXML parse are delegated to SafeXmlParser so that the external-entity
     * loader is pinned to null for each parse, even when this method is called
     * from inside the permissive-loader window that callSoapSource opens for
     * WSDL resolution (C2 partial-fix: the permissive loader was only restored
     * in finally but the parse calls inside the try block were still exposed).
     *
     * @param string $xmlString The XML split in two parts: the XSD and the data to parse.
     *
     * @return \SimpleXMLElement|null The resulting XML element, or null when no document element is present.
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-5
     */
    private function parseDynamicXsd(string $xmlString): ?\SimpleXMLElement
    {
        // @TODO: This is awfully specific, to be replaced by a more generic fix for faulty XSD.
        $xmlString = '<any>'.str_replace('NewDataSet', 'DocumentElement', $xmlString).'</any>';

        // Load via SafeXmlParser so the entity loader is pinned to null for the
        // duration of the load, regardless of any permissive-loader window opened
        // by callSoapSource for WSDL resolution.
        $dom = new DOMDocument();
        SafeXmlParser::loadDom($dom, $xmlString);

        // OPTIONAL: Validate against schema in the XML itself (or use an external .xsd file).
        libxml_use_internal_errors(true);
        if ($dom->schemaValidateSource($xmlString) !== true) {
            libxml_clear_errors();
        }

        // Parse the data inside diffgram via SafeXmlParser (pins entity loader + LIBXML_NONET).
        $simpleXml = SafeXmlParser::parse($xmlString, 'SimpleXMLElement', 0);

        if ($simpleXml === false) {
            return null;
        }

        // The diffgram will be under the 'diffgram' namespace (unused directly — kept for
        // explicit namespace resolution; DocumentElement is retrieved via xpath below).
        $namespaces = $simpleXml->getNamespaces(true);
        // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter,SlevomatCodingStandard.Variables.UnusedVariable -- retained for namespace context.
        $diffgram = $simpleXml->children($namespaces['diffgr'] ?? '')->diffgram;

        // Or just get the DocumentElement directly.
        $documentElement = $simpleXml->xpath('//DocumentElement')[0] ?? null;

        if ($documentElement === null) {
            return null;
        }

        // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- External SOAP XML element name.
        return $documentElement->QueryExecResult;

    }//end parseDynamicXsd()

    /**
     * Call a SOAP source with provided configuration.
     *
     * @param ObjectEntity $source     The SOAP source ObjectEntity.
     * @param string       $soapAction The SOAPAction to call (most comparable to an endpoint in REST).
     * @param array        $config     The configuration to use when calling the source.
     *
     * @return Response The resulting response.
     *
     * @throws \SoapFault When the SOAP engine cannot satisfy the request.
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-4
     */
    public function callSoapSource(ObjectEntity $source, string $soapAction, array $config): Response
    {
        $body = json_decode(json: $config['body'] ?? '{}', associative: true);
        unset($config['body']);
        if (isset($config['json']) === true) {
            $body = $config['json'];
            unset($config['json']);
        }

        // Allow the SOAP/WSDL engine to resolve schema imports during this call
        // window only.  The permissive loader is ALWAYS restored in the finally
        // block so that an exception inside the call cannot leave the process-
        // global entity loader open (XXE prevention).
        libxml_set_external_entity_loader(
            static function (string $public, string $system): string {
                return $system;
            }
        );

        try {
            /*
             * @var $engine Engine
             */

            $engine = $this->setupEngine(source: $source, passedConfig: $config);

            if (isset($body['edcLk01']['object']['inhoud']) === true) {
                if (is_array($body['edcLk01']['object']['inhoud']) === false) {
                    $body['edcLk01']['object']['inhoud'] = base64_decode($body['edcLk01']['object']['inhoud']);
                }

                if (is_array($body['edcLk01']['object']['inhoud']) === true
                    && isset($body['edcLk01']['object']['inhoud']['_']) === true
                ) {
                    $body['edcLk01']['object']['inhoud']['_'] = base64_decode($body['edcLk01']['object']['inhoud']['_']);
                }
            }

            // In SOAP the endpoint is decided by the WSDL, however, the SOAP method
            // can be derived from the endpoint property of the call.
            $result = $engine->request($soapAction, $body);

            // @TODO: This must be replaced by a generic detector of fields that should be parsed in the parseDynamicXsd-function.
            if (isset($result->{'QueryExecute2Result'}) === true
                && isset($result->{'QueryExecute2Result'}->any) === true
            ) {
                $result->{'QueryExecute2Result'} = $this->parseDynamicXsd(xmlString: $result->{'QueryExecute2Result'}->any);

                if ($result->{'QueryExecute2Result'} === null) {
                    $result->{'QueryExecute2Result'} = [];
                }
            }//end if

            // @TODO: The detection of binary data fields should be dynamic.
            // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- External SOAP XML element name.
            if (isset($result->FileBytes) === true && json_encode($result) === false) {
                // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- External SOAP XML element name.
                $result->FileBytes = base64_encode($result->FileBytes);
            }
        } finally {
            // Restore the safe null-resolver regardless of success or failure so
            // the permissive loader cannot leak into subsequent simplexml calls.
            libxml_set_external_entity_loader(static fn (): null => null);
        }//end try

        return new Response(status: 200, body: json_encode($result));

    }//end callSoapSource()
}//end class
