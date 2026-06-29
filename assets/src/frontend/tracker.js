import './style.scss';

const config = window.ACEFrontendConfig || {};
const WOO_INTEREST_STORAGE_KEY = 'ace_wc_interest_v1';

function setCookie(name, value, duration, useDays = false) {
	const maxAge = useDays ? duration * 24 * 60 * 60 : duration * 60;
	const secure = window.location.protocol === 'https:' ? '; Secure' : '';
	document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; SameSite=Lax${secure}`;
}

function getCookie(name) {
	const prefix = `${name}=`;
	return document.cookie
		.split(';')
		.map((part) => part.trim())
		.find((part) => part.startsWith(prefix))
		?.slice(prefix.length) || '';
}

function generateUuid() {
	if (window.crypto && typeof window.crypto.randomUUID === 'function') {
		return window.crypto.randomUUID();
	}

	const bytes = new Uint8Array(16);

	if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
		window.crypto.getRandomValues(bytes);
	} else {
		for (let i = 0; i < 16; i += 1) {
			bytes[i] = Math.floor(Math.random() * 256);
		}
	}

	bytes[6] = (bytes[6] & 0x0f) | 0x40;
	bytes[8] = (bytes[8] & 0x3f) | 0x80;

	const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'));

	return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10, 16).join('')}`;
}

function isValidId(value) {
	// Stored ids must fit the 36-char id columns; regenerate anything older/longer.
	return typeof value === 'string' && value.length > 0 && value.length <= 36;
}

function ensureUuid(name, duration, useDays = false) {
	let value = getCookie(name);

	if (!isValidId(value)) {
		value = generateUuid();
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

function tryParseJson(text) {
	try {
		return JSON.parse(text);
	} catch (error) {
		return null;
	}
}

function extractJsonPayload(rawText) {
	const text = String(rawText || '').trim();

	if (!text) {
		return null;
	}

	const starts = [0, text.indexOf('{'), text.indexOf('[')]
		.filter((index, position, values) => index >= 0 && values.indexOf(index) === position)
		.sort((left, right) => left - right);

	for (const start of starts) {
		const candidate = text.slice(start).trim();
		const direct = tryParseJson(candidate);

		if (direct !== null) {
			return direct;
		}

		const closingChar = candidate.startsWith('[') ? ']' : '}';
		const end = candidate.lastIndexOf(closingChar);

		if (end > 0) {
			const bounded = tryParseJson(candidate.slice(0, end + 1));

			if (bounded !== null) {
				return bounded;
			}
		}
	}

	return null;
}

function buildApiError(payload, fallbackMessage, status = 0) {
	const error = new Error(String(payload?.message || fallbackMessage || 'The request could not be completed.'));
	error.status = status;
	error.data = payload;
	return error;
}

async function readJsonResponse(response, fallbackMessage) {
	const payload = extractJsonPayload(await response.text());

	if (!response.ok) {
		throw buildApiError(payload, fallbackMessage, response.status);
	}

	if (payload === null) {
		throw buildApiError(null, fallbackMessage || 'The server returned an unreadable response.', response.status);
	}

	return payload;
}

async function fetchJson(url, options = {}, fallbackMessage = '') {
	const response = await fetch(url, options);
	return readJsonResponse(response, fallbackMessage);
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
		const number = await fetchJson(`${config.root}${config.namespace}/number/resolve?${params.toString()}`, {
			credentials: 'same-origin',
		}, 'The phone number could not be loaded just now.');

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

	// Voice (provider-agnostic — works regardless of whether the text brain is OpenAI or Claude).
	const SpeechRecognitionImpl = window.SpeechRecognition || window.webkitSpeechRecognition || null;
	const voiceOutputSupported = typeof window.speechSynthesis !== 'undefined' && typeof window.SpeechSynthesisUtterance !== 'undefined';
	const voiceInputConfigured = !!chatConfig.voiceInput;
	const mediaRecorderSupported = typeof window.MediaRecorder !== 'undefined' && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
	const voiceSttPremium = !!chatConfig.voiceSttEnabled && !!chatConfig.voiceTranscribeEndpoint && mediaRecorderSupported;
	const voiceInputEnabled = voiceInputConfigured && (voiceSttPremium || !!SpeechRecognitionImpl);
	const premiumTtsEnabled = !!chatConfig.voiceTtsEnabled && !!chatConfig.voiceProvider && chatConfig.voiceProvider !== 'browser' && !!chatConfig.voiceTtsEndpoint;
	const voiceRepliesEnabled = !!chatConfig.voiceReplies && (voiceOutputSupported || premiumTtsEnabled);
	const voiceHandsFree = !!chatConfig.voiceHandsFree;
	const voiceLang = String(chatConfig.voiceLang || 'en-GB');
	let speechRecognition = null;
	let lastSpokenKey = '';
	let voiceRenderPrimed = false;
	let currentAudio = null;
	let mediaRecorder = null;
	let mediaChunks = [];
	let mediaStream = null;
	let vadContext = null;
	let vadRaf = null;
	let vadStream = null;

	const launcher = document.createElement('button');
	const panel = document.createElement('section');
	const header = document.createElement('div');
	const titleWrap = document.createElement('div');
	const title = document.createElement('strong');
	const status = document.createElement('small');
	const headerActions = document.createElement('div');
	const contactToggle = document.createElement('button');
	const endChat = document.createElement('button');
	const close = document.createElement('button');
	const messagesNode = document.createElement('div');
	const form = document.createElement('form');
	const contactPanel = document.createElement('div');
	const contactCopy = document.createElement('p');
	const contactName = document.createElement('input');
	const contactEmail = document.createElement('input');
	const contactPhone = document.createElement('input');
	const contactCompany = document.createElement('input');
	const contactRole = document.createElement('input');
	const contactSubmit = document.createElement('button');
	const starterQuestions = document.createElement('div');
	const input = document.createElement('textarea');
	const actions = document.createElement('div');
	const meta = document.createElement('div');
	const send = document.createElement('button');
	const micButton = document.createElement('button');
	const voiceToggle = document.createElement('button');
	const state = {
		open: false,
		started: false,
		pending: false,
		conversationUuid: '',
		messages: [],
		conversationStatus: 'open',
		handoverEnabled: false,
		endedAt: '',
		availabilityOnline: false,
		availabilityWatcherCount: 0,
		followUpRequested: false,
		typing: {},
		syncBackoffUntil: 0,
		voiceListening: false,
		voiceTranscribing: false,
		voiceSpeaking: false,
		// Spoken replies are opt-in per visitor — off by default even when the admin enabled the capability.
		voiceSpeakerOn: voiceRepliesEnabled && (() => {
			try {
				return window.localStorage.getItem(`ace_ai_voice_speaker:${visitorUuid || 'guest'}`) === '1';
			} catch (error) {
				return false;
			}
		})(),
	};
	const chatStateKey = `ace_ai_chat_state_v1:${visitorUuid || 'guest'}`;
	let syncTimer = null;
	let availabilityTimer = null;
	let typingResetTimer = null;
	let typingLastSentAt = 0;
	let typingSentState = false;

	const generateConversationUuid = () => generateUuid();

	launcher.id = 'ace-ai-chat-launcher';
	launcher.type = 'button';
	launcher.textContent = chatConfig.title || 'Chat with us';
	launcher.setAttribute('aria-expanded', 'false');

	panel.id = 'ace-ai-chat-panel';
	panel.hidden = true;

	header.id = 'ace-ai-chat-header';
	title.textContent = chatConfig.title || chatConfig.botName || 'Site assistant';
	status.id = 'ace-ai-chat-status';
	status.dataset.online = 'false';
	status.textContent = 'Checking team availability…';
	titleWrap.appendChild(title);
	titleWrap.appendChild(status);

	close.id = 'ace-ai-chat-close';
	close.type = 'button';
	close.setAttribute('aria-label', 'Close chat');
	close.textContent = '×';
	contactToggle.id = 'ace-ai-chat-contact-toggle';
	contactToggle.type = 'button';
	contactToggle.textContent = 'Leave your details';
	contactToggle.hidden = true;
	endChat.id = 'ace-ai-chat-end';
	endChat.type = 'button';
	endChat.textContent = 'End chat';
	headerActions.id = 'ace-ai-chat-header-actions';
	voiceToggle.id = 'ace-ai-chat-voice-toggle';
	voiceToggle.type = 'button';
	voiceToggle.className = 'ace-ai-chat-voice-toggle';
	voiceToggle.setAttribute('aria-label', 'Toggle spoken replies');
	voiceToggle.setAttribute('aria-pressed', 'false');
	voiceToggle.textContent = '🔇';
	headerActions.appendChild(contactToggle);
	if (voiceRepliesEnabled) {
		headerActions.appendChild(voiceToggle);
	}
	headerActions.appendChild(endChat);
	headerActions.appendChild(close);

	header.appendChild(titleWrap);
	header.appendChild(headerActions);

	messagesNode.id = 'ace-ai-chat-messages';
	messagesNode.setAttribute('aria-live', 'polite');

	form.id = 'ace-ai-chat-form';
	contactPanel.id = 'ace-ai-chat-contact-panel';
	contactPanel.hidden = true;
	contactCopy.textContent = 'Nobody is watching live chats just now. Leave your details and the team can get back to you.';
	contactName.className = 'ace-ai-chat-contact-field';
	contactName.type = 'text';
	contactName.placeholder = 'Your name';
	contactEmail.className = 'ace-ai-chat-contact-field';
	contactEmail.type = 'email';
	contactEmail.placeholder = 'Email address';
	contactPhone.className = 'ace-ai-chat-contact-field';
	contactPhone.type = 'tel';
	contactPhone.placeholder = 'Phone number (optional)';
	contactCompany.className = 'ace-ai-chat-contact-field';
	contactCompany.type = 'text';
	contactCompany.placeholder = 'Company or organisation';
	contactRole.className = 'ace-ai-chat-contact-field';
	contactRole.type = 'text';
	contactRole.placeholder = 'Role or team (optional)';
	contactSubmit.id = 'ace-ai-chat-contact-submit';
	contactSubmit.type = 'button';
	contactSubmit.textContent = 'Request follow-up';
	starterQuestions.id = 'ace-ai-chat-starters';
	starterQuestions.hidden = true;
	input.id = 'ace-ai-chat-input';
	input.rows = 1;
	input.placeholder = chatConfig.placeholder || 'Ask about the company or products';

	actions.id = 'ace-ai-chat-actions';
	meta.id = 'ace-ai-chat-meta';
	meta.textContent = chatConfig.showSources ? 'Replies can include links to relevant company and product information.' : 'Ask about the company, products, or services.';
	send.id = 'ace-ai-chat-send';
	send.type = 'submit';
	send.textContent = 'Send';
	micButton.id = 'ace-ai-chat-mic';
	micButton.type = 'button';
	micButton.className = 'ace-ai-chat-mic';
	micButton.setAttribute('aria-label', 'Speak your message');
	micButton.setAttribute('aria-pressed', 'false');
	micButton.textContent = '🎤';

	if (voiceInputConfigured && !voiceInputEnabled) {
		// Site has voice input on, but this browser has no Speech Recognition API.
		micButton.disabled = true;
		micButton.title = 'Voice input is not supported in this browser. Try Chrome, Edge, or Safari.';
	}

	actions.appendChild(meta);
	if (voiceInputConfigured) {
		actions.appendChild(micButton);
	}
	actions.appendChild(send);
	contactPanel.appendChild(contactCopy);
	contactPanel.appendChild(contactName);
	contactPanel.appendChild(contactEmail);
	contactPanel.appendChild(contactPhone);
	contactPanel.appendChild(contactCompany);
	contactPanel.appendChild(contactRole);
	contactPanel.appendChild(contactSubmit);
	form.appendChild(contactPanel);
	form.appendChild(starterQuestions);
	form.appendChild(input);
	form.appendChild(actions);

	panel.appendChild(header);
	panel.appendChild(messagesNode);
	panel.appendChild(form);
	document.body.appendChild(launcher);
	document.body.appendChild(panel);

	const ensureMessageKey = (message) => {
		if (!message || typeof message !== 'object') {
			return `ace-message-${Date.now()}-${Math.random().toString(16).slice(2)}`;
		}

		if (!message.client_key) {
			message.client_key = `ace-message-${Date.now()}-${Math.random().toString(16).slice(2)}`;
		}

		return message.client_key;
	};

	const pushMessage = (message) => {
		state.messages.push(serialiseMessage(message));
		persistChatState();
	};

	const serialiseMessage = (message) => ({
		id: Number(message?.id || 0),
		client_key: String(message?.client_key || ensureMessageKey(message)),
		role: message?.role === 'user' ? 'user' : (message?.role === 'operator' ? 'operator' : 'assistant'),
		content: String(message?.content || ''),
		author_name: String(message?.author_name || ''),
		author_avatar_url: String(message?.author_avatar_url || ''),
		created_at: String(message?.created_at || ''),
		is_error: !!message?.is_error,
		sources: Array.isArray(message?.sources)
			? message.sources.slice(0, 5).map((source) => ({
				title: String(source?.title || ''),
				url: String(source?.url || ''),
				label: String(source?.label || ''),
				content_type: String(source?.content_type || ''),
				summary: String(source?.summary || ''),
				image_url: String(source?.image_url || ''),
				source_type: String(source?.source_type || ''),
				commerce: {
					price: String(source?.commerce?.price || ''),
					sku: String(source?.commerce?.sku || ''),
					stock_status: String(source?.commerce?.stock_status || ''),
					stock_quantity: Number.isFinite(Number(source?.commerce?.stock_quantity)) ? Number(source?.commerce?.stock_quantity) : null,
					empty_weight_kg: Number.isFinite(Number(source?.commerce?.empty_weight_kg)) ? Number(source?.commerce?.empty_weight_kg) : null,
					dimensions_cm: {
						length: Number.isFinite(Number(source?.commerce?.dimensions_cm?.length)) ? Number(source?.commerce?.dimensions_cm?.length) : null,
						width: Number.isFinite(Number(source?.commerce?.dimensions_cm?.width)) ? Number(source?.commerce?.dimensions_cm?.width) : null,
						height: Number.isFinite(Number(source?.commerce?.dimensions_cm?.height)) ? Number(source?.commerce?.dimensions_cm?.height) : null,
					},
					needs_shipping: source?.commerce?.needs_shipping === null || typeof source?.commerce?.needs_shipping === 'undefined' ? null : !!source?.commerce?.needs_shipping,
					shipping_class: String(source?.commerce?.shipping_class || ''),
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
				conversationStatus: state.conversationStatus || 'open',
				handoverEnabled: !!state.handoverEnabled,
				endedAt: state.endedAt || '',
				followUpRequested: !!state.followUpRequested,
				messages: state.messages.slice(-20).map(serialiseMessage),
			}));
		} catch (error) {
			// Ignore storage failures in the browser.
		}
	};

	const getMessageAuthor = (message) => {
		const role = String(message?.role || '');
		const explicitName = String(message?.author_name || '').trim();
		const explicitAvatar = String(message?.author_avatar_url || '').trim();

		if (role === 'user') {
			return {
				name: explicitName || 'You',
				avatarUrl: explicitAvatar,
			};
		}

		if (role === 'operator') {
			return {
				name: explicitName || 'Agent',
				avatarUrl: explicitAvatar,
			};
		}

		return {
			name: explicitName || chatConfig.botName || chatConfig.title || 'Site assistant',
			avatarUrl: explicitAvatar || String(chatConfig.botAvatarUrl || '').trim(),
		};
	};

	const buildAvatarNode = ({ name, avatarUrl }) => {
		if (avatarUrl) {
			const image = document.createElement('img');
			image.className = 'ace-ai-chat-avatar';
			image.src = avatarUrl;
			image.alt = name || '';
			image.loading = 'lazy';
			return image;
		}

		const fallback = document.createElement('span');
		fallback.className = 'ace-ai-chat-avatar-placeholder';
		fallback.textContent = String(name || '').trim().charAt(0).toUpperCase() || '•';
		return fallback;
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
			state.conversationStatus = String(saved.conversationStatus || 'open');
			state.handoverEnabled = !!saved.handoverEnabled;
			state.endedAt = String(saved.endedAt || '');
			state.followUpRequested = !!saved.followUpRequested;
			state.messages = Array.isArray(saved.messages) ? saved.messages.map(serialiseMessage) : [];
			state.syncBackoffUntil = 0;

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

	const scrollLatestMessageIntoView = (node) => {
		if (!(node instanceof HTMLElement)) {
			return;
		}

		window.requestAnimationFrame(() => {
			node.scrollIntoView({ block: 'start', inline: 'nearest' });
		});
	};

	const getDefaultMetaText = () => (chatConfig.showSources
		? 'Replies can include links to relevant company and product information.'
		: 'Ask about the company, products, or services.');

	const updateAvailabilityUi = () => {
		status.dataset.online = state.availabilityOnline ? 'true' : 'false';
		status.textContent = state.availabilityOnline
			? `Team online now${state.availabilityWatcherCount > 1 ? ` (${state.availabilityWatcherCount})` : ''}`
			: 'No agent watching chats just now';
		contactToggle.hidden = state.availabilityOnline || state.conversationStatus === 'ended' || state.followUpRequested;
		contactSubmit.disabled = state.pending || state.availabilityOnline || state.conversationStatus === 'ended';
		if (state.availabilityOnline || state.conversationStatus === 'ended' || state.followUpRequested) {
			contactPanel.hidden = true;
		}
		if (state.followUpRequested) {
			contactPanel.hidden = true;
		}
	};

	const updateChatMeta = () => {
		if (state.conversationStatus === 'ended') {
			meta.textContent = 'This chat has ended. Open the chat again to start a new conversation.';
		} else if (state.handoverEnabled) {
			meta.textContent = 'A member of the team can reply here while this chat is in handover.';
		} else if (state.followUpRequested) {
			meta.textContent = 'Thanks, your details have been saved and the team can follow up with you.';
		} else if (!state.availabilityOnline) {
			meta.textContent = 'Nobody is watching live chats just now. You can still chat here or leave your details for a follow-up.';
		} else {
			meta.textContent = getDefaultMetaText();
		}

		endChat.disabled = !state.started || state.pending || state.conversationStatus === 'ended';
		input.disabled = state.pending || state.conversationStatus === 'ended';
		send.disabled = state.pending || state.conversationStatus === 'ended';
		updateAvailabilityUi();
	};

	const applyConversationSnapshot = (snapshot) => {
		const conversation = snapshot?.conversation || {};
		const messages = Array.isArray(snapshot?.messages) ? snapshot.messages.map(serialiseMessage) : null;
		const typing = snapshot?.typing || conversation?.typing || {};

		if (conversation && typeof conversation === 'object') {
			state.conversationUuid = String(conversation.conversation_uuid || state.conversationUuid || '');
			state.conversationStatus = String(conversation.status || state.conversationStatus || 'open');
			state.handoverEnabled = !!conversation.handover_enabled;
			state.endedAt = String(conversation.ended_at || '');
			state.followUpRequested = !!conversation.follow_up_requested;
			state.started = !!state.conversationUuid || !!state.started;

			if (!contactName.value.trim() && conversation.contact_name) {
				contactName.value = String(conversation.contact_name || '');
			}

			if (!contactEmail.value.trim() && conversation.contact_email) {
				contactEmail.value = String(conversation.contact_email || '');
			}

			if (!contactPhone.value.trim() && conversation.contact_phone) {
				contactPhone.value = String(conversation.contact_phone || '');
			}

			if (!contactCompany.value.trim() && conversation.contact_company) {
				contactCompany.value = String(conversation.contact_company || '');
			}

			if (!contactRole.value.trim() && conversation.contact_role) {
				contactRole.value = String(conversation.contact_role || '');
			}
		}

		if (messages) {
			state.messages = messages.map((message, index) => {
				const previous = state.messages[index];

				if (
					previous
					&& previous.role === message.role
					&& previous.content === message.content
					&& previous.created_at === message.created_at
					&& previous.client_key
				) {
					return {
						...message,
						client_key: previous.client_key,
					};
				}

				return serialiseMessage(message);
			});
		}

		state.typing = (typing && typeof typing === 'object') ? typing : {};
		state.syncBackoffUntil = 0;

		persistChatState();
		updateChatMeta();
	};

	const resetConversationState = ({ keepOpen = false } = {}) => {
		if (typingResetTimer) {
			window.clearTimeout(typingResetTimer);
			typingResetTimer = null;
		}

		typingSentState = false;
		typingLastSentAt = 0;
		state.open = keepOpen;
		state.started = false;
		state.pending = false;
		state.conversationUuid = '';
		state.conversationStatus = 'open';
		state.handoverEnabled = false;
		state.endedAt = '';
		state.followUpRequested = false;
		state.messages = [];
		state.typing = {};
		state.syncBackoffUntil = 0;
		send.textContent = 'Send';
		panel.hidden = !keepOpen;
		launcher.setAttribute('aria-expanded', keepOpen ? 'true' : 'false');
		pushMessage({
			role: 'assistant',
			content: chatConfig.greeting || `Hello, I am ${chatConfig.botName || chatConfig.title || 'the site assistant'}. Ask me about the company, products, or services and I will do my best to help.`,
			sources: [],
		});
		updateChatMeta();
		renderMessages({ force: true, focusLatest: true });
	};

	const beginFreshConversation = ({ keepOpen = true, focusInput = false } = {}) => {
		resetConversationState({ keepOpen });
		state.started = true;
		state.conversationUuid = generateConversationUuid();
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
		persistChatState();
		startSync();
		startAvailabilityPolling();
		if (focusInput) {
			input.focus();
			updateInputHeight();
		}
	};

	const renderStarterQuestions = () => {
		const questions = Array.isArray(chatConfig.starterQuestions)
			? chatConfig.starterQuestions.map((question) => String(question || '').trim()).filter(Boolean).slice(0, 3)
			: [];
		const hasUserMessages = state.messages.some((message) => message?.role === 'user');

		starterQuestions.innerHTML = '';

		if (!questions.length || hasUserMessages || state.conversationStatus === 'ended') {
			starterQuestions.hidden = true;
			return;
		}

		const label = document.createElement('p');
		const list = document.createElement('div');
		label.className = 'ace-ai-chat-starters-label';
		label.textContent = 'Popular questions';
		list.className = 'ace-ai-chat-starters-list';

		questions.forEach((question) => {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'ace-ai-chat-starter';
			button.disabled = state.pending;
			button.textContent = question;
			button.addEventListener('click', () => {
				if (!state.pending) {
					sendMessage(question).catch(() => {});
				}
			});
			list.appendChild(button);
		});

		starterQuestions.appendChild(label);
		starterQuestions.appendChild(list);
		starterQuestions.hidden = false;
	};

	const fetchAvailability = async () => {
		const headers = {};

		if (chatConfig.restNonce) {
			headers['X-WP-Nonce'] = chatConfig.restNonce;
		}

		const data = await fetchJson(chatConfig.availabilityEndpoint || `${config.root}${config.namespace}/ai/chat/availability`, {
			method: 'GET',
			credentials: 'same-origin',
			headers,
		}, 'Live chat availability could not be loaded just now.');
		state.availabilityOnline = !!data?.online;
		state.availabilityWatcherCount = Number(data?.watcher_count || 0);
		updateChatMeta();
	};

	const normaliseSourceTitle = (title) => String(title || '').trim().toLowerCase();

	const normaliseTelHref = (value) => {
		const raw = String(value || '').trim();

		if (!raw) {
			return '';
		}

		const withoutPrefix = raw.replace(/^tel:/i, '').trim();
		const cleaned = withoutPrefix.replace(/[^+\d]/g, '');

		if (!cleaned) {
			return '';
		}

		return `tel:${cleaned}`;
	};

	const getTextMatches = (content, sources) => {
		const matches = [];
		const linkableSources = Array.isArray(sources)
			? sources
				.filter((source) => source?.url && source?.title)
				.sort((left, right) => String(right.title || '').length - String(left.title || '').length)
			: [];

		linkableSources.forEach((source) => {
			const title = String(source.title || '');
			const titleLower = normaliseSourceTitle(title);

			if (!titleLower) {
				return;
			}

			let searchFrom = 0;
			const contentLower = content.toLowerCase();

			while (searchFrom < content.length) {
				const index = contentLower.indexOf(titleLower, searchFrom);

				if (index === -1) {
					break;
				}

				matches.push({
					index,
					length: title.length,
					href: source.url,
					text: content.slice(index, index + title.length),
					external: true,
				});
				searchFrom = index + title.length;
			}
		});

		Array.from(content.matchAll(/\btel:\+?[0-9][0-9()\s.-]{5,}\b/gi)).forEach((match) => {
			const value = String(match[0] || '');
			const index = Number(match.index || 0);
			const href = normaliseTelHref(value);

			if (!href) {
				return;
			}

			matches.push({
				index,
				length: value.length,
				href,
				text: value.replace(/^tel:/i, ''),
				external: false,
			});
		});

		Array.from(content.matchAll(/(^|[^\w])(\+?\d[\d\s().-]{7,}\d)\b/g)).forEach((match) => {
			const value = String(match[2] || '');
			const leading = String(match[1] || '');
			const index = Number(match.index || 0) + leading.length;
			const href = normaliseTelHref(value);

			if (!href) {
				return;
			}

			matches.push({
				index,
				length: value.length,
				href,
				text: value,
				external: false,
			});
		});

		matches.sort((left, right) => {
			if (left.index === right.index) {
				return right.length - left.length;
			}

			return left.index - right.index;
		});

		return matches;
	};

	const appendLinkedText = (container, text, sources) => {
		const content = String(text || '');
		const matches = getTextMatches(content, sources);

		if (!content || !matches.length) {
			container.appendChild(document.createTextNode(content));
			return;
		}

		let cursor = 0;

		matches.forEach((match) => {
			if (match.index < cursor) {
				return;
			}

			if (match.index > cursor) {
				container.appendChild(document.createTextNode(content.slice(cursor, match.index)));
			}

			const link = document.createElement('a');
			link.href = match.href;
			link.textContent = match.text;

			if (match.external) {
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
			}

			container.appendChild(link);
			cursor = match.index + match.length;
		});

		if (cursor < content.length) {
			container.appendChild(document.createTextNode(content.slice(cursor)));
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
		const typeLabel = getSourceTypeLabel(source);
		const price = String(source?.commerce?.price || '').trim();
		const variationCount = Number(source?.commerce?.variation_count || 0);

		if (typeLabel && String(source?.source_type || '') !== 'product') {
			meta.push(typeLabel);
		}

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

	const getWooAjaxUrl = () => {
		const template = window.wc_add_to_cart_params?.wc_ajax_url || window.woocommerce_params?.wc_ajax_url || `${window.location.origin}/?wc-ajax=%%endpoint%%`;
		return String(template).replace('%%endpoint%%', 'add_to_cart');
	};

	const getAddToCartRequestData = (source) => {
		const addToCartUrl = String(source?.commerce?.add_to_cart_url || '').trim();

		if (!addToCartUrl) {
			return null;
		}

		const parsed = new URL(addToCartUrl, window.location.origin);
		const params = parsed.searchParams;
		const productId = params.get('add-to-cart') || params.get('product_id') || params.get('variation_id') || '';

		if (!productId) {
			return null;
		}

		return {
			product_id: productId,
			quantity: params.get('quantity') || '1',
		};
	};

	const refreshCartFragments = (fragments) => {
		if (fragments && typeof fragments === 'object') {
			Object.entries(fragments).forEach(([selector, markup]) => {
				document.querySelectorAll(selector).forEach((node) => {
					node.outerHTML = markup;
				});
			});
		}

		if (window.jQuery) {
			window.jQuery(document.body).trigger('wc_fragment_refresh');
			window.jQuery(document.body).trigger('wc_fragments_loaded');
		}
	};

	const openMiniCartDrawer = () => {
		const trigger = document.querySelector('.wc-block-mini-cart__button');

		if (trigger instanceof HTMLElement) {
			window.setTimeout(() => trigger.click(), 120);
		}
	};

	const addSourceToCart = async (source, triggerButton) => {
		const data = getAddToCartRequestData(source);

		if (!data) {
			throw new Error('This item could not be added to the basket just now.');
		}

		if (triggerButton instanceof HTMLButtonElement) {
			triggerButton.disabled = true;
			triggerButton.textContent = 'Adding…';
		}

		try {
			const payload = new URLSearchParams(data);

			if (window.jQuery) {
				window.jQuery(document.body).trigger('adding_to_cart', [window.jQuery(triggerButton), data]);
			}

			const response = await fetch(getWooAjaxUrl(), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: payload.toString(),
			});
			const result = await response.json();

			if (!response.ok) {
				throw new Error(result?.message || 'This item could not be added to the basket just now.');
			}

			if (result?.error && result?.product_url) {
				throw new Error('This item needs to be selected on the product page.');
			}

			refreshCartFragments(result?.fragments);

			if (window.jQuery) {
				window.jQuery(document.body).trigger('added_to_cart', [result?.fragments || {}, result?.cart_hash || '', window.jQuery(triggerButton)]);
			}

			document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart', { bubbles: true }));
			openMiniCartDrawer();
		} finally {
			if (triggerButton instanceof HTMLButtonElement) {
				triggerButton.disabled = false;
				triggerButton.textContent = 'Add to basket';
			}
		}
	};

	const getSourceTypeLabel = (source) => {
		const contentType = String(source?.content_type || '').trim();

		if (contentType) {
			return contentType;
		}

		if (String(source?.label || '').includes(':')) {
			return String(source.label).split(':')[0].trim();
		}

		switch (String(source?.source_type || '').trim()) {
			case 'product':
				return 'Product';
			case 'document':
				return 'PDF document';
			case 'page':
				return 'Page';
			case 'post':
				return 'Article';
			default:
				return 'Content';
		}
	};

	const getSourceViewLabel = (source) => {
		if (source?.source_type === 'document') {
			return 'Open PDF';
		}

		if (source?.source_type === 'product') {
			return source?.commerce?.variation_count > 0 ? 'View options' : 'View product';
		}

		const typeLabel = getSourceTypeLabel(source).toLowerCase();

		if (typeLabel === 'post' || typeLabel === 'article' || typeLabel === 'blog post' || typeLabel === 'news') {
			return 'Read article';
		}

		return `View ${typeLabel || 'content'}`;
	};

	const getTypingIndicators = () => {
		const indicators = [];
		const typing = (state.typing && typeof state.typing === 'object') ? state.typing : {};
		const assistantName = String(chatConfig.botName || chatConfig.title || 'Assistant').trim() || 'Assistant';
		const agentTyping = typing.agent && state.conversationStatus !== 'ended';

		if (!agentTyping && state.pending && state.conversationStatus !== 'ended' && !state.handoverEnabled) {
			indicators.push({
				key: 'assistant-pending',
				name: assistantName,
				avatar: String(chatConfig.botAvatarUrl || '').trim(),
				text: 'is thinking…',
			});
		} else if (!agentTyping && typing.assistant) {
			const assistantStatus = String(typing.assistant.status || '').trim().toLowerCase() === 'typing'
				? 'is typing…'
				: 'is thinking…';
			indicators.push({
				key: 'assistant-live',
				name: String(typing.assistant.name || typing.assistant.label || assistantName).trim() || assistantName,
				avatar: String(chatConfig.botAvatarUrl || '').trim(),
				text: assistantStatus,
			});
		}

		if (agentTyping) {
			indicators.push({
				key: 'agent-live',
				name: String(typing.agent.name || typing.agent.label || 'Agent').trim() || 'Agent',
				avatar: '',
				text: 'is typing…',
			});
		}

		return indicators;
	};

	const buildTypingMessage = (indicator) => {
		const item = document.createElement('div');
		const body = document.createElement('div');
		const metaRow = document.createElement('div');
		const nameNode = document.createElement('span');
		const bubble = document.createElement('div');
		const dots = document.createElement('span');
		const text = document.createElement('span');
		const avatar = buildAvatarNode({
			name: indicator.name,
			avatarUrl: indicator.avatar,
		});

		item.className = 'ace-ai-chat-message';
		item.dataset.role = 'typing';
		body.className = 'ace-ai-chat-message-body';
		metaRow.className = 'ace-ai-chat-message-meta';
		nameNode.className = 'ace-ai-chat-message-name';
		nameNode.textContent = indicator.name;
		bubble.className = 'ace-ai-chat-bubble';
		dots.className = 'ace-ai-chat-typing-dots';
		dots.innerHTML = '<span></span><span></span><span></span>';
		text.className = 'ace-ai-chat-typing-text';
		text.textContent = indicator.text;
		metaRow.appendChild(avatar);
		metaRow.appendChild(nameNode);
		bubble.appendChild(dots);
		bubble.appendChild(text);
		body.appendChild(metaRow);
		body.appendChild(bubble);
		item.appendChild(body);

		return item;
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
			addButton.addEventListener('click', async () => {
				try {
					await addSourceToCart(source, addButton);
				} catch (error) {
					pushMessage({
						role: 'assistant',
						content: error?.message || 'This item could not be added to the basket just now.',
						sources: [],
					});
					renderMessages({ focusLatest: true });
				}
			});
			actionsNode.appendChild(addButton);
		}

		if (source?.commerce?.view_url || source?.url) {
			const viewButton = document.createElement('button');
			viewButton.type = 'button';
			viewButton.className = 'ace-ai-chat-source-action ace-ai-chat-source-action--secondary';
			viewButton.textContent = getSourceViewLabel(source);
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

	const buildMessageNode = (message) => {
			const item = document.createElement('div');
			const body = document.createElement('div');
			const metaRow = document.createElement('div');
			const nameNode = document.createElement('span');
			const bubble = document.createElement('div');
			const author = getMessageAuthor(message);
			const avatar = buildAvatarNode(author);

			item.className = 'ace-ai-chat-message';
			item.dataset.aceMessage = 'true';
			item.dataset.messageKey = String(message.client_key || message.id || '');
			item.dataset.role = message.role;
			body.className = 'ace-ai-chat-message-body';
			metaRow.className = 'ace-ai-chat-message-meta';
			nameNode.className = 'ace-ai-chat-message-name';
			nameNode.textContent = author.name;
			bubble.className = 'ace-ai-chat-bubble';
			renderBubbleContent(bubble, message);
			metaRow.appendChild(avatar);
			metaRow.appendChild(nameNode);
			body.appendChild(metaRow);
			body.appendChild(bubble);
			item.appendChild(body);

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
					body.appendChild(list);
				}
		}

		return item;
	};

	const renderTypingIndicators = () => {
		Array.from(messagesNode.querySelectorAll('[data-ace-typing="true"]')).forEach((node) => node.remove());

		getTypingIndicators().forEach((indicator) => {
			const typingNode = buildTypingMessage(indicator);
			typingNode.dataset.aceTyping = 'true';
			typingNode.dataset.typingKey = indicator.key;
			messagesNode.appendChild(typingNode);
		});
	};

	const updateVoiceUi = () => {
		if (voiceInputEnabled) {
			micButton.classList.toggle('is-listening', state.voiceListening || state.voiceTranscribing);
			micButton.disabled = state.pending || state.voiceTranscribing || state.conversationStatus === 'ended';
			micButton.setAttribute('aria-pressed', state.voiceListening ? 'true' : 'false');
		}

		if (voiceRepliesEnabled) {
			voiceToggle.classList.toggle('is-on', state.voiceSpeakerOn);
			voiceToggle.classList.toggle('is-speaking', state.voiceSpeaking);
			voiceToggle.textContent = state.voiceSpeakerOn ? '🔊' : '🔇';
			voiceToggle.setAttribute('aria-pressed', state.voiceSpeakerOn ? 'true' : 'false');
		}
	};

	const stopSpeaking = () => {
		try {
			// Only cancel when something is actually speaking/queued. Calling
			// cancel() on an idle synth produces an audible click in some browsers.
			if (voiceOutputSupported && ( window.speechSynthesis.speaking || window.speechSynthesis.pending )) {
				window.speechSynthesis.cancel();
			}
		} catch (error) {
			// Ignore speech synthesis failures.
		}

		if (currentAudio) {
			try {
				currentAudio.pause();
				currentAudio.src = '';
			} catch (error) {
				// Ignore audio teardown failures.
			}

			currentAudio = null;
		}

		state.voiceSpeaking = false;
	};

	const onSpeakStart = () => {
		state.voiceSpeaking = true;
		updateVoiceUi();
	};

	const onSpeakEnd = () => {
		state.voiceSpeaking = false;
		updateVoiceUi();

		if (voiceHandsFree && voiceInputEnabled && state.voiceSpeakerOn && state.open && !state.pending && state.conversationStatus !== 'ended') {
			startListening();
		}
	};

	const speakBrowser = (text) => {
		if (!voiceOutputSupported) {
			onSpeakEnd();
			return;
		}

		try {
			const utterance = new window.SpeechSynthesisUtterance(text);
			utterance.lang = voiceLang;
			utterance.onstart = onSpeakStart;
			utterance.onend = onSpeakEnd;
			utterance.onerror = onSpeakEnd;
			window.speechSynthesis.speak(utterance);
		} catch (error) {
			onSpeakEnd();
		}
	};

	const speakPremium = async (text) => {
		try {
			onSpeakStart();

			const headers = { 'Content-Type': 'application/json' };

			if (chatConfig.restNonce) {
				headers['X-WP-Nonce'] = chatConfig.restNonce;
			}

			const response = await fetch(chatConfig.voiceTtsEndpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers,
				body: JSON.stringify({ text }),
			});

			if (!response.ok) {
				throw new Error('tts-request-failed');
			}

			const data = await response.json();

			if (!data || !data.audio) {
				throw new Error('tts-empty');
			}

			stopSpeaking();
			currentAudio = new Audio(`data:${data.mime || 'audio/mpeg'};base64,${data.audio}`);
			state.voiceSpeaking = true;
			updateVoiceUi();
			currentAudio.onended = onSpeakEnd;
			currentAudio.onerror = onSpeakEnd;
			await currentAudio.play();
		} catch (error) {
			// Fall back to the browser voice if the premium provider fails.
			currentAudio = null;
			speakBrowser(text);
		}
	};

	const speakText = (text) => {
		const clean = String(text || '').replace(/\s+/g, ' ').trim();

		if (!clean || !voiceRepliesEnabled || !state.voiceSpeakerOn) {
			return;
		}

		stopSpeaking();

		if (premiumTtsEnabled) {
			speakPremium(clean);
		} else {
			speakBrowser(clean);
		}
	};

	const applyTranscript = (transcript) => {
		transcript = String(transcript || '').trim();

		if (!transcript) {
			return;
		}

		input.value = transcript;
		updateInputHeight();
		// Conversational: a spoken message is sent automatically when the visitor pauses.
		form.requestSubmit();
	};

	const blobToBase64 = (blob) => new Promise((resolve, reject) => {
		const reader = new FileReader();
		reader.onloadend = () => {
			const result = String(reader.result || '');
			resolve(result.slice(result.indexOf(',') + 1));
		};
		reader.onerror = reject;
		reader.readAsDataURL(blob);
	});

	const stopMediaStream = () => {
		if (mediaStream) {
			try {
				mediaStream.getTracks().forEach((track) => track.stop());
			} catch (error) {
				// Ignore.
			}

			mediaStream = null;
		}
	};

	// Pick a recording format the browser actually supports. Firefox produces
	// audio/ogg; Chrome audio/webm — being explicit keeps the blob well-formed.
	const pickRecorderMimeType = () => {
		const candidates = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm', 'audio/ogg', 'audio/mp4'];

		if (window.MediaRecorder && typeof window.MediaRecorder.isTypeSupported === 'function') {
			return candidates.find((type) => {
				try {
					return window.MediaRecorder.isTypeSupported(type);
				} catch (error) {
					return false;
				}
			}) || '';
		}

		return '';
	};

	let voiceNoticeTimer = null;

	// Surface a transient hint in the composer so voice failures are never silent.
	const flashVoiceNotice = (message) => {
		if (!input) {
			return;
		}

		const original = input.dataset.acePlaceholder || input.getAttribute('placeholder') || '';
		input.dataset.acePlaceholder = original;
		input.setAttribute('placeholder', message);

		if (voiceNoticeTimer) {
			window.clearTimeout(voiceNoticeTimer);
		}

		voiceNoticeTimer = window.setTimeout(() => {
			input.setAttribute('placeholder', input.dataset.acePlaceholder || '');
			voiceNoticeTimer = null;
		}, 3500);
	};

	// Premium path: record audio (works in any browser, incl. Firefox) and transcribe server-side.
	const startRecording = async () => {
		try {
			mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
			mediaChunks = [];
			const recorderMimeType = pickRecorderMimeType();
			mediaRecorder = recorderMimeType
				? new window.MediaRecorder(mediaStream, { mimeType: recorderMimeType })
				: new window.MediaRecorder(mediaStream);
			mediaRecorder.ondataavailable = (event) => {
				if (event.data && event.data.size) {
					mediaChunks.push(event.data);
				}
			};
			mediaRecorder.onstop = async () => {
				stopVad();
				stopMediaStream();
				state.voiceListening = false;

				if (!mediaChunks.length) {
					flashVoiceNotice('Sorry, I didn’t catch that — please try again.');
					updateVoiceUi();
					return;
				}

				const mime = (mediaRecorder && mediaRecorder.mimeType) || 'audio/webm';
				const blob = new Blob(mediaChunks, { type: mime });
				mediaChunks = [];
				state.voiceTranscribing = true;
				updateVoiceUi();

				try {
					const audio = await blobToBase64(blob);
					const headers = { 'Content-Type': 'application/json' };

					if (chatConfig.restNonce) {
						headers['X-WP-Nonce'] = chatConfig.restNonce;
					}

					const data = await fetchJson(chatConfig.voiceTranscribeEndpoint, {
						method: 'POST',
						credentials: 'same-origin',
						headers,
						body: JSON.stringify({ audio, mime }),
					}, 'The recording could not be transcribed just now.');

					const transcript = String((data && data.text) || '').trim();

					if (transcript) {
						applyTranscript(transcript);
					} else {
						flashVoiceNotice('Sorry, I didn’t catch that — please try again.');
					}
				} catch (error) {
					flashVoiceNotice('Sorry, I couldn’t hear that — please try again.');
				} finally {
					state.voiceTranscribing = false;
					updateVoiceUi();
				}
			};
			// Timeslice so dataavailable fires during recording — Firefox can
			// otherwise deliver nothing when stop() races the final chunk.
			mediaRecorder.start(250);
			state.voiceListening = true;
			updateVoiceUi();
			startVad();
		} catch (error) {
			stopVad();
			stopMediaStream();
			state.voiceListening = false;
			updateVoiceUi();
		}
	};

	// Voice-activity detection: auto-stop recording shortly after the visitor
	// stops talking, so a brief pause sends the message (conversational flow).
	const startVad = () => {
		try {
			const AudioCtx = window.AudioContext || window.webkitAudioContext;

			if (!AudioCtx || !mediaStream) {
				return;
			}

			vadContext = new AudioCtx();

			if (vadContext.state === 'suspended' && typeof vadContext.resume === 'function') {
				vadContext.resume();
			}

			// Analyse a cloned track so the recorder keeps sole ownership of the
			// original stream — Firefox can drop recorder data when a stream is
			// consumed by both MediaRecorder and Web Audio at once.
			const audioTrack = mediaStream.getAudioTracks ? mediaStream.getAudioTracks()[0] : null;

			if (audioTrack && typeof audioTrack.clone === 'function') {
				vadStream = new MediaStream([audioTrack.clone()]);
			} else {
				vadStream = mediaStream;
			}

			const source = vadContext.createMediaStreamSource(vadStream);
			const analyser = vadContext.createAnalyser();
			analyser.fftSize = 512;
			source.connect(analyser);

			const samples = new Uint8Array(analyser.frequencyBinCount);
			const started = Date.now();
			let spoke = false;
			let lastVoice = Date.now();
			const SILENCE_MS = 1400;
			const MAX_MS = 20000;
			const NO_SPEECH_MS = 6000;

			const tick = () => {
				if (!vadContext || !state.voiceListening) {
					return;
				}

				analyser.getByteTimeDomainData(samples);
				let sum = 0;

				for (let i = 0; i < samples.length; i += 1) {
					const v = (samples[i] - 128) / 128;
					sum += v * v;
				}

				const rms = Math.sqrt(sum / samples.length);
				const now = Date.now();

				if (rms > 0.045) {
					spoke = true;
					lastVoice = now;
				}

				if ((spoke && now - lastVoice > SILENCE_MS) || now - started > MAX_MS || (!spoke && now - started > NO_SPEECH_MS)) {
					stopListening();
					return;
				}

				vadRaf = window.requestAnimationFrame(tick);
			};

			vadRaf = window.requestAnimationFrame(tick);
		} catch (error) {
			// VAD unavailable — the visitor can still tap the mic again to stop.
		}
	};

	const stopVad = () => {
		if (vadRaf) {
			window.cancelAnimationFrame(vadRaf);
			vadRaf = null;
		}

		if (vadContext) {
			try {
				vadContext.close();
			} catch (error) {
				// Ignore.
			}

			vadContext = null;
		}

		if (vadStream && vadStream !== mediaStream) {
			try {
				vadStream.getTracks().forEach((track) => track.stop());
			} catch (error) {
				// Ignore.
			}
		}

		vadStream = null;
	};

	// Browser path: native Web Speech recognition (Chrome/Edge/Safari).
	const startBrowserRecognition = () => {
		try {
			speechRecognition = new SpeechRecognitionImpl();
			speechRecognition.lang = voiceLang;
			speechRecognition.interimResults = false;
			speechRecognition.maxAlternatives = 1;
			speechRecognition.onstart = () => {
				state.voiceListening = true;
				updateVoiceUi();
			};
			speechRecognition.onerror = () => {
				state.voiceListening = false;
				updateVoiceUi();
			};
			speechRecognition.onend = () => {
				state.voiceListening = false;
				updateVoiceUi();
			};
			speechRecognition.onresult = (event) => {
				applyTranscript(
					Array.from(event.results || [])
						.map((result) => (result[0] && result[0].transcript) || '')
						.join(' ')
				);
			};
			speechRecognition.start();
		} catch (error) {
			state.voiceListening = false;
			updateVoiceUi();
		}
	};

	const startListening = () => {
		if (!voiceInputEnabled || state.voiceListening || state.voiceTranscribing || state.pending || state.conversationStatus === 'ended') {
			return;
		}

		stopSpeaking();

		if (voiceSttPremium) {
			startRecording();
		} else if (SpeechRecognitionImpl) {
			startBrowserRecognition();
		}
	};

	const stopListening = () => {
		if (voiceSttPremium) {
			try {
				if (mediaRecorder && mediaRecorder.state !== 'inactive') {
					mediaRecorder.stop();
				}
			} catch (error) {
				// Ignore.
			}

			return;
		}

		try {
			if (speechRecognition) {
				speechRecognition.stop();
			}
		} catch (error) {
			// Ignore.
		}

		state.voiceListening = false;
		updateVoiceUi();
	};

	const maybeSpeakLatest = () => {
		if (!voiceRepliesEnabled) {
			return;
		}

		const speakable = [...state.messages].reverse().find((message) => (message.role === 'assistant' || message.role === 'operator') && String(message.content || '').trim() && !message.is_error);
		const key = speakable ? String(speakable.client_key || speakable.id || '') : '';

		// On the first render after restore, prime the marker so existing history is not read aloud.
		if (!voiceRenderPrimed) {
			voiceRenderPrimed = true;
			lastSpokenKey = key;
			return;
		}

		if (!key || key === lastSpokenKey) {
			return;
		}

		lastSpokenKey = key;

		if (state.voiceSpeakerOn && state.open) {
			speakText(speakable.content);
		}
	};

	const renderMessages = ({ force = false, focusLatest = false } = {}) => {
		const existingNodes = Array.from(messagesNode.querySelectorAll('[data-ace-message="true"]'));
		const existingKeys = existingNodes.map((node) => node.dataset.messageKey || '');
		const desiredKeys = state.messages.map((message) => String(message.client_key || message.id || ''));
		const canAppendOnly = !force
			&& existingKeys.length <= desiredKeys.length
			&& existingKeys.every((key, index) => key === desiredKeys[index]);
		let latestNode = null;

		if (!canAppendOnly) {
			messagesNode.innerHTML = '';
			state.messages.forEach((message) => {
				const node = buildMessageNode(message);
				messagesNode.appendChild(node);
				latestNode = node;
			});
		} else {
			state.messages.slice(existingKeys.length).forEach((message) => {
				const node = buildMessageNode(message);
				messagesNode.appendChild(node);
				latestNode = node;
			});
		}

		renderTypingIndicators();
		renderStarterQuestions();

		if (focusLatest && latestNode) {
			scrollLatestMessageIntoView(latestNode);
		}

		updateVoiceUi();
		maybeSpeakLatest();
	};

	restoreChatState();

	const syncConversation = async () => {
		if (!state.conversationUuid || state.pending || Date.now() < Number(state.syncBackoffUntil || 0)) {
			return;
		}

		const headers = {};

		if (chatConfig.restNonce) {
			headers['X-WP-Nonce'] = chatConfig.restNonce;
		}

		const params = new URLSearchParams({
			conversation_uuid: state.conversationUuid,
			session_uuid: sessionUuid || '',
			visitor_uuid: visitorUuid || '',
		});
		try {
			const snapshot = await fetchJson(`${chatConfig.syncEndpoint || `${config.root}${config.namespace}/ai/chat/conversation`}?${params.toString()}`, {
				method: 'GET',
				credentials: 'same-origin',
				headers,
			}, 'The chat conversation could not be loaded.');
			applyConversationSnapshot(snapshot);
			renderMessages({ focusLatest: true });
		} catch (error) {
			if (Number(error?.status || 0) === 404) {
				queueTypingState(false, { force: true });
				stopSync();
				resetConversationState({ keepOpen: state.open });
				persistChatState();
				return;
			}

			if (Number(error?.status || 0) === 429) {
				const retryAfter = Math.max(15, Number(error?.data?.data?.retry_after || 30));
				state.syncBackoffUntil = Date.now() + (retryAfter * 1000);
			}
		}
	};

	const stopSync = () => {
		if (syncTimer) {
			window.clearInterval(syncTimer);
			syncTimer = null;
		}
	};

	const stopAvailabilityPolling = () => {
		if (availabilityTimer) {
			window.clearInterval(availabilityTimer);
			availabilityTimer = null;
		}
	};

	const postTypingState = async (isTyping) => {
		if (!state.conversationUuid || state.conversationStatus === 'ended' || !state.messages.some((message) => Number(message?.id || 0) > 0)) {
			return;
		}

		const headers = {
			'Content-Type': 'application/json',
		};

		if (chatConfig.restNonce) {
			headers['X-WP-Nonce'] = chatConfig.restNonce;
		}

		const data = await fetchJson(chatConfig.typingEndpoint || `${config.root}${config.namespace}/ai/chat/typing`, {
			method: 'POST',
			credentials: 'same-origin',
			headers,
			body: JSON.stringify({
				conversation_uuid: state.conversationUuid || '',
				session_uuid: sessionUuid || '',
				visitor_uuid: visitorUuid || '',
				is_typing: !!isTyping,
			}),
		}, 'The typing state could not be updated just now.');
		state.typing = (data?.typing && typeof data.typing === 'object') ? data.typing : (state.typing || {});
		typingSentState = !!isTyping;
		typingLastSentAt = Date.now();
		renderMessages();
	};

	const queueTypingState = (isTyping, { force = false } = {}) => {
		if (typingResetTimer) {
			window.clearTimeout(typingResetTimer);
			typingResetTimer = null;
		}

		if (!isTyping) {
			if (typingSentState || force) {
				postTypingState(false).catch(() => {});
			}
			return;
		}

		if (state.conversationStatus === 'ended') {
			return;
		}

		if (!typingSentState || force || (Date.now() - typingLastSentAt) > 4000) {
			postTypingState(true).catch(() => {});
		}

		typingResetTimer = window.setTimeout(() => {
			queueTypingState(false, { force: true });
		}, 2200);
	};

	const startSync = () => {
		stopSync();

		if (!state.started || state.conversationStatus === 'ended') {
			return;
		}

		syncTimer = window.setInterval(() => {
			if (state.open) {
				syncConversation().catch(() => {});
			}
		}, Number(chatConfig.pollIntervalMs || 5000));
	};

	const startAvailabilityPolling = () => {
		stopAvailabilityPolling();

		if (!state.open) {
			return;
		}

		fetchAvailability().catch(() => {});
		availabilityTimer = window.setInterval(() => {
			if (state.open) {
				fetchAvailability().catch(() => {});
			}
		}, Number(chatConfig.availabilityPollIntervalMs || 15000));
	};

	const endCurrentConversation = async () => {
		if (!state.started || !state.conversationUuid || state.pending) {
			resetConversationState({ keepOpen: false });
			return;
		}

		try {
			const headers = {
				'Content-Type': 'application/json',
			};

			if (chatConfig.restNonce) {
				headers['X-WP-Nonce'] = chatConfig.restNonce;
			}

			await fetch(chatConfig.endEndpoint || `${config.root}${config.namespace}/ai/chat/end`, {
				method: 'POST',
				credentials: 'same-origin',
				headers,
				body: JSON.stringify({
					conversation_uuid: state.conversationUuid,
					session_uuid: sessionUuid || '',
					visitor_uuid: visitorUuid || '',
				}),
			});
		} catch (error) {
			// Ignore end-chat transport failures and reset the local state anyway.
		}

		queueTypingState(false, { force: true });
		stopSync();
		stopAvailabilityPolling();
		resetConversationState({ keepOpen: false });
	};

	const setOpen = (nextOpen) => {
		state.open = nextOpen;
		panel.hidden = !nextOpen;
		launcher.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
		persistChatState();
		updateChatMeta();

		if (nextOpen && (state.conversationStatus === 'ended' || state.endedAt)) {
			beginFreshConversation({ keepOpen: true, focusInput: true });
		} else if (nextOpen && !state.started) {
			beginFreshConversation({ keepOpen: true, focusInput: true });
		} else if (nextOpen) {
			updateInputHeight();
			renderMessages({ force: true, focusLatest: true });
			syncConversation().catch(() => {});
			startSync();
			startAvailabilityPolling();
		} else {
			stopSync();
			stopAvailabilityPolling();
			stopSpeaking();
			stopListening();
		}
	};

	const setPending = (nextPending) => {
		state.pending = nextPending;
		send.textContent = nextPending ? 'Sending…' : 'Send';
		updateInputHeight();
		updateChatMeta();
		updateVoiceUi();
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

		queueTypingState(false, { force: true });
		pushMessage({ role: 'user', content });
		renderMessages({ focusLatest: true });
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

			const data = await fetchJson(chatConfig.endpoint || `${config.root}${config.namespace}/ai/chat/respond`, {
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
			}, 'The site assistant could not reply just now.');

			if (data?.conversation || Array.isArray(data?.messages)) {
				applyConversationSnapshot(data);
			}

			if (!Array.isArray(data?.messages) && data?.message) {
				pushMessage({
					role: 'assistant',
					content: data?.message || 'Sorry, I could not prepare a reply just now.',
					sources: Array.isArray(data?.sources) ? data.sources : [],
				});
			}
		} catch (error) {
			pushMessage({
				role: 'assistant',
				content: error?.message || 'Sorry, I could not prepare a reply just now.',
				sources: [],
			});
		} finally {
			setPending(false);
			renderMessages({ focusLatest: true });
			persistChatState();
		}
	};

	const submitFollowUpRequest = async () => {
		const name = contactName.value.trim();
		const email = contactEmail.value.trim();
		const phone = contactPhone.value.trim();
		const company = contactCompany.value.trim();
		const role = contactRole.value.trim();

		if (state.pending || state.conversationStatus === 'ended') {
			return;
		}

		setPending(true);

		try {
			const headers = {
				'Content-Type': 'application/json',
			};

			if (chatConfig.restNonce) {
				headers['X-WP-Nonce'] = chatConfig.restNonce;
			}

			const data = await fetchJson(chatConfig.contactEndpoint || `${config.root}${config.namespace}/ai/chat/contact`, {
				method: 'POST',
				credentials: 'same-origin',
				headers,
				body: JSON.stringify({
					conversation_uuid: state.conversationUuid || '',
					session_uuid: sessionUuid || '',
					visitor_uuid: visitorUuid || '',
					page_url: window.location.href,
					page_title: document.title || '',
					contact_name: name,
					contact_email: email,
					contact_phone: phone,
					contact_company: company,
					contact_role: role,
				}),
			}, 'Your follow-up request could not be saved just now.');

			if (!state.started) {
				state.started = true;
			}

			if (data?.conversation || Array.isArray(data?.messages)) {
				applyConversationSnapshot(data);
			}

			pushMessage({
				role: 'assistant',
				content: 'Thanks. I have passed your details to the team and marked this chat for follow-up.',
				sources: [],
			});
			contactPanel.hidden = true;
			contactName.value = '';
			contactEmail.value = '';
			contactPhone.value = '';
			contactCompany.value = '';
			contactRole.value = '';
		} catch (error) {
			pushMessage({
				role: 'assistant',
				content: error?.message || 'Your follow-up request could not be saved just now.',
				sources: [],
			});
		} finally {
			setPending(false);
			renderMessages({ focusLatest: true });
			persistChatState();
		}
	};

	if (!state.messages.length) {
		resetConversationState({ keepOpen: false });
	}
	updateChatMeta();
	renderMessages({ force: true });
	updateInputHeight();
	fetchAvailability().catch(() => {});
	if (state.open) {
		setOpen(true);
	}

	launcher.addEventListener('click', () => setOpen(!state.open));
	close.addEventListener('click', () => setOpen(false));
	contactToggle.addEventListener('click', () => {
		contactPanel.hidden = !contactPanel.hidden;
		if (!contactPanel.hidden) {
			contactName.focus();
		}
	});
	contactSubmit.addEventListener('click', () => {
		submitFollowUpRequest();
	});
	endChat.addEventListener('click', () => {
		endCurrentConversation().catch(() => {});
	});
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
	input.addEventListener('input', () => {
		if (input.value.trim()) {
			queueTypingState(true);
		} else {
			queueTypingState(false, { force: true });
		}
	});
	input.addEventListener('blur', () => {
		queueTypingState(false, { force: true });
	});
	input.addEventListener('keydown', (event) => {
		if (event.key === 'Enter' && !event.shiftKey) {
			event.preventDefault();
			form.requestSubmit();
		}
	});

	if (voiceInputEnabled) {
		micButton.addEventListener('click', () => {
			if (state.voiceListening) {
				stopListening();
			} else {
				startListening();
			}
		});
	}

	if (voiceRepliesEnabled) {
		voiceToggle.addEventListener('click', () => {
			state.voiceSpeakerOn = !state.voiceSpeakerOn;

			try {
				window.localStorage.setItem(`ace_ai_voice_speaker:${visitorUuid || 'guest'}`, state.voiceSpeakerOn ? '1' : '0');
			} catch (error) {
				// Ignore storage failures.
			}

			if (!state.voiceSpeakerOn) {
				stopSpeaking();
			}

			updateVoiceUi();
		});
	}

	updateVoiceUi();
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
