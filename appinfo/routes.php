<?php

return [
	'resources' => [
		'Endpoints' => ['url' => 'api/endpoints'],
		'Sources' => ['url' => 'api/sources'],
		'Mappings' => ['url' => 'api/mappings'],
		'Jobs' => ['url' => 'api/jobs'],
		'Synchronizations' => ['url' => 'api/synchronizations'],
		'Consumers' => ['url' => 'api/consumers'],
		'Rules' => ['url' => 'api/rules'],
		'Events' => ['url' => 'api/events'],
		'SynchronizationContracts' => ['url' => 'api/synchronization-contracts'],
	],
	'routes' => [
		['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],

		// Metrics and health
		['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
		['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

		// DSO / Omgevingsloket STAM koppelvlak
		['name' => 'dso#receiveVerzoek', 'url' => '/api/dso/stam/verzoeken', 'verb' => 'POST'],

		['name' => 'dashboard#index', 'url' => '/api/dashboard', 'verb' => 'GET'],
		['name' => 'dashboard#getCallStats', 'url' => '/api/dashboard/callstats', 'verb' => 'GET'],
		['name' => 'dashboard#getJobStats', 'url' => '/api/dashboard/jobstats', 'verb' => 'GET'],
		['name' => 'dashboard#getSyncStats', 'url' => '/api/dashboard/syncstats', 'verb' => 'GET'],
		// Source endpoints
		['name' => 'sources#test', 'url' => '/api/sources/test/{id}', 'verb' => 'POST'],
		['name' => 'sources#logs', 'url' => '/api/sources/logs', 'verb' => 'GET'],
		['name' => 'sources#statistics', 'url' => '/api/sources/statistics', 'verb' => 'GET'],
		// Job endpoints
		['name' => 'jobs#run', 'url' => '/api/jobs/run/{id}', 'verb' => 'POST'],
		['name' => 'jobs#test', 'url' => '/api/jobs/test/{id}', 'verb' => 'POST'],
		['name' => 'jobs#logs', 'url' => '/api/jobs/logs', 'verb' => 'GET'],
		['name' => 'jobs#statistics', 'url' => '/api/jobs/statistics', 'verb' => 'GET'],
		// Endpoint endpoints
		['name' => 'endpoints#test', 'url' => '/api/endpoints/test/{id}', 'verb' => 'POST'],
		['name' => 'endpoints#logs', 'url' => '/api/endpoints/logs', 'verb' => 'GET'],
		['name' => 'endpoints#statistics', 'url' => '/api/endpoints/statistics', 'verb' => 'GET'],
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
        ['name' => 'endpoints#preflighted_cors', 'url' => '/api/endpoint/{_path}', 'verb' => 'OPTIONS', 'requirements' => ['path' => '.+']],
		['name' => 'endpoints#handlePath', 'postfix' => 'read', 'url' => '/api/endpoint/{_path}', 'verb' => 'GET', 'requirements' => ['_path' => '.+']],
		['name' => 'endpoints#handlePath', 'postfix' => 'update', 'url' => '/api/endpoint/{_path}', 'verb' => 'PUT', 'requirements' => ['_path' => '.+']],
		['name' => 'endpoints#handlePath', 'postfix' => 'partialupdate', 'url' => '/api/endpoint/{_path}', 'verb' => 'PATCH', 'requirements' => ['_path' => '.+']],
		['name' => 'endpoints#handlePath', 'postfix' => 'create', 'url' => '/api/endpoint/{_path}', 'verb' => 'POST', 'requirements' => ['_path' => '.+']],
		['name' => 'endpoints#handlePath', 'postfix' => 'destroy', 'url' => '/api/endpoint/{_path}', 'verb' => 'DELETE', 'requirements' => ['_path' => '.+']],

		// Import & Export
		['name' => 'import#import', 'url' => '/api/import', 'verb' => 'POST'],
		['name' => 'export#export', 'url' => '/api/export/{type}/{id}', 'verb' => 'GET'],

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
		['name' => 'settings#stats', 'url' => '/api/settings/stats', 'verb' => 'GET'],
		['name' => 'settings#getSettings', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'settings#updateSettings', 'url' => '/api/settings', 'verb' => 'PUT'],
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
		['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
	],
];
