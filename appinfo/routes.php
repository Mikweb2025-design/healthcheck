<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#report', 'url' => '/report', 'verb' => 'GET'],
		['name' => 'page#infographic', 'url' => '/infographic', 'verb' => 'GET'],
		['name' => 'page#manifest', 'url' => '/manifest', 'verb' => 'GET'],
		['name' => 'page#serviceWorker', 'url' => '/sw.js', 'verb' => 'GET'],
		['name' => 'page#ring', 'url' => '/ring/{kind}/{value}.svg', 'verb' => 'GET', 'requirements' => ['kind' => '[a-z]+', 'value' => '[0-9a-z]+']],

		['name' => 'api#overview', 'url' => '/api/overview', 'verb' => 'GET'],
		['name' => 'api#series', 'url' => '/api/series', 'verb' => 'GET'],
		['name' => 'api#report', 'url' => '/api/report', 'verb' => 'GET'],
		['name' => 'api#infographic', 'url' => '/api/infographic', 'verb' => 'GET'],
		['name' => 'api#status', 'url' => '/api/status', 'verb' => 'GET'],
		['name' => 'api#importNow', 'url' => '/api/import', 'verb' => 'POST'],
		['name' => 'api#settings', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'api#updateSettings', 'url' => '/api/settings', 'verb' => 'PUT'],
		['name' => 'api#newSecret', 'url' => '/api/settings/secret', 'verb' => 'POST'],
		['name' => 'api#calendarSync', 'url' => '/api/calendar/sync', 'verb' => 'POST'],
		['name' => 'api#calendarRemove', 'url' => '/api/calendar/remove', 'verb' => 'POST'],
		['name' => 'api#requestReport', 'url' => '/api/ai/request', 'verb' => 'POST'],
		['name' => 'api#reports', 'url' => '/api/ai/reports', 'verb' => 'GET'],
		['name' => 'api#chats', 'url' => '/api/chat', 'verb' => 'GET'],
		['name' => 'api#ask', 'url' => '/api/chat', 'verb' => 'POST'],
		['name' => 'api#deleteAll', 'url' => '/api/data', 'verb' => 'DELETE'],

		['name' => 'api#automationSummary', 'url' => '/api/automation/summary', 'verb' => 'GET'],
		['name' => 'api#automationReport', 'url' => '/api/automation/report', 'verb' => 'POST'],
	],
];
