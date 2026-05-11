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
			.ace-ai-chat-bubble a {
				color: #1d4ed8;
				font-weight: 600;
				text-decoration: underline;
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
				display: grid;
				gap: 10px;
				margin: 10px 0 0;
			}
			.ace-ai-chat-source-lead {
				margin-bottom: 6px;
			}
			.ace-ai-chat-source-more {
				display: grid;
				gap: 12px;
				margin-top: 8px;
			}
			.ace-ai-chat-source-more[hidden] {
				display: none;
			}
			.ace-ai-chat-source-toggle {
				justify-self: start;
				padding: 0;
				border: 0;
				background: transparent;
				color: #1d4ed8;
				font: 700 12px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				cursor: pointer;
				text-decoration: underline;
			}
			.ace-ai-chat-source-meta {
				display: block;
				margin-top: 4px;
				font: 12px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				color: #334155;
			}
			.ace-ai-chat-source-card {
				display: grid;
				grid-template-columns: 64px minmax(0, 1fr);
				gap: 10px;
				align-items: start;
				padding: 10px;
				border-radius: 14px;
				background: #ffffff;
				border: 1px solid rgba(15, 23, 42, 0.08);
				color: #0f172a;
				text-decoration: none;
			}
			.ace-ai-chat-source-card:hover {
				border-color: rgba(37, 99, 235, 0.28);
				box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
			}
			.ace-ai-chat-source-thumb {
				width: 64px;
				height: 64px;
				border-radius: 12px;
				object-fit: cover;
				background: #e2e8f0;
			}
			.ace-ai-chat-source-thumb-placeholder {
				display: flex;
				align-items: center;
				justify-content: center;
				width: 64px;
				height: 64px;
				border-radius: 12px;
				background: linear-gradient(135deg, #dbeafe, #eff6ff);
				color: #1d4ed8;
				font: 700 18px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			}
			.ace-ai-chat-source-copy {
				min-width: 0;
			}
			.ace-ai-chat-source-title {
				display: block;
				font: 700 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				color: inherit;
			}
			.ace-ai-chat-source-summary {
				display: block;
				margin-top: 4px;
				font: 12px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				color: #475569;
			}
			.ace-ai-chat-source-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin-top: 8px;
			}
			.ace-ai-chat-source-action {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				padding: 8px 12px;
				border-radius: 999px;
				border: 1px solid rgba(37, 99, 235, 0.16);
				background: #eff6ff;
				color: #1d4ed8;
				font: 700 12px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				text-decoration: none;
				cursor: pointer;
			}
			.ace-ai-chat-source-action--secondary {
				background: #ffffff;
				color: #0f172a;
				border-color: rgba(15, 23, 42, 0.12);
			}
			#ace-ai-chat-form {
				box-sizing: border-box;
				padding: 12px;
				border-top: 1px solid rgba(15, 23, 42, 0.08);
				background: #ffffff;
			}
			#ace-ai-chat-input {
				display: block;
				box-sizing: border-box;
				width: 100%;
				height: 42px;
				min-height: 42px;
				max-height: 126px;
				padding: 10px 14px;
				border: 1px solid rgba(15, 23, 42, 0.14);
				border-radius: 14px;
				resize: none;
				overflow-y: hidden;
				font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			}
			#ace-ai-chat-actions {
				display: flex;
				flex-wrap: wrap;
				align-items: flex-start;
				justify-content: space-between;
				gap: 12px;
				margin-top: 10px;
			}
			#ace-ai-chat-meta {
				flex: 1 1 180px;
				min-width: 0;
				font: 12px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				color: #64748b;
			}
			#ace-ai-chat-send {
				flex: 0 0 auto;
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
	const chatStateKey = `ace_ai_chat_state_v1:${visitorUuid || 'guest'}`;

	launcher.id = 'ace-ai-chat-launcher';
	launcher.type = 'button';
	launcher.textContent = chatConfig.title || 'Chat with us';
	launcher.setAttribute('aria-expanded', 'false');

	panel.id = 'ace-ai-chat-panel';
	panel.hidden = true;

	header.id = 'ace-ai-chat-header';
	title.textContent = chatConfig.title || chatConfig.botName || 'Site assistant';
	titleWrap.appendChild(title);

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
	input.rows = 1;
	input.placeholder = chatConfig.placeholder || 'Ask about the company or products';

	actions.id = 'ace-ai-chat-actions';
	meta.id = 'ace-ai-chat-meta';
	meta.textContent = chatConfig.showSources ? 'Replies can include links to relevant company and product information.' : 'Ask about the company, products, or services.';
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
		persistChatState();
	};

	const serialiseMessage = (message) => ({
		role: message?.role === 'user' ? 'user' : 'assistant',
		content: String(message?.content || ''),
		sources: Array.isArray(message?.sources)
			? message.sources.slice(0, 5).map((source) => ({
				title: String(source?.title || ''),
				url: String(source?.url || ''),
				label: String(source?.label || ''),
				summary: String(source?.summary || ''),
				image_url: String(source?.image_url || ''),
				source_type: String(source?.source_type || ''),
				commerce: {
					price: String(source?.commerce?.price || ''),
					variation_count: Number(source?.commerce?.variation_count || 0),
					can_add_to_cart: !!source?.commerce?.can_add_to_cart,
					add_to_cart_url: String(source?.commerce?.add_to_cart_url || ''),
					view_url: String(source?.commerce?.view_url || source?.url || ''),
				},
			}))
			: [],
	});

	const persistChatState = () => {
		try {
			window.localStorage?.setItem(chatStateKey, JSON.stringify({
				open: !!state.open,
				started: !!state.started,
				conversationUuid: state.conversationUuid || '',
				messages: state.messages.slice(-20).map(serialiseMessage),
			}));
		} catch (error) {
			// Ignore storage failures in the browser.
		}
	};

	const restoreChatState = () => {
		try {
			const raw = window.localStorage?.getItem(chatStateKey);

			if (!raw) {
				return;
			}

			const saved = JSON.parse(raw);

			if (!saved || typeof saved !== 'object') {
				return;
			}

			state.open = !!saved.open;
			state.started = !!saved.started;
			state.conversationUuid = String(saved.conversationUuid || '');
			state.messages = Array.isArray(saved.messages) ? saved.messages.map(serialiseMessage) : [];

			if (!state.started && (state.messages.length || state.conversationUuid)) {
				state.started = true;
			}
		} catch (error) {
			// Ignore storage failures in the browser.
		}
	};

	const updateInputHeight = () => {
		input.style.height = 'auto';

		const computed = window.getComputedStyle(input);
		const lineHeight = parseFloat(computed.lineHeight) || 21;
		const padding = (parseFloat(computed.paddingTop) || 0) + (parseFloat(computed.paddingBottom) || 0);
		const border = (parseFloat(computed.borderTopWidth) || 0) + (parseFloat(computed.borderBottomWidth) || 0);
		const minHeight = Math.round(lineHeight + padding + border);
		const maxHeight = Math.round((lineHeight * 5) + padding + border);
		const nextHeight = Math.max(minHeight, Math.min(input.scrollHeight, maxHeight));

		input.style.height = `${nextHeight}px`;
		input.style.overflowY = input.scrollHeight > maxHeight ? 'auto' : 'hidden';
	};

	const normaliseSourceTitle = (title) => String(title || '').trim().toLowerCase();

	const appendLinkedText = (container, text, sources) => {
		const content = String(text || '');
		const linkableSources = Array.isArray(sources)
			? sources
				.filter((source) => source?.url && source?.title)
				.sort((left, right) => String(right.title || '').length - String(left.title || '').length)
			: [];

		if (!content || !linkableSources.length) {
			container.appendChild(document.createTextNode(content));
			return;
		}

		const contentLower = content.toLowerCase();
		let cursor = 0;

		while (cursor < content.length) {
			let nextMatch = null;

			linkableSources.forEach((source) => {
				const title = String(source.title || '');
				const titleLower = normaliseSourceTitle(title);

				if (!titleLower) {
					return;
				}

				const index = contentLower.indexOf(titleLower, cursor);

				if (index === -1) {
					return;
				}

				if (!nextMatch || index < nextMatch.index || (index === nextMatch.index && title.length > nextMatch.title.length)) {
					nextMatch = { index, title, source };
				}
			});

			if (!nextMatch) {
				container.appendChild(document.createTextNode(content.slice(cursor)));
				break;
			}

			if (nextMatch.index > cursor) {
				container.appendChild(document.createTextNode(content.slice(cursor, nextMatch.index)));
			}

			const link = document.createElement('a');
			link.href = nextMatch.source.url;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.textContent = content.slice(nextMatch.index, nextMatch.index + nextMatch.title.length);
			container.appendChild(link);

			cursor = nextMatch.index + nextMatch.title.length;
		}
	};

	const renderBubbleContent = (bubble, message) => {
		bubble.textContent = '';

		String(message?.content || '').split('\n').forEach((line, index, lines) => {
			appendLinkedText(bubble, line, message?.role === 'assistant' ? message.sources : []);

			if (index < lines.length - 1) {
				bubble.appendChild(document.createElement('br'));
			}
		});
	};

	const summariseSourceText = (source) => {
		const raw = String(source?.summary || '').replace(/\s+/g, ' ').replace(/^["'\s]+|["'\s]+$/g, '').trim();

		if (!raw) {
			return '';
		}

		const sentences = raw.match(/[^.!?]+[.!?]?/g) || [raw];
		const lead = sentences
			.map((sentence) => sentence.trim())
			.filter(Boolean)
			.slice(0, 2)
			.join(' ');

		const summary = lead || raw;

		if (summary.length <= 140) {
			return summary;
		}

		return `${summary.slice(0, 137).trim().replace(/[.,;:!?-]+$/u, '')}…`;
	};

	const buildSourceMeta = (source) => {
		const meta = [];
		const price = String(source?.commerce?.price || '').trim();
		const variationCount = Number(source?.commerce?.variation_count || 0);

		if (price) {
			meta.push(price);
		}

		if (variationCount > 0) {
			meta.push(`${variationCount} option${variationCount === 1 ? '' : 's'}`);
		}

		return meta.join(' · ');
	};

	const navigateWithChatState = (url) => {
		if (!url) {
			return;
		}

		state.open = true;
		persistChatState();
		window.location.assign(url);
	};

	const buildSourceCard = (source) => {
		if (!source?.url || !source?.title) {
			return null;
		}

		const card = document.createElement('div');
		const thumb = document.createElement(source.image_url ? 'img' : 'div');
		const copy = document.createElement('div');
		const titleNode = document.createElement('strong');
		const metaNode = document.createElement('span');
		const summaryNode = document.createElement('span');
		const actionsNode = document.createElement('div');

		card.className = 'ace-ai-chat-source-card';

		if (source.image_url) {
			thumb.className = 'ace-ai-chat-source-thumb';
			thumb.src = source.image_url;
			thumb.alt = source.title;
			thumb.loading = 'lazy';
		} else {
			thumb.className = 'ace-ai-chat-source-thumb-placeholder';
			thumb.textContent = String(source.title || '').trim().charAt(0).toUpperCase() || '•';
		}

		copy.className = 'ace-ai-chat-source-copy';
		titleNode.className = 'ace-ai-chat-source-title';
		titleNode.textContent = source.title;
		metaNode.className = 'ace-ai-chat-source-meta';
		metaNode.textContent = buildSourceMeta(source);
		summaryNode.className = 'ace-ai-chat-source-summary';
		summaryNode.textContent = summariseSourceText(source);
		actionsNode.className = 'ace-ai-chat-source-actions';

		copy.appendChild(titleNode);

		if (metaNode.textContent) {
			copy.appendChild(metaNode);
		}

		if (summaryNode.textContent) {
			copy.appendChild(summaryNode);
		}

		if (source?.commerce?.can_add_to_cart && source?.commerce?.add_to_cart_url) {
			const addButton = document.createElement('button');
			addButton.type = 'button';
			addButton.className = 'ace-ai-chat-source-action';
			addButton.textContent = 'Add to basket';
			addButton.addEventListener('click', () => navigateWithChatState(source.commerce.add_to_cart_url));
			actionsNode.appendChild(addButton);
		}

		if (source?.commerce?.view_url || source?.url) {
			const viewButton = document.createElement('button');
			viewButton.type = 'button';
			viewButton.className = 'ace-ai-chat-source-action ace-ai-chat-source-action--secondary';
			viewButton.textContent = source?.commerce?.variation_count > 0 ? 'View options' : 'View product';
			viewButton.addEventListener('click', () => navigateWithChatState(source?.commerce?.view_url || source.url));
			actionsNode.appendChild(viewButton);
		}

		if (actionsNode.childNodes.length) {
			copy.appendChild(actionsNode);
		}

		card.appendChild(thumb);
		card.appendChild(copy);

		return card;
	};

	const renderMessages = () => {
		messagesNode.innerHTML = '';

		state.messages.forEach((message) => {
			const item = document.createElement('div');
			const bubble = document.createElement('div');

			item.className = 'ace-ai-chat-message';
			item.dataset.role = message.role;
			bubble.className = 'ace-ai-chat-bubble';
			renderBubbleContent(bubble, message);
			item.appendChild(bubble);

			if (chatConfig.showSources && Array.isArray(message.sources) && message.sources.length) {
				const list = document.createElement('div');
				const leadWrap = document.createElement('div');
				const moreWrap = document.createElement('div');
				list.className = 'ace-ai-chat-sources';
				leadWrap.className = 'ace-ai-chat-source-lead';
				moreWrap.className = 'ace-ai-chat-source-more';
				moreWrap.hidden = true;

				message.sources.forEach((source, index) => {
					const card = buildSourceCard(source);

					if (card) {
						if (0 === index) {
							leadWrap.appendChild(card);
						} else {
							moreWrap.appendChild(card);
						}
					}
				});

				if (leadWrap.childNodes.length) {
					list.appendChild(leadWrap);
				}

				if (moreWrap.childNodes.length) {
					const toggle = document.createElement('button');
					const extraCount = moreWrap.childNodes.length;
					toggle.type = 'button';
					toggle.className = 'ace-ai-chat-source-toggle';
					toggle.textContent = `Show ${extraCount} other option${extraCount === 1 ? '' : 's'}`;
					toggle.addEventListener('click', () => {
						const isHidden = moreWrap.hidden;
						moreWrap.hidden = !isHidden;
						toggle.textContent = isHidden
							? 'Hide other options'
							: `Show ${extraCount} other option${extraCount === 1 ? '' : 's'}`;
					});
					list.appendChild(toggle);
					list.appendChild(moreWrap);
				}

				if (list.childNodes.length) {
					item.appendChild(list);
				}
			}

			messagesNode.appendChild(item);
		});

		messagesNode.scrollTop = messagesNode.scrollHeight;
	};

	restoreChatState();

	const setOpen = (nextOpen) => {
		state.open = nextOpen;
		panel.hidden = !nextOpen;
		launcher.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
		persistChatState();

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
			updateInputHeight();
		}
	};

	const setPending = (nextPending) => {
		state.pending = nextPending;
		input.disabled = nextPending;
		send.disabled = nextPending;
		send.textContent = nextPending ? 'Sending…' : 'Send';
		updateInputHeight();
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
			persistChatState();
		}
	};

	if (!state.messages.length) {
		pushMessage({
			role: 'assistant',
			content: chatConfig.greeting || `Hello, I am ${chatConfig.botName || chatConfig.title || 'the site assistant'}. Ask me about the company, products, or services and I will do my best to help.`,
			sources: [],
		});
	}
	renderMessages();
	updateInputHeight();
	if (state.open) {
		setOpen(true);
	}

	launcher.addEventListener('click', () => setOpen(!state.open));
	close.addEventListener('click', () => setOpen(false));
	form.addEventListener('submit', (event) => {
		event.preventDefault();
		const content = input.value.trim();

		if (!content) {
			return;
		}

		input.value = '';
		updateInputHeight();
		sendMessage(content);
	});
	input.addEventListener('input', updateInputHeight);
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
