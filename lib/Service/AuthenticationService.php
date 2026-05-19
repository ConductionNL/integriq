<?php

namespace OCA\OpenConnector\Service;


use DateTime;
use GuzzleHttp\Client;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\HS384;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JSONFlattenedSerializer;
use OAuthException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Service class for handling authentication on other services.
 *
 * @todo We should test the effect of @Authors & @Package(s) in Class doc-blocks. And add them if possible.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UndefinedVariable)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class AuthenticationService
{

	public const REQUIRED_PARAMETERS_CLIENT_CREDENTIALS = [
		'grant_type',
		'scope',
		'authentication',
		'client_id',
		'client_secret',
	];
	public const REQUIRED_PARAMETERS_PASSWORD = [
		'grant_type',
		'scope',
		'authentication',
		'username',
		'password',
	];

	public const REQUIRED_PARAMETERS_JWT = [
		'payload',
		'secret',
		'algorithm'
	];

	/**
	 * Setting up the class with required service.
	 *
	 * @param ArrayLoader $loader The ArrayLoader for Twig.
	 */
	public function __construct(
		ArrayLoader $loader
	)
	{
		$this->twig = new Environment(loader: $loader);
	}

	/**
	 * Create call options for OAuth with Client Credentials
	 *
	 * @param array $configuration Configuration array for authentication.
	 *
	 * @return array|array[] The call options for OAuth with Client Credentials.
	 */
	private function createClientCredentialConfig(array $configuration): array
	{
		$diff = array_diff(self::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS, array_keys(array: $configuration));
		if ($diff !== []) {
			throw new BadRequestException(message: 'Some required parameters are not set: ['.implode(separator: ',', array: $diff).']');
		}

		$callConfig = [
			'form_params' => [
				'grant_type' => $configuration['grant_type'],
				'scope'		 => $configuration['scope'],
			]
		];

		if ($configuration['authentication'] === 'body') {
			$callConfig['form_params']['client_id']     = $configuration['client_id'];
			$callConfig['form_params']['client_secret'] = $configuration['client_secret'];
		} else if ($configuration['authentication'] === 'basic_auth') {
			$callConfig['auth'] = [
				'username' => $configuration['client_id'],
				'password' => $configuration['client_secret'],
			];
		}
		//@todo: check for off-cases, i.e. camelCase (not according to OAuth standards)

		if (isset($configuration['client_assertion_type']) === true && $configuration['client_assertion_type'] === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer') {
			$callConfig['form_params']['client_assertion_type'] = $configuration['client_assertion_type'];
			$callConfig['form_params']['client_assertion'] = $this->fetchJWTToken([
				'algorithm' => 'PS256',
				'secret'    => $configuration['private_key'],
				'x5t'       => $configuration['x5t'],
				'payload'   => $configuration['payload'],
			]);
		}



		return $callConfig;
	}

	/**
	 * Create call options for OAuth with Password Credentials
	 *
	 * @param array $configuration Configuration array for authentication.
	 *
	 * @return array|array[] The call options for OAuth with Password Credentials
	 */
	private function createPasswordConfig(array $configuration): array
	{
		$diff  = array_diff(self::REQUIRED_PARAMETERS_PASSWORD, array_keys(array: $configuration));
		if ($diff !== []) {
			throw new BadRequestException(message: 'Some required parameters are not set: ['.implode(separator: ',', array: $diff).']');
		}

		$callConfig = [
			'form_params' => [
				'grant_type' => $configuration['grant_type'],
				'scope'		 => $configuration['scope'],
			]
		];

		if ($configuration['authentication'] === 'body') {
			$callConfig['form_params']['username'] = $configuration['username'];
			$callConfig['form_params']['password'] = $configuration['password'];
		} else if ($configuration['authentication'] === 'basic_auth') {
			$callConfig['auth'] = [
				'username' => $configuration['username'],
				'password' => $configuration['password'],
			];
		}

		return $callConfig;
	}

	/**
	 * Requests an OAuth Access Token with predefined configuration
	 *
	 * @param array $configuration The configuration for the OAuth call.

	 * @return string The resulting access token
	 *
	 * @throws BadRequestException					 Thrown if the configuration is not compatible with OAuth.
	 * @throws \GuzzleHttp\Exception\GuzzleException Thrown if the token endpoint does not respond with an access token.
	 * @todo Convert GuzzleException to another error.
	 */
    public function fetchOAuthTokens (array $configuration): string
	{
		if (isset($configuration['grant_type']) === false) {
			throw new BadRequestException(message: 'Grant type not set, cannot request token');
		}
		if (isset($configuration['tokenUrl']) === false) {
			throw new BadRequestException(message: 'Token URL not set, cannot request token');
		}

		switch ($configuration['grant_type'])
		{
			case 'client_credentials':
				$callConfig = $this->createClientCredentialConfig(configuration: $configuration);
				break;
			case 'password':
				$callConfig = $this->createPasswordConfig(configuration: $configuration);
				break;
			default:
				throw new BadRequestException(message: 'Grant type not supported');

		}

		$client = new Client();

		$response = $client->post(uri: $configuration['tokenUrl'], options: $callConfig);

		$result = json_decode(json: $response->getBody()->getContents(), associative: true);

		if (isset($configuration['tokenLocation']) === true) {
			return $result[$configuration['tokenLocation']];
		}

		return $result['access_token'];
	}

	/**
	 * Fetch an access token from the DeCOS non-implementation of OAuth 2.0
	 *
	 * @param array $configuration The configuration of the source.
	 *
	 * @return string The access token
	 *
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function fetchDecosToken(array $configuration): string
	{
		$url = $configuration['tokenUrl'];
		$tokenLocation = $configuration['tokenLocation'];
		unset($configuration['tokenUrl']);

		$callConfig['json'] = $configuration;

		$client = new Client();
		$response = $client->post(uri: $url, options: $callConfig);

		$result = json_decode(json: $response->getBody()->getContents(), associative: true);

		if (isset($tokenLocation) === true) {
			return $result[$tokenLocation];
		}

		return $result['token'];
	}

	/**
	 * Get RSA key for RS and PS (asymmetrical) encryption.
	 *
	 * @param array $configuration
	 * @return JWK|null
	 */
	private function getRSJWK(array $configuration): ?JWK
	{
		$stamp = microtime().getmypid();
		$filename = "/var/tmp/privatekey-$stamp";
		file_put_contents($filename, base64_decode($configuration['secret']));
		$jwk = null;
		try {
			$jwk = JWKFactory::createFromKeyFile(
				$filename,
				null,
				['use' => 'sig']
			);
		}
		catch (Exception $exception) {
			throw $exception;
		}

		unlink($filename);

		return $jwk;
	}

	/**
	 * Get OCT key for HS (symmetrical) encryption.
	 *
	 * @param array $configuration The source configuration.
	 *
	 * @return JWK|null
	 */
	private function getHSJWK(array $configuration): ?JWK
	{
		return new JWK(
			[
				'kty' => 'oct',
				'k'   => rtrim(string: base64_encode(addslashes($configuration['secret'])), characters: '='),
			]
		);
	}

	/**
	 * Generates the JWT Payload by rendering the payload before decoding it.
	 *
	 * @param array $configuration The source auth configuration.
	 *
	 * @return array The resulting JWT payload.
	 * @throws \Twig\Error\LoaderError
	 * @throws \Twig\Error\SyntaxError
	 */
	private function getJWTPayload(array $configuration): array
	{
		$renderedPayload = $this->twig->createTemplate($configuration['payload'])->render($configuration);

		return json_decode($renderedPayload, true);
	}

	/**
	 * Gets the JWK key based upon algorithm and secret in the configuration.
	 *
	 * @param array $configuration The auth configuration for the source.
	 * @return JWK|null The resulting JWK key.
	 */
	private function getJWK(array $configuration): ?JWK
	{
		$jwk = null;
		if (in_array(needle: $configuration['algorithm'], haystack: ['HS256', 'HS512']) === true) {
			return $this->getHSJWK($configuration);
		} else if (in_array(needle: $configuration['algorithm'], haystack: ['RS256', 'RS384', 'RS512', 'PS256']) === true) {
			return $this->getRSJWK($configuration);
		}

		throw new BadRequestException('Algorithm not supported by key generator');
	}

	/**
	 * Generates a signed JWT token based on key, payload and algorithm.
	 *
	 * @param array $payload The payload for the JWT token
	 * @param JWK $jwk The JWT Key for the token.
	 * @param string $algorithm The algorithm.
	 * @param string|null $x5t If applicable: The Base64 encoded SHA-1 thumbprint of the used certificate.
	 * @return string
	 */
	private function generateJWT(array $payload, JWK $jwk, string $algorithm, ?string $x5t = null): string
	{
		$algorithmManager = new AlgorithmManager([
			new HS256(),
			new HS384(),
			new HS512(),
			new RS256(),
			new RS384(),
			new RS512(),
			new PS256(),
		]);
		$jwsBuilder 	  = new JWSBuilder($algorithmManager);
		$jwsSerializer	  = new CompactSerializer();

		$header = ['alg' => $algorithm, 'typ' => 'JWT'];
		if ($x5t !== null) {
			$header['x5t'] = $x5t;
		}

		try {
			$jws = $jwsBuilder
				->create()
				->withPayload(json_encode($payload))
				->addSignature($jwk, $header)
				->build();
		} catch (Exception $e) {
			return $e->getMessage();
		}

		return $jwsSerializer->serialize($jws, 0);
	}

	/**
	 * Generates a JWT token that can be used for authentication.
	 *
	 * @param array $configuration The auth configuration for the JWT token. Must at least contain payload, algorithm and secret.
	 *
	 * @return string The generated JWT token
	 */
	public function fetchJWTToken (array $configuration): string
	{
		$diff = array_diff(self::REQUIRED_PARAMETERS_JWT, array_keys(array: $configuration));
		if ($diff !== []) {
			throw new BadRequestException(message: 'Some required parameters are not set: [' . implode(separator: ',', array: $diff) . ']');
		}

		$payload = $this->getJWTPayload($configuration);

		$jwk 	 = $this->getJWK($configuration);

		if ($jwk === null) {
			throw new BadRequestException('No JWK key could be formed with given data');
		}

		if (isset($configuration['x5t']) === true) {
			return $this->generateJWT($payload, $jwk, $configuration['algorithm'], x5t: $configuration['x5t']);
		}

		return $this->generateJWT($payload, $jwk, $configuration['algorithm']);
	}

}
