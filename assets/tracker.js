/* Suruh Lead Radar — records WhatsApp/call clicks and form submissions. */
(function () {
	'use strict';
	var CFG = window.SLR_CFG;
	if (!CFG || !CFG.api) return;

	var DAY = 864e5;

	function store(key, value) {
		try {
			if (value === undefined) return JSON.parse(localStorage.getItem(key));
			localStorage.setItem(key, JSON.stringify(value));
		} catch (e) { return null; }
	}

	function readCookie(name) {
		var m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
		return m ? decodeURIComponent(m[1]) : '';
	}

	function randomId() {
		var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', out = '', bytes;
		if (window.crypto && crypto.getRandomValues) {
			bytes = crypto.getRandomValues(new Uint8Array(22));
			for (var i = 0; i < 22; i++) out += chars[bytes[i] % 62];
			return out;
		}
		for (var j = 0; j < 22; j++) out += chars[Math.floor(Math.random() * 62)];
		return out;
	}

	// Visitor ID lives in both a cookie and localStorage so clearing one does not lose it.
	function visitorId() {
		var id = store('slr_vid') || readCookie('slr_vid');
		if (!/^[A-Za-z0-9_-]{8,64}$/.test(id || '')) id = randomId();
		store('slr_vid', id);
		document.cookie = 'slr_vid=' + id + ';path=/;max-age=' + 400 * 86400 + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
		return id;
	}

	var CLICK_IDS = {
		gclid: ['google', 'cpc'], gbraid: ['google', 'cpc'], wbraid: ['google', 'cpc'],
		ttclid: ['tiktok', 'cpc'], ScCid: ['snapchat', 'cpc'], msclkid: ['bing', 'cpc'],
		li_fat_id: ['linkedin', 'cpc'], fbclid: ['facebook', 'social']
	};

	var REFERRERS = [
		[/(^|\.)google\./, 'google', 'organic'], [/(^|\.)bing\.com$/, 'bing', 'organic'],
		[/(^|\.)(yahoo|duckduckgo|yandex)\./, 'search', 'organic'],
		[/(^|\.)(facebook\.com|fb\.com|fb\.me)$/, 'facebook', 'social'], [/(^|\.)instagram\.com$/, 'instagram', 'social'],
		[/(^|\.)tiktok\.com$/, 'tiktok', 'social'], [/(^|\.)snapchat\.com$/, 'snapchat', 'social'],
		[/(^|\.)(t\.co|x\.com|twitter\.com)$/, 'x', 'social'], [/(^|\.)(linkedin\.com|lnkd\.in)$/, 'linkedin', 'social'],
		[/(^|\.)(youtube\.com|youtu\.be)$/, 'youtube', 'social'], [/(^|\.)whatsapp\.com$/, 'whatsapp', 'social']
	];

	function currentTouch() {
		var q = new URLSearchParams(location.search), t = null;
		for (var k in CLICK_IDS) {
			if (q.get(k)) { t = { s: CLICK_IDS[k][0], m: CLICK_IDS[k][1], ct: k, ci: q.get(k).slice(0, 250) }; break; }
		}
		if (q.get('utm_source')) {
			t = t || {};
			t.s = q.get('utm_source'); t.m = q.get('utm_medium') || t.m || ''; t.c = q.get('utm_campaign') || '';
		}
		if (t) return t;
		var ref = document.referrer, host = '';
		try { host = ref ? new URL(ref).hostname.replace(/^www\./, '') : ''; } catch (e) {}
		if (!host || host === location.hostname.replace(/^www\./, '')) return null;
		if (/^android-app:\/\/com\.google/.test(ref)) return { s: 'google', m: 'organic' };
		for (var i = 0; i < REFERRERS.length; i++) {
			if (REFERRERS[i][0].test(host)) return { s: REFERRERS[i][1], m: REFERRERS[i][2] };
		}
		return { s: host, m: 'referral' };
	}

	// Last non-direct touch wins (30-day window); first touch is kept forever.
	function attribution() {
		var now = Date.now(), touch = currentTouch(), last = store('slr_attr');
		if (touch) {
			touch.t = now; store('slr_attr', touch); last = touch;
		} else if (!last || now - (last.t || 0) > 30 * DAY) {
			last = { s: 'direct', m: 'none', t: now }; store('slr_attr', last);
		}
		if (!store('slr_first')) {
			var f = touch || { s: 'direct', m: 'none' };
			store('slr_first', { s: f.s, m: f.m, c: f.c || '', l: location.href.split('#')[0] });
		}
		return last;
	}

	var VID = visitorId();
	var ATTR = attribution();

	// Server-side form integrations (Contact Form 7, WPForms…) read the source from this cookie.
	try {
		var cookieAttr = { s: ATTR.s, m: ATTR.m, c: (ATTR.c || '').slice(0, 100), ct: ATTR.ct || '', ci: (ATTR.ci || '').slice(0, 150) };
		document.cookie = 'slr_attr=' + encodeURIComponent(JSON.stringify(cookieAttr)) + ';path=/;max-age=' + 30 * 86400 + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
	} catch (e) {}

	function base() {
		return {
			v: VID,
			u: location.href.split('#')[0],
			ti: document.title,
			r: document.referrer,
			a: ATTR,
			fa: store('slr_first') || ATTR
		};
	}

	function send(path, data) {
		var body = JSON.stringify(data);
		// "ajax" mode is switched on automatically when a security plugin blocks the REST API.
		var url = CFG.mode === 'ajax' && CFG.ajax ? CFG.ajax + '?action=slr_' + path : CFG.api + path;
		// text/plain keeps sendBeacon a "simple" request; it survives the jump to WhatsApp.
		try {
			if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], { type: 'text/plain' }))) return;
		} catch (e) {}
		try { fetch(url, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }); } catch (e) {}
	}

	function pushLayer(obj) {
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push(obj);
	}

	function contactType(href) {
		if (/^tel:/i.test(href)) return 'call';
		if (/^whatsapp:|\/\/(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com|chat\.whatsapp\.com)\b/i.test(href)) return 'whatsapp';
		return '';
	}

	function targetNumber(href) {
		var m = href.match(/wa\.me\/(\+?\d+)/i) || href.match(/[?&]phone=(\+?\d+)/i) || href.match(/^tel:([+\d\s-]+)/i);
		return m ? m[1].replace(/[\s-]/g, '') : '';
	}

	function placement(a) {
		var el = a.closest('[data-slr-placement]');
		if (el) return el.getAttribute('data-slr-placement');
		if (a.closest('.sscl-floating-contact, .sscl-float-btn, [class*="floating"], [class*="float-btn"]')) return 'floating';
		if (a.closest('header, #header, .ct-header, [data-elementor-type="header"]')) return 'header';
		if (a.closest('footer, #footer, .ct-footer, .sscl-footer, [data-elementor-type="footer"]')) return 'footer';
		return 'content';
	}

	function label(a) {
		var l = a.getAttribute('data-slr-label') || (a.textContent || '').replace(/\s+/g, ' ').trim() || a.getAttribute('aria-label') || a.getAttribute('title') || '';
		return l.slice(0, 120);
	}

	// Guards against click + auxclick (or a double tap) on the same button counting twice.
	var lastClick = { el: null, at: 0 };

	function onClick(e) {
		var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
		if (!a) return;
		var href = a.getAttribute('href') || '', type = contactType(href);
		if (!type) return;
		var now = Date.now();
		if (lastClick.el === a && now - lastClick.at < 1500) return;
		lastClick = { el: a, at: now };

		var data = base(), p = placement(a);
		data.t = type; data.p = p; data.l = label(a); data.h = targetNumber(href);
		send('e', data);
		pushLayer({ event: 'slr_contact_click', contact_type: type, contact_placement: p, contact_label: data.l, page_url: data.u });
	}

	function fieldRole(el) {
		var key = ((el.id || '') + ' ' + (el.name || '') + ' ' + (el.getAttribute('placeholder') || '')).toLowerCase();
		var tag = el.tagName.toLowerCase(), type = (el.type || '').toLowerCase();
		if (type === 'tel' || /phone|mobile|tel|جوال|هاتف|موبايل/.test(key)) return 'phone';
		if (type === 'email' || /mail|بريد/.test(key)) return 'email';
		if (tag === 'select' || /service|خدمة/.test(key)) return 'service';
		if (tag === 'textarea' || /message|details|msg|تفاصيل|رسالة/.test(key)) return 'message';
		if (/name|اسم/.test(key)) return 'name';
		return type === 'text' ? 'text' : '';
	}

	function onSubmit(e) {
		var form = e.target;
		if (!form || form.tagName !== 'FORM' || !CFG.forms) return;
		try { if (!form.matches(CFG.forms)) return; } catch (err) { return; }

		var f = { form_id: form.id || form.getAttribute('name') || form.getAttribute('data-slr-form') || 'form' };
		var els = form.querySelectorAll('input, select, textarea');
		for (var i = 0; i < els.length; i++) {
			var el = els[i], t = (el.type || '').toLowerCase();
			if (/hidden|submit|button|password|checkbox|radio|file/.test(t)) continue;
			var role = fieldRole(el), val = (el.value || '').trim();
			if (!val) continue;
			if (role === 'text') role = f.name ? '' : 'name';
			if (role && !f[role]) f[role] = val.slice(0, role === 'message' ? 3000 : 190);
		}
		if (!f.phone && !f.name) return;

		var data = base();
		data.f = f;
		send('lead', data);
		pushLayer({ event: 'slr_form_submit', form_id: f.form_id, service: f.service || '', page_url: data.u });
	}

	// Capture phase: runs before the site's own handlers open WhatsApp.
	document.addEventListener('click', onClick, true);
	document.addEventListener('auxclick', onClick, true);
	document.addEventListener('submit', onSubmit, true);
})();
