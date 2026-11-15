<?php

/**
 * Integriq — the live MijnOverheid Berichtenbox client.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Berichtenbox;

use DOMDocument;
use DOMXPath;
use Psr\Log\LoggerInterface;

/**
 * The binding with `logius.berichtenbox.feature_flag` set.
 *
 * Letters go as `GLOBE-R-BV-Request` through the operator's ebMS adapter;
 * subscriptions are checked with WUS `ValidateAbonnementen` straight to Logius;
 * results come back as `GLOBE-R-BV-Result` messages at the adapter. A source
 * that lacks any value this needs is refused with each missing value named.
 *
 * Results and events are read once per source per run and kept for that run,
 * so a status poll over many letters makes one round trip, not one per letter.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-live-binding-speaks-the-interface-logius-publishes-req-dpa-008
 */
class BerichtenboxClientHttp extends BerichtenboxClient {
	/**
	 * What each required value is called in a refusal.
	 */
	private const REQUIRED = [
		'adapterUrl' => 'the ebMS adapter (adapterUrl)',
		'cpaId' => 'the CPA id Logius made (cpaId)',
		'toPartyId' => "Logius' party id from the CPA (toPartyId)",
		'service' => 'the CPA service (service)',
		'wusEndpoint' => 'the subscription check endpoint (wusEndpoint)',
	];

	/**
	 * Results read this run, per source.
	 *
	 * @var array<string,array<string,array{code:string,stadium:string,resultMessageId:string}>>
	 */
	private array $results = [];

	/**
	 * Events read this run, per source.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $events = [];

	/**
	 * Constructor.
	 *
	 * @param BerichtenboxValidatieClient $validatie The WUS subscription check.
	 * @param EbmsAdapterClient $adapter The ebMS adapter.
	 * @param LoggerInterface $logger Structured logger; never receives a BSN.
	 */
	public function __construct(
		private readonly BerichtenboxValidatieClient $validatie,
		private readonly EbmsAdapterClient $adapter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Flavour identifier.
	 *
	 * @return string Always `https`.
	 */
	public function flavour(): string {
		return 'https';
	}//end flavour()

	/**
	 * Every value the live binding needs and this source lacks.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,string> The refusals.
	 */
	public function configurationRefusals(array $config): array {
		$missing = [];
		foreach (self::REQUIRED as $key => $label) {
			if (trim((string)($config[$key] ?? '')) === '') {
				$missing[] = $label;
			}
		}

		$types = ($config['berichtTypes'] ?? []);
		if (is_array($types) === false || array_filter($types, static fn ($type) => is_string($type) && $type !== '') === []) {
			$missing[] = 'a BerichtType per letter category (berichtTypes)';
		}

		if ($missing === []) {
			return [];
		}

		return ['The live Berichtenbox needs ' . implode(', ', $missing) . '. Nothing was sent.'];
	}//end configurationRefusals()

	/**
	 * Ask Logius over WUS.
	 *
	 * @param array<int,string> $bsns The BSNs.
	 * @param string $berichtType The BerichtType.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<string,bool>
	 */
	public function checkSubscriptions(array $bsns, string $berichtType, array $config): array {
		return $this->validatie->check(bsns: $bsns, berichtType: $berichtType, config: $config);
	}//end checkSubscriptions()

	/**
	 * Hand the batch to the ebMS adapter.
	 *
	 * @param BerichtenboxBatch $batch The batch.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return string The transport message id.
	 */
	public function deliver(BerichtenboxBatch $batch, array $config): string {
		return $this->adapter->send(batch: $batch, config: $config);
	}//end deliver()

	/**
	 * Every result waiting at the adapter for this source, keyed by BerichtID.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<string,array{code:string,stadium:string,resultMessageId:string}>
	 */
	public function results(array $config): array {
		$key = $this->sourceKey(config: $config);
		if (isset($this->results[$key]) === true) {
			return $this->results[$key];
		}

		$results = [];
		foreach ($this->adapter->unprocessedResults(config: $config) as $resultMessageId) {
			foreach ($this->parseResult(xml: $this->adapter->payload(messageId: $resultMessageId, config: $config)) as $berichtId => $outcome) {
				$results[$berichtId] = $outcome + ['resultMessageId' => $resultMessageId];
			}
		}

		$this->results[$key] = $results;

		return $results;
	}//end results()

	/**
	 * Every transport event waiting at the adapter for this source.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<string,string>
	 */
	public function transportEvents(array $config): array {
		$key = $this->sourceKey(config: $config);
		if (isset($this->events[$key]) === false) {
			$this->events[$key] = $this->adapter->unprocessedEvents(config: $config);
		}

		return $this->events[$key];
	}//end transportEvents()

	/**
	 * Let the adapter drop a result message.
	 *
	 * @param string $resultMessageId The result message id.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return void
	 */
	public function resultProcessed(string $resultMessageId, array $config): void {
		$this->adapter->markMessageProcessed(messageId: $resultMessageId, config: $config);
	}//end resultProcessed()

	/**
	 * Let the adapter drop a transport event.
	 *
	 * @param string $transportMessageId The transport message id.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return void
	 */
	public function eventProcessed(string $transportMessageId, array $config): void {
		$this->adapter->markEventProcessed(messageId: $transportMessageId, config: $config);
		unset($this->events[$this->sourceKey(config: $config)][$transportMessageId]);
	}//end eventProcessed()

	/**
	 * Read one GLOBE-R-BV-Result, checked against the vendored response schema.
	 *
	 * @param string $xml The result document.
	 *
	 * @return array<string,array{code:string,stadium:string}> BerichtID => outcome.
	 */
	public function parseResult(string $xml): array {
		if ((new LogiusSchema())->errors(xml: $xml, schema: 'BerichtVerwerkService/Response/GLOBEBatchResponse.xsd') !== []) {
			// Left unprocessed at the adapter, so an operator can look at it.
			$this->logger->warning('digital-post.berichtenbox.result-invalid', ['length' => strlen($xml)]);
			return [];
		}

		$document = new DOMDocument();
		$document->loadXML($xml, LIBXML_NONET);
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('r', 'http://schemas.rdw.nl/GEB/BerichtVerwerkService/BerichtResultaat/Types/2009/01');

		$outcomes = [];
		$letters = $xpath->query('//r:Bericht');
		if ($letters === false) {
			return [];
		}

		foreach ($letters as $letter) {
			$berichtId = strtolower(trim((string)$xpath->evaluate('string(BerichtID)', $letter)));
			$outcomes[$berichtId] = [
				'code' => trim((string)$xpath->evaluate('string(VerwerkingsCode)', $letter)),
				'stadium' => trim((string)$xpath->evaluate('string(Stadium)', $letter)),
			];
		}

		return $outcomes;
	}//end parseResult()

	/**
	 * One key per source for this run's reads.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return string The key.
	 */
	private function sourceKey(array $config): string {
		return (string)($config['adapterUrl'] ?? '') . '|' . (string)($config['cpaId'] ?? '');
	}//end sourceKey()
}//end class
