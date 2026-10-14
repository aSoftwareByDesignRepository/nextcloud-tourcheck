/* TourCheck client portal — aggregate-only read view. No worker names, no
 * positions, no trails (NN-03). Vanilla JS, no build step. */
(function () {
	'use strict';
	var root = document.querySelector('[data-tck-portal-site]');
	if (!root) { return; }

	function t(key, vars) {
		try { return OC.L10N.translate('tourcheck', key, vars) || key; } catch (e) { return key; }
	}
	function route(p) { return OC.generateUrl('/apps/tourcheck' + p); }
	function api(method, path) {
		var h = { 'OCS-APIREQUEST': 'true' };
		if (OC.requestToken) { h.requesttoken = OC.requestToken; }
		return fetch(route(path), { method: method, headers: h, credentials: 'same-origin' })
			.then(function (res) { return res.json().catch(function () { return { success: false, message: res.statusText }; }).then(function (j) { j._status = res.status; return j; }); });
	}
	function esc(s) { var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }
	function badge(label, variant) {
		return '<span class="tck-badge' + (variant ? ' tck-badge--' + variant : '') + '">' +
			'<span class="tck-badge__dot" aria-hidden="true"></span>' + esc(label) + '</span>';
	}

	var siteId = parseInt(root.getAttribute('data-tck-portal-site') || '0', 10);

	function kpiCard(label, value, sub) {
		var el = document.createElement('div');
		el.className = 'tck-kpi';
		el.innerHTML = '<span class="tck-kpi__value">' + esc(value) + '</span>' +
			'<span class="tck-kpi__label">' + esc(label) + '</span>' +
			(sub ? '<span class="tck-kpi__sub">' + esc(sub) + '</span>' : '');
		return el;
	}

	function loadSites() {
		var list = document.getElementById('tck-portal-site-list');
		var empty = document.getElementById('tck-portal-empty');
		api('GET', '/api/portal/sites').then(function (j) {
			if (!j.success || !list) { return; }
			var sites = j.data.sites || [];
			list.innerHTML = '';
			if (empty) { empty.hidden = sites.length !== 0; }
			sites.forEach(function (s) {
				var li = document.createElement('li');
				li.className = 'tck-list__row';
				var a = document.createElement('a');
				a.className = 'tck-list__title';
				a.href = route('/portal/sites/' + s.id);
				a.textContent = s.name || '#' + s.id;
				var main = document.createElement('div');
				main.className = 'tck-list__main';
				main.appendChild(a);
				li.appendChild(main);
				list.appendChild(li);
			});
		});
	}

	function loadOverview() {
		api('GET', '/api/portal/sites/' + siteId + '/overview').then(function (j) {
			var grid = document.getElementById('tck-portal-overview');
			var nameEl = document.getElementById('tck-portal-site-name');
			if (!j.success || !grid) { return; }
			var d = j.data;
			if (nameEl && d.site) { nameEl.textContent = d.site.name || ''; }
			grid.innerHTML = '';
			grid.appendChild(kpiCard(t('Site'), (d.site && d.site.name) || '—'));
			grid.appendChild(kpiCard(t('Rounds this week'), String(d.rounds_week ?? '—')));
			grid.appendChild(kpiCard(t('Completed'), d.completed_week_pct === null || d.completed_week_pct === undefined ? '—' : d.completed_week_pct + '%'));
			grid.appendChild(kpiCard(t('Open alerts'), String(d.open_alerts ?? 0)));
		});
	}

	function loadKpis() {
		var from = document.getElementById('tck-portal-kpi-from').value;
		var to = document.getElementById('tck-portal-kpi-to').value;
		api('GET', '/api/portal/sites/' + siteId + '/kpis' + (from && to ? '?from=' + from + '&to=' + to : '')).then(function (j) {
			var grid = document.getElementById('tck-portal-kpis');
			if (!j.success || !grid) { return; }
			var d = j.data;
			grid.innerHTML = '';
			grid.appendChild(kpiCard(t('Checkpoint hit rate'), d.hit_rate_pct === null ? '—' : d.hit_rate_pct + '%', t('Target: %d percent', [d.sla_hit_target_pct])));
			grid.appendChild(kpiCard(t('Punctuality'), d.punctuality_pct === null ? '—' : d.punctuality_pct + '%', t('within %d min', [d.sla_punctuality_min])));
			grid.appendChild(kpiCard(t('Coverage'), d.coverage_pct === null ? '—' : d.coverage_pct + '%', t('rounds not missed')));
			grid.appendChild(kpiCard(t('Open incidents'), String(d.open_incidents)));
			if (d.insufficient) {
				var note = document.createElement('p');
				note.className = 'tck-hint';
				note.textContent = t('Too few rounds in this period for meaningful rates.');
				grid.appendChild(note);
			}
		});
	}

	var INC_BADGE = { open: 'danger', action: 'warning', verify: 'info', done: 'success', closed: 'success' };
	var INC_LABEL = { open: 'Open', action: 'In progress', verify: 'To verify', done: 'Done', closed: 'Closed' };
	function loadIncidents() {
		var ul = document.getElementById('tck-portal-incidents');
		var empty = document.getElementById('tck-portal-incidents-empty');
		api('GET', '/api/portal/sites/' + siteId + '/incidents').then(function (j) {
			if (!j.success || !ul) { return; }
			var rows = j.data.incidents || [];
			ul.innerHTML = '';
			if (empty) { empty.hidden = rows.length !== 0; }
			rows.forEach(function (i) {
				var li = document.createElement('li');
				li.className = 'tck-list__row';
				li.innerHTML = '<div class="tck-list__main"><span class="tck-list__title">' + esc(i.title || '#') + '</span>' +
					'<span class="tck-list__sub">' + esc((i.createdAt || '').replace('T', ' ').slice(0, 16)) + '</span></div>' +
					'<div class="tck-list__aside">' + badge(t(INC_LABEL[i.status] || i.status), INC_BADGE[i.status] || 'info') + '</div>';
				ul.appendChild(li);
			});
		});
	}

	function loadReports() {
		var ul = document.getElementById('tck-portal-reports');
		var empty = document.getElementById('tck-portal-reports-empty');
		api('GET', '/api/portal/sites/' + siteId + '/reports').then(function (j) {
			if (!j.success || !ul) { return; }
			var rows = j.data.reports || [];
			ul.innerHTML = '';
			if (empty) { empty.hidden = rows.length !== 0; }
			rows.forEach(function (r) {
				var li = document.createElement('li');
				li.className = 'tck-list__row';
				li.innerHTML = '<div class="tck-list__main"><span class="tck-list__title">' + esc(r.period || '#') + '</span>' +
					'<span class="tck-list__sub">' + esc((r.createdAt || '').replace('T', ' ').slice(0, 16)) + '</span></div>' +
					'<div class="tck-list__aside"><a class="tck-btn" href="' + esc(route('/api/portal/reports/' + r.id + '/download')) + '">' + esc(t('Download')) + '</a></div>';
				ul.appendChild(li);
			});
		});
	}

	var kf = document.getElementById('tck-portal-kpi-from'), kt = document.getElementById('tck-portal-kpi-to');
	if (kf && kt) {
		var d = new Date(); kt.value = d.toISOString().slice(0, 10);
		d.setDate(d.getDate() - 30); kf.value = d.toISOString().slice(0, 10);
	}
	var kpiBtn = document.getElementById('tck-portal-kpi-load');
	if (kpiBtn) { kpiBtn.addEventListener('click', loadKpis); }

	if (siteId > 0) {
		document.getElementById('tck-portal-detail').classList.remove('tck-hidden');
		document.getElementById('tck-portal-sites').classList.add('tck-hidden');
		loadOverview(); loadKpis(); loadIncidents(); loadReports();
	} else {
		loadSites();
	}
})();
