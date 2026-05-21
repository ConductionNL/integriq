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

		// DSO / Omgevingsloket STAM koppelvlak — route disabled, controller
		// hasn't been ported to the post-OR-cutover surface yet. Re-enable
		// once DsoController lands (see GH backlog).
		// ['name' => 'dso#receiveVerzoek', 'url' => '/api/dso/stam/verzoeken', 'verb' => 'POST'],
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
        // endpoints#preflighted_cors removed — agent deleted the CORS preflight method.
        // If cross-origin clients need preflight on /api/endpoint/*, re-add the
        // method on EndpointsController and restore this route.
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

		// Logs endpoints
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
		['name' => 'settings#rebase', 'url' => '/api/settings/rebase', 'verb' => 'POST'],

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
		['name' => 'ui#import', 'url' => '/import', 'verb' => 'GET'],
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
