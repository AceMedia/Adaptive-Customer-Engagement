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
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}
