import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, Notice, SelectControl, Spinner, TextControl, ToggleControl } from '@wordpress/components';
import { createElement, Fragment, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { render } from '@wordpress/element';

const config = window.ACEAdminConfig || {};
const SESSION_FILTER_DEFAULTS = {
	search: '',
	confidence: '',
	source: '',
	date_from: '',
	date_to: '',
};
const COMPANY_FILTER_DEFAULTS = {
	search: '',
	confidence: '',
	provider: '',
	date_from: '',
	date_to: '',
};
const COMMERCE_FILTER_DEFAULTS = {
	search: '',
	date_from: '',
	date_to: '',
	repeat_only: '1',
};
const CALL_FILTER_DEFAULTS = {
	search: '',
	status: '',
	date_from: '',
	date_to: '',
	match_only: '',
};

function request(route, options = {}) {
	return apiFetch({
		url: `${config.root}${config.namespace}${route}`,
		headers: {
			'X-WP-Nonce': config.nonce,
		},
		...options,
	});
}

function withQuery(route, params = {}) {
	const search = new URLSearchParams();

	Object.entries(params).forEach(([key, value]) => {
		if (value !== undefined && value !== null && value !== '') {
			search.set(key, value);
		}
	});

	const query = search.toString();

	return query ? `${route}?${query}` : route;
}

function normaliseFilters(defaults, filters = {}) {
	return {
		...defaults,
		...(filters || {}),
	};
}

function getQueryParam(key) {
	return new URLSearchParams(window.location.search).get(key) || '';
}

function clearQueryParam(key) {
	const url = new URL(window.location.href);
	url.searchParams.delete(key);
	window.history.replaceState({}, '', url.toString());
}

function getAdminPageUrl(page, params = {}) {
	const base = config.adminUrl || 'admin.php';
	const url = new URL(base, window.location.origin);

	url.searchParams.set('page', page === 'dashboard' ? 'ace-dashboard' : `ace-${page}`);

	Object.entries(params).forEach(([key, value]) => {
		if (value !== undefined && value !== null && value !== '') {
			url.searchParams.set(key, value);
		}
	});

	return url.toString();
}

function getExportUrl(action, params = {}) {
	const base = config.adminPostUrl || config.adminUrl || 'admin-post.php';
	const url = new URL(base, window.location.origin);

	url.searchParams.set('action', action);
	url.searchParams.set('_wpnonce', config.exportNonce || '');

	Object.entries(params).forEach(([key, value]) => {
		if (value !== undefined && value !== null && value !== '') {
			url.searchParams.set(key, value);
		}
	});

	return url.toString();
}

function SessionsTable({ items, onView }) {
	if (!items.length) {
		return createElement(Notice, { status: 'info', isDismissible: false }, __('No sessions recorded yet.', 'adaptive-customer-engagement'));
	}

	return createElement(
		'table',
		{ className: 'widefat striped' },
		createElement(
			'thead',
			null,
			createElement(
				'tr',
				null,
				['Session', 'Landing page', 'Source', 'Campaign', 'Events', 'Call clicks', 'Score', 'Why it scored', 'Last seen', 'Actions'].map((label) =>
					createElement('th', { key: label }, __(label, 'adaptive-customer-engagement'))
				)
			)
		),
		createElement(
			'tbody',
			null,
			items.map((item) =>
				createElement(
					'tr',
					{ key: item.id },
					createElement('td', null, item.session_uuid),
					createElement('td', null, item.landing_path || '—'),
					createElement('td', null, item.utm_source || '—'),
					createElement('td', null, item.utm_campaign || '—'),
					createElement('td', null, item.event_count),
					createElement('td', null, item.call_clicks),
					createElement('td', null, `${item.score || 0} (${item.score_label || 'noise'})`),
					createElement('td', null, item.score_summary || '—'),
					createElement('td', null, item.last_seen),
					createElement(
						'td',
						null,
						createElement(Button, { variant: 'secondary', onClick: () => onView && onView(item.id) }, __('View', 'adaptive-customer-engagement'))
					)
				)
			)
		)
	);
}

function DashboardView() {
	const [data, setData] = useState(null);
	const [selectedSession, setSelectedSession] = useState(null);
	const [selectedCompany, setSelectedCompany] = useState(null);

	useEffect(() => {
		request('/admin/dashboard').then(setData);
	}, []);

	if (!data) {
		return createElement(Spinner);
	}

	const cards = [
		['Sessions today', data.metrics.sessions_today],
		['Returning sessions', data.metrics.returning_sessions],
		['Click-to-call events', data.metrics.click_to_call_events],
		['Download events', data.metrics.download_events],
		['Form submissions', data.metrics.form_submissions],
		['Ignored traffic', data.metrics.ignored_traffic],
	];

	return createElement(
		Fragment,
		null,
		createElement(
			'div',
			{ style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: '12px', marginBottom: '16px' } },
			cards.map(([label, value]) =>
				createElement(
					Card,
					{ key: label },
					createElement(CardBody, null, createElement('strong', null, __(label, 'adaptive-customer-engagement')), createElement('div', { style: { fontSize: '24px', marginTop: '8px' } }, value))
				)
			)
		),
		createElement('h2', null, __('Top pages', 'adaptive-customer-engagement')),
		createElement(
			'table',
			{ className: 'widefat striped', style: { marginBottom: '20px' } },
			createElement(
				'tbody',
				null,
				(data.top_pages || []).map((page) =>
					createElement('tr', { key: page.path }, createElement('td', null, page.path || '/'), createElement('td', null, page.total))
				)
			)
		),
		createElement(DashboardSegmentsPanel, { shortcuts: data.segment_shortcuts || {} }),
		createElement('h2', null, __('Recent sessions', 'adaptive-customer-engagement')),
		createElement(SessionsTable, {
			items: data.recent_sessions || [],
			onView: async (id) => {
				const detail = await request(`/admin/sessions/${id}`);
				setSelectedSession(detail);
			},
		}),
		createElement('h2', { style: { marginTop: '20px' } }, __('Hot companies', 'adaptive-customer-engagement')),
		createElement(CompaniesTable, {
			items: data.hot_companies || [],
			onView: async (id) => {
				const detail = await request(`/admin/companies/${id}`);
				setSelectedCompany(detail);
			},
			compact: true,
		}),
		selectedSession && createElement(SessionDetailPanel, { detail: selectedSession, onClose: () => setSelectedSession(null) }),
		selectedCompany && createElement(CompanyDetailPanel, { detail: selectedCompany, onClose: () => setSelectedCompany(null) })
	);
}

function CallsView() {
	const [data, setData] = useState(null);
	const [selectedSession, setSelectedSession] = useState(null);
	const [filters, setFilters] = useState(CALL_FILTER_DEFAULTS);
	const [segments, setSegments] = useState([]);
	const [segmentName, setSegmentName] = useState('');

	const load = (nextFilters = filters) => {
		request(withQuery('/admin/calls', nextFilters)).then((response) => {
			setData(response);
			setSegments(response.segments || []);
		});
	};

	const saveSegment = async () => {
		const response = await request('/admin/reporting-segments', {
			method: 'POST',
			data: {
				name: segmentName,
				view: 'calls',
				filters,
			},
		});
		setSegments(response.items || []);
		setSegmentName('');
	};

	useEffect(() => {
		load(filters);
	}, []);

	useEffect(() => {
		const segmentId = getQueryParam('ace_segment');

		if (!segmentId || !segments.length) {
			return;
		}

		const segment = segments.find((item) => item.id === segmentId);

		if (!segment) {
			return;
		}

		const nextFilters = normaliseFilters(CALL_FILTER_DEFAULTS, segment.filters);
		setFilters(nextFilters);
		load(nextFilters);
		clearQueryParam('ace_segment');
	}, [segments]);

	if (!data) {
		return createElement(Spinner);
	}

	const cards = [
		['Click-to-call today', data.metrics.click_to_call_today],
		['Stored calls today', data.metrics.stored_calls_today],
		['Matched calls today', data.metrics.matched_calls_today],
		['Stored calls total', data.metrics.stored_calls_total],
		['Matched calls total', data.metrics.matched_calls_total],
		['Unmatched calls', data.metrics.unmatched_calls],
		['Filtered calls', data.metrics.filtered_calls],
	];

	return createElement(
		Fragment,
		null,
		createElement(
			'div',
			{ style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: '12px', marginBottom: '16px' } },
			cards.map(([label, value]) =>
				createElement(
					Card,
					{ key: label },
					createElement(CardBody, null, createElement('strong', null, __(label, 'adaptive-customer-engagement')), createElement('div', { style: { fontSize: '24px', marginTop: '8px' } }, value))
				)
			)
		),
		createElement(SavedSegmentsPanel, {
			segments,
			segmentName,
			onSegmentNameChange: setSegmentName,
			onSave: saveSegment,
			onApply: (segment) => {
				const nextFilters = normaliseFilters(CALL_FILTER_DEFAULTS, segment.filters);
				setFilters(nextFilters);
				load(nextFilters);
			},
			onDelete: async (segmentId) => {
				const response = await request(`/admin/reporting-segments/${segmentId}`, { method: 'DELETE' });
				setSegments((response.items || []).filter((item) => item.view === 'calls'));
			},
		}),
		createElement(FilterPanel, {
			filters,
			onChange: setFilters,
			onApply: () => load(filters),
			onReset: () => {
				const reset = { ...CALL_FILTER_DEFAULTS };
				setFilters(reset);
				load(reset);
			},
			selects: [
				{ key: 'status', label: 'Status', options: data.filters?.statuses || [] },
			],
		}),
		createElement(
			Card,
			{ style: { marginBottom: '16px' } },
			createElement(
				CardBody,
				null,
				createElement(ToggleControl, {
					label: __('Only show matched calls', 'adaptive-customer-engagement'),
					checked: !!filters.match_only,
					onChange: (next) => setFilters({ ...filters, match_only: next ? '1' : '' }),
				})
			)
		),
		createElement(ExportPanel, {
			label: __('Export current calls', 'adaptive-customer-engagement'),
			href: getExportUrl('ace_export_calls', filters),
		}),
		createElement('h2', null, __('Top call-intent pages', 'adaptive-customer-engagement')),
		createElement(
			'table',
			{ className: 'widefat striped', style: { marginBottom: '20px' } },
			createElement(
				'tbody',
				null,
				(data.top_call_paths || []).length
					? data.top_call_paths.map((page) =>
						createElement('tr', { key: page.path }, createElement('td', null, page.path || '/'), createElement('td', null, page.total))
					  )
					: createElement('tr', null, createElement('td', { colSpan: 2 }, __('No call-intent paths recorded yet.', 'adaptive-customer-engagement')))
			)
		),
		createElement('h2', null, __('Recent call-intent sessions', 'adaptive-customer-engagement')),
		createElement(SessionsTable, {
			items: data.call_intent_sessions || [],
			onView: async (id) => {
				const detail = await request(`/admin/sessions/${id}`);
				setSelectedSession(detail);
			},
		}),
		createElement('h2', { style: { marginTop: '20px' } }, __('Stored calls', 'adaptive-customer-engagement')),
		createElement(CallsTable, {
			items: data.recent_calls || [],
		}),
		selectedSession && createElement(SessionDetailPanel, { detail: selectedSession, onClose: () => setSelectedSession(null) })
	);
}

function WooCommerceInterestTable({ items, type = 'product' }) {
	if (!items.length) {
		return createElement(Notice, { status: 'info', isDismissible: false }, __('No WooCommerce interest data has been recorded yet.', 'adaptive-customer-engagement'));
	}

	return createElement(
		'table',
		{ className: 'widefat striped' },
		createElement(
			'thead',
			null,
			createElement(
				'tr',
				null,
				[type === 'product' ? 'Product' : 'Category', 'Slug', 'Views', 'Highest repeat count'].map((label) =>
					createElement('th', { key: label }, __(label, 'adaptive-customer-engagement'))
				)
			)
		),
		createElement(
			'tbody',
			null,
			items.map((item) =>
				createElement(
					'tr',
					{ key: item.key || `${type}-${item.slug}-${item.id}` },
					createElement('td', null, item.name || '—'),
					createElement('td', null, item.slug || '—'),
					createElement('td', null, item.views ?? 0),
					createElement('td', null, item.repeat_views ?? 1)
				)
			)
		)
	);
}

function InterestSummaryPanel({ title, commerce }) {
	if (!commerce) {
		return null;
	}

	return createElement(
		Card,
		{ style: { marginBottom: '16px' } },
		createElement(
			CardBody,
			null,
			createElement('h3', { style: { marginTop: 0 } }, title),
			createElement('p', null, commerce.summary || __('No WooCommerce interest recorded yet.', 'adaptive-customer-engagement')),
			createElement(
				'div',
				{ style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))', gap: '12px' } },
				createElement(
					'div',
					null,
					createElement('strong', null, __('Products', 'adaptive-customer-engagement')),
					commerce.products?.length
						? createElement(
								'ul',
								null,
								commerce.products.map((item) =>
									createElement('li', { key: item.key || item.slug || item.id }, `${item.name || item.slug || '—'} (${item.repeat_views || item.views || 0})`)
								)
						  )
						: createElement('p', null, '—')
				),
				createElement(
					'div',
					null,
					createElement('strong', null, __('Categories', 'adaptive-customer-engagement')),
					commerce.categories?.length
						? createElement(
								'ul',
								null,
								commerce.categories.map((item) =>
									createElement('li', { key: item.key || item.slug || item.id }, `${item.name || item.slug || '—'} (${item.repeat_views || item.views || 0})`)
								)
						  )
						: createElement('p', null, '—')
				)
			)
		)
	);
}

function CommerceView() {
	const [data, setData] = useState(null);
	const [selectedSession, setSelectedSession] = useState(null);
	const [selectedCompany, setSelectedCompany] = useState(null);
	const [filters, setFilters] = useState(COMMERCE_FILTER_DEFAULTS);
	const [segments, setSegments] = useState([]);
	const [segmentName, setSegmentName] = useState('');

	const load = (nextFilters = filters) => {
		request(withQuery('/admin/commerce', nextFilters)).then((response) => {
			setData(response);
			setSegments(response.segments || []);
		});
	};

	const saveSegment = async () => {
		const response = await request('/admin/reporting-segments', {
			method: 'POST',
			data: {
				name: segmentName,
				view: 'commerce',
				filters,
			},
		});
		setSegments(response.items || []);
		setSegmentName('');
	};

	useEffect(() => {
		load(filters);
	}, []);

	useEffect(() => {
		const segmentId = getQueryParam('ace_segment');

		if (!segmentId || !segments.length) {
			return;
		}

		const segment = segments.find((item) => item.id === segmentId);

		if (!segment) {
			return;
		}

		const nextFilters = normaliseFilters(COMMERCE_FILTER_DEFAULTS, segment.filters);
		setFilters(nextFilters);
		load(nextFilters);
		clearQueryParam('ace_segment');
	}, [segments]);

	if (!data) {
		return createElement(Spinner);
	}

	const cards = [
		['Sessions with interest', data.metrics.sessions_with_interest],
		['Sessions with repeat interest', data.metrics.sessions_with_repeat_interest],
		['Companies with interest', data.metrics.companies_with_interest],
		['Companies with repeat interest', data.metrics.companies_with_repeat_interest],
		['Products tracked', data.metrics.products_tracked],
		['Categories tracked', data.metrics.categories_tracked],
	];

	return createElement(
		Fragment,
		null,
		createElement(
			'div',
			{ style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: '12px', marginBottom: '16px' } },
			cards.map(([label, value]) =>
				createElement(
					Card,
					{ key: label },
					createElement(CardBody, null, createElement('strong', null, __(label, 'adaptive-customer-engagement')), createElement('div', { style: { fontSize: '24px', marginTop: '8px' } }, value))
				)
			)
		),
		createElement(SavedSegmentsPanel, {
			segments,
			segmentName,
			onSegmentNameChange: setSegmentName,
			onSave: saveSegment,
			onApply: (segment) => {
				const nextFilters = normaliseFilters(COMMERCE_FILTER_DEFAULTS, segment.filters);
				setFilters(nextFilters);
				load(nextFilters);
			},
			onDelete: async (segmentId) => {
				const response = await request(`/admin/reporting-segments/${segmentId}`, { method: 'DELETE' });
				setSegments((response.items || []).filter((item) => item.view === 'commerce'));
			},
		}),
		createElement(FilterPanel, {
			filters,
			onChange: setFilters,
			onApply: () => load(filters),
			onReset: () => {
				const reset = { ...COMMERCE_FILTER_DEFAULTS };
				setFilters(reset);
				load(reset);
			},
		}),
		createElement(
			Card,
			{ style: { marginBottom: '16px' } },
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('WooCommerce view options', 'adaptive-customer-engagement')),
				createElement(ToggleControl, {
					label: __('Only show repeat interest', 'adaptive-customer-engagement'),
					checked: filters.repeat_only !== '0',
					onChange: (next) => setFilters({ ...filters, repeat_only: next ? '1' : '0' }),
				})
			)
		),
		createElement(
			'div',
			{ style: { marginBottom: '16px', display: 'flex', gap: '8px', flexWrap: 'wrap', justifyContent: 'flex-end' } },
			createElement(Button, { variant: 'secondary', href: getExportUrl('ace_export_commerce', { ...filters, dataset: 'products' }) }, __('Export products', 'adaptive-customer-engagement')),
			createElement(Button, { variant: 'secondary', href: getExportUrl('ace_export_commerce', { ...filters, dataset: 'categories' }) }, __('Export categories', 'adaptive-customer-engagement')),
			createElement(Button, { variant: 'secondary', href: getExportUrl('ace_export_commerce', { ...filters, dataset: 'sessions' }) }, __('Export sessions', 'adaptive-customer-engagement')),
			createElement(Button, { variant: 'secondary', href: getExportUrl('ace_export_commerce', { ...filters, dataset: 'companies' }) }, __('Export companies', 'adaptive-customer-engagement'))
		),
		createElement('h2', null, __('Top repeated products', 'adaptive-customer-engagement')),
		createElement(WooCommerceInterestTable, { items: data.top_products || [], type: 'product' }),
		createElement('h2', { style: { marginTop: '20px' } }, __('Top repeated categories', 'adaptive-customer-engagement')),
		createElement(WooCommerceInterestTable, { items: data.top_categories || [], type: 'category' }),
		createElement('h2', { style: { marginTop: '20px' } }, __('Sessions showing WooCommerce interest', 'adaptive-customer-engagement')),
		createElement(SessionsTable, {
			items: data.repeat_sessions || [],
			onView: async (id) => {
				const detail = await request(`/admin/sessions/${id}`);
				setSelectedSession(detail);
			},
		}),
		createElement('h2', { style: { marginTop: '20px' } }, __('Companies showing WooCommerce interest', 'adaptive-customer-engagement')),
		createElement(CompaniesTable, {
			items: data.repeat_companies || [],
			onView: async (id) => {
				const detail = await request(`/admin/companies/${id}`);
				setSelectedCompany(detail);
			},
		}),
		selectedSession && createElement(SessionDetailPanel, { detail: selectedSession, onClose: () => setSelectedSession(null) }),
		selectedCompany && createElement(CompanyDetailPanel, { detail: selectedCompany, onClose: () => setSelectedCompany(null) })
	);
}

function DashboardSegmentsPanel({ shortcuts }) {
	const sessionSegments = shortcuts.sessions || [];
	const companySegments = shortcuts.companies || [];
	const callSegments = shortcuts.calls || [];
	const commerceSegments = shortcuts.commerce || [];

	if (!sessionSegments.length && !companySegments.length && !callSegments.length && !commerceSegments.length) {
		return null;
	}

	return createElement(
		Fragment,
		null,
		createElement('h2', { style: { marginTop: '20px' } }, __('Saved segments', 'adaptive-customer-engagement')),
		createElement(
			'div',
			{ style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))', gap: '12px', marginBottom: '20px' } },
			createElement(DashboardSegmentCard, {
				title: __('Session segments', 'adaptive-customer-engagement'),
				segments: sessionSegments,
				page: 'sessions',
			}),
			createElement(DashboardSegmentCard, {
				title: __('Company segments', 'adaptive-customer-engagement'),
				segments: companySegments,
				page: 'companies',
			}),
			createElement(DashboardSegmentCard, {
				title: __('Call segments', 'adaptive-customer-engagement'),
				segments: callSegments,
				page: 'calls',
			}),
			createElement(DashboardSegmentCard, {
				title: __('WooCommerce segments', 'adaptive-customer-engagement'),
				segments: commerceSegments,
				page: 'commerce',
			})
		)
	);
}

function DashboardSegmentCard({ title, segments, page }) {
	return createElement(
		Card,
		null,
		createElement(
			CardBody,
			null,
			createElement('h3', { style: { marginTop: 0 } }, title),
			segments.length
				? createElement(
						'div',
						{ style: { display: 'grid', gap: '8px' } },
						segments.map((segment) =>
							createElement(
								'a',
								{
									key: segment.id,
									href: getAdminPageUrl(page, { ace_segment: segment.id }),
									style: {
										display: 'flex',
										justifyContent: 'space-between',
										alignItems: 'center',
										padding: '8px 0',
										borderTop: '1px solid #f0f0f0',
										textDecoration: 'none',
									},
								},
								createElement('strong', null, segment.name),
								createElement('span', { style: { color: '#3858e9' } }, __('Open', 'adaptive-customer-engagement'))
							)
						)
				  )
				: createElement(Notice, { status: 'info', isDismissible: false }, __('No saved segments yet.', 'adaptive-customer-engagement'))
		)
	);
}

function SessionsView() {
	const [items, setItems] = useState(null);
	const [detail, setDetail] = useState(null);
	const [options, setOptions] = useState({ sources: [], confidences: [] });
	const [segments, setSegments] = useState([]);
	const [segmentName, setSegmentName] = useState('');
	const [pagination, setPagination] = useState({ page: 1, per_page: 25, total: 0, total_pages: 1 });
	const [filters, setFilters] = useState(SESSION_FILTER_DEFAULTS);

	const load = async (nextFilters = filters, nextPage = pagination.page) => {
		const response = await request(withQuery('/admin/sessions', { ...nextFilters, page: nextPage, per_page: pagination.per_page }));
		setItems(response.items || []);
		setOptions(response.filters || { sources: [], confidences: [] });
		setSegments(response.segments || []);
		setPagination(response.pagination || { page: 1, per_page: 25, total: 0, total_pages: 1 });
	};

	const saveSegment = async () => {
		const response = await request('/admin/reporting-segments', {
			method: 'POST',
			data: {
				name: segmentName,
				view: 'sessions',
				filters,
			},
		});
		setSegments(response.items || []);
		setSegmentName('');
	};

	useEffect(() => {
		load(filters);
	}, []);

	useEffect(() => {
		const segmentId = getQueryParam('ace_segment');

		if (!segmentId || !segments.length) {
			return;
		}

		const segment = segments.find((item) => item.id === segmentId);

		if (!segment) {
			return;
		}

		const nextFilters = normaliseFilters(SESSION_FILTER_DEFAULTS, segment.filters);
		setFilters(nextFilters);
		load(nextFilters, 1);
		clearQueryParam('ace_segment');
	}, [segments]);

	if (!items) {
		return createElement(Spinner);
	}

	return createElement(
		Fragment,
		null,
		createElement(SavedSegmentsPanel, {
			segments,
			segmentName,
			onSegmentNameChange: setSegmentName,
			onSave: saveSegment,
			onApply: (segment) => {
				const nextFilters = normaliseFilters(SESSION_FILTER_DEFAULTS, segment.filters);
				setFilters(nextFilters);
				load(nextFilters, 1);
			},
			onDelete: async (segmentId) => {
				const response = await request(`/admin/reporting-segments/${segmentId}`, { method: 'DELETE' });
				setSegments((response.items || []).filter((item) => item.view === 'sessions'));
			},
		}),
		createElement(FilterPanel, {
			filters,
			onChange: setFilters,
			onApply: () => load(filters, 1),
			onReset: () => {
				const reset = { ...SESSION_FILTER_DEFAULTS };
				setFilters(reset);
				load(reset, 1);
			},
			selects: [
				{ key: 'confidence', label: 'Confidence', options: options.confidences || [] },
				{ key: 'source', label: 'Source', options: options.sources || [] },
			],
		}),
		createElement(ExportPanel, {
			label: __('Export current sessions', 'adaptive-customer-engagement'),
			href: getExportUrl('ace_export_sessions', filters),
		}),
		createElement(SessionsTable, {
			items,
			onView: async (id) => {
				const response = await request(`/admin/sessions/${id}`);
				setDetail(response);
			},
		}),
		createElement(PaginationControls, {
			pagination,
			onPageChange: (page) => load(filters, page),
		}),
		detail && createElement(SessionDetailPanel, { detail, onClose: () => setDetail(null) })
	);
}

function SessionDetailPanel({ detail, onClose }) {
	const session = detail?.session || {};
	const events = detail?.events || [];

	return createElement(
		Card,
		{ style: { marginTop: '20px' } },
		createElement(
			CardBody,
			null,
			createElement(
				'div',
				{ style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } },
				createElement('h2', { style: { margin: 0 } }, __('Session detail', 'adaptive-customer-engagement')),
				createElement(Button, { variant: 'secondary', onClick: onClose }, __('Close', 'adaptive-customer-engagement'))
			),
			createElement('p', null, `${__('Score', 'adaptive-customer-engagement')}: ${session.score || 0} (${session.score_label || 'noise'})`),
			createElement('p', null, `${__('Why it scored', 'adaptive-customer-engagement')}: ${session.score_summary || '—'}`),
			createElement(InterestSummaryPanel, { title: __('WooCommerce interest', 'adaptive-customer-engagement'), commerce: detail?.commerce }),
			createElement(
				'table',
				{ className: 'widefat striped', style: { marginBottom: '16px' } },
				createElement(
					'tbody',
					null,
					[
						['Landing page', session.landing_path || '—'],
						['Referrer', session.referrer || '—'],
						['Source', session.utm_source || '—'],
						['Campaign', session.utm_campaign || '—'],
						['Company', session.company_name || '—'],
						['Company domain', session.company_domain || '—'],
						['First seen', session.first_seen || '—'],
						['Last seen', session.last_seen || '—'],
						['Company confidence', session.company_confidence || 'unknown'],
					].map(([label, value]) => createElement('tr', { key: label }, createElement('th', null, __(label, 'adaptive-customer-engagement')), createElement('td', null, value)))
				)
			),
			session.score_breakdown?.length
				? createElement(
						Fragment,
						null,
						createElement('h3', null, __('Score breakdown', 'adaptive-customer-engagement')),
						createElement(
							'ul',
							null,
							session.score_breakdown.map((item, index) =>
								createElement('li', { key: `${item.label}-${index}` }, `${item.label}: ${item.points > 0 ? '+' : ''}${item.points}`)
							)
						)
				  )
				: null,
			createElement('h3', null, __('Timeline', 'adaptive-customer-engagement')),
			createElement(
				'table',
				{ className: 'widefat striped' },
				createElement(
					'thead',
					null,
					createElement(
						'tr',
						null,
						['When', 'Type', 'Name', 'Path', 'Metadata'].map((label) => createElement('th', { key: label }, __(label, 'adaptive-customer-engagement')))
					)
				),
				createElement(
					'tbody',
					null,
					events.map((item) =>
						createElement(
							'tr',
							{ key: item.id },
							createElement('td', null, item.occurred_at),
							createElement('td', null, item.event_type),
							createElement('td', null, item.event_name || '—'),
							createElement('td', null, item.path || '—'),
							createElement('td', null, Object.entries(item.metadata || {}).map(([key, value]) => `${key}: ${value}`).join(', ') || '—')
						)
					)
				)
			)
		)
	);
}

function CompaniesTable({ items, onView, compact = false }) {
	if (!items.length) {
		return createElement(Notice, { status: 'info', isDismissible: false }, __('No enriched companies are available yet.', 'adaptive-customer-engagement'));
	}

	const columns = compact
		? ['Company', 'Domain', 'Confidence', 'Priority', 'Sessions', 'Events', 'Last seen', 'Actions']
		: ['Company', 'Type', 'Domain', 'Confidence', 'Priority', 'Why it scored', 'Sessions', 'Events', 'Calls', 'Last seen', 'Actions'];

	return createElement(
		'table',
		{ className: 'widefat striped' },
		createElement(
			'thead',
			null,
			createElement(
				'tr',
				null,
				columns.map((label) => createElement('th', { key: label }, __(label, 'adaptive-customer-engagement')))
			)
		),
		createElement(
			'tbody',
			null,
			items.map((item) =>
				createElement(
					'tr',
					{ key: item.id },
					createElement('td', null, item.name || '—'),
					!compact && createElement('td', null, item.type || '—'),
					createElement('td', null, item.domain || '—'),
					createElement('td', null, item.confidence || 'unknown'),
					createElement('td', null, `${item.priority_score ?? 0} (${item.priority_label || 'noise'})`),
					!compact && createElement('td', null, item.priority_summary || '—'),
					createElement('td', null, item.total_sessions ?? item.session_count ?? 0),
					createElement('td', null, item.total_events ?? item.page_views ?? 0),
					!compact && createElement('td', null, item.total_calls ?? 0),
					createElement('td', null, item.last_seen || '—'),
					createElement(
						'td',
						null,
						createElement(Button, { variant: 'secondary', onClick: () => onView && onView(item.id) }, __('View', 'adaptive-customer-engagement'))
					)
				)
			)
		)
	);
}

function CompanyDetailPanel({ detail, onClose }) {
	const sessions = detail?.recent_sessions || [];

	return createElement(
		Card,
		{ style: { marginTop: '20px' } },
		createElement(
			CardBody,
			null,
			createElement(
				'div',
				{ style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } },
				createElement('h2', { style: { margin: 0 } }, __('Company detail', 'adaptive-customer-engagement')),
				createElement(Button, { variant: 'secondary', onClick: onClose }, __('Close', 'adaptive-customer-engagement'))
			),
			createElement(InterestSummaryPanel, { title: __('WooCommerce interest', 'adaptive-customer-engagement'), commerce: detail?.commerce }),
			createElement(
				'table',
				{ className: 'widefat striped', style: { marginBottom: '16px' } },
				createElement(
					'tbody',
					null,
					[
						['Company', detail.name || '—'],
						['Domain', detail.domain || '—'],
						['Type', detail.type || '—'],
						['Confidence', detail.confidence || 'unknown'],
						['Provider', detail.source_provider || '—'],
						['Priority', `${detail.priority_score || 0} (${detail.priority_label || 'noise'})`],
						['Why it scored', detail.priority_summary || '—'],
						['Country', detail.country_code || '—'],
						['Sessions', detail.total_sessions || 0],
						['Events', detail.total_events || 0],
						['Calls', detail.total_calls || 0],
						['First seen', detail.first_seen || '—'],
						['Last seen', detail.last_seen || '—'],
					].map(([label, value]) => createElement('tr', { key: label }, createElement('th', null, __(label, 'adaptive-customer-engagement')), createElement('td', null, value)))
				)
			),
			detail.priority_breakdown?.length
				? createElement(
						Fragment,
						null,
						createElement('h3', null, __('Priority breakdown', 'adaptive-customer-engagement')),
						createElement(
							'ul',
							null,
							detail.priority_breakdown.map((item, index) =>
								createElement('li', { key: `${item.label}-${index}` }, `${item.label}: ${item.points > 0 ? '+' : ''}${item.points}`)
							)
						)
				  )
				: null,
			createElement('h3', null, __('Recent sessions', 'adaptive-customer-engagement')),
			createElement(SessionsTable, { items: sessions })
		)
	);
}

function CallsTable({ items }) {
	if (!items.length) {
		return createElement(Notice, { status: 'info', isDismissible: false }, __('No stored call records are available yet.', 'adaptive-customer-engagement'));
	}

	return createElement(
		'table',
		{ className: 'widefat striped' },
		createElement(
			'thead',
			null,
			createElement(
				'tr',
				null,
				['When', 'Status', 'Called number', 'Tracking number', 'Company', 'Session', 'Duration', 'Match confidence'].map((label) =>
					createElement('th', { key: label }, __(label, 'adaptive-customer-engagement'))
				)
			)
		),
		createElement(
			'tbody',
			null,
			items.map((item) =>
				createElement(
					'tr',
					{ key: item.id },
					createElement('td', null, item.started_at || '—'),
					createElement('td', null, item.status || '—'),
					createElement('td', null, item.called_number || '—'),
					createElement('td', null, item.number_label || '—'),
					createElement('td', null, item.company_name || '—'),
					createElement('td', null, item.session_uuid || '—'),
					createElement('td', null, item.duration_seconds ?? '—'),
					createElement('td', null, item.match_confidence || 'unknown')
				)
			)
		)
	);
}

function CompaniesView() {
	const [items, setItems] = useState(null);
	const [detail, setDetail] = useState(null);
	const [options, setOptions] = useState({ providers: [], confidences: [] });
	const [segments, setSegments] = useState([]);
	const [segmentName, setSegmentName] = useState('');
	const [pagination, setPagination] = useState({ page: 1, per_page: 25, total: 0, total_pages: 1 });
	const [filters, setFilters] = useState(COMPANY_FILTER_DEFAULTS);

	const load = async (nextFilters = filters, nextPage = pagination.page) => {
		const response = await request(withQuery('/admin/companies', { ...nextFilters, page: nextPage, per_page: pagination.per_page }));
		setItems(response.items || []);
		setOptions(response.filters || { providers: [], confidences: [] });
		setSegments(response.segments || []);
		setPagination(response.pagination || { page: 1, per_page: 25, total: 0, total_pages: 1 });
	};

	const saveSegment = async () => {
		const response = await request('/admin/reporting-segments', {
			method: 'POST',
			data: {
				name: segmentName,
				view: 'companies',
				filters,
			},
		});
		setSegments(response.items || []);
		setSegmentName('');
	};

	useEffect(() => {
		load(filters);
	}, []);

	useEffect(() => {
		const segmentId = getQueryParam('ace_segment');

		if (!segmentId || !segments.length) {
			return;
		}

		const segment = segments.find((item) => item.id === segmentId);

		if (!segment) {
			return;
		}

		const nextFilters = normaliseFilters(COMPANY_FILTER_DEFAULTS, segment.filters);
		setFilters(nextFilters);
		load(nextFilters, 1);
		clearQueryParam('ace_segment');
	}, [segments]);

	if (!items) {
		return createElement(Spinner);
	}

	return createElement(
		Fragment,
		null,
		createElement(SavedSegmentsPanel, {
			segments,
			segmentName,
			onSegmentNameChange: setSegmentName,
			onSave: saveSegment,
			onApply: (segment) => {
				const nextFilters = normaliseFilters(COMPANY_FILTER_DEFAULTS, segment.filters);
				setFilters(nextFilters);
				load(nextFilters, 1);
			},
			onDelete: async (segmentId) => {
				const response = await request(`/admin/reporting-segments/${segmentId}`, { method: 'DELETE' });
				setSegments((response.items || []).filter((item) => item.view === 'companies'));
			},
		}),
		createElement(FilterPanel, {
			filters,
			onChange: setFilters,
			onApply: () => load(filters, 1),
			onReset: () => {
				const reset = { ...COMPANY_FILTER_DEFAULTS };
				setFilters(reset);
				load(reset, 1);
			},
			selects: [
				{ key: 'confidence', label: 'Confidence', options: options.confidences || [] },
				{ key: 'provider', label: 'Provider', options: options.providers || [] },
			],
		}),
		createElement(ExportPanel, {
			label: __('Export current companies', 'adaptive-customer-engagement'),
			href: getExportUrl('ace_export_companies', filters),
		}),
		createElement(CompaniesTable, {
			items,
			onView: async (id) => {
				const response = await request(`/admin/companies/${id}`);
				setDetail(response);
			},
		}),
		createElement(PaginationControls, {
			pagination,
			onPageChange: (page) => load(filters, page),
		}),
		detail && createElement(CompanyDetailPanel, { detail, onClose: () => setDetail(null) })
	);
}

function SavedSegmentsPanel({ segments, segmentName, onSegmentNameChange, onSave, onApply, onDelete }) {
	return createElement(
		Card,
		{ style: { marginBottom: '16px' } },
		createElement(
			CardBody,
			null,
			createElement('h3', { style: { marginTop: 0 } }, __('Saved segments', 'adaptive-customer-engagement')),
			createElement(
				'div',
				{ style: { display: 'flex', gap: '8px', alignItems: 'end', marginBottom: '12px', flexWrap: 'wrap' } },
				createElement(TextControl, {
					label: __('Segment name', 'adaptive-customer-engagement'),
					value: segmentName,
					onChange: onSegmentNameChange,
				}),
				createElement(
					Button,
					{ variant: 'primary', onClick: onSave, disabled: !segmentName.trim() },
					__('Save current filters', 'adaptive-customer-engagement')
				)
			),
			segments.length
				? createElement(
						'div',
						{ style: { display: 'grid', gap: '8px' } },
						segments.map((segment) =>
							createElement(
								'div',
								{
									key: segment.id,
									style: {
										display: 'flex',
										justifyContent: 'space-between',
										alignItems: 'center',
										gap: '8px',
										padding: '8px 0',
										borderTop: '1px solid #f0f0f0',
									},
								},
								createElement(
									'div',
									null,
									createElement('strong', null, segment.name),
									createElement('div', { style: { color: '#50575e', fontSize: '12px' } }, segment.created_at || '—')
								),
								createElement(
									'div',
									{ style: { display: 'flex', gap: '8px' } },
									createElement(Button, { variant: 'secondary', onClick: () => onApply(segment) }, __('Apply', 'adaptive-customer-engagement')),
									createElement(Button, { variant: 'tertiary', onClick: () => onDelete(segment.id) }, __('Delete', 'adaptive-customer-engagement'))
								)
							)
						)
				  )
				: createElement(Notice, { status: 'info', isDismissible: false }, __('No saved segments yet.', 'adaptive-customer-engagement'))
		)
	);
}

function ExportPanel({ href, label }) {
	return createElement(
		'div',
		{ style: { marginBottom: '16px', display: 'flex', justifyContent: 'flex-end' } },
		createElement(Button, { variant: 'secondary', href }, label)
	);
}

function FilterPanel({ filters, onChange, onApply, onReset, selects = [] }) {
	return createElement(
		Card,
		{ style: { marginBottom: '16px' } },
		createElement(
			CardBody,
			null,
			createElement(
				'div',
				{ style: { display: 'grid', gap: '12px', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))' } },
				createElement(TextControl, {
					label: __('Search', 'adaptive-customer-engagement'),
					value: filters.search,
					onChange: (next) => onChange({ ...filters, search: next }),
				}),
				...selects.map((select) =>
					createElement(SelectControl, {
						key: select.key,
						label: __(select.label, 'adaptive-customer-engagement'),
						value: filters[select.key] || '',
						options: [{ label: __('All', 'adaptive-customer-engagement'), value: '' }].concat(
							(select.options || []).map((entry) => ({ label: entry, value: entry }))
						),
						onChange: (next) => onChange({ ...filters, [select.key]: next }),
					})
				),
				createElement(TextControl, {
					label: __('From date', 'adaptive-customer-engagement'),
					type: 'date',
					value: filters.date_from,
					onChange: (next) => onChange({ ...filters, date_from: next }),
				}),
				createElement(TextControl, {
					label: __('To date', 'adaptive-customer-engagement'),
					type: 'date',
					value: filters.date_to,
					onChange: (next) => onChange({ ...filters, date_to: next }),
				})
			),
			createElement(
				'div',
				{ style: { marginTop: '12px', display: 'flex', gap: '8px' } },
				createElement(Button, { variant: 'primary', onClick: onApply }, __('Apply filters', 'adaptive-customer-engagement')),
				createElement(Button, { variant: 'secondary', onClick: onReset }, __('Reset filters', 'adaptive-customer-engagement'))
			)
		)
	);
}

function PaginationControls({ pagination, onPageChange }) {
	if (!pagination || pagination.total_pages <= 1) {
		return null;
	}

	return createElement(
		'div',
		{ style: { marginTop: '16px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' } },
		createElement(
			'span',
			null,
			`${__('Page', 'adaptive-customer-engagement')} ${pagination.page} ${__('of', 'adaptive-customer-engagement')} ${pagination.total_pages} (${pagination.total} ${__('items', 'adaptive-customer-engagement')})`
		),
		createElement(
			'div',
			{ style: { display: 'flex', gap: '8px' } },
			createElement(
				Button,
				{ variant: 'secondary', disabled: pagination.page <= 1, onClick: () => onPageChange(pagination.page - 1) },
				__('Previous', 'adaptive-customer-engagement')
			),
			createElement(
				Button,
				{ variant: 'secondary', disabled: pagination.page >= pagination.total_pages, onClick: () => onPageChange(pagination.page + 1) },
				__('Next', 'adaptive-customer-engagement')
			)
		)
	);
}

function NumberForm({ value, onChange, onSubmit, busy, label }) {
	return createElement(
		'div',
		{ style: { display: 'grid', gap: '8px', marginBottom: '16px', maxWidth: '640px' } },
		createElement(TextControl, { label: __('Label', 'adaptive-customer-engagement'), value: value.label, onChange: (next) => onChange({ ...value, label: next }) }),
		createElement(TextControl, { label: __('Display number', 'adaptive-customer-engagement'), value: value.display_number, onChange: (next) => onChange({ ...value, display_number: next }) }),
		createElement(TextControl, { label: __('E.164 number', 'adaptive-customer-engagement'), value: value.e164_number, onChange: (next) => onChange({ ...value, e164_number: next }) }),
		createElement(SelectControl, {
			label: __('Source type', 'adaptive-customer-engagement'),
			value: value.source_type,
			options: ['default', 'website', 'campaign', 'google_business_profile', 'bing', 'social', 'product_page', 'brand_page', 'brochure_qr'].map((entry) => ({ label: entry, value: entry })),
			onChange: (next) => onChange({ ...value, source_type: next }),
		}),
		createElement(TextControl, { label: __('Source value', 'adaptive-customer-engagement'), value: value.source_value, onChange: (next) => onChange({ ...value, source_value: next }) }),
		createElement(SelectControl, {
			label: __('Page match type', 'adaptive-customer-engagement'),
			value: value.page_match_type,
			options: ['contains', 'exact', 'prefix', 'regex'].map((entry) => ({ label: entry, value: entry })),
			onChange: (next) => onChange({ ...value, page_match_type: next }),
		}),
		createElement(TextControl, { label: __('Page match value', 'adaptive-customer-engagement'), value: value.page_match_value, onChange: (next) => onChange({ ...value, page_match_value: next }) }),
		createElement(TextControl, { label: __('Campaign match', 'adaptive-customer-engagement'), value: value.campaign_match, onChange: (next) => onChange({ ...value, campaign_match: next }) }),
		createElement(TextControl, { label: __('Priority', 'adaptive-customer-engagement'), type: 'number', value: value.priority, onChange: (next) => onChange({ ...value, priority: Number(next || 0) }) }),
		createElement(ToggleControl, { label: __('Default number', 'adaptive-customer-engagement'), checked: !!value.is_default, onChange: (next) => onChange({ ...value, is_default: next }) }),
		createElement(ToggleControl, { label: __('Active', 'adaptive-customer-engagement'), checked: !!value.is_active, onChange: (next) => onChange({ ...value, is_active: next }) }),
		createElement(Button, { variant: 'primary', onClick: onSubmit, disabled: busy }, label)
	);
}

function NumbersView() {
	const empty = useMemo(
		() => ({
			label: '',
			display_number: '',
			e164_number: '',
			source_type: 'default',
			source_value: '',
			page_match_type: 'contains',
			page_match_value: '',
			campaign_match: '',
			priority: 10,
			is_default: false,
			is_active: true,
			amazon_connect_phone_number_id: '',
			amazon_connect_contact_flow_id: '',
		}),
		[]
	);
	const [items, setItems] = useState(null);
	const [current, setCurrent] = useState(empty);
	const [busy, setBusy] = useState(false);

	const load = () => request('/admin/numbers').then((response) => setItems(response.items || []));

	useEffect(() => {
		load();
	}, []);

	const save = async () => {
		setBusy(true);
		await request(current.id ? `/admin/numbers/${current.id}` : '/admin/numbers', {
			method: current.id ? 'PATCH' : 'POST',
			data: current,
		});
		setCurrent(empty);
		await load();
		setBusy(false);
	};

	const remove = async (id) => {
		setBusy(true);
		await request(`/admin/numbers/${id}`, { method: 'DELETE' });
		if (current.id === id) {
			setCurrent(empty);
		}
		await load();
		setBusy(false);
	};

	if (!items) {
		return createElement(Spinner);
	}

	return createElement(
		Fragment,
		null,
		createElement(NumberForm, {
			value: current,
			onChange: setCurrent,
			onSubmit: save,
			busy,
			label: current.id ? __('Update number', 'adaptive-customer-engagement') : __('Add number', 'adaptive-customer-engagement'),
		}),
		createElement(
			'table',
			{ className: 'widefat striped' },
			createElement(
				'thead',
				null,
				createElement(
					'tr',
					null,
					['Label', 'Display', 'E.164', 'Source', 'Path rule', 'Priority', 'Status', 'Actions'].map((label) =>
						createElement('th', { key: label }, __(label, 'adaptive-customer-engagement'))
					)
				)
			),
			createElement(
				'tbody',
				null,
				items.map((item) =>
					createElement(
						'tr',
						{ key: item.id },
						createElement('td', null, item.label),
						createElement('td', null, item.display_number),
						createElement('td', null, item.e164_number),
						createElement('td', null, item.source_type),
						createElement('td', null, item.page_match_value || '—'),
						createElement('td', null, item.priority),
						createElement('td', null, item.is_active ? __('Active', 'adaptive-customer-engagement') : __('Inactive', 'adaptive-customer-engagement')),
						createElement(
							'td',
							null,
							createElement(Button, { onClick: () => setCurrent({ ...item, priority: Number(item.priority) }) }, __('Edit', 'adaptive-customer-engagement')),
							' ',
							createElement(Button, { isDestructive: true, onClick: () => remove(item.id), disabled: busy }, __('Delete', 'adaptive-customer-engagement'))
						)
					)
				)
			)
		)
	);
}

function SettingsView({ section = 'settings' }) {
	const [settings, setSettings] = useState(null);
	const [notice, setNotice] = useState(null);
	const [busy, setBusy] = useState(false);
	const [testIp, setTestIp] = useState('');
	const [testResult, setTestResult] = useState(null);

	useEffect(() => {
		request('/admin/settings').then(setSettings);
	}, []);

	if (!settings) {
		return createElement(Spinner);
	}

	const save = async () => {
		setBusy(true);
		const response = await request('/admin/settings', { method: 'POST', data: settings });
		setSettings(response);
		setNotice(__('Settings saved.', 'adaptive-customer-engagement'));
		setBusy(false);
	};

	const purge = async () => {
		setBusy(true);
		await request('/admin/privacy/purge', { method: 'POST' });
		setNotice(__('Expired raw data purged.', 'adaptive-customer-engagement'));
		setBusy(false);
	};

	const runEnrichmentTest = async () => {
		setBusy(true);
		try {
			const response = await request('/admin/enrichment/test', {
				method: 'POST',
				data: {
					ip: testIp,
				},
			});
			setTestResult(response);
			setNotice(__('Enrichment lookup completed.', 'adaptive-customer-engagement'));
		} catch (error) {
			setTestResult(null);
			setNotice(error.message || __('The enrichment test failed.', 'adaptive-customer-engagement'));
		}
		setBusy(false);
	};

	const sections = {
		settings: createElement(
			Fragment,
			null,
			createElement(ToggleControl, { label: __('Enable tracking', 'adaptive-customer-engagement'), checked: !!settings.enabled, onChange: (next) => setSettings({ ...settings, enabled: next }) }),
			createElement(ToggleControl, { label: __('Ignore logged-in admins', 'adaptive-customer-engagement'), checked: !!settings.tracking.ignore_logged_in_admins, onChange: (next) => setSettings({ ...settings, tracking: { ...settings.tracking, ignore_logged_in_admins: next } }) }),
			createElement(ToggleControl, { label: __('Respect Do Not Track', 'adaptive-customer-engagement'), checked: !!settings.tracking.respect_dnt, onChange: (next) => setSettings({ ...settings, tracking: { ...settings.tracking, respect_dnt: next } }) }),
			createElement(TextControl, { label: __('Session cookie name', 'adaptive-customer-engagement'), value: settings.tracking.cookie_name, onChange: (next) => setSettings({ ...settings, tracking: { ...settings.tracking, cookie_name: next } }) }),
			createElement(TextControl, { label: __('Visitor cookie name', 'adaptive-customer-engagement'), value: settings.tracking.visitor_cookie_name, onChange: (next) => setSettings({ ...settings, tracking: { ...settings.tracking, visitor_cookie_name: next } }) })
		),
		privacy: createElement(
			Fragment,
			null,
			createElement(TextControl, { label: __('Raw IP retention (days)', 'adaptive-customer-engagement'), type: 'number', value: settings.privacy.raw_ip_retention_days, onChange: (next) => setSettings({ ...settings, privacy: { ...settings.privacy, raw_ip_retention_days: Number(next || 1) } }) }),
			createElement(TextControl, { label: __('Raw phone retention (days)', 'adaptive-customer-engagement'), type: 'number', value: settings.privacy.raw_phone_retention_days, onChange: (next) => setSettings({ ...settings, privacy: { ...settings.privacy, raw_phone_retention_days: Number(next || 1) } }) }),
			createElement(Button, { variant: 'secondary', onClick: purge, disabled: busy }, __('Run privacy purge now', 'adaptive-customer-engagement'))
		),
		enrichment: createElement(
			Fragment,
			null,
			createElement(SelectControl, {
				label: __('Provider', 'adaptive-customer-engagement'),
				value: settings.enrichment.provider,
				options: ['none', 'ipregistry', 'ipinfo'].map((entry) => ({ label: entry, value: entry })),
				onChange: (next) => setSettings({ ...settings, enrichment: { ...settings.enrichment, provider: next } }),
			}),
			createElement(TextControl, { label: __('API key', 'adaptive-customer-engagement'), value: settings.enrichment.api_key, onChange: (next) => setSettings({ ...settings, enrichment: { ...settings.enrichment, api_key: next } }) }),
			createElement(TextControl, { label: __('Test lookup IP', 'adaptive-customer-engagement'), value: testIp, onChange: setTestIp }),
			createElement(Button, { variant: 'secondary', onClick: runEnrichmentTest, disabled: busy || !testIp }, __('Run enrichment test', 'adaptive-customer-engagement')),
			testResult && createElement(
				Card,
				{ style: { marginTop: '12px' } },
				createElement(
					CardBody,
					null,
					createElement('p', null, `${__('Provider', 'adaptive-customer-engagement')}: ${testResult.provider || '—'}`),
					createElement('p', null, `${__('Company', 'adaptive-customer-engagement')}: ${testResult.company_name || '—'}`),
					createElement('p', null, `${__('Domain', 'adaptive-customer-engagement')}: ${testResult.company_domain || '—'}`),
					createElement('p', null, `${__('Type', 'adaptive-customer-engagement')}: ${testResult.company_type || '—'}`),
					createElement('p', null, `${__('Location', 'adaptive-customer-engagement')}: ${[testResult.city, testResult.region, testResult.country_code].filter(Boolean).join(', ') || '—'}`),
					createElement('p', null, `${__('Network', 'adaptive-customer-engagement')}: ${testResult.isp || testResult.asn || '—'}`),
					createElement('p', null, `${__('Confidence', 'adaptive-customer-engagement')}: ${testResult.confidence || 'unknown'}`)
				)
			)
		),
		'amazon-connect': createElement(Notice, { status: 'info', isDismissible: false }, __('Amazon Connect support is scaffolded ready for a later implementation pass.', 'adaptive-customer-engagement')),
		'ai-agent': createElement(Notice, { status: 'info', isDismissible: false }, __('The AI agent surface is intentionally placeholder-only in this release.', 'adaptive-customer-engagement')),
	};

	return createElement(
		Fragment,
		null,
		notice && createElement(Notice, { status: 'success', isDismissible: true, onRemove: () => setNotice(null) }, notice),
		sections[section] || sections.settings,
		createElement('div', { style: { marginTop: '16px' } }, createElement(Button, { variant: 'primary', onClick: save, disabled: busy }, __('Save settings', 'adaptive-customer-engagement')))
	);
}

function PlaceholderView({ title, message }) {
	return createElement(Notice, { status: 'info', isDismissible: false }, `${title}: ${message}`);
}

function App() {
	const page = (config.page || 'dashboard').replace(/^ace-/, '');

	switch (page) {
		case 'dashboard':
			return createElement(DashboardView);
		case 'sessions':
			return createElement(SessionsView);
		case 'numbers':
			return createElement(NumbersView);
		case 'settings':
		case 'privacy':
		case 'enrichment':
		case 'amazon-connect':
		case 'ai-agent':
			return createElement(SettingsView, { section: page });
		case 'companies':
			return createElement(CompaniesView);
		case 'commerce':
			return createElement(CommerceView);
		case 'calls':
			return createElement(CallsView);
		default:
			return createElement(PlaceholderView, { title: __('Adaptive Customer Engagement', 'adaptive-customer-engagement'), message: __('This screen is not built yet.', 'adaptive-customer-engagement') });
	}
}

const root = document.getElementById('ace-admin-root');

if (root) {
	render(createElement(App), root);
}
