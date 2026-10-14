<?php

declare(strict_types=1);

return [
	'routes' => [
		// ── Pages (session) ───────────────────────────────────────────────
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#dashboard', 'url' => '/dashboard', 'verb' => 'GET'],
		['name' => 'page#sites', 'url' => '/sites', 'verb' => 'GET'],
		['name' => 'page#site', 'url' => '/sites/{siteId}', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'page#qrSheet', 'url' => '/sites/{siteId}/qr-sheet', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'page#rounds', 'url' => '/rounds', 'verb' => 'GET'],
		['name' => 'page#round', 'url' => '/rounds/{roundId}', 'verb' => 'GET', 'requirements' => ['roundId' => '\d+']],
		['name' => 'page#journal', 'url' => '/journal', 'verb' => 'GET'],
		['name' => 'page#incidents', 'url' => '/incidents', 'verb' => 'GET'],
		['name' => 'page#reports', 'url' => '/reports', 'verb' => 'GET'],
		['name' => 'page#settingsIndex', 'url' => '/settings', 'verb' => 'GET'],
		['name' => 'page#settings', 'url' => '/settings/{section}', 'verb' => 'GET',
			'requirements' => ['section' => \OCA\TourCheck\Service\SettingsSectionCatalog::routeRequirement()]],

		// Worker web capture (session)
		['name' => 'capture#today', 'url' => '/capture', 'verb' => 'GET'],
		['name' => 'capture#scan', 'url' => '/capture/scan/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[A-Za-z0-9_-]{20,}']],

		// Kiosk web surface (station token)
		['name' => 'kiosk#home', 'url' => '/kiosk', 'verb' => 'GET'],

		// Public live object page (opaque path token)
		['name' => 'publicPage#live', 'url' => '/live/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[A-Za-z0-9_-]{40,}']],

		// ── Web JSON API (session + CSRF) ────────────────────────────────
		['name' => 'api#listSites', 'url' => '/api/sites', 'verb' => 'GET'],
		['name' => 'api#createSite', 'url' => '/api/sites', 'verb' => 'POST'],
		['name' => 'api#getSite', 'url' => '/api/sites/{siteId}', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#updateSite', 'url' => '/api/sites/{siteId}', 'verb' => 'PUT', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#archiveSite', 'url' => '/api/sites/{siteId}', 'verb' => 'DELETE', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#applyTemplate', 'url' => '/api/sites/{siteId}/template/{templateKey}', 'verb' => 'POST', 'requirements' => ['siteId' => '\d+', 'templateKey' => '[a-z0-9_-]+']],

		['name' => 'api#listCheckpoints', 'url' => '/api/sites/{siteId}/checkpoints', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#createCheckpoint', 'url' => '/api/sites/{siteId}/checkpoints', 'verb' => 'POST', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#updateCheckpoint', 'url' => '/api/checkpoints/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'api#archiveCheckpoint', 'url' => '/api/checkpoints/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'api#rotateCheckpointToken', 'url' => '/api/checkpoints/{id}/rotate-token', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#signNfcTag', 'url' => '/api/checkpoints/{id}/sign-nfc', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#checkpointSheet', 'url' => '/api/checkpoints/{id}/sheet', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

		['name' => 'api#listPlans', 'url' => '/api/sites/{siteId}/plans', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#createPlan', 'url' => '/api/sites/{siteId}/plans', 'verb' => 'POST', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#getPlan', 'url' => '/api/plans/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#updatePlan', 'url' => '/api/plans/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'api#activatePlan', 'url' => '/api/plans/{id}/activate', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#deactivatePlan', 'url' => '/api/plans/{id}/deactivate', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		['name' => 'api#listMembers', 'url' => '/api/sites/{siteId}/members', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#setMember', 'url' => '/api/sites/{siteId}/members', 'verb' => 'PUT', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#removeMember', 'url' => '/api/sites/{siteId}/members/{uid}', 'verb' => 'DELETE', 'requirements' => ['siteId' => '\d+', 'uid' => '[^/]+']],

		['name' => 'api#listRounds', 'url' => '/api/rounds', 'verb' => 'GET'],
		['name' => 'api#getRound', 'url' => '/api/rounds/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#skipRound', 'url' => '/api/rounds/{id}/skip', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#eraseUid', 'url' => '/api/sites/{siteId}/erase', 'verb' => 'POST', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#attestEvent', 'url' => '/api/events/attest', 'verb' => 'POST'],
		['name' => 'api#manualJournal', 'url' => '/api/journal', 'verb' => 'POST'],
		['name' => 'api#listJournal', 'url' => '/api/sites/{siteId}/journal', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],

		['name' => 'api#listIncidents', 'url' => '/api/incidents', 'verb' => 'GET'],
		['name' => 'api#createIncident', 'url' => '/api/incidents', 'verb' => 'POST'],
		['name' => 'api#getIncident', 'url' => '/api/incidents/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#updateIncident', 'url' => '/api/incidents/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'api#createCorrective', 'url' => '/api/incidents/{id}/actions', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#updateCorrective', 'url' => '/api/actions/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'api#verifyCorrective', 'url' => '/api/actions/{id}/verify', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		['name' => 'api#listStations', 'url' => '/api/sites/{siteId}/stations', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#createStation', 'url' => '/api/sites/{siteId}/stations', 'verb' => 'POST', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#revokeStation', 'url' => '/api/stations/{id}/revoke', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#rotateStationToken', 'url' => '/api/stations/{id}/rotate', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#bindStationCheckpoint', 'url' => '/api/stations/{id}/checkpoint', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#pairingCode', 'url' => '/api/stations/{id}/pairing-code', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#listStationMembers', 'url' => '/api/stations/{id}/members', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#upsertStationMember', 'url' => '/api/stations/{id}/members', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'api#deactivateMember', 'url' => '/api/station-members/{id}/deactivate', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#resetMemberPin', 'url' => '/api/station-members/{id}/reset-pin', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		['name' => 'api#generateReport', 'url' => '/api/sites/{siteId}/reports', 'verb' => 'POST', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#listReports', 'url' => '/api/sites/{siteId}/reports', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#downloadReport', 'url' => '/api/reports/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#claimBundle', 'url' => '/api/sites/{siteId}/claim-bundle', 'verb' => 'POST', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#listSchedules', 'url' => '/api/sites/{siteId}/report-schedules', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#upsertSchedule', 'url' => '/api/sites/{siteId}/report-schedules', 'verb' => 'PUT', 'requirements' => ['siteId' => '\d+']],

		['name' => 'api#listLiveLinks', 'url' => '/api/sites/{siteId}/live-links', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#createLiveLink', 'url' => '/api/sites/{siteId}/live-links', 'verb' => 'POST', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#revokeLiveLink', 'url' => '/api/live-links/{id}/revoke', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		['name' => 'api#dashboard', 'url' => '/api/dashboard', 'verb' => 'GET'],
		['name' => 'capture#resolve', 'url' => '/api/capture/resolve', 'verb' => 'POST'],
		['name' => 'capture#confirm', 'url' => '/api/capture/confirm', 'verb' => 'POST'],
		['name' => 'capture#close', 'url' => '/api/capture/close', 'verb' => 'POST'],
		['name' => 'capture#signal', 'url' => '/api/capture/signal', 'verb' => 'POST'],
		['name' => 'kiosk#confirm', 'url' => '/api/kiosk/confirm', 'verb' => 'POST'],
		['name' => 'api#searchUsers', 'url' => '/api/admin/users/search', 'verb' => 'GET'],
		['name' => 'api#searchGroups', 'url' => '/api/admin/groups/search', 'verb' => 'GET'],
		['name' => 'api#saveSettings', 'url' => '/api/admin/settings', 'verb' => 'POST'],
		['name' => 'api#getSettings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'api#applyLicense', 'url' => '/api/admin/license', 'verb' => 'POST'],
		['name' => 'api#clearLicense', 'url' => '/api/admin/license', 'verb' => 'DELETE'],
		['name' => 'api#verifyChain', 'url' => '/api/sites/{siteId}/verify-chain', 'verb' => 'POST', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#anchorNow', 'url' => '/api/sites/{siteId}/anchor', 'verb' => 'POST', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#exportCsv', 'url' => '/api/sites/{siteId}/export', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#driveSegments', 'url' => '/api/drive-segments', 'verb' => 'GET'],
		['name' => 'api#keys', 'url' => '/api/sites/{siteId}/keys', 'verb' => 'GET', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#createKey', 'url' => '/api/sites/{siteId}/keys', 'verb' => 'POST', 'requirements' => ['siteId' => '\d+']],
		['name' => 'api#takeKey', 'url' => '/api/keys/{id}/take', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#returnKey', 'url' => '/api/keys/{id}/return', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ── Companion API (app-password session) ─────────────────────────
		['name' => 'companion#entitlement', 'url' => '/api/companion/entitlement', 'verb' => 'GET'],
		['name' => 'companion#rounds', 'url' => '/api/companion/rounds', 'verb' => 'GET'],
		['name' => 'companion#resolveToken', 'url' => '/api/companion/tokens/resolve', 'verb' => 'POST'],
		['name' => 'companion#submitEvents', 'url' => '/api/companion/events', 'verb' => 'POST'],
		['name' => 'companion#createIncident', 'url' => '/api/companion/incidents', 'verb' => 'POST'],
		['name' => 'companion#uploadPhoto', 'url' => '/api/companion/photos', 'verb' => 'POST'],
		['name' => 'companion#uploadAudio', 'url' => '/api/companion/audio', 'verb' => 'POST'],
		['name' => 'companion#briefingShown', 'url' => '/api/companion/briefing-shown', 'verb' => 'POST'],
		['name' => 'companion#practiceRound', 'url' => '/api/companion/practice', 'verb' => 'GET'],
		['name' => 'companion#position', 'url' => '/api/companion/positions', 'verb' => 'POST'],
		['name' => 'companion#stationMembers', 'url' => '/api/companion/station/members', 'verb' => 'GET'],
		['name' => 'companion#stationConfirm', 'url' => '/api/companion/station/confirm', 'verb' => 'POST'],
		['name' => 'companion#stationKeys', 'url' => '/api/companion/station/keys', 'verb' => 'GET'],
		['name' => 'companion#stationKeyAction', 'url' => '/api/companion/station/keys/action', 'verb' => 'POST'],
		['name' => 'companion#pairStation', 'url' => '/api/companion/stations/pair', 'verb' => 'POST'],
	],
];
