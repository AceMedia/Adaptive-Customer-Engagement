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

	// ---- Take-over mini chat (Messenger-style, bottom-right) ----
	const chatPollInterval = Math.max(2000, Number(config.chatPollInterval) || 4000);
	const miniChats = {};
	let miniHost = null;
	const OPEN_CHATS_KEY = 'ace_mini_open_chats_v1';

	// Remember which chats are open so they follow the operator across pages.
	const saveOpenChats = () => {
		try {
			const list = Object.keys(miniChats).map((id) => ({
				id: miniChats[id].id,
				title: miniChats[id].title,
				admin_url: miniChats[id].adminUrl,
			}));
			window.localStorage.setItem(OPEN_CHATS_KEY, JSON.stringify(list));
		} catch (error) {
			// Ignore storage failures.
		}
	};

	const loadOpenChats = () => {
		try {
			return JSON.parse(window.localStorage.getItem(OPEN_CHATS_KEY) || '[]') || [];
		} catch (error) {
			return [];
		}
	};

	const ensureMiniHost = () => {
		if (miniHost && document.body.contains(miniHost)) {
			return miniHost;
		}

		miniHost = document.createElement('div');
		miniHost.className = 'ace-mini-chats';
		document.body.appendChild(miniHost);

		return miniHost;
	};

	function chatUrl(id, suffix) {
		return (config.restBase || '') + '/admin/chats/' + id + (suffix || '');
	}

	function apiFetch(url, options) {
		const opts = options || {};
		return fetch(url, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '' },
			body: opts.body,
		}).then((r) => (r.ok ? r.json() : Promise.reject(r)));
	}

	function roleClass(role) {
		if (role === 'operator') return 'is-out';
		if (role === 'assistant') return 'is-ai';
		if (role === 'user') return 'is-in';
		return 'is-meta';
	}

	function renderMiniMessages(state, messages) {
		if (!Array.isArray(messages)) return;
		const box = state.el.querySelector('.ace-mini-chat__messages');
		let appended = false;

		messages.forEach((m) => {
			const role = String(m.message_role || '');
			if (role === 'system') return;
			const id = String(m.id || (String(m.created_at || '') + ':' + String(m.message_text || '').length));
			if (state.seen[id]) return;
			state.seen[id] = true;

			const row = document.createElement('div');
			row.className = 'ace-mini-msg ' + roleClass(role);
			const bubble = document.createElement('div');
			bubble.className = 'ace-mini-bubble';
			bubble.textContent = String(m.message_text || '');
			row.appendChild(bubble);
			box.appendChild(row);
			appended = true;
		});

		if (appended) box.scrollTop = box.scrollHeight;
	}

	function renderMiniSuggestions(state, suggestions) {
		const wrap = state.el.querySelector('.ace-mini-chat__suggestions');
		wrap.innerHTML = '';

		(Array.isArray(suggestions) ? suggestions : []).slice(0, 4).forEach((s) => {
			const text = String(s || '').trim();
			if (!text) return;
			const chip = document.createElement('button');
			chip.type = 'button';
			chip.className = 'ace-mini-chip';
			chip.textContent = text;
			chip.addEventListener('click', () => {
				const ta = state.el.querySelector('.ace-mini-chat__input');
				ta.value = text;
				ta.focus();
			});
			wrap.appendChild(chip);
		});

		wrap.style.display = wrap.children.length ? '' : 'none';
	}

	function flashMiniError(state, message) {
		const el = state.el.querySelector('.ace-mini-chat__error');
		el.textContent = message;
		el.style.display = '';
		window.setTimeout(() => { el.style.display = 'none'; }, 3500);
	}

	function refreshMiniThread(state) {
		return apiFetch(chatUrl(state.id)).then((data) => {
			if (!miniChats[state.id]) return data;
			if (data && data.messages) renderMiniMessages(state, data.messages);
			return data;
		});
	}

	function pollMini(state) {
		refreshMiniThread(state).catch(() => {}).finally(() => {
			if (miniChats[state.id]) {
				state.pollTimer = window.setTimeout(() => pollMini(state), chatPollInterval);
			}
		});
	}

	// Broadcast the operator's typing so the visitor sees "<name> is typing…".
	function setMiniTyping(state, isTyping) {
		if (state.typingSent === isTyping) return;
		state.typingSent = isTyping;
		apiFetch(chatUrl(state.id, '/typing'), { method: 'POST', body: JSON.stringify({ is_typing: isTyping }) }).catch(() => {});
	}

	function handleMiniInput(state) {
		const ta = state.el.querySelector('.ace-mini-chat__input');
		if (state.typingResetTimer) window.clearTimeout(state.typingResetTimer);

		if (String(ta.value || '').trim()) {
			setMiniTyping(state, true);
			// Auto-clear if they pause, so the visitor's indicator doesn't stick.
			state.typingResetTimer = window.setTimeout(() => setMiniTyping(state, false), 4000);
		} else {
			setMiniTyping(state, false);
		}
	}

	function sendMiniReply(state) {
		const ta = state.el.querySelector('.ace-mini-chat__input');
		const text = String(ta.value || '').trim();
		if (!text || state.sending) return;

		if (state.typingResetTimer) window.clearTimeout(state.typingResetTimer);
		setMiniTyping(state, false);

		state.sending = true;
		const sendBtn = state.el.querySelector('.ace-mini-chat__send');
		sendBtn.disabled = true;

		apiFetch(chatUrl(state.id, '/reply'), { method: 'POST', body: JSON.stringify({ message: text }) })
			.then((data) => {
				ta.value = '';
				if (data && data.messages) renderMiniMessages(state, data.messages);
				if (data && data.suggestions) renderMiniSuggestions(state, data.suggestions);
			})
			.catch(() => flashMiniError(state, i18n.sendFailed || 'Could not send — try again.'))
			.finally(() => { state.sending = false; sendBtn.disabled = false; ta.focus(); });
	}

	function closeMiniChat(id, handBack) {
		const state = miniChats[id];
		if (!state) return;
		if (state.pollTimer) window.clearTimeout(state.pollTimer);
		if (state.typingResetTimer) window.clearTimeout(state.typingResetTimer);
		setMiniTyping(state, false);
		if (handBack) {
			apiFetch(chatUrl(id, '/status'), { method: 'POST', body: JSON.stringify({ action: 'resume_ai' }) }).catch(() => {});
		}
		if (state.el && state.el.parentNode) state.el.parentNode.removeChild(state.el);
		delete miniChats[id];
		saveOpenChats();
	}

	// Load the thread, then start polling. When restoring across a page load we
	// drop the window quietly if the chat is no longer a live human takeover.
	function bootMiniThread(state, restore) {
		const box = state.el.querySelector('.ace-mini-chat__messages');
		box.innerHTML = '';

		refreshMiniThread(state).then((data) => {
			if (!miniChats[state.id]) return;
			const conv = data && data.conversation;

			if (restore && conv && (Number(conv.handover_enabled) !== 1 || conv.status === 'ended' || conv.ended_at)) {
				closeMiniChat(state.id, false);
				return;
			}

			if (!restore) {
				state.el.querySelector('.ace-mini-chat__input').focus();
			}

			if (miniChats[state.id]) state.pollTimer = window.setTimeout(() => pollMini(state), chatPollInterval);
		}).catch(() => {
			if (miniChats[state.id]) state.pollTimer = window.setTimeout(() => pollMini(state), chatPollInterval);
		});
	}

	function openMiniChat(conversation, opts) {
		const id = conversation && conversation.id;
		if (!id) return;
		const restore = !!(opts && opts.restore);

		if (miniChats[id]) {
			miniChats[id].el.classList.remove('is-collapsed');
			miniChats[id].el.querySelector('.ace-mini-chat__input').focus();
			return;
		}

		const host = ensureMiniHost();
		const el = document.createElement('div');
		el.className = 'ace-mini-chat';
		const title = escapeHtml(conversation.title || i18n.visitor || 'Website visitor');
		const adminUrl = escapeHtml(conversation.admin_url || '#');

		el.innerHTML =
			'<div class="ace-mini-chat__head">' +
				'<span class="ace-mini-chat__title" data-toggle title="' + title + '">' + title + '</span>' +
				'<div class="ace-mini-chat__head-actions">' +
					'<a class="ace-mini-chat__btn" href="' + adminUrl + '" target="_blank" rel="noopener" title="' + escapeHtml(i18n.openConsole || 'Open full console') + '" aria-label="' + escapeHtml(i18n.openConsole || 'Open full console') + '">&#x2197;</a>' +
					'<button type="button" class="ace-mini-chat__btn" data-collapse title="' + escapeHtml(i18n.collapse || 'Minimise') + '" aria-label="' + escapeHtml(i18n.collapse || 'Minimise') + '">&#x2013;</button>' +
					'<button type="button" class="ace-mini-chat__btn" data-handback title="' + escapeHtml(i18n.handBack || 'Hand back to assistant') + '" aria-label="' + escapeHtml(i18n.handBack || 'Hand back to assistant') + '">&#x1F916;</button>' +
					'<button type="button" class="ace-mini-chat__btn" data-close title="' + escapeHtml(i18n.close || 'Close') + '" aria-label="' + escapeHtml(i18n.close || 'Close') + '">&times;</button>' +
				'</div>' +
			'</div>' +
			'<div class="ace-mini-chat__body">' +
				'<div class="ace-mini-chat__messages"><div class="ace-mini-chat__status">' + escapeHtml(restore ? (i18n.send || 'Loading…') : (i18n.takingOver || 'Taking over…')) + '</div></div>' +
				'<div class="ace-mini-chat__error" style="display:none"></div>' +
				'<div class="ace-mini-chat__suggestions" style="display:none"></div>' +
				'<form class="ace-mini-chat__form">' +
					'<textarea class="ace-mini-chat__input" rows="1" placeholder="' + escapeHtml(i18n.replyPlaceholder || 'Type your reply…') + '"></textarea>' +
					'<button type="submit" class="ace-mini-chat__send">' + escapeHtml(i18n.send || 'Send') + '</button>' +
				'</form>' +
				'<a class="ace-mini-chat__manage" href="' + adminUrl + '" target="_blank" rel="noopener">' + escapeHtml(i18n.openConsole || 'Open in admin console') + ' &#x2197;</a>' +
			'</div>';

		host.appendChild(el);
		const state = { id: id, el: el, title: conversation.title || i18n.visitor || 'Website visitor', adminUrl: conversation.admin_url || '#', seen: {}, sending: false, pollTimer: null };
		miniChats[id] = state;
		saveOpenChats();

		el.querySelector('[data-close]').addEventListener('click', () => closeMiniChat(id, false));
		el.querySelector('[data-handback]').addEventListener('click', () => closeMiniChat(id, true));
		const toggleCollapse = () => el.classList.toggle('is-collapsed');
		el.querySelector('[data-collapse]').addEventListener('click', toggleCollapse);
		el.querySelector('[data-toggle]').addEventListener('click', toggleCollapse);
		const form = el.querySelector('.ace-mini-chat__form');
		const ta = el.querySelector('.ace-mini-chat__input');
		form.addEventListener('submit', (e) => { e.preventDefault(); sendMiniReply(state); });
		ta.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMiniReply(state); }
		});
		ta.addEventListener('input', () => handleMiniInput(state));
		ta.addEventListener('blur', () => {
			if (state.typingResetTimer) window.clearTimeout(state.typingResetTimer);
			setMiniTyping(state, false);
		});

		if (restore) {
			// Already taken over on a previous page — just reattach, don't re-handover.
			bootMiniThread(state, true);
			return;
		}

		// Take over (switch to manual), then load the thread + suggestions and poll.
		apiFetch(chatUrl(id, '/status'), { method: 'POST', body: JSON.stringify({ action: 'handover' }) })
			.then((data) => {
				if (data && data.suggestions) renderMiniSuggestions(state, data.suggestions);
			})
			.catch(() => flashMiniError(state, i18n.connectFailed || 'Could not open this chat just now.'))
			.finally(() => bootMiniThread(state, false));
	}

	function restoreOpenChats() {
		loadOpenChats().forEach((c) => {
			if (c && c.id) openMiniChat(c, { restore: true });
		});
	}

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
		card.querySelector('.ace-live-card__take').addEventListener('click', (event) => {
			event.preventDefault();
			openMiniChat(conversation);
			removeCard(card);
		});
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
		restoreOpenChats();
		poll();
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
