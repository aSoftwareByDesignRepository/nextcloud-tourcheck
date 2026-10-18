/* TourCheck operations board — map, alerts, tasks, logbook, KPIs.
 * Vanilla JS, no build step. Leaflet is vendored (js/vendor/leaflet). */
(function () {
	'use strict';
	if (!document.querySelector('[data-tck-page="ops"]')) { return; }

	function t(key) {
		try { return OC.L10N.translate('tourcheck', key) || key; } catch (e) { return key; }
	}
	/* OC.L10N.translate does not sprintf — positional %d/%s in a translated
	 * string must be substituted here or users read raw placeholders. */
	function tf(key, vars) {
		var str = t(key);
		(vars || []).forEach(function (v) { str = str.replace(/%[ds]/, v === null || v === undefined ? '—' : String(v)); });
		return str;
	}
	function badge(label, variant) {
		return '<span class="tck-badge' + (variant ? ' tck-badge--' + variant : '') + '">' +
			'<span class="tck-badge__dot" aria-hidden="true"></span>' + esc(label) + '</span>';
	}
	var ROUND_BADGE = { scheduled: '', active: 'info', missed: 'danger', completed: 'success', partial: 'warning', skipped: 'warning' };
	function route(p) { return OC.generateUrl('/apps/tourcheck' + p); }
	function api(method, path, body) {
		/* No OCS-APIRequest — session mutations ride requesttoken (CSRF). */
		var h = { 'Content-Type': 'application/json' };
		if (OC.requestToken) { h.requesttoken = OC.requestToken; }
		return fetch(route(path), { method: method, headers: h, credentials: 'same-origin', body: body === undefined ? undefined : JSON.stringify(body) })
			.then(function (res) { return res.json().catch(function () { return { success: false, message: res.statusText }; }).then(function (j) { j._status = res.status; return j; }); });
	}

	/* Toast contract identical to app.js (dedup on kind+text, timer reset). */
	var toastBox = null, toastTimers = {};
	function toast(kind, text) {
		if (!toastBox) { toastBox = document.createElement('div'); toastBox.className = 'tck-toasts'; document.body.appendChild(toastBox); }
		var ttl = kind === 'error' ? 7000 : 4000, key = kind + '|' + text;
		var existing = toastBox.querySelector('[data-tck-toast-key="' + CSS.escape(key) + '"]');
		if (existing && toastTimers[key]) { clearTimeout(toastTimers[key]); toastTimers[key] = setTimeout(function () { existing.remove(); delete toastTimers[key]; }, ttl); return; }
		var el = document.createElement('div');
		el.className = 'tck-toast' + (kind === 'error' ? ' tck-toast--error' : '');
		el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		el.setAttribute('data-tck-toast-key', key);
		var txt = document.createElement('span'); txt.textContent = String(text);
		var close = document.createElement('button'); close.type = 'button'; close.className = 'tck-toast__close'; close.setAttribute('aria-label', t('Dismiss')); close.textContent = '✕';
		close.addEventListener('click', function () { clearTimeout(toastTimers[key]); delete toastTimers[key]; el.remove(); });
		el.appendChild(txt); el.appendChild(close); toastBox.appendChild(el);
		toastTimers[key] = setTimeout(function () { el.remove(); delete toastTimers[key]; }, ttl);
	}
	function announce(txt) { var r = document.getElementById('tck-live-region'); if (r) { r.textContent = txt; } }
	function esc(s) { var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }
	// ISO → locale datetime (de: 18.10.2026, 11:30); falls back to ISO slice
	function fmtTs(ts) {
		var d = new Date(ts);
		return isNaN(d) ? String(ts || '').slice(0, 16).replace('T', ' ')
			: d.toLocaleString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
	}

	var siteSel = document.getElementById('tck-site-select');
	function currentSite() { return siteSel ? parseInt(siteSel.value, 10) : parseInt((document.getElementById('tck-alerts') || {}).dataset.siteId || '0', 10); }

	/* ── Alerts ─────────────────────────────────────────────────────── */
	function loadAlerts() {
		var siteId = currentSite();
		if (!siteId) { return; }
		api('GET', '/api/sites/' + siteId + '/alerts').then(function (j) {
			var box = document.getElementById('tck-alerts');
			var empty = document.getElementById('tck-alerts-empty');
			var count = document.getElementById('tck-alert-count');
			if (!j.success) { return; }
			var open = (j.data.alerts || []).filter(function (a) { return a.state !== 'resolved' && a.state !== 'resolved_false_alarm'; });
			if (count) { count.textContent = open.length === 0 ? '' : String(open.length); }
			box.querySelectorAll('.tck-alert-card').forEach(function (n) { n.remove(); });
			if (empty) { empty.hidden = open.length !== 0; }
			open.forEach(function (a) {
				var card = document.createElement('div');
				card.className = 'tck-alert-card tck-alert-card--' + (a.severity === 'critical' ? 'critical' : 'high');
				var typeLabel = { mandown: t('Man-down'), sos: t('SOS'), zone_violation: t('Zone violation'), coverage_gap: t('Coverage gap') }[a.type] || a.type;
				card.innerHTML =
					'<div class="tck-alert-card__main"><strong>' + esc(typeLabel) + '</strong>' +
					'<span class="tck-alert-card__meta">' + esc(a.workerName || a.workerUid || '—') + ' · ' + esc(fmtTs(a.firedAt)) + '</span></div>' +
					'<div class="tck-alert-card__actions"></div>';
				var actions = card.querySelector('.tck-alert-card__actions');
				if (a.state === 'fired' || a.state === 'escalating') {
					var ack = document.createElement('button');
					ack.type = 'button'; ack.className = 'tck-btn'; ack.textContent = t('Acknowledge');
					ack.addEventListener('click', function () {
						ack.disabled = true;
						api('POST', '/api/alerts/' + a.id + '/ack').then(function (j2) {
							if (j2.success) { toast('ok', t('Acknowledged')); loadAlerts(); } else { ack.disabled = false; toast('error', j2.message || t('Error')); }
						});
					});
					actions.appendChild(ack);
				}
				var res = document.createElement('button');
				res.type = 'button'; res.className = 'tck-btn tck-btn--danger'; res.textContent = t('Resolve');
				res.addEventListener('click', function () {
					TCK.note({
						title: t('Resolve alert'),
						label: t('Resolution note (required, min. 10 characters)'),
						minLength: 10,
						submitLabel: t('Resolve'),
					}).then(function (note) {
						if (note === null) { return; }
						res.disabled = true;
						api('POST', '/api/alerts/' + a.id + '/resolve', { note: note }).then(function (j2) {
							if (j2.success) { toast('ok', t('Resolved')); loadAlerts(); } else { res.disabled = false; toast('error', j2.message || t('Error')); }
						});
					});
				});
				actions.appendChild(res);
				box.appendChild(card);
			});
		});
	}

	/* ── Board ──────────────────────────────────────────────────────── */
	var STATUS_LABEL = { scheduled: 'Scheduled', active: 'Active', missed: 'Missed', completed: 'Done', partial: 'Partial', skipped: 'Skipped' };
	function loadBoard() {
		api('GET', '/api/ops').then(function (j) {
			var board = document.getElementById('tck-board');
			var empty = document.getElementById('tck-board-empty');
			if (!j.success) { return; }
			board.querySelectorAll('.tck-ops-card').forEach(function (n) { n.remove(); });
			var sites = j.data.sites || [];
			if (empty) { empty.hidden = sites.length !== 0; }
			sites.forEach(function (s) {
				var card = document.createElement('article');
				card.className = 'tck-ops-card tck-ops-card--' + s.severity;
				var sev = { ok: [t('OK'), 'success'], attention: [t('Needs attention'), 'warning'], critical: [t('Critical'), 'danger'] }[s.severity] || [t('OK'), 'success'];
				var rounds = s.rounds.map(function (r) {
					var extra = r.overdueCheckpoints > 0 ? ' · ' + tf('%d overdue', [r.overdueCheckpoints]) : '';
					return '<li class="tck-ops-round">' + badge(t(STATUS_LABEL[r.status] || r.status), ROUND_BADGE[r.status] || '') +
						' <span>' + esc((r.scheduledStart || '').slice(11, 16)) + '–' + esc((r.windowEnd || '').slice(11, 16)) + '</span>' +
						(r.worker ? ' <span>' + esc(r.worker) + '</span>' : '') + esc(extra) + '</li>';
				}).join('');
				card.innerHTML =
					'<header class="tck-ops-card__head"><h3>' + esc(s.site.name) + '</h3>' + badge(sev[0], sev[1]) + '</header>' +
					(rounds !== '' ? '<ul class="tck-ops-rounds">' + rounds + '</ul>' : '<p class="tck-hint">' + esc(t('No rounds in the window.')) + '</p>') +
					'<footer class="tck-ops-card__foot"><span>' + esc(tf('%d open alert(s), %d open task(s)', [s.alerts.length, s.openTasks])) + '</span>' +
					'<button type="button" class="tck-btn" data-ops-map="' + s.site.id + '">' + esc(t('Map')) + '</button></footer>';
				card.querySelector('[data-ops-map]').addEventListener('click', function () {
					if (siteSel) { siteSel.value = String(s.site.id); siteSel.dispatchEvent(new Event('change')); }
					document.getElementById('tck-sec-map').scrollIntoView({ behavior: 'smooth', block: 'start' });
				});
				board.appendChild(card);
			});
		});
	}

	/* ── Map + geofences + trail ────────────────────────────────────── */
	var map = null, mapLayers = { gf: [], cp: [], pos: [], trail: null };
	function ensureMap() {
		if (map || typeof L === 'undefined') { return; }
		var el = document.getElementById('tck-map');
		if (!el) { return; }
		map = L.map(el, { zoomControl: true, attributionControl: true });
		map.setView([51.0, 10.0], 5);
		map.on('click', function (e) {
			var form = document.getElementById('tck-gf-form');
			if (form && !form.classList.contains('tck-hidden')) {
				form.querySelector('[name="center_lat"]').value = e.latlng.lat.toFixed(6);
				form.querySelector('[name="center_lon"]').value = e.latlng.lng.toFixed(6);
			}
		});
	}
	function clearLayers(key) { (mapLayers[key] || []).forEach(function (l) { map.removeLayer(l); }); mapLayers[key] = []; }
	function loadMap() {
		var siteId = currentSite();
		if (!siteId || typeof L === 'undefined') { return; }
		ensureMap();
		api('GET', '/api/ops/' + siteId + '/map').then(function (j) {
			if (!j.success) { return; }
			var schematic = document.getElementById('tck-map-schematic');
			if (j.data.tileUrl && !map._tckTiles) {
				L.tileLayer(j.data.tileUrl, { maxZoom: 19, attribution: '© providers' }).addTo(map);
				map._tckTiles = true;
				if (schematic) { schematic.hidden = true; }
			} else if (!j.data.tileUrl && schematic) {
				schematic.hidden = false;
			}
			var bounds = [];
			clearLayers('gf'); clearLayers('cp'); clearLayers('pos');
			(j.data.geofences || []).forEach(function (g) {
				var color = g.kind === 'restricted' ? '#b91c1c' : '#1d4ed8';
				if (g.shape === 'circle' && g.centerLat !== null) {
					var c = L.circle([g.centerLat, g.centerLon], { radius: g.radiusM, color: color, weight: 2, fillOpacity: 0.08 }).addTo(map);
					c.bindTooltip(g.kind === 'restricted' ? t('Restricted zone') : t('Site perimeter'));
					mapLayers.gf.push(c); bounds.push([g.centerLat, g.centerLon]);
				} else if (g.shape === 'polygon' && g.polygon) {
					var p = L.polygon(g.polygon, { color: color, weight: 2, fillOpacity: 0.08 }).addTo(map);
					mapLayers.gf.push(p);
					g.polygon.forEach(function (pt) { bounds.push(pt); });
				}
			});
			(j.data.checkpoints || []).forEach(function (c) {
				var m = L.circleMarker([parseFloat(c.geo_lat), parseFloat(c.geo_lon)], { radius: 7, color: '#0f766e', fillOpacity: 0.9 }).addTo(map);
				m.bindTooltip(c.name + (c.cp_kind === 'geo' ? ' · ' + t('GPS') : c.cp_kind === 'beacon' ? ' · ' + t('Beacon') : ''));
				mapLayers.cp.push(m); bounds.push([parseFloat(c.geo_lat), parseFloat(c.geo_lon)]);
			});
			(j.data.positions || []).forEach(function (p) {
				var m = L.marker([p.lat, p.lon]).addTo(map);
				m.bindTooltip((p.worker || '') + ' · ' + (p.at || ''));
				mapLayers.pos.push(m); bounds.push([p.lat, p.lon]);
			});
			if (bounds.length) { map.fitBounds(bounds, { padding: [30, 30], maxZoom: 17 }); }
			// populate the trail picker
			var sel = document.getElementById('tck-map-round');
			var play = document.getElementById('tck-trail-play');
			if (sel) {
				var prev = sel.value;
				var seen = {}, opts = '';
				(j.data.positions || []).forEach(function (p) {
					if (seen[p.roundId]) { return; }
					seen[p.roundId] = true;
					opts += '<option value="' + p.roundId + '">' + esc(tf('Round #%d', [p.roundId]) + ' · ' + (p.worker || '')) + '</option>';
				});
				sel.innerHTML = '<option value="">' + esc(t('Round…')) + '</option>' + opts;
				sel.value = prev; sel.hidden = opts === '';
				if (play) { play.hidden = opts === ''; }
			}
		});
	}
	// click-to-set-center for the geofence form
	document.addEventListener('DOMContentLoaded', function () {
		var gfBtn = document.getElementById('tck-gf-new');
		var gfForm = document.getElementById('tck-gf-form');
		if (gfBtn && gfForm) {
			gfBtn.addEventListener('click', function () {
				ensureMap();
				gfForm.classList.remove('tck-hidden');
				var lat = gfForm.querySelector('[name="center_lat"]');
				if (lat) { lat.focus(); }
			});
			gfForm.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var fd = new FormData(gfForm);
				api('POST', '/api/sites/' + gfForm.dataset.siteId + '/geofences', {
					kind: fd.get('kind'), shape: 'circle',
					center_lat: fd.get('center_lat'), center_lon: fd.get('center_lon'), radius_m: fd.get('radius_m')
				}).then(function (j) {
					if (j.success) { toast('ok', t('Geofence saved')); gfForm.classList.add('tck-hidden'); loadMap(); loadGeofences(); }
					else { toast('error', j.message || t('Error')); }
				});
			});
		}
	});
	function loadGeofences() {
		var siteId = currentSite();
		if (!siteId) { return; }
		api('GET', '/api/sites/' + siteId + '/geofences').then(function (j) {
			var list = document.getElementById('tck-gf-list');
			if (!j.success || !list) { return; }
			list.innerHTML = '';
			(j.data.geofences || []).forEach(function (g) {
				var row = document.createElement('div');
				row.className = 'tck-ops-gfrow';
				row.innerHTML = '<span>' + esc(g.kind === 'restricted' ? t('Restricted zone') : t('Perimeter')) + ' · ' + (g.shape === 'circle' ? 'r=' + g.radiusM + ' m' : t('polygon')) + '</span>';
				var del = document.createElement('button');
				del.type = 'button'; del.className = 'tck-btn'; del.textContent = t('Delete');
				del.addEventListener('click', function () {
					TCK.confirm(t('Delete this geofence?')).then(function (ok) {
						if (!ok) { return; }
						api('DELETE', '/api/geofences/' + g.id).then(function (j2) {
							if (j2.success) { toast('ok', t('Deleted')); loadGeofences(); loadMap(); }
						});
					});
				});
				row.appendChild(del); list.appendChild(row);
			});
		});
	}
	// trail replay
	var trailPlay = document.getElementById('tck-trail-play');
	var roundSel = document.getElementById('tck-map-round');
	if (trailPlay && roundSel) {
		trailPlay.addEventListener('click', function () {
			var rid = roundSel.value;
			if (!rid) { toast('error', t('Pick a round first.')); return; }
			api('GET', '/api/rounds/' + rid + '/trail').then(function (j) {
				if (!j.success) { toast('error', j.message || t('Error')); return; }
				var pts = (j.data.trail || []).map(function (p) { return [p.lat, p.lon]; });
				if (!pts.length) { toast('error', t('No positions recorded for this round.')); return; }
				if (mapLayers.trail) { map.removeLayer(mapLayers.trail); }
				mapLayers.trail = L.polyline(pts, { color: '#7c3aed', weight: 3 }).addTo(map);
				map.fitBounds(mapLayers.trail.getBounds(), { padding: [30, 30] });
				announce(tf('Trail loaded — %d points.', [pts.length]));
			});
		});
	}


	/* ── Tasks ──────────────────────────────────────────────────────── */
	function loadMembers() {
		var siteId = currentSite();
		var sel = document.getElementById('tck-task-assignee');
		if (!siteId || !sel) { return; }
		api('GET', '/api/sites/' + siteId + '/members').then(function (j) {
			if (!j.success) { return; }
			var cur = sel.value;
			sel.innerHTML = '<option value="">' + esc(t('Unassigned')) + '</option>';
			(j.data.members || []).forEach(function (m) {
				var o = document.createElement('option');
				o.value = m.uid; o.textContent = m.displayName || m.uid;
				sel.appendChild(o);
			});
			sel.value = cur;
		});
	}
	function loadTasks() {
		var siteId = currentSite();
		var ul = document.getElementById('tck-tasks');
		var empty = document.getElementById('tck-tasks-empty');
		if (!siteId || !ul) { return; }
		api('GET', '/api/sites/' + siteId + '/tasks').then(function (j) {
			if (!j.success) { return; }
			ul.innerHTML = '';
			var tasks = j.data.tasks || [];
			if (empty) { empty.hidden = tasks.length !== 0; }
			tasks.forEach(function (tk) {
				var li = document.createElement('li');
				li.className = 'tck-list__row';
				li.innerHTML = '<div class="tck-list__main"><span class="tck-list__title">' + esc(tk.title) + '</span>' +
					'<span class="tck-list__sub">' + esc(tk.assigneeName || tk.assigneeUid || t('Unassigned')) + (tk.dueAt ? ' · ' + esc(tf('due %s', [fmtTs(tk.dueAt)])) : '') + '</span></div>';
				var aside = document.createElement('div');
				aside.className = 'tck-list__aside';
				var TASK_BADGE = { open: 'warning', done: 'success', cancelled: 'info' };
				aside.insertAdjacentHTML('beforeend', badge(tk.status === 'open' ? t('Open') : tk.status === 'done' ? t('Done') : t('Cancelled'), TASK_BADGE[tk.status] || ''));
				if (tk.status === 'open') {
					var done = document.createElement('button');
					done.type = 'button'; done.className = 'tck-btn'; done.textContent = t('Complete');
					done.addEventListener('click', function () {
						done.disabled = true;
						api('POST', '/api/tasks/' + tk.id + '/complete', {}).then(function (j2) {
							if (j2.success) { toast('ok', t('Task completed')); loadTasks(); } else { done.disabled = false; toast('error', j2.message || t('Error')); }
						});
					});
					aside.appendChild(done);
				}
				li.appendChild(aside);
				ul.appendChild(li);
			});
		});
	}

	/* ── Logbook ────────────────────────────────────────────────────── */
	function loadLogbook() {
		var siteId = currentSite();
		var ul = document.getElementById('tck-logbook');
		var empty = document.getElementById('tck-log-empty');
		if (!siteId || !ul) { return; }
		api('GET', '/api/sites/' + siteId + '/logbook').then(function (j) {
			if (!j.success) { return; }
			ul.innerHTML = '';
			var rows = j.data.entries || [];
			if (empty) { empty.hidden = rows.length !== 0; }
			rows.forEach(function (e) {
				var li = document.createElement('li');
				li.className = 'tck-list__row';
				var kindLabel = { note: t('Note'), handover: t('Handover'), hazard: t('Hazard') }[e.kind] || e.kind;
				li.innerHTML = '<div class="tck-list__main"><span class="tck-list__title">' +
					(e.pinned ? '📌 ' : '') + esc(kindLabel) + ' — ' + esc(e.body.slice(0, 140)) + (e.body.length > 140 ? '…' : '') + '</span>' +
					'<span class="tck-list__sub">' + esc(e.authorName || e.authorUid) + ' · ' + esc(fmtTs(e.createdAt)) +
					' · ' + esc(tf('%d acked', [(e.ackedBy || []).length])) + '</span></div>';
				var aside = document.createElement('div');
				aside.className = 'tck-list__aside';
				var ack = document.createElement('button');
				ack.type = 'button'; ack.className = 'tck-btn'; ack.textContent = t('Acknowledge');
				ack.addEventListener('click', function () {
					ack.disabled = true;
					api('POST', '/api/logbook/' + e.id + '/ack').then(function (j2) {
						if (j2.success) { toast('ok', t('Acknowledged')); loadLogbook(); } else { ack.disabled = false; toast('error', j2.message || t('Error')); }
					});
				});
				aside.appendChild(ack); li.appendChild(aside); ul.appendChild(li);
			});
		});
	}

	/* ── KPIs ───────────────────────────────────────────────────────── */
	function loadKpis() {
		var siteId = currentSite();
		var grid = document.getElementById('tck-kpis');
		if (!siteId || !grid) { return; }
		var from = document.getElementById('tck-kpi-from').value;
		var to = document.getElementById('tck-kpi-to').value;
		api('GET', '/api/sites/' + siteId + '/kpis' + (from && to ? '?from=' + from + '&to=' + to : '')).then(function (j) {
			if (!j.success) { toast('error', j.message || t('Error')); return; }
			var d = j.data;
			var cards = [
				[t('Checkpoint hit rate'), d.hit_rate_pct === null ? '—' : d.hit_rate_pct + '%', tf('Target: %d percent', [d.sla_hit_target_pct])],
				[t('Punctuality'), d.punctuality_pct === null ? '—' : d.punctuality_pct + '%', tf('within %d min', [d.sla_punctuality_min])],
				[t('Coverage'), d.coverage_pct === null ? '—' : d.coverage_pct + '%', t('rounds not missed')],
				[t('Open incidents'), String(d.open_incidents), d.oldest_open_incident_h !== null ? tf('oldest %d h', [d.oldest_open_incident_h]) : ''],
			];
			grid.innerHTML = '';
			if (d.insufficient) {
				var note = document.createElement('p');
				note.className = 'tck-hint';
				note.textContent = t('Too few rounds in this period for meaningful rates.');
				grid.appendChild(note);
			}
			cards.forEach(function (c) {
				var el = document.createElement('div');
				el.className = 'tck-kpi';
				el.innerHTML = '<span class="tck-kpi__value">' + esc(c[1]) + '</span><span class="tck-kpi__label">' + esc(c[0]) + '</span>' + (c[2] ? '<span class="tck-kpi__sub">' + esc(c[2]) + '</span>' : '');
				grid.appendChild(el);
			});
		});
	}

	/* ── form toggles + submit ──────────────────────────────────────── */
	function bindToggle(btnId, formId) {
		var b = document.getElementById(btnId), f = document.getElementById(formId);
		if (b && f) { b.addEventListener('click', function () { f.classList.toggle('tck-hidden'); var i = f.querySelector('input,textarea,select'); if (i && !f.classList.contains('tck-hidden')) { i.focus(); } }); }
	}
	document.querySelectorAll('[data-tck-cancel]').forEach(function (btn) {
		btn.addEventListener('click', function () { var f = document.getElementById(btn.getAttribute('data-tck-cancel')); if (f) { f.classList.add('tck-hidden'); } });
	});
	bindToggle('tck-task-new', 'tck-task-form');
	bindToggle('tck-log-new', 'tck-log-form');

	var taskForm = document.getElementById('tck-task-form');
	if (taskForm) {
		taskForm.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var fd = new FormData(taskForm);
			api('POST', '/api/sites/' + taskForm.dataset.siteId + '/tasks', {
				title: fd.get('title'), assignee_uid: fd.get('assignee_uid') || null,
				due_at: fd.get('due_at') || null, body: fd.get('body') || null
			}).then(function (j) {
				if (j.success) { toast('ok', t('Task created')); taskForm.reset(); taskForm.classList.add('tck-hidden'); loadTasks(); }
				else { toast('error', j.message || t('Error')); }
			});
		});
	}
	var logForm = document.getElementById('tck-log-form');
	if (logForm) {
		logForm.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var fd = new FormData(logForm);
			api('POST', '/api/sites/' + logForm.dataset.siteId + '/logbook', {
				kind: fd.get('kind'), body: fd.get('body'), pinned: fd.get('pinned') === '1'
			}).then(function (j) {
				if (j.success) { toast('ok', t('Entry added')); logForm.reset(); logForm.classList.add('tck-hidden'); loadLogbook(); }
				else { toast('error', j.message || t('Error')); }
			});
		});
	}
	var kpiLoad = document.getElementById('tck-kpi-load');
	if (kpiLoad) { kpiLoad.addEventListener('click', loadKpis); }
	// default range: last 30 days
	var kf = document.getElementById('tck-kpi-from'), kt = document.getElementById('tck-kpi-to');
	if (kf && kt) {
		var d = new Date(); kt.value = d.toISOString().slice(0, 10);
		d.setDate(d.getDate() - 30); kf.value = d.toISOString().slice(0, 10);
	}

	/* ── boot + site switch + light polling ─────────────────────────── */
	function boot() {
		loadBoard(); loadAlerts(); loadTasks(); loadLogbook(); loadKpis(); loadGeofences();
		if (currentSite()) { loadMap(); loadMembers(); }
	}
	if (siteSel) {
		siteSel.addEventListener('change', function () {
			loadAlerts(); loadTasks(); loadLogbook(); loadKpis(); loadGeofences(); loadMap(); loadMembers();
		});
	}
	boot();
	setInterval(function () { loadBoard(); loadAlerts(); }, 30000); // ops freshness — board+alerts only, not the map
	setInterval(loadMap, 45000);
})();
