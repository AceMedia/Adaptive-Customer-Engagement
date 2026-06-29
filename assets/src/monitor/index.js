/**
 * Live chat monitor — runs for capable users on the front end and in wp-admin.
 *
 * Polls the monitor endpoint, drives the admin-bar traffic light, and pops
 * iOS-style notifications when a visitor sends a new message, with a one-click
 * deep link to take over the conversation.
 */

import './style.scss';

(function () {
	const config = window.aceLiveMonitor;

	if (!config || !config.endpoint) {
		return;
	}

	const i18n = config.i18n || {};
	const pollInterval = Math.max(5000, Number(config.pollInterval) || 12000);
	const STORAGE_KEY = 'ace_live_monitor_seen_v1';
	const MAX_VISIBLE = 4;
	const AUTO_DISMISS_MS = 12000;

	let seen = {};
	let firstRun = true;
	let container = null;

	try {
		seen = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}') || {};
	} catch (error) {
		seen = {};
	}

	const persistSeen = () => {
		try {
			window.localStorage.setItem(STORAGE_KEY, JSON.stringify(seen));
		} catch (error) {
			// Ignore storage failures.
		}
	};

	const ensureContainer = () => {
		if (container && document.body.contains(container)) {
			return container;
		}

		container = document.createElement('div');
		container.className = 'ace-live-notifications';
		container.setAttribute('aria-live', 'polite');
		document.body.appendChild(container);

		return container;
	};

	const escapeHtml = (value) =>
		String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');

	const updateAdminBar = (data) => {
		const dot = document.querySelector('[data-ace-live-dot]');
		const count = document.querySelector('[data-ace-live-count]');
		const active = (data.counts && data.counts.active) || 0;

		if (dot) {
			dot.setAttribute('data-state', data.status || 'idle');
		}

		if (count) {
			count.textContent = String(active);
			count.setAttribute('data-empty', active ? 'false' : 'true');
		}
	};

	const removeCard = (card) => {
		if (!card || card.dataset.closing === '1') {
			return;
		}

		card.dataset.closing = '1';
		card.classList.remove('is-in');

		window.setTimeout(() => {
			if (card.parentNode) {
				card.parentNode.removeChild(card);
			}
		}, 320);
	};

	const showNotification = (conversation) => {
		const host = ensureContainer();

		while (host.children.length >= MAX_VISIBLE) {
			removeCard(host.firstElementChild);
		}

		const waiting = !!conversation.handover_requested;
		const card = document.createElement('div');
		card.className = 'ace-live-card' + (waiting ? ' is-attention' : '');

		const title = escapeHtml(conversation.title || i18n.visitor || 'Website visitor');
		const message = escapeHtml(conversation.latest_user_message || '');
		const sub = waiting ? escapeHtml(i18n.needsHuman || 'is asking to talk to a person') : escapeHtml(i18n.newMessage || 'New message');

		card.innerHTML =
			'<div class="ace-live-card__head">' +
			'<span class="ace-live-card__icon" aria-hidden="true">💬</span>' +
			'<span class="ace-live-card__title">' + title + '</span>' +
			'<span class="ace-live-card__time">' + escapeHtml(i18n.justNow || 'just now') + '</span>' +
			'<button type="button" class="ace-live-card__close" aria-label="' + escapeHtml(i18n.dismiss || 'Dismiss') + '">&times;</button>' +
			'</div>' +
			'<div class="ace-live-card__sub">' + sub + '</div>' +
			'<div class="ace-live-card__body">' + message + '</div>' +
			'<div class="ace-live-card__actions">' +
			'<a class="ace-live-card__take" href="' + escapeHtml(conversation.admin_url || '#') + '">' + escapeHtml(i18n.takeOver || 'Take over') + '</a>' +
			'</div>';

		host.appendChild(card);
		window.requestAnimationFrame(() => card.classList.add('is-in'));

		let dismissTimer = window.setTimeout(() => removeCard(card), AUTO_DISMISS_MS);

		card.addEventListener('mouseenter', () => window.clearTimeout(dismissTimer));
		card.addEventListener('mouseleave', () => {
			dismissTimer = window.setTimeout(() => removeCard(card), AUTO_DISMISS_MS);
		});

		card.querySelector('.ace-live-card__close').addEventListener('click', () => removeCard(card));
	};

	const handleData = (data) => {
		if (!data || typeof data !== 'object') {
			return;
		}

		updateAdminBar(data);

		const conversations = Array.isArray(data.conversations) ? data.conversations : [];
		const activeIds = {};

		conversations.forEach((conversation) => {
			const id = String(conversation.id);
			activeIds[id] = true;
			const stamp = conversation.latest_user_message_at || '';

			if (!stamp || !conversation.latest_user_message) {
				return;
			}

			if (seen[id] !== stamp) {
				// Seed silently on first run so we only alert on genuinely new messages.
				if (!firstRun) {
					showNotification(conversation);
				}

				seen[id] = stamp;
			}
		});

		// Forget conversations that are no longer active to keep storage small.
		Object.keys(seen).forEach((id) => {
			if (!activeIds[id]) {
				delete seen[id];
			}
		});

		persistSeen();
		firstRun = false;
	};

	const poll = () => {
		fetch(config.endpoint, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce || '' },
		})
			.then((response) => (response.ok ? response.json() : null))
			.then((data) => {
				if (data) {
					handleData(data);
				}
			})
			.catch(() => {
				// Network hiccup — try again on the next tick.
			})
			.finally(() => {
				window.setTimeout(poll, pollInterval);
			});
	};

	const start = () => {
		ensureContainer();
		poll();
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
