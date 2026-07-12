<?php

// Resource block intentionally omitted: chain-C deleted every
// index/show/create/update/destroy from the per-schema controllers. CRUD now
// lives at OR's `/api/objects/openconnector/{schema}/*`. Re-adding a
// `resources` entry here without restoring the controller methods produces
// auto-routes that 500 on hit — `composer check:routes` enforces this.
return [
	'routes' => [
		// SPA shell entry (root) — UiController serves the Vue app for all section paths.
		// The Dashboard data routes (/api/dashboard/{callstats,jobstats,syncstats}) were
		// deleted in the chain-C OR-cutover: dashboard data now comes from declarative
		// manifest widgets resolved by CnStatsBlockWidget against OR's aggregate endpoint.
		// See openspec/changes/openconnector-services-direct-or-usage/proposal.md § 2a.

		// Metrics and health
		['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
		['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

		// DSO / Omgevingsloket STAM koppelvlak.
		//
		// Re-enabled once `DSOSignatureVerifierService` implemented real
		// PKIoverheid certificate-chain (RSA) / HMAC shared-secret signature
		// verification — see
		// openspec/changes/dso-stam-pkioverheid-signature-verification. Every
		// request now MUST carry an `X-DSO-Signature` header that
		// cryptographically verifies before the payload is parsed; the
		// previous string-presence-only placeholder has been removed.
		['name' => 'dSO#receiveVerzoek', 'url' => '/api/dso/stam/verzoeken', 'verb' => 'POST'],

		// Peppol Access Point connector (openspec/changes/peppol-access-point-connector).
		// Participant/SMP lookup is an authenticated NC-session call (production
		// binding for shillinq's PeppolTransmissionAdapterInterface::lookupParticipant).
		// Outbound transmission is event-driven (PeppolOutboundConsumer), not a route.
		['name' => 'peppol#participants', 'url' => '/api/peppol/participants/{peppolId}', 'verb' => 'GET', 'requirements' => ['peppolId' => '.+']],
		// Inbound AP delivery callbacks / document notifications — gated by webhook
		// signature (HMAC), not an NC session; see PeppolController::inbound().
		['name' => 'peppol#inbound', 'url' => '/api/peppol/inbound', 'verb' => 'POST'],

		// LTI 1.3 / LTI Advantage adapter (openspec/changes/lti-13-platform).
		// Dedicated controller (DSOController precedent), not the generic
		// Endpoint pipeline — every route here is called by an external
		// Platform/Tool, never by an NC session; authentication is the
		// protocol itself (signed id_token / RFC 7523 client assertion /
		// previously-issued access token), enforced inside LtiController.
		['name' => 'lti#login', 'url' => '/api/lti/{deployment}/login', 'verb' => 'GET'],
		['name' => 'lti#login', 'url' => '/api/lti/{deployment}/login', 'verb' => 'POST'],
		['name' => 'lti#launch', 'url' => '/api/lti/{deployment}/launch', 'verb' => 'POST'],
		['name' => 'lti#token', 'url' => '/api/lti/token', 'verb' => 'POST'],
		['name' => 'lti#agsScore', 'url' => '/api/lti/{deployment}/ags/lineitems/{lineItemId}/scores', 'verb' => 'POST', 'requirements' => ['lineItemId' => '.+']],
		['name' => 'lti#agsLineItem', 'url' => '/api/lti/{deployment}/ags/lineitems/{lineItemId}', 'verb' => 'GET', 'requirements' => ['lineItemId' => '.+']],
		['name' => 'lti#nrpsMembership', 'url' => '/api/lti/{deployment}/nrps/membership', 'verb' => 'GET'],
		['name' => 'lti#jwks', 'url' => '/.well-known/lti/{registrationType}/{registrationUuid}/jwks.json', 'verb' => 'GET'],
		// Tenant-wide key management (Beheer > Authenticatie) — admin-gated, CSRF-protected.
		['name' => 'lti#generateKey', 'url' => '/api/lti/{registrationType}/{registrationUuid}/keys/generate', 'verb' => 'POST'],
		['name' => 'lti#rotateKey', 'url' => '/api/lti/{registrationType}/{registrationUuid}/keys/rotate', 'verb' => 'POST'],

		// EUDI wallet credential issuance — OpenID4VCI pre-authorized code flow
		// (openspec/changes/eudi-wallet-credential-issuance). Dedicated controller
		// (DSOController/LtiController precedent), not the generic Endpoint
		// pipeline. Per design.md D-ROUTE these four wallet-facing routes are
		// registered ONLY because their verification is real and tested
		// (EudiCredentialOfferService::exchangeToken()/issueCredential()'s atomic
		// consume-on-read + proof-of-possession checks) — never ahead of it, per
		// the DSOController removed-route precedent this design cites.
		['name' => 'eudiWallet#issuerMetadata', 'url' => '/.well-known/openid-credential-issuer', 'verb' => 'GET'],
		['name' => 'eudiWallet#resolveOffer', 'url' => '/api/eudi/credential-offers/{id}', 'verb' => 'GET'],
		['name' => 'eudiWallet#token', 'url' => '/api/eudi/token', 'verb' => 'POST'],
		['name' => 'eudiWallet#credential', 'url' => '/api/eudi/credential', 'verb' => 'POST'],
		['name' => 'eudiWallet#statusList', 'url' => '/api/eudi/status-lists/{id}', 'verb' => 'GET'],
		// App-facing offer creation + revocation — consumer-gated (REQ-CON-001 +
		// authorization-jwt REQ-001), not an NC session, hence #[PublicPage] too.
		['name' => 'eudiWallet#createOffer', 'url' => '/api/eudi/credential-offers', 'verb' => 'POST'],
		['name' => 'eudiWallet#revoke', 'url' => '/api/eudi/credential-offers/{id}/revoke', 'verb' => 'POST'],
		// Tenant-wide issuer key management (Beheer > Authenticatie) — admin-gated, CSRF-protected.
		['name' => 'eudiIssuerKeyAdmin#status', 'url' => '/api/admin/eudi/keys', 'verb' => 'GET'],
		['name' => 'eudiIssuerKeyAdmin#generateKey', 'url' => '/api/admin/eudi/keys/generate', 'verb' => 'POST'],
		['name' => 'eudiIssuerKeyAdmin#rotateKey', 'url' => '/api/admin/eudi/keys/rotate', 'verb' => 'POST'],

		// PSD2 AIS bank-feed connector (openspec/changes/psd2-ais-bank-feed-connector).
		// Redirect-based SCA consent flow: connect returns the bank SCA URL, the bank
		// redirects the operator back to callback (GET — no NC request token possible
		// on an external redirect; the in-body pending-requisition validation + action
		// RBAC are the auth body; see Psd2Controller). The scheduled transaction sync
		// is cron-driven (BankfeedSyncJob), not a route.
		['name' => 'psd2#connect', 'url' => '/api/psd2/connect', 'verb' => 'POST'],
		['name' => 'psd2#callback', 'url' => '/api/psd2/callback', 'verb' => 'GET'],
		['name' => 'psd2#discoverAccounts', 'url' => '/api/psd2/connections/{connectionId}/accounts', 'verb' => 'POST'],

		// Source endpoints
		['name' => 'sources#test', 'url' => '/api/sources/test/{id}', 'verb' => 'POST'],
		['name' => 'sources#logs', 'url' => '/api/sources/logs', 'verb' => 'GET'],
		// sources#statistics route removed — controller method was deleted by the
		// chain-C agent's overreach. Dashboard stats now come from declarative
		// manifest widgets resolving against OR's aggregate endpoint.

		// Job endpoints
		['name' => 'jobs#run', 'url' => '/api/jobs/run/{id}', 'verb' => 'POST'],
		['name' => 'jobs#test', 'url' => '/api/jobs/test/{id}', 'verb' => 'POST'],
		['name' => 'jobs#logs', 'url' => '/api/jobs/logs', 'verb' => 'GET'],
		// jobs#statistics route removed — controller method was deleted by the
		// chain-C agent's overreach. Stats now come from manifest widgets.

		// Endpoint endpoints
		// endpoints#test route removed — controller method was deleted by agent.
		['name' => 'endpoints#logs', 'url' => '/api/endpoints/logs', 'verb' => 'GET'],
		// endpoints#statistics route removed — controller method was deleted by agent.

		// Synchronization endpoints
		['name' => 'synchronizations#run', 'url' => '/api/synchronizations/{id}/run', 'verb' => 'POST'],
		['name' => 'synchronizations#test', 'url' => '/api/synchronizations/{id}/test', 'verb' => 'POST'],
		['name' => 'synchronizations#logs', 'url' => '/api/synchronizations/logs', 'verb' => 'GET'],
		['name' => 'synchronizations#statistics', 'url' => '/api/synchronizations/statistics', 'verb' => 'GET'],
		['name' => 'synchronizations#contracts', 'url' => '/api/synchronizations/contracts/{id}', 'verb' => 'GET'],

		// Mapping endpoints
		['name' => 'mappings#test', 'url' => '/api/mappings/test', 'verb' => 'POST'],
		['name' => 'mappings#saveObject', 'url' => '/api/mappings/objects', 'verb' => 'POST'],
		['name' => 'mappings#getObjects', 'url' => '/api/mappings/objects', 'verb' => 'GET'],

		// Running endpoints - allow any path after /api/endpoints/
        // endpoints#preflightedCors was restored with the DSO/CORS runtime fix.
		['name' => 'endpoints#preflightedCors', 'url' => '/api/endpoint/{_path}', 'verb' => 'OPTIONS', 'requirements' => ['_path' => '.+']],
		['name' => 'endpoints#handlePath', 'postfix' => 'read', 'url' => '/api/endpoint/{_path}', 'verb' => 'GET', 'requirements' => ['_path' => '.+']],
		['name' => 'endpoints#handlePath', 'postfix' => 'update', 'url' => '/api/endpoint/{_path}', 'verb' => 'PUT', 'requirements' => ['_path' => '.+']],
		['name' => 'endpoints#handlePath', 'postfix' => 'partialupdate', 'url' => '/api/endpoint/{_path}', 'verb' => 'PATCH', 'requirements' => ['_path' => '.+']],
		['name' => 'endpoints#handlePath', 'postfix' => 'create', 'url' => '/api/endpoint/{_path}', 'verb' => 'POST', 'requirements' => ['_path' => '.+']],
		['name' => 'endpoints#handlePath', 'postfix' => 'destroy', 'url' => '/api/endpoint/{_path}', 'verb' => 'DELETE', 'requirements' => ['_path' => '.+']],

		// Import & Export
		// Import/Export routes deleted in chain-C OR-cutover. OR provides:
		//   POST /api/registers/{id}/import
		//   POST /api/configurations/{id}/import
		//   POST /api/objects/{register}/{schema}/  (single-object create)
		//   GET  /api/registers/{id}/export
		//   GET  /api/objects/{register}/{schema}/export
		//   GET  /api/objects/{register}/{schema}/{id}  (single-object read)
		// Slug-translation per ADR-015 is now a thin SlugTranslatorService decorator.
		// See openspec/changes/openconnector-services-direct-or-usage/proposal.md § 2a.

		// Event messages
		['name' => 'events#messages', 'url' => '/api/events/{id}/messages', 'verb' => 'GET'],

		// Subscription management
		['name' => 'events#subscriptions', 'url' => '/api/events/subscriptions', 'verb' => 'GET'],
		['name' => 'events#subscriptionMessages', 'url' => '/api/events/subscriptions/{subscriptionId}/messages', 'verb' => 'GET'],
		['name' => 'events#subscribe', 'url' => '/api/events/subscriptions', 'verb' => 'POST'],
		['name' => 'events#updateSubscription', 'url' => '/api/events/subscriptions/{subscriptionId}', 'verb' => 'PUT'],
		['name' => 'events#unsubscribe', 'url' => '/api/events/subscriptions/{subscriptionId}', 'verb' => 'DELETE'],

		// Pull-based delivery
		['name' => 'events#pull', 'url' => '/api/events/subscriptions/{subscriptionId}/pull', 'verb' => 'GET'],

		// Webhook signing secret lifecycle (admin-only, CSRF intact)
		['name' => 'events#generateSigningSecret', 'url' => '/api/events/subscriptions/{subscriptionId}/signing-secret', 'verb' => 'POST'],
		['name' => 'events#rotateSigningSecret', 'url' => '/api/events/subscriptions/{subscriptionId}/signing-secret/rotate', 'verb' => 'POST'],

		// Dead-letter queue inspection and replay (admin-only, CSRF intact)
		['name' => 'events#deadLetterIndex', 'url' => '/api/events/dead-letter', 'verb' => 'GET'],
		['name' => 'events#bulkReplay', 'url' => '/api/events/dead-letter/replay', 'verb' => 'POST'],
		['name' => 'events#bulkDiscard', 'url' => '/api/events/dead-letter/discard', 'verb' => 'POST'],
		['name' => 'events#deadLetterShow', 'url' => '/api/events/dead-letter/{id}', 'verb' => 'GET'],
		['name' => 'events#replay', 'url' => '/api/events/dead-letter/{id}/replay', 'verb' => 'POST'],
		['name' => 'events#discard', 'url' => '/api/events/dead-letter/{id}/discard', 'verb' => 'POST'],

		// Logs endpoints (LogsController — synchronization_log schema)
		['name' => 'logs#index', 'url' => '/api/logs', 'verb' => 'GET'],
		['name' => 'logs#show', 'url' => '/api/logs/{id}', 'verb' => 'GET'],
		['name' => 'logs#destroy', 'url' => '/api/logs/{id}', 'verb' => 'DELETE'],
		['name' => 'logs#statistics', 'url' => '/api/logs/statistics', 'verb' => 'GET'],
		['name' => 'logs#export', 'url' => '/api/logs/export', 'verb' => 'GET'],

		// Logs sub-endpoints on SynchronizationsController
		['name' => 'synchronizations#logsStatistics', 'url' => '/api/synchronizations/logs/statistics', 'verb' => 'GET'],
		['name' => 'synchronizations#logsExport', 'url' => '/api/synchronizations/logs/export', 'verb' => 'GET'],
		['name' => 'synchronizations#deleteLog', 'url' => '/api/synchronizations/logs/{id}', 'verb' => 'DELETE'],

		// Synchronization Contracts endpoints
		['name' => 'synchronizationContracts#statistics', 'url' => '/api/synchronization-contracts/statistics', 'verb' => 'GET'],
		['name' => 'synchronizationContracts#performance', 'url' => '/api/synchronization-contracts/performance', 'verb' => 'GET'],
		['name' => 'synchronizationContracts#export', 'url' => '/api/synchronization-contracts/export', 'verb' => 'GET'],
		['name' => 'synchronizationContracts#activate', 'url' => '/api/synchronization-contracts/{id}/activate', 'verb' => 'POST'],
		['name' => 'synchronizationContracts#deactivate', 'url' => '/api/synchronization-contracts/{id}/deactivate', 'verb' => 'POST'],
		['name' => 'synchronizationContracts#execute', 'url' => '/api/synchronization-contracts/{id}/execute', 'verb' => 'POST'],

		// PDOK Locatieserver proxy endpoints (auth: #[NoAdminRequired])
		['name' => 'pdok#suggestAction', 'url' => '/api/pdok/suggest', 'verb' => 'GET'],
		['name' => 'pdok#lookupAction', 'url' => '/api/pdok/lookup/{id}', 'verb' => 'GET'],
		['name' => 'pdok#freeAction', 'url' => '/api/pdok/free', 'verb' => 'GET'],
		['name' => 'pdok#reverseAction', 'url' => '/api/pdok/reverse', 'verb' => 'GET'],

		// User CORS preflight endpoints
		['name' => 'user#preflightedCorsMe', 'url' => '/api/user/me', 'verb' => 'OPTIONS'],
		['name' => 'user#preflightedCorsLogin', 'url' => '/api/user/login', 'verb' => 'OPTIONS'],

		// User endpoints
		['name' => 'user#me', 'url' => '/api/user/me', 'verb' => 'GET'],
		['name' => 'user#updateMe', 'url' => '/api/user/me', 'verb' => 'PUT'],
		['name' => 'user#login', 'url' => '/api/user/login', 'verb' => 'POST'],
		['name' => 'user#logout', 'url' => '/api/user/logout', 'verb' => 'POST'],

		// Settings endpoints
		// Settings routes shrunk in chain-C OR-cutover:
		// - GET /api/settings/stats — replaced by manifest dashboard widgets resolving against OR's aggregate endpoint
		// - GET /api/settings + PUT /api/settings — replaced by OR's /api/settings/* surface
		// Only the connector-specific rebase action remains. Postgres portability tracked at #822.
		// rebase is admin-only (AuthorizedAdminSetting).
		['name' => 'settings#rebase', 'url' => '/api/settings/rebase', 'verb' => 'POST'],

		// ADR-023 action-authorization matrix (admin-only via #[AuthorizedAdminSetting])
		['name' => 'actionMatrix#getMatrix', 'url' => '/api/admin/action-matrix', 'verb' => 'GET'],
		['name' => 'actionMatrix#setMatrix', 'url' => '/api/admin/action-matrix', 'verb' => 'PUT'],

		// DSO STAM PKIoverheid signing configuration (admin-only via #[AuthorizedAdminSetting])
		['name' => 'dsoPkiSettings#getConfig', 'url' => '/api/admin/dso-pki-config', 'verb' => 'GET'],
		['name' => 'dsoPkiSettings#setConfig', 'url' => '/api/admin/dso-pki-config', 'verb' => 'PUT'],

		// Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog) —
		// served by OpenRegister's AppHost GenericPreferencesController (ADR-040). The leaf-namespaced
		// controller class is bound to the engine generic in lib/AppInfo/Application.php (appName=openconnector,
		// so the `pref_` user-value namespace stays scoped to this app). URLs + JSON contract unchanged.
		['name' => 'AppHost\Controller\GenericPreferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
		['name' => 'AppHost\Controller\GenericPreferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

		// UI page routes for SPA deep links
		['name' => 'ui#dashboard', 'url' => '/', 'verb' => 'GET'],
		['name' => 'ui#sources', 'url' => '/sources', 'verb' => 'GET'],
		['name' => 'ui#sourcesLogs', 'url' => '/sources/logs', 'verb' => 'GET'],
		['name' => 'ui#endpoints', 'url' => '/endpoints', 'verb' => 'GET'],
		['name' => 'ui#endpointsLogs', 'url' => '/endpoints/logs', 'verb' => 'GET'],
		['name' => 'ui#endpointsId', 'url' => '/endpoints/{id}', 'verb' => 'GET'],
		['name' => 'ui#consumers', 'url' => '/consumers', 'verb' => 'GET'],
		['name' => 'ui#consumersId', 'url' => '/consumers/{id}', 'verb' => 'GET'],
		['name' => 'ui#webhooks', 'url' => '/webhooks', 'verb' => 'GET'],
		['name' => 'ui#jobs', 'url' => '/jobs', 'verb' => 'GET'],
		['name' => 'ui#jobsLogs', 'url' => '/jobs/logs', 'verb' => 'GET'],
		['name' => 'ui#mappings', 'url' => '/mappings', 'verb' => 'GET'],
		['name' => 'ui#mappingsId', 'url' => '/mappings/{id}', 'verb' => 'GET'],
		['name' => 'ui#rules', 'url' => '/rules', 'verb' => 'GET'],
		['name' => 'ui#rulesId', 'url' => '/rules/{id}', 'verb' => 'GET'],
		['name' => 'ui#synchronizations', 'url' => '/synchronizations', 'verb' => 'GET'],
		['name' => 'ui#synchronizationsContracts', 'url' => '/synchronizations/contracts', 'verb' => 'GET'],
		['name' => 'ui#synchronizationsLogs', 'url' => '/synchronizations/logs', 'verb' => 'GET'],
		['name' => 'ui#cloudEvents', 'url' => '/cloud-events', 'verb' => 'GET'],
		['name' => 'ui#cloudEventsEvents', 'url' => '/cloud-events/events', 'verb' => 'GET'],
		['name' => 'ui#cloudEventsEventsId', 'url' => '/cloud-events/events/{id}', 'verb' => 'GET'],
		['name' => 'ui#cloudEventsLogs', 'url' => '/cloud-events/logs', 'verb' => 'GET'],
		// SPA catch-all — serves the Vue app for any frontend route (history mode routing)
		// Catch-all SPA route: serve the Vue app for any sub-path that no specific ui#* route handles.
		// Replaces the deleted dashboard#page catch-all in the chain-C cutover.
		// MUST exclude /api/* so deleted API routes return 404, not the SPA shell.
		// Regex is `.*` (not `.+`) so the empty-path case (`/apps/openconnector/`) also
		// resolves to the Dashboard — the duplicate `ui#dashboard` controller#method name
		// with the line-112 `/` route triggers NC's last-wins binding, which means the
		// catch-all is the one that actually serves `/` at runtime.
		['name' => 'ui#dashboard', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '(?!api(/|$)).*'], 'defaults' => ['path' => '']],
	],
];
