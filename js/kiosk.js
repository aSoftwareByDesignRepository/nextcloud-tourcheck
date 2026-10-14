/* TourCheck kiosk — grid → PIN → confirm → full reset (UX-4). */
(function () {
	'use strict';
	function t(key) {
		if (window.OC && OC.L10N && typeof OC.L10N.translate === 'function') {
			try {
				var tr = OC.L10N.translate('tourcheck', key);
				if (tr) { return tr; }
			} catch (e) { /* fall through */ }
		}
		return key;
	}
	var appId = 'tourcheck';
	function route(path) { return OC.generateUrl('/apps/' + appId + path); }
	function headers() {
		return { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken || '' };
	}
	/* The station token must not sit in the address bar (history, shoulder
	   surf, copied links) or ride every API URL (access logs record the
	   full path). Take it from the URL once, park it in sessionStorage so
	   a reload still works, strip it from the visible URL, and send it as
	   a Bearer header — the server accepts both. If storage is blocked we
	   keep the URL token (degraded but functional). */
	var stationToken = new URLSearchParams(location.search).get('station_token') || '';
	if (stationToken) {
		var persisted = false;
		try { sessionStorage.setItem('tck_station_token', stationToken); persisted = true; } catch (e) { /* storage blocked */ }
		if (persisted && window.history && history.replaceState) {
			var clean = new URL(location.href);
			clean.searchParams.delete('station_token');
			history.replaceState(null, '', clean.toString());
		}
	} else {
		try { stationToken = sessionStorage.getItem('tck_station_token') || ''; } catch (e) { /* storage blocked */ }
	}

	function api(path, body) {
		var h = headers();
		if (stationToken) { h['Authorization'] = 'Bearer ' + stationToken; }
		return fetch(route(path), { method: 'POST', headers: h, credentials: 'same-origin', body: JSON.stringify(body) })
			.then(function (r) { return r.json(); });
	}
	function announce(text) {
		var r = document.getElementById('tck-alert-region');
		if (r) { r.textContent = ''; setTimeout(function () { r.textContent = text; }, 30); }
	}

	var stages = {};
	document.querySelectorAll('[data-tck-stage]').forEach(function (s) {
		stages[s.getAttribute('data-tck-stage')] = s;
	});
	var pinInput = document.querySelector('[data-tck-pin-input]');
	var dots = document.querySelector('[data-tck-dots]');
	var pinName = document.querySelector('[data-tck-pin-name]');
	var pinErr = document.querySelector('[data-tck-pin-error]');
	var confirmText = document.querySelector('[data-tck-confirm-text]');
	var memberId = null;
	var pin = '';
	var submitting = false;
	/* PIN length is site-configured (4–8). The pad renders that many dots
	   and auto-submits at that length — a hardcoded count would dead-end
	   every PIN that isn't exactly 6 digits. */
	var kioskEl = document.querySelector('[data-tck-kiosk]');
	var pinLen = Math.min(8, Math.max(4, parseInt(kioskEl ? kioskEl.getAttribute('data-pin-len') : '6', 10) || 6));

	function show(name) {
		Object.keys(stages).forEach(function (k) { stages[k].classList.add('tck-hidden'); });
		stages[name].classList.remove('tck-hidden');
	}
	function reset() {
		memberId = null; pin = ''; submitting = false;
		renderDots();
		if (pinInput) { pinInput.value = ''; }
		if (pinErr) { pinErr.hidden = true; }
		show('grid');
	}
	function renderDots() {
		dots.innerHTML = '';
		for (var i = 0; i < pinLen; i++) {
			var d = document.createElement('span');
			if (i < pin.length) { d.className = 'tck-dot--filled'; }
			dots.appendChild(d);
		}
	}
	function submitPin() {
		/* Guard: every key press after the pad is full used to re-POST the
		   same PIN — a double event + double lockout count. */
		if (submitting) { return; }
		submitting = true;
		api('/api/kiosk/confirm', { member_id: memberId, pin: pin }).then(function (j) {
			if (j.success) {
				confirmText.textContent = j.data.message || t('Done');
				show('confirm');
				setTimeout(reset, 1800);
			} else {
				submitting = false;
				pin = '';
				renderDots();
				pinErr.textContent = j.message || t('Wrong PIN');
				pinErr.hidden = false;
				announce(pinErr.textContent);
				if (j.data && j.data.locked) {
					setTimeout(reset, 2500);
				}
			}
		}).catch(function () {
			submitting = false;
			pin = '';
			renderDots();
			pinErr.textContent = t('Network error');
			pinErr.hidden = false;
			announce(pinErr.textContent);
		});
	}

	document.querySelectorAll('[data-tck-member]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			memberId = parseInt(btn.getAttribute('data-tck-member'), 10);
			pinName.textContent = btn.getAttribute('data-name');
			pin = '';
			renderDots();
			show('pin');
			if (pinInput) { pinInput.focus(); }
		});
	});
	document.querySelectorAll('[data-tck-key]').forEach(function (key) {
		key.addEventListener('click', function () {
			var k = key.getAttribute('data-tck-key');
			if (k === '⌫') { pin = pin.slice(0, -1); }
			else if (pin.length < pinLen) { pin += k; }
			renderDots();
			if (pin.length === pinLen) { submitPin(); }
		});
	});
	if (pinInput) {
		pinInput.addEventListener('input', function () {
			pin = pinInput.value.replace(/\D/g, '').slice(0, pinLen);
			pinInput.value = pin;
			renderDots();
			if (pin.length === pinLen) { submitPin(); }
		});
		pinInput.addEventListener('keydown', function (e) {
			/* Keyboard users can still hit Enter — submits when full. */
			if (e.key === 'Enter' && pin.length === pinLen) { e.preventDefault(); submitPin(); }
		});
	}
	var back = document.querySelector('[data-tck-back]');
	if (back) { back.addEventListener('click', reset); }
	renderDots();
})();
