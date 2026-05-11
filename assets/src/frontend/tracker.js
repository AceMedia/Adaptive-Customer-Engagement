const config = window.ACEFrontendConfig || {};
const WOO_INTEREST_STORAGE_KEY = 'ace_wc_interest_v1';

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
	const endpoint = `${config.root}${config.namespace}/track`;
	const body = JSON.stringify(payload);

	try {
		if (navigator.sendBeacon) {
			const blob = new Blob([body], { type: 'application/json' });
			navigator.sendBeacon(endpoint, blob);
			return;
		}

		const controller = new AbortController();
		const timeoutId = window.setTimeout(() => controller.abort(), 250);

		try {
			await fetch(endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
				},
				body,
				signal: controller.signal,
			});
		} finally {
			window.clearTimeout(timeoutId);
		}
	} catch (error) {
		// Frontend tracking must not disrupt the page.
	}
}

function readWooInterestStore() {
	try {
		const raw = window.localStorage?.getItem(WOO_INTEREST_STORAGE_KEY);
		const parsed = raw ? JSON.parse(raw) : {};

		return {
			products: parsed?.products && typeof parsed.products === 'object' ? parsed.products : {},
			categories: parsed?.categories && typeof parsed.categories === 'object' ? parsed.categories : {},
		};
	} catch (error) {
		return {
			products: {},
			categories: {},
		};
	}
}

function writeWooInterestStore(store) {
	try {
		window.localStorage?.setItem(WOO_INTEREST_STORAGE_KEY, JSON.stringify(store));
	} catch (error) {
		// Storage is optional and must not break tracking.
	}
}

function trimInterestEntries(entries, limit = 50) {
	return Object.fromEntries(
		Object.entries(entries)
			.sort(([, left], [, right]) => (right?.last_seen || 0) - (left?.last_seen || 0))
			.slice(0, limit)
	);
}

function recordInterest(entries, key, details) {
	if (!key) {
		return 0;
	}

	const existing = entries[key] || {};
	const count = Number(existing.count || 0) + 1;

	entries[key] = {
		...existing,
		...details,
		count,
		last_seen: Date.now(),
	};

	return count;
}

function buildWooCommerceContext() {
	const page = config.page || {};

	if (!page?.is_woocommerce) {
		return {
			post_id: 0,
			post_type: '',
			taxonomy_context: '',
			product_area: '',
			brand_context: '',
			metadata: {},
		};
	}

	const store = readWooInterestStore();
	const metadata = {
		woo_context: page.context_type || '',
	};
	const categories = Array.isArray(page.categories) ? page.categories.filter(Boolean) : [];
	const categorySlugs = categories.map((item) => item.slug).filter(Boolean);
	const categoryNames = categories.map((item) => item.name).filter(Boolean);
	const product = page.product || null;
	const category = page.category || null;
	let productArea = '';

	if (product?.id) {
		const productKey = String(product.id);
		const productViews = recordInterest(store.products, productKey, {
			id: Number(product.id),
			slug: product.slug || '',
			name: product.name || '',
		});

		metadata.product_id = String(product.id);
		metadata.product_slug = product.slug || '';
		metadata.product_name = product.name || '';
		metadata.product_view_count = String(productViews);
		metadata.repeat_product_interest = productViews > 1 ? '1' : '0';
		productArea = product.slug || `product-${product.id}`;
	}

	categories.forEach((item) => {
		const categoryKey = item?.slug || (item?.id ? String(item.id) : '');

		if (!categoryKey) {
			return;
		}

		recordInterest(store.categories, categoryKey, {
			id: Number(item.id || 0),
			slug: item.slug || '',
			name: item.name || '',
		});
	});

	if (category?.id || categories.length) {
		const primaryCategory = category || categories[0];
		const categoryKey = primaryCategory?.slug || (primaryCategory?.id ? String(primaryCategory.id) : '');
		const storedCategory = categoryKey ? store.categories[categoryKey] || {} : {};

		metadata.category_id = primaryCategory?.id ? String(primaryCategory.id) : '';
		metadata.category_slug = primaryCategory?.slug || '';
		metadata.category_name = primaryCategory?.name || '';
		metadata.category_view_count = storedCategory?.count ? String(storedCategory.count) : '0';
		metadata.repeat_category_interest = Number(storedCategory?.count || 0) > 1 ? '1' : '0';
		if (!productArea) {
			productArea = primaryCategory?.slug || `category-${primaryCategory?.id || 'unknown'}`;
		}
	}

	if (categorySlugs.length) {
		metadata.product_categories = categorySlugs.join(', ');
	}

	if (categoryNames.length) {
		metadata.product_category_names = categoryNames.join(', ');
	}

	if (page.brand?.slug) {
		metadata.brand_slug = page.brand.slug;
		metadata.brand_name = page.brand.name || '';
	}

	store.products = trimInterestEntries(store.products);
	store.categories = trimInterestEntries(store.categories);
	writeWooInterestStore(store);

	return {
		post_id: Number(page.post_id || 0),
		post_type: page.post_type || '',
		taxonomy_context: categorySlugs.join(', '),
		product_area: productArea,
		brand_context: page.brand?.slug || '',
		metadata,
	};
}

function buildEventPayload(sessionUuid, visitorUuid, values = {}, pageContext = {}) {
	return {
		session_uuid: sessionUuid,
		visitor_uuid: visitorUuid,
		post_id: pageContext.post_id || 0,
		post_type: pageContext.post_type || '',
		taxonomy_context: pageContext.taxonomy_context || '',
		product_area: pageContext.product_area || '',
		brand_context: pageContext.brand_context || '',
		...values,
		metadata: {
			...(pageContext.metadata || {}),
			...(values.metadata || {}),
		},
	};
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

function bindFormTracking(sessionUuid, visitorUuid, pageContext) {
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

			sendTrackingEvent(buildEventPayload(sessionUuid, visitorUuid, {
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
			}, pageContext));
		},
		true
	);
}

function bindInteractionTracking(sessionUuid, visitorUuid, pageContext) {
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

		sendTrackingEvent(buildEventPayload(sessionUuid, visitorUuid, {
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
		}, pageContext));
	});
}

function embedAiChatWidget(sessionUuid, visitorUuid, pageContext) {
	const chatConfig = config.aiChat || {};

	if (!chatConfig.enabled || window.__aceAiChatConfigured || !document.body) {
		return;
	}

	window.__aceAiChatConfigured = true;

	if (!document.querySelector('#ace-ai-chat-style')) {
		const style = document.createElement('style');
		style.id = 'ace-ai-chat-style';
		style.textContent = `
			#ace-ai-chat-launcher {
				position: fixed;
				left: 24px;
				bottom: 24px;
				z-index: 2147483000;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				padding: 14px 20px;
				border: 0;
				border-radius: 999px;
				background: #2563eb;
				color: #ffffff;
				font: 700 15px/1.2 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				box-shadow: 0 18px 48px rgba(15, 23, 42, 0.28);
				cursor: pointer;
			}
			#ace-ai-chat-panel {
				position: fixed;
				left: 24px;
				bottom: 92px;
				z-index: 2147483000;
				width: min(380px, calc(100vw - 32px));
				height: min(560px, calc(100vh - 132px));
				display: flex;
				flex-direction: column;
				background: #ffffff;
				border: 1px solid rgba(15, 23, 42, 0.1);
				border-radius: 18px;
				box-shadow: 0 24px 60px rgba(15, 23, 42, 0.24);
				overflow: hidden;
			}
			#ace-ai-chat-panel[hidden] {
				display: none;
			}
			#ace-ai-chat-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 16px 18px;
				background: #0f172a;
				color: #ffffff;
			}
			#ace-ai-chat-header strong {
				display: block;
				font-size: 15px;
			}
			#ace-ai-chat-header small {
				display: block;
				margin-top: 4px;
				color: rgba(255, 255, 255, 0.75);
			}
			#ace-ai-chat-close {
				border: 0;
				background: transparent;
				color: inherit;
				font-size: 22px;
				line-height: 1;
				cursor: pointer;
			}
			#ace-ai-chat-messages {
				flex: 1;
				padding: 16px;
				overflow-y: auto;
				background: #f8fafc;
			}
			.ace-ai-chat-message {
				margin-bottom: 14px;
			}
			.ace-ai-chat-bubble {
				display: inline-block;
				max-width: 100%;
				padding: 11px 14px;
				border-radius: 14px;
				white-space: pre-wrap;
				word-break: break-word;
				font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			}
			.ace-ai-chat-message[data-role="assistant"] .ace-ai-chat-bubble {
				background: #ffffff;
				color: #0f172a;
				border: 1px solid rgba(15, 23, 42, 0.08);
			}
			.ace-ai-chat-message[data-role="user"] {
				text-align: right;
			}
			.ace-ai-chat-message[data-role="user"] .ace-ai-chat-bubble {
				background: #2563eb;
				color: #ffffff;
			}
			.ace-ai-chat-sources {
				margin: 8px 0 0;
				padding-left: 18px;
				font: 12px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				color: #334155;
			}
			.ace-ai-chat-sources a {
				color: #1d4ed8;
			}
			#ace-ai-chat-form {
				padding: 14px;
				border-top: 1px solid rgba(15, 23, 42, 0.08);
				background: #ffffff;
			}
			#ace-ai-chat-input {
				width: 100%;
				min-height: 92px;
				padding: 12px 14px;
				border: 1px solid rgba(15, 23, 42, 0.14);
				border-radius: 14px;
				resize: vertical;
				font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			}
			#ace-ai-chat-actions {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 12px;
				margin-top: 10px;
			}
			#ace-ai-chat-meta {
				font: 12px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				color: #64748b;
			}
			#ace-ai-chat-send {
				border: 0;
				border-radius: 999px;
				background: #2563eb;
				color: #ffffff;
				padding: 10px 16px;
				font: 700 14px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				cursor: pointer;
			}
			#ace-ai-chat-send[disabled],
			#ace-ai-chat-input[disabled] {
				opacity: 0.6;
				cursor: not-allowed;
			}
		`;
		document.head.appendChild(style);
	}

	const launcher = document.createElement('button');
	const panel = document.createElement('section');
	const header = document.createElement('div');
	const titleWrap = document.createElement('div');
	const title = document.createElement('strong');
	const subtitle = document.createElement('small');
	const close = document.createElement('button');
	const messagesNode = document.createElement('div');
	const form = document.createElement('form');
	const input = document.createElement('textarea');
	const actions = document.createElement('div');
	const meta = document.createElement('div');
	const send = document.createElement('button');
	const state = {
		open: false,
		started: false,
		pending: false,
		conversationUuid: '',
		messages: [],
	};

	launcher.id = 'ace-ai-chat-launcher';
	launcher.type = 'button';
	launcher.textContent = chatConfig.title || 'Chat with us';
	launcher.setAttribute('aria-expanded', 'false');

	panel.id = 'ace-ai-chat-panel';
	panel.hidden = true;

	header.id = 'ace-ai-chat-header';
	title.textContent = chatConfig.title || 'Site assistant';
	subtitle.textContent = chatConfig.provider === 'openai' && chatConfig.model ? `OpenAI · ${chatConfig.model}` : 'Website assistant';
	titleWrap.appendChild(title);
	titleWrap.appendChild(subtitle);

	close.id = 'ace-ai-chat-close';
	close.type = 'button';
	close.setAttribute('aria-label', 'Close chat');
	close.textContent = '×';

	header.appendChild(titleWrap);
	header.appendChild(close);

	messagesNode.id = 'ace-ai-chat-messages';
	messagesNode.setAttribute('aria-live', 'polite');

	form.id = 'ace-ai-chat-form';
	input.id = 'ace-ai-chat-input';
	input.placeholder = chatConfig.placeholder || 'Ask a question about this website';

	actions.id = 'ace-ai-chat-actions';
	meta.id = 'ace-ai-chat-meta';
	meta.textContent = chatConfig.showSources ? 'Replies can include source links from this website.' : 'Ask about pages, posts, and products on this website.';
	send.id = 'ace-ai-chat-send';
	send.type = 'submit';
	send.textContent = 'Send';

	actions.appendChild(meta);
	actions.appendChild(send);
	form.appendChild(input);
	form.appendChild(actions);

	panel.appendChild(header);
	panel.appendChild(messagesNode);
	panel.appendChild(form);
	document.body.appendChild(launcher);
	document.body.appendChild(panel);

	const pushMessage = (message) => {
		state.messages.push(message);
	};

	const renderMessages = () => {
		messagesNode.innerHTML = '';

		state.messages.forEach((message) => {
			const item = document.createElement('div');
			const bubble = document.createElement('div');

			item.className = 'ace-ai-chat-message';
			item.dataset.role = message.role;
			bubble.className = 'ace-ai-chat-bubble';
			bubble.textContent = message.content;
			item.appendChild(bubble);

			if (chatConfig.showSources && Array.isArray(message.sources) && message.sources.length) {
				const list = document.createElement('ol');
				list.className = 'ace-ai-chat-sources';

				message.sources.forEach((source) => {
					if (!source?.url || !source?.title) {
						return;
					}

					const listItem = document.createElement('li');
					const link = document.createElement('a');
					link.href = source.url;
					link.target = '_blank';
					link.rel = 'noopener noreferrer';
					link.textContent = source.label ? `${source.title} (${source.label})` : source.title;
					listItem.appendChild(link);
					list.appendChild(listItem);
				});

				if (list.childNodes.length) {
					item.appendChild(list);
				}
			}

			messagesNode.appendChild(item);
		});

		messagesNode.scrollTop = messagesNode.scrollHeight;
	};

	const setOpen = (nextOpen) => {
		state.open = nextOpen;
		panel.hidden = !nextOpen;
		launcher.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');

		if (nextOpen && !state.started) {
			state.started = true;
			state.conversationUuid = window.crypto?.randomUUID?.() || `ace-chat-${Date.now()}-${Math.random().toString(16).slice(2)}`;
			sendTrackingEvent(buildEventPayload(sessionUuid, visitorUuid, {
				event_type: 'chat_start',
				event_name: 'frontend_ai_chat_started',
				url: window.location.href,
				path: window.location.pathname,
				page_title: document.title,
				referrer: document.referrer,
				utm: getUtm(),
				metadata: {
					conversation_uuid: state.conversationUuid,
					provider: chatConfig.provider || 'openai',
					model: chatConfig.model || '',
				},
			}, pageContext));
			input.focus();
		}
	};

	const setPending = (nextPending) => {
		state.pending = nextPending;
		input.disabled = nextPending;
		send.disabled = nextPending;
		send.textContent = nextPending ? 'Sending…' : 'Send';
	};

	const buildHistory = () => state.messages
		.filter((message) => message.role === 'user' || message.role === 'assistant')
		.slice(-1 * Number(chatConfig.maxHistoryMessages || 8))
		.map((message) => ({
			role: message.role,
			content: message.content,
		}));

	const sendMessage = async (content) => {
		if (!content || state.pending) {
			return;
		}

		pushMessage({ role: 'user', content });
		renderMessages();
		setPending(true);

		sendTrackingEvent(buildEventPayload(sessionUuid, visitorUuid, {
			event_type: 'chat_message',
			event_name: 'frontend_ai_chat_message',
			url: window.location.href,
			path: window.location.pathname,
			page_title: document.title,
			referrer: document.referrer,
			utm: getUtm(),
			metadata: {
				provider: chatConfig.provider || 'openai',
				model: chatConfig.model || '',
				message_length: String(content.length),
			},
		}, pageContext));

		try {
			const history = buildHistory().slice(0, -1);
			const headers = {
				'Content-Type': 'application/json',
			};

			if (chatConfig.restNonce) {
				headers['X-WP-Nonce'] = chatConfig.restNonce;
			}

			const response = await fetch(chatConfig.endpoint || `${config.root}${config.namespace}/ai/chat/respond`, {
				method: 'POST',
				credentials: 'same-origin',
				headers,
				body: JSON.stringify({
					message: content,
					history: chatConfig.keepHistory ? history : [],
					conversation_uuid: state.conversationUuid || '',
					session_uuid: sessionUuid || '',
					visitor_uuid: visitorUuid || '',
					page_url: window.location.href,
					page_title: document.title || '',
				}),
			});
			const data = await response.json();

			if (!response.ok) {
				throw new Error(data?.message || 'The site assistant could not reply just now.');
			}

			pushMessage({
				role: 'assistant',
				content: data?.message || 'Sorry, I could not prepare a reply just now.',
				sources: Array.isArray(data?.sources) ? data.sources : [],
			});
		} catch (error) {
			pushMessage({
				role: 'assistant',
				content: error?.message || 'Sorry, I could not prepare a reply just now.',
				sources: [],
			});
		} finally {
			setPending(false);
			renderMessages();
		}
	};

	pushMessage({
		role: 'assistant',
		content: chatConfig.greeting || 'Hello, I am the site assistant. Ask me anything about this website and I will do my best to help.',
		sources: [],
	});
	renderMessages();

	launcher.addEventListener('click', () => setOpen(!state.open));
	close.addEventListener('click', () => setOpen(false));
	form.addEventListener('submit', (event) => {
		event.preventDefault();
		const content = input.value.trim();

		if (!content) {
			return;
		}

		input.value = '';
		sendMessage(content);
	});
	input.addEventListener('keydown', (event) => {
		if (event.key === 'Enter' && !event.shiftKey) {
			event.preventDefault();
			form.requestSubmit();
		}
	});
}

function init() {
	const trackingEnabled = !!config.enabled;
	const shouldEmbedChat = !!config.aiChat?.enabled;

	if (!trackingEnabled && !shouldEmbedChat) {
		return;
	}

	const shouldTrackPageviews = trackingEnabled && !!config.tracking?.track_pageviews;
	const shouldTrackClicks = trackingEnabled && (!!config.tracking?.track_click_to_call || !!config.tracking?.track_downloads);
	const shouldTrackForms = trackingEnabled && !!config.tracking?.track_forms;
	const shouldResolveNumbers = trackingEnabled && !!document.querySelector('[data-ace-phone], [data-ace-phone-link]');

	if (!shouldTrackPageviews && !shouldTrackClicks && !shouldTrackForms && !shouldResolveNumbers && !shouldEmbedChat) {
		return;
	}

	const sessionUuid = ensureUuid(config.tracking.cookie_name || 'ace_sid', Number(config.tracking.session_lifetime_minutes || 30));
	const visitorUuid = ensureUuid(config.tracking.visitor_cookie_name || 'ace_vid', Number(config.tracking.visitor_lifetime_days || 90), true);
	const pageContext = buildWooCommerceContext();

	if (shouldTrackPageviews) {
		sendTrackingEvent(buildEventPayload(sessionUuid, visitorUuid, {
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
		}, pageContext));
	}

	if (shouldTrackClicks) {
		bindInteractionTracking(sessionUuid, visitorUuid, pageContext);
	}

	if (shouldTrackForms) {
		bindFormTracking(sessionUuid, visitorUuid, pageContext);
	}

	if (shouldResolveNumbers) {
		resolvePhoneNumber();
	}

	if (shouldEmbedChat) {
		embedAiChatWidget(sessionUuid, visitorUuid, pageContext);
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}
