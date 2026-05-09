const config = window.ACEFrontendConfig || {};

function setCookie(name, value, duration, useDays = false) {
	const maxAge = useDays ? duration * 24 * 60 * 60 : duration * 60;
	document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; SameSite=Lax`;
}

function getCookie(name) {
	const prefix = `${name}=`;
	return document.cookie
		.split(';')
		.map((part) => part.trim())
		.find((part) => part.startsWith(prefix))
		?.slice(prefix.length) || '';
}

function ensureUuid(name, duration, useDays = false) {
	let value = getCookie(name);

	if (!value) {
		value = window.crypto?.randomUUID?.() || `ace-${Date.now()}-${Math.random().toString(16).slice(2)}`;
	}

	setCookie(name, value, duration, useDays);

	return value;
}

function getUtm() {
	const params = new URLSearchParams(window.location.search);
	return {
		source: params.get('utm_source') || '',
		medium: params.get('utm_medium') || '',
		campaign: params.get('utm_campaign') || '',
		term: params.get('utm_term') || '',
		content: params.get('utm_content') || '',
	};
}

async function sendTrackingEvent(payload) {
	try {
		await fetch(`${config.root}${config.namespace}/track`, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify(payload),
		});
	} catch (error) {
		// Frontend tracking must not disrupt the page.
	}
}

async function resolvePhoneNumber() {
	const nodes = document.querySelectorAll('[data-ace-phone], [data-ace-phone-link]');

	if (!nodes.length) {
		return;
	}

	const params = new URLSearchParams({
		path: window.location.pathname,
		utm_source: getUtm().source,
		utm_campaign: getUtm().campaign,
	});

	try {
		const response = await fetch(`${config.root}${config.namespace}/number/resolve?${params.toString()}`, {
			credentials: 'same-origin',
		});
		const number = await response.json();

		if (!number?.e164_number) {
			return;
		}

		document.querySelectorAll('[data-ace-phone]').forEach((node) => {
			node.textContent = number.display_number;
			node.dataset.aceNumberId = String(number.number_id);
		});

		document.querySelectorAll('[data-ace-phone-link]').forEach((node) => {
			node.textContent = number.display_number;
			node.href = `tel:${number.e164_number}`;
			node.dataset.aceNumberId = String(number.number_id);
		});
	} catch (error) {
		// Ignore number resolution failures.
	}
}

function bindFormTracking(sessionUuid, visitorUuid) {
	document.addEventListener(
		'submit',
		(event) => {
			const form = event.target;

			if (!(form instanceof HTMLFormElement)) {
				return;
			}

			const searchInput = form.querySelector('input[type="search"]');
			const looksLikeSearch = form.getAttribute('role') === 'search' || form.classList.contains('search-form') || !!searchInput;

			if (looksLikeSearch || form.hasAttribute('data-ace-ignore-form')) {
				return;
			}

			const actionUrl = new URL(form.getAttribute('action') || window.location.href, window.location.origin);
			const identifier = form.getAttribute('id') || form.getAttribute('name') || form.dataset.aceForm || form.action || 'form';

			sendTrackingEvent({
				session_uuid: sessionUuid,
				visitor_uuid: visitorUuid,
				event_type: 'form_submit',
				event_name: identifier,
				url: window.location.href,
				path: window.location.pathname,
				page_title: document.title,
				referrer: document.referrer,
				utm: getUtm(),
				metadata: {
					form_action: actionUrl.pathname,
					form_method: (form.getAttribute('method') || 'get').toUpperCase(),
					field_count: String(form.elements.length),
				},
			});
		},
		true
	);
}

function bindInteractionTracking(sessionUuid, visitorUuid) {
	document.addEventListener('click', (event) => {
		const target = event.target.closest('a,button');

		if (!target) {
			return;
		}

		const href = target.getAttribute('href') || '';
		const isCall = href.startsWith('tel:') || target.matches('.ace-track-call');
		const isDownload = /\.(pdf|docx?|xlsx?|pptx?)($|\?)/i.test(href);

		if (!isCall && !isDownload) {
			return;
		}

		sendTrackingEvent({
			session_uuid: sessionUuid,
			visitor_uuid: visitorUuid,
			event_type: isCall ? 'click_to_call' : 'download',
			event_name: target.textContent?.trim() || '',
			url: window.location.href,
			path: window.location.pathname,
			page_title: document.title,
			referrer: document.referrer,
			number_id: target.dataset.aceNumberId ? Number(target.dataset.aceNumberId) : 0,
			utm: getUtm(),
			metadata: {
				link: href,
			},
		});
	});
}

function init() {
	if (!config.enabled) {
		return;
	}

	const shouldTrackPageviews = !!config.tracking?.track_pageviews;
	const shouldTrackClicks = !!config.tracking?.track_click_to_call || !!config.tracking?.track_downloads;
	const shouldTrackForms = !!config.tracking?.track_forms;
	const shouldResolveNumbers = !!document.querySelector('[data-ace-phone], [data-ace-phone-link]');

	if (!shouldTrackPageviews && !shouldTrackClicks && !shouldTrackForms && !shouldResolveNumbers) {
		return;
	}

	const sessionUuid = ensureUuid(config.tracking.cookie_name || 'ace_sid', Number(config.tracking.session_lifetime_minutes || 30));
	const visitorUuid = ensureUuid(config.tracking.visitor_cookie_name || 'ace_vid', Number(config.tracking.visitor_lifetime_days || 90), true);

	if (shouldTrackPageviews) {
		sendTrackingEvent({
			session_uuid: sessionUuid,
			visitor_uuid: visitorUuid,
			event_type: 'pageview',
			url: window.location.href,
			path: window.location.pathname,
			page_title: document.title,
			referrer: document.referrer,
			utm: getUtm(),
			metadata: {
				screen: `${window.screen.width}x${window.screen.height}`,
				language: navigator.language || '',
			},
		});
	}

	if (shouldTrackClicks) {
		bindInteractionTracking(sessionUuid, visitorUuid);
	}

	if (shouldTrackForms) {
		bindFormTracking(sessionUuid, visitorUuid);
	}

	if (shouldResolveNumbers) {
		resolvePhoneNumber();
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}
