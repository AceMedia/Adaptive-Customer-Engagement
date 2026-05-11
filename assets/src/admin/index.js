import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, Modal, Notice, SelectControl, Spinner, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import { createElement, Fragment, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { BarChart } from '@mui/x-charts/BarChart';
import { LineChart } from '@mui/x-charts/LineChart';
import { PieChart } from '@mui/x-charts/PieChart';
import { render } from '@wordpress/element';
import './style.scss';

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
	connect_import_only: '',
};
const DASHBOARD_FILTER_DEFAULTS = {
	date_from: '',
	date_to: '',
};
const AUTO_SOURCE_VALUES = {
	website: 'website',
	campaign: 'newsletter',
	google_business_profile: 'google_business_profile',
	bing: 'bing',
	social: 'social',
	product_page: 'product_page',
	brand_page: 'brand_page',
	brochure_qr: 'brochure_qr',
};
const CONNECT_FLOW_DRAFT_DEFAULTS = {
	template_type: 'message_disconnect',
	name: '',
	description: '',
	message: __('Thank you for calling. This number is managed by Adaptive Customer Engagement. Please review this contact flow in Amazon Connect before sending live traffic.', 'adaptive-customer-engagement'),
	queue_id: '',
	queue_flow_id: '',
	failure_message: __('Sorry, nobody is available just now. Please try again later.', 'adaptive-customer-engagement'),
	target_phone_number: '',
	caller_id_number: '',
	timeout_seconds: 30,
	dtmf_sequence: '',
	resume_after_disconnect: false,
	set_as_default: false,
	set_as_chat_flow: false,
};
const CONNECT_ASSISTANT_DRAFT_DEFAULTS = {
	name: __('Sitewide Assistant', 'adaptive-customer-engagement'),
	description: __('Sitewide Amazon Q in Connect assistant for website and call-context support.', 'adaptive-customer-engagement'),
	link_to_ai_agent: true,
};
const LEX_BOT_DRAFT_DEFAULTS = {
	name: 'Site-FAQ-Bot',
	description: __('Amazon Lex V2 bot seeded from the site knowledge base managed inside Adaptive Customer Engagement.', 'adaptive-customer-engagement'),
	role_arn: '',
	locale_id: 'en_GB',
	link_to_settings: true,
};
const NAV_GROUPS = [
	{
		label: __('Overview', 'adaptive-customer-engagement'),
		items: ['dashboard'],
	},
	{
		label: __('Reporting', 'adaptive-customer-engagement'),
		items: ['sessions', 'companies', 'commerce', 'calls'],
	},
	{
		label: __('Setup', 'adaptive-customer-engagement'),
		items: ['settings', 'privacy', 'enrichment', 'amazon-connect', 'numbers', 'ai-agent'],
	},
];
const PAGE_META = {
	dashboard: {
		title: __('Dashboard', 'adaptive-customer-engagement'),
		icon: 'chart-bar',
		description: __('View service activity, priority accounts, call signals, and reporting summaries from one operational dashboard.', 'adaptive-customer-engagement'),
	},
	sessions: {
		title: __('Sessions', 'adaptive-customer-engagement'),
		icon: 'clock',
		description: __('Inspect first-party visit history, segment interesting traffic, and drill into timelines for the people and organisations showing intent.', 'adaptive-customer-engagement'),
	},
	companies: {
		title: __('Companies', 'adaptive-customer-engagement'),
		icon: 'building',
		description: __('Review enriched company records, confidence levels, and priority signals for the organisations appearing in your tracked journeys.', 'adaptive-customer-engagement'),
	},
	commerce: {
		title: __('WooCommerce interest', 'adaptive-customer-engagement'),
		icon: 'cart',
		description: __('Track repeat interest in products and categories so you can spot buying intent across sessions and companies before tying anything back to live orders.', 'adaptive-customer-engagement'),
	},
	calls: {
		title: __('Calls', 'adaptive-customer-engagement'),
		icon: 'phone',
		description: __('Review call intent, stored call records, and matched sessions so the phone journey is visible alongside the rest of the engagement data.', 'adaptive-customer-engagement'),
	},
	numbers: {
		title: __('Phone numbers', 'adaptive-customer-engagement'),
		icon: 'smartphone',
		description: __('Manage the routing rules and tracking numbers used for first-party call attribution across campaigns, product pages, and general site traffic.', 'adaptive-customer-engagement'),
	},
	settings: {
		title: __('Tracking settings', 'adaptive-customer-engagement'),
		icon: 'admin-generic',
		description: __('Configure first-party tracking, cookie behaviour, and call-intent capture settings for the service.', 'adaptive-customer-engagement'),
	},
	privacy: {
		title: __('Privacy', 'adaptive-customer-engagement'),
		icon: 'shield',
		description: __('Manage retention, exclusions, and raw data handling for privacy-aware service operations.', 'adaptive-customer-engagement'),
	},
	enrichment: {
		title: __('IP Enrichment', 'adaptive-customer-engagement'),
		icon: 'search',
		description: __('Connect and manage the enrichment provider used for company, network, and location lookups.', 'adaptive-customer-engagement'),
	},
	'amazon-connect': {
		title: __('Amazon Connect', 'adaptive-customer-engagement'),
		icon: 'networking',
		description: __('Manage Amazon Connect credentials, instance settings, flows, and service readiness for live telephony operations.', 'adaptive-customer-engagement'),
	},
	'ai-agent': {
		title: __('AI agent', 'adaptive-customer-engagement'),
		icon: 'format-chat',
		description: __('Configure Amazon Q in Connect, manage Amazon Lex chat bots, and control the site knowledge shared into assisted service workflows.', 'adaptive-customer-engagement'),
	},
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

function getAutoSourceValue(sourceType) {
	return AUTO_SOURCE_VALUES[sourceType] || '';
}

function shouldReplaceAutoSourceValue(currentValue, previousType) {
	return !currentValue || currentValue === getAutoSourceValue(previousType);
}

function hasConnectConfig(settings = {}) {
	const hasAccessKeys = !!(settings?.access_key_id && settings?.secret_access_key);

	return !!(
		settings?.enabled &&
		settings?.region &&
		settings?.instance_id &&
		(hasAccessKeys || settings?.use_iam_role)
	);
}

function hasEnrichmentConfig(settings = {}) {
	return !!(settings?.provider && settings.provider !== 'none' && settings?.api_key);
}

function isServiceLinkedLexRoleArn(roleArn = '') {
	return roleArn.includes(':role/aws-service-role/lexv2.amazonaws.com/')
		|| roleArn.includes(':role/aws-service-role/channels.lexv2.amazonaws.com/')
		|| roleArn.includes('AWSServiceRoleForLexV2');
}

function getApiErrorMessage(error, fallback) {
	return error?.message
		|| error?.data?.body?.message
		|| error?.data?.body?.Message
		|| fallback;
}

function getHashRoute() {
	const rawHash = (window.location.hash || '').replace(/^#/, '');
	const [section = '', query = ''] = rawHash.split('?');
	const fallback = (config.page || 'dashboard').replace(/^ace-/, '');
	const current = section && PAGE_META[section] ? section : fallback || 'dashboard';

	return {
		section: current,
		params: new URLSearchParams(query),
	};
}

function getQueryParam(key) {
	const route = getHashRoute();

	return route.params.get(key) || new URLSearchParams(window.location.search).get(key) || '';
}

function clearQueryParam(key) {
	const url = new URL(window.location.href);
	const route = getHashRoute();

	route.params.delete(key);
	url.searchParams.delete(key);
	url.hash = route.params.toString() ? `${route.section}?${route.params.toString()}` : route.section;
	window.history.replaceState({}, '', url.toString());
}

function getAdminPageUrl(page, params = {}) {
	const base = config.adminUrl || 'admin.php';
	const url = new URL(base, window.location.origin);
	const hashParams = new URLSearchParams();

	url.searchParams.set('page', 'ace-dashboard');

	Object.entries(params).forEach(([key, value]) => {
		if (value !== undefined && value !== null && value !== '') {
			hashParams.set(key, value);
		}
	});

	url.hash = hashParams.toString() ? `${page}?${hashParams.toString()}` : page;

	return url.toString();
}

function navigateToAdminPage(page, params = {}, options = {}) {
	const nextUrl = new URL(getAdminPageUrl(page, params), window.location.origin);
	const method = options.replace ? 'replaceState' : 'pushState';

	window.history[method]({}, '', nextUrl.toString());
	window.dispatchEvent(new PopStateEvent('popstate'));
}

function getAceSectionFromUrl(urlValue) {
	try {
		const url = new URL(urlValue, window.location.origin);
		const page = (url.searchParams.get('page') || '').replace(/^ace-/, '');
		const hashSection = (url.hash || '').replace(/^#/, '').split('?')[0];

		if (hashSection && PAGE_META[hashSection]) {
			return hashSection;
		}

		if (page && PAGE_META[page]) {
			return page;
		}
	} catch (error) {
		return null;
	}

	return null;
}

function syncWpAdminSidebar(page) {
	const pluginLinks = document.querySelectorAll('#adminmenu a[href*="page=ace-"]');
	const topLevel = document.querySelector('#adminmenu .toplevel_page_ace-dashboard');
	const topLevelLink = topLevel ? topLevel.querySelector('a.menu-top') : null;

	pluginLinks.forEach((link) => {
		const item = link.closest('li');

		if (item) {
			item.classList.remove('current');
		}

		link.classList.remove('current');
		link.removeAttribute('aria-current');
	});

	if (topLevel) {
		topLevel.classList.add('wp-has-current-submenu', 'wp-menu-open', 'current');
	}

	if (topLevelLink) {
		topLevelLink.classList.add('current');
		topLevelLink.setAttribute('aria-current', 'page');
	}

	pluginLinks.forEach((link) => {
		if (getAceSectionFromUrl(link.href) !== page) {
			return;
		}

		const item = link.closest('li');

		if (item) {
			item.classList.add('current');
		}

		link.classList.add('current');
		link.setAttribute('aria-current', 'page');
	});
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

function AdminShell({ page, children }) {
	const meta = PAGE_META[page] || {
		title: __('Adaptive Customer Engagement', 'adaptive-customer-engagement'),
		description: __('Adaptive Customer Engagement admin surface.', 'adaptive-customer-engagement'),
	};

	return createElement(
		'div',
		{ className: 'ace-admin-shell' },
		createElement(
			'aside',
			{ className: 'ace-admin-sidebar' },
			createElement(
				'div',
				{ className: 'ace-admin-brand' },
				config.logoUrl && createElement('img', { src: config.logoUrl, alt: __('Ace Media', 'adaptive-customer-engagement'), className: 'ace-admin-brand__logo' }),
				createElement(
					'div',
					null,
					createElement('h1', { className: 'ace-admin-brand__title' }, __('Adaptive Customer Engagement', 'adaptive-customer-engagement')),
					createElement('p', { className: 'ace-admin-brand__text' }, __('Track visits, calls, companies, and buyer intent in one place.', 'adaptive-customer-engagement'))
				)
			),
			createElement(AdminSidebarNavigation, { page })
		),
		createElement(
			'main',
			{ className: 'ace-admin-main' },
			createElement(
				'div',
				{ className: 'ace-admin-main__inner' },
				createElement(
					'header',
					{ className: 'ace-admin-hero' },
					createElement('h2', { className: 'ace-admin-hero__title' }, meta.title),
					createElement('p', { className: 'ace-admin-hero__description' }, meta.description)
				),
				createElement('div', { className: 'ace-admin-view ace-admin-stack' }, children)
			)
		)
	);
}

function AdminSidebarNavigation({ page }) {
	return createElement(
		'nav',
		{ className: 'ace-admin-nav', 'aria-label': __('Adaptive Customer Engagement navigation', 'adaptive-customer-engagement') },
		NAV_GROUPS.map((group, index) =>
			createElement(
				Fragment,
				{ key: group.label },
				index > 0 && createElement('hr', { className: 'ace-admin-nav__divider' }),
				createElement(
					'div',
					{ className: 'ace-admin-nav__group' },
					createElement('p', { className: 'ace-admin-nav__heading' }, group.label),
					group.items.map((item) =>
						createElement(
							'a',
							{
								key: item,
								className: `ace-admin-nav__link${page === item ? ' is-active' : ''}`,
								href: getAdminPageUrl(item),
							},
							createElement('span', { className: `ace-admin-nav__icon dashicons dashicons-${PAGE_META[item]?.icon || 'admin-generic'}`, 'aria-hidden': 'true' }),
							createElement('span', { className: 'ace-admin-nav__label' }, PAGE_META[item]?.title || item)
						)
					)
				)
			)
		)
	);
}

function SampleDataPanel({ status, busy, onSeed, onReset }) {
	return createElement(
		Card,
		{ className: 'ace-admin-sample-card' },
		createElement(
			CardBody,
			null,
			createElement(
				'div',
				{ className: 'ace-admin-sample-card__header' },
				createElement(
					'div',
					null,
					createElement('h3', { style: { marginTop: 0 } }, __('Local sample data', 'adaptive-customer-engagement')),
					createElement('p', null, status?.description || __('Seed three months of realistic UK business and council activity to preview the reporting UI before going live.', 'adaptive-customer-engagement'))
				),
				createElement(
					'div',
					{ className: 'ace-admin-sample-card__actions' },
					createElement(Button, { variant: 'primary', onClick: onSeed, isBusy: busy, disabled: busy }, __('Seed sample data', 'adaptive-customer-engagement')),
					createElement(Button, { variant: 'secondary', onClick: onReset, disabled: busy || !status?.is_seeded }, __('Remove sample data', 'adaptive-customer-engagement'))
				)
			),
			createElement(
				'div',
				{ className: 'ace-admin-sample-stats' },
				[
					[__('Companies', 'adaptive-customer-engagement'), status?.companies || 0],
					[__('Sessions', 'adaptive-customer-engagement'), status?.sessions || 0],
					[__('Events', 'adaptive-customer-engagement'), status?.events || 0],
					[__('Calls', 'adaptive-customer-engagement'), status?.calls || 0],
					[__('Demo numbers', 'adaptive-customer-engagement'), status?.numbers || 0],
					[__('Live numbers kept separate', 'adaptive-customer-engagement'), status?.live_numbers || 0],
				].map(([label, value]) =>
					createElement(
						'div',
						{ className: 'ace-admin-sample-stats__item', key: label },
						createElement('span', { className: 'ace-admin-sample-stats__label' }, label),
						createElement('strong', { className: 'ace-admin-sample-stats__value' }, value)
					)
				)
			),
			createElement(
				Notice,
				{ status: 'warning', isDismissible: false },
				__('Sample seeding only creates clearly marked demo rows and fictional reserved UK numbers. Removing sample data only removes those marked demo rows and does not delete live tracking rules or real phone numbers.', 'adaptive-customer-engagement')
			),
			status?.first_seen &&
				createElement(
					Notice,
					{ status: 'info', isDismissible: false },
					`${__('Current demo range', 'adaptive-customer-engagement')}: ${status.first_seen} — ${status.last_seen}`
				)
		)
	);
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
					createElement(
						'td',
						null,
						createElement(
							'a',
							{
								href: getAdminPageUrl('sessions', { ace_session: item.id }),
								onClick: (event) => {
									if (onView) {
										event.preventDefault();
										onView(item.id);
									}
								},
							},
							item.session_uuid
						)
					),
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

function buildCountChart(items, getLabel, fallbackLabel = '—', limit = 5) {
	const counts = new Map();

	(items || []).forEach((item) => {
		const key = getLabel(item) || fallbackLabel;
		counts.set(key, (counts.get(key) || 0) + 1);
	});

	const rows = Array.from(counts.entries())
		.map(([label, value]) => ({ label, value }))
		.sort((left, right) => right.value - left.value)
		.slice(0, limit);

	const max = rows.reduce((current, row) => Math.max(current, row.value), 0) || 1;

	return rows.map((row) => ({
		...row,
		percent: Math.max(8, Math.round((row.value / max) * 100)),
	}));
}

function buildTimelineChart(items, key, limit = 7) {
	const counts = new Map();

	(items || []).forEach((item) => {
		const rawValue = item?.[key];
		const dateKey = typeof rawValue === 'string' ? rawValue.slice(0, 10) : '';

		if (!dateKey) {
			return;
		}

		counts.set(dateKey, (counts.get(dateKey) || 0) + 1);
	});

	const rows = Array.from(counts.entries())
		.map(([label, value]) => ({ label, value }))
		.sort((left, right) => left.label.localeCompare(right.label))
		.slice(-limit);

	const max = rows.reduce((current, row) => Math.max(current, row.value), 0) || 1;

	return rows.map((row) => ({
		...row,
		percent: Math.max(14, Math.round((row.value / max) * 100)),
		shortLabel: row.label.slice(5),
	}));
}

function formatDuration(totalSeconds) {
	const seconds = Number(totalSeconds || 0);

	if (!seconds) {
		return '0s';
	}

	const hours = Math.floor(seconds / 3600);
	const minutes = Math.floor((seconds % 3600) / 60);
	const remainder = seconds % 60;
	const parts = [];

	if (hours) {
		parts.push(`${hours}h`);
	}

	if (minutes) {
		parts.push(`${minutes}m`);
	}

	if (!hours && remainder) {
		parts.push(`${remainder}s`);
	}

	return parts.join(' ');
}

function DashboardRankingList({ items, getHref, getLabel, getValueLabel, onNavigate }) {
	if (!items.length) {
		return null;
	}

	return createElement(
		'ol',
		{ className: 'ace-admin-dashboard-ranking' },
		items.map((item) =>
			createElement(
				'li',
				{ className: 'ace-admin-dashboard-ranking__item', key: `${getLabel(item)}-${item.value || item.total || item.label}` },
				createElement(
					'div',
					{ className: 'ace-admin-dashboard-ranking__main' },
					getHref && getHref(item)
						? createElement(
								'a',
								{
									className: 'ace-admin-dashboard-ranking__link',
									href: getHref(item),
									onClick: (event) => {
										event.preventDefault();
										if (onNavigate) {
											onNavigate(item);
										}
									},
								},
								getLabel(item)
						  )
						: createElement('span', { className: 'ace-admin-dashboard-ranking__label' }, getLabel(item)),
					createElement('span', { className: 'ace-admin-dashboard-ranking__value' }, getValueLabel(item))
				)
			)
		)
	);
}

function DashboardBarChartCard({ title, items, emptyMessage, targetPage = 'sessions', getHref, onNavigate }) {
	const chartHeight = Math.max(260, items.length * 44);

	return createElement(
		Card,
		{ className: 'ace-admin-dashboard-chart' },
		createElement(
			CardBody,
			null,
			createElement('h3', { className: 'ace-admin-dashboard-chart__title' }, title),
			items.length
				? createElement(BarChart, {
						className: 'ace-admin-mui-chart',
						dataset: items,
						layout: 'horizontal',
						yAxis: [
							{
								scaleType: 'band',
								dataKey: 'label',
								width: 120,
							},
						],
						xAxis: [
							{
								min: 0,
							},
						],
						series: [
							{
								dataKey: 'value',
								label: title,
								color: '#336699',
								valueFormatter: (value) => `${value || 0}`,
							},
						],
						height: chartHeight,
						margin: { top: 12, right: 20, bottom: 24, left: 8 },
						grid: { vertical: true },
						slotProps: {
							legend: {
								hidden: true,
							},
						},
					})
				: createElement(Notice, { status: 'info', isDismissible: false }, emptyMessage),
			items.length
				? createElement(DashboardRankingList, {
						items,
						getHref: getHref || ((item) => (item.navigateParams ? getAdminPageUrl(targetPage, item.navigateParams) : null)),
						getLabel: (item) => item.label,
						getValueLabel: (item) => `${item.value}`,
						onNavigate:
							onNavigate ||
							((item) => {
								if (item.navigateParams) {
									navigateToAdminPage(targetPage, item.navigateParams);
								}
							}),
					})
				: null
		)
	);
}

function DashboardPieChartCard({ title, items, emptyMessage, valueLabel = __('sessions', 'adaptive-customer-engagement'), targetPage = 'sessions', getHref, onNavigate }) {
	const chartData = items.map((item, index) => ({
		id: index,
		label: item.label,
		value: item.value,
	}));

	return createElement(
		Card,
		{ className: 'ace-admin-dashboard-chart' },
		createElement(
			CardBody,
			null,
			createElement('h3', { className: 'ace-admin-dashboard-chart__title' }, title),
			items.length
				? createElement(PieChart, {
						className: 'ace-admin-mui-chart',
						height: 280,
						margin: { top: 12, right: 140, bottom: 12, left: 12 },
						series: [
							{
								data: chartData,
								innerRadius: 45,
								outerRadius: 90,
								paddingAngle: 2,
								cornerRadius: 4,
								highlightScope: { faded: 'global', highlighted: 'item' },
								faded: { innerRadius: 35, additionalRadius: -6, color: '#dcdcde' },
								valueFormatter: (value) => `${value.value} ${valueLabel}`,
							},
						],
						slotProps: {
							legend: {
								direction: 'column',
								position: { vertical: 'middle', horizontal: 'right' },
							},
						},
					})
				: createElement(Notice, { status: 'info', isDismissible: false }, emptyMessage),
			items.length
				? createElement(DashboardRankingList, {
						items,
						getHref: getHref || ((item) => (item.navigateParams ? getAdminPageUrl(targetPage, item.navigateParams) : null)),
						getLabel: (item) => item.label,
						getValueLabel: (item) => `${item.value}`,
						onNavigate:
							onNavigate ||
							((item) => {
								if (item.navigateParams) {
									navigateToAdminPage(targetPage, item.navigateParams);
								}
							}),
					})
				: null
		)
	);
}

function DashboardTimelineCard({ title, items, emptyMessage, targetPage = 'sessions', getHref, onNavigate }) {
	return createElement(
		Card,
		{ className: 'ace-admin-dashboard-chart' },
		createElement(
			CardBody,
			null,
			createElement('h3', { className: 'ace-admin-dashboard-chart__title' }, title),
			items.length
				? createElement(LineChart, {
						className: 'ace-admin-mui-chart',
						height: 280,
						xAxis: [
							{
								scaleType: 'point',
								data: items.map((item) => item.shortLabel || item.label),
							},
						],
						series: [
							{
								data: items.map((item) => item.value),
								label: __('Sessions', 'adaptive-customer-engagement'),
								color: '#1d3557',
								area: true,
								curve: 'catmullRom',
								valueFormatter: (value) => `${value || 0} ${__('sessions', 'adaptive-customer-engagement')}`,
							},
						],
						margin: { top: 16, right: 20, bottom: 32, left: 40 },
						grid: { horizontal: true },
						slotProps: {
							legend: {
								hidden: true,
							},
						},
					})
				: createElement(Notice, { status: 'info', isDismissible: false }, emptyMessage),
			items.length
				? createElement(DashboardRankingList, {
						items,
						getHref: getHref || ((item) => (item.navigateParams ? getAdminPageUrl(targetPage, item.navigateParams) : null)),
						getLabel: (item) => item.label,
						getValueLabel: (item) => `${item.value}`,
						onNavigate:
							onNavigate ||
							((item) => {
								if (item.navigateParams) {
									navigateToAdminPage(targetPage, item.navigateParams);
								}
							}),
					})
				: null
		)
	);
}

function DetailHeader({ eyebrow, title, description, meta = [], onClose }) {
	return createElement(
		Card,
		{ className: 'ace-admin-detail-hero' },
		createElement(
			CardBody,
			null,
			createElement(
				'div',
				{ className: 'ace-admin-detail-hero__top' },
				createElement(
					'div',
					null,
					createElement('p', { className: 'ace-admin-detail-hero__eyebrow' }, eyebrow),
					createElement('h2', { className: 'ace-admin-detail-hero__title' }, title),
					description ? createElement('p', { className: 'ace-admin-detail-hero__description' }, description) : null
				),
				createElement(Button, { variant: 'secondary', onClick: onClose }, __('Close', 'adaptive-customer-engagement'))
			),
			meta.length
				? createElement(
						'div',
						{ className: 'ace-admin-detail-hero__meta' },
						meta.map((item) =>
							createElement(
								'div',
								{ className: 'ace-admin-detail-hero__meta-item', key: item.label },
								createElement('span', { className: 'ace-admin-detail-hero__meta-label' }, item.label),
								createElement('strong', { className: 'ace-admin-detail-hero__meta-value' }, item.value || '—')
							)
						)
				  )
				: null
		)
	);
}

function DetailMetricGrid({ items }) {
	return createElement(
		'div',
		{ className: 'ace-admin-detail-metrics' },
		items.map((item) =>
			createElement(
				Card,
				{ key: item.label },
				createElement(
					CardBody,
					null,
					createElement('span', { className: 'ace-admin-detail-metric__label' }, item.label),
					createElement('strong', { className: 'ace-admin-detail-metric__value' }, item.value)
				)
			)
		)
	);
}

function DetailBreakdownList({ title, items }) {
	if (!items?.length) {
		return null;
	}

	return createElement(
		Card,
		null,
		createElement(
			CardBody,
			null,
			createElement('h3', { style: { marginTop: 0 } }, title),
			createElement(
				'ol',
				{ className: 'ace-admin-detail-breakdown' },
				items.map((item, index) =>
					createElement(
						'li',
						{ className: 'ace-admin-detail-breakdown__item', key: `${item.label}-${index}` },
						createElement('span', { className: 'ace-admin-detail-breakdown__label' }, item.label),
						createElement('strong', { className: 'ace-admin-detail-breakdown__value' }, `${item.points > 0 ? '+' : ''}${item.points}`)
					)
				)
			)
		)
	);
}

function DashboardView({ active }) {
	const [data, setData] = useState(null);
	const [busySample, setBusySample] = useState(false);
	const [filters, setFilters] = useState(DASHBOARD_FILTER_DEFAULTS);

	const load = (nextFilters = filters) => request(withQuery('/admin/dashboard', nextFilters)).then(setData);

	useEffect(() => {
		if (active) {
			load(filters);
		}
	}, [active]);

	if (!data) {
		return createElement(Spinner);
	}

	const numberItems = data.numbers || [];
	const liveNumberItems = numberItems.filter((item) => !item.is_sample);
	const cards = [
		['Sessions', data.metrics.sessions],
		['Returning sessions', data.metrics.returning_sessions],
		['Likely business visits', data.metrics.likely_business_visits],
		['Click-to-call events', data.metrics.click_to_call_events],
		['Download events', data.metrics.download_events],
		['Form submissions', data.metrics.form_submissions],
		['Stored calls', data.metrics.stored_calls],
		['Matched calls', data.metrics.matched_calls],
	];
	const scoreMix = buildCountChart(data.recent_sessions, (item) => item.score_label || __('Unscored', 'adaptive-customer-engagement'), __('Unscored', 'adaptive-customer-engagement'));
	const sourceMix = (data.top_sources || []).map((item) => ({
		label: item.source_label,
		value: Number(item.total || 0),
		navigateParams: { source: item.source_key },
	}));
	const activityTimeline = buildTimelineChart(data.recent_sessions, 'last_seen');
	const topPagesChart = (data.top_pages || []).slice(0, 6).map((item) => ({ label: item.path || '/', value: item.total || 0, navigateParams: { search: item.path || '/' } }));
	const callStatusMix = buildCountChart(data.recent_calls || [], (item) => item.status || __('Unknown', 'adaptive-customer-engagement'), __('Unknown', 'adaptive-customer-engagement'), 6);
	const callActivityTimeline = buildTimelineChart(data.recent_calls || [], 'started_at', 8);
	const topCallPaths = (data.top_call_paths || []).map((page) => ({
		label: page.path || '/',
		value: Number(page.total || 0),
		navigateParams: { search: page.path || '/' },
	}));
	const numberSourceMix = buildCountChart(liveNumberItems, (item) => item.source_type || __('Unknown', 'adaptive-customer-engagement'), __('Unknown', 'adaptive-customer-engagement'), 8);
	const numberStatusMix = buildCountChart(liveNumberItems, (item) => item.is_active ? __('Active', 'adaptive-customer-engagement') : __('Inactive', 'adaptive-customer-engagement'), __('Inactive', 'adaptive-customer-engagement'), 4);
	const numberMatchTypeMix = buildCountChart(liveNumberItems.filter((item) => item.page_match_value), (item) => item.page_match_type || __('contains', 'adaptive-customer-engagement'), __('contains', 'adaptive-customer-engagement'), 6);
	const topPageMax = topPagesChart.reduce((current, row) => Math.max(current, row.value), 0) || 1;
	const topPages = topPagesChart.map((row) => ({
		...row,
		percent: Math.max(8, Math.round((row.value / topPageMax) * 100)),
	}));
	const topCallPathMax = topCallPaths.reduce((current, row) => Math.max(current, row.value), 0) || 1;
	const rankedCallPaths = topCallPaths.map((row) => ({
		...row,
		percent: Math.max(8, Math.round((row.value / topCallPathMax) * 100)),
	}));
	const sourceMax = sourceMix.reduce((current, row) => Math.max(current, row.value), 0) || 1;
	const rankedSources = sourceMix.map((row) => ({
		...row,
		percent: Math.max(8, Math.round((row.value / sourceMax) * 100)),
	}));

	return createElement(
		Fragment,
		null,
		createElement(SampleDataPanel, {
			status: data.sample_data || {},
			busy: busySample,
			onSeed: async () => {
				setBusySample(true);
				await request('/admin/sample-data', { method: 'POST' });
				await load();
				setBusySample(false);
			},
			onReset: async () => {
				setBusySample(true);
				await request('/admin/sample-data', { method: 'DELETE' });
				await load();
				setBusySample(false);
			},
		}),
		createElement(FilterPanel, {
			filters,
			showSearch: false,
			onChange: setFilters,
			onApply: () => load(filters),
			onReset: () => {
				const reset = { ...DASHBOARD_FILTER_DEFAULTS };
				setFilters(reset);
				load(reset);
			},
		}),
		createElement(
			'div',
			{ className: 'ace-admin-dashboard-metrics' },
			cards.map(([label, value]) =>
				createElement(
					Card,
					{ key: label },
					createElement(CardBody, null, createElement('strong', null, __(label, 'adaptive-customer-engagement')), createElement('div', { style: { fontSize: '24px', marginTop: '8px' } }, value))
				)
			)
		),
		createElement('h2', null, __('Session activity', 'adaptive-customer-engagement')),
		createElement(
			'div',
			{ className: 'ace-admin-dashboard-charts' },
			createElement(DashboardBarChartCard, {
				title: __('Top pages', 'adaptive-customer-engagement'),
				items: topPages,
				targetPage: 'sessions',
				emptyMessage: __('No page activity has been recorded yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardBarChartCard, {
				title: __('Traffic sources', 'adaptive-customer-engagement'),
				items: rankedSources,
				targetPage: 'sessions',
				emptyMessage: __('No source data is available yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardPieChartCard, {
				title: __('Session score mix', 'adaptive-customer-engagement'),
				items: scoreMix,
				emptyMessage: __('No scored sessions are available yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardTimelineCard, {
				title: __('Recent session activity', 'adaptive-customer-engagement'),
				items: activityTimeline,
				emptyMessage: __('No recent activity is available yet.', 'adaptive-customer-engagement'),
			})
		),
		createElement('h2', null, __('Call activity', 'adaptive-customer-engagement')),
		createElement(
			'div',
			{ className: 'ace-admin-dashboard-charts' },
			createElement(DashboardBarChartCard, {
				title: __('Top call-intent pages', 'adaptive-customer-engagement'),
				items: rankedCallPaths,
				targetPage: 'sessions',
				emptyMessage: __('No call-intent paths recorded yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardPieChartCard, {
				title: __('Stored call status mix', 'adaptive-customer-engagement'),
				items: callStatusMix,
				valueLabel: __('calls', 'adaptive-customer-engagement'),
				emptyMessage: __('No stored call records are available yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardTimelineCard, {
				title: __('Recent stored call activity', 'adaptive-customer-engagement'),
				items: callActivityTimeline,
				targetPage: 'calls',
				emptyMessage: __('No stored call activity is available yet.', 'adaptive-customer-engagement'),
			})
		),
		createElement('h2', null, __('Number setup overview', 'adaptive-customer-engagement')),
		createElement(
			'div',
			{ className: 'ace-admin-dashboard-charts' },
			createElement(DashboardBarChartCard, {
				title: __('Number source types', 'adaptive-customer-engagement'),
				items: numberSourceMix,
				targetPage: 'numbers',
				emptyMessage: __('No tracked numbers are available yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardPieChartCard, {
				title: __('Number status mix', 'adaptive-customer-engagement'),
				items: numberStatusMix,
				valueLabel: __('numbers', 'adaptive-customer-engagement'),
				targetPage: 'numbers',
				emptyMessage: __('No tracked numbers are available yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardBarChartCard, {
				title: __('Page rule match types', 'adaptive-customer-engagement'),
				items: numberMatchTypeMix,
				targetPage: 'numbers',
				emptyMessage: __('No page routing rules are available yet.', 'adaptive-customer-engagement'),
			})
		),
		createElement(DashboardSegmentsPanel, { shortcuts: data.segment_shortcuts || {} }),
		createElement('h2', null, __('Recent sessions', 'adaptive-customer-engagement')),
		createElement(SessionsTable, {
			items: data.recent_sessions || [],
			onView: (id) => {
				navigateToAdminPage('sessions', { ace_session: id });
			},
		}),
		createElement('h2', null, __('Hot companies', 'adaptive-customer-engagement')),
		createElement(CompaniesTable, {
			items: data.hot_companies || [],
			onView: (id) => {
				navigateToAdminPage('companies', { ace_company: id });
			},
			compact: true,
		})
	);
}

function CallsView({ active, route }) {
	const [data, setData] = useState(null);
	const [detail, setDetail] = useState(null);
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
		if (active) {
			load(filters);
		}
	}, [active]);

	useEffect(() => {
		const callId = route?.params?.get('ace_call');

		if (!active) {
			return;
		}

		if (!callId) {
			setDetail(null);
			return;
		}

		request(`/admin/calls/${callId}`).then(setDetail);
	}, [active, route]);

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

	if (detail) {
		return createElement(CallDetailPanel, {
			detail,
			onClose: () => {
				setDetail(null);
				clearQueryParam('ace_call');
			},
		});
	}

	const cards = [
		['Click-to-call today', data.metrics.click_to_call_today],
		['Stored calls today', data.metrics.stored_calls_today],
		['Matched calls today', data.metrics.matched_calls_today],
		['Stored calls total', data.metrics.stored_calls_total],
		['Matched calls total', data.metrics.matched_calls_total],
		['Imported Connect calls', data.metrics.connect_imported_total],
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
			null,
			createElement(
				CardBody,
				null,
				createElement('div', { style: { display: 'grid', gap: '8px' } },
					createElement(ToggleControl, {
						label: __('Only show matched calls', 'adaptive-customer-engagement'),
						checked: !!filters.match_only,
						onChange: (next) => setFilters({ ...filters, match_only: next ? '1' : '' }),
					}),
					createElement(ToggleControl, {
						label: __('Only show imported Connect calls', 'adaptive-customer-engagement'),
						checked: !!filters.connect_import_only,
						onChange: (next) => setFilters({ ...filters, connect_import_only: next ? '1' : '' }),
					})
				)
			)
		),
		createElement(ExportPanel, {
			label: __('Export current calls', 'adaptive-customer-engagement'),
			href: getExportUrl('ace_export_calls', filters),
		}),
		createElement('h2', null, __('Recent call-intent sessions', 'adaptive-customer-engagement')),
		createElement(SessionsTable, {
			items: data.call_intent_sessions || [],
			onView: (id) => {
				navigateToAdminPage('sessions', { ace_session: id });
			},
		}),
		createElement('h2', null, __('Stored calls', 'adaptive-customer-engagement')),
		createElement(CallsTable, {
			items: data.recent_calls || [],
			onView: (id) => navigateToAdminPage('calls', { ace_call: id }),
			onNumberView: (id) => navigateToAdminPage('numbers', { ace_number: id }),
			onSessionView: (id) => navigateToAdminPage('sessions', { ace_session: id }),
			onCompanyView: (id) => navigateToAdminPage('companies', { ace_company: id }),
		})
	);
}

function WooCommerceInterestTable({ items, type = 'product', onNavigate }) {
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
					createElement(
						'td',
						null,
						onNavigate
							? createElement(
								'a',
								{
									href: getAdminPageUrl('commerce', { search: item.slug || item.name || '' }),
									onClick: (event) => {
										event.preventDefault();
										onNavigate(item);
									},
								},
								item.name || '—'
							)
							: (item.name || '—')
					),
					createElement(
						'td',
						null,
						onNavigate
							? createElement(
								'a',
								{
									href: getAdminPageUrl('commerce', { search: item.slug || item.name || '' }),
									onClick: (event) => {
										event.preventDefault();
										onNavigate(item);
									},
								},
								item.slug || '—'
							)
							: (item.slug || '—')
					),
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

function CommerceView({ active }) {
	const [data, setData] = useState(null);
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
		if (active) {
			load(filters);
		}
	}, [active]);

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
		createElement(WooCommerceInterestTable, {
			items: data.top_products || [],
			type: 'product',
			onNavigate: (item) => navigateToAdminPage('commerce', { search: item.slug || item.name || '', repeat_only: filters.repeat_only || '1' }),
		}),
		createElement('h2', null, __('Top repeated categories', 'adaptive-customer-engagement')),
		createElement(WooCommerceInterestTable, {
			items: data.top_categories || [],
			type: 'category',
			onNavigate: (item) => navigateToAdminPage('commerce', { search: item.slug || item.name || '', repeat_only: filters.repeat_only || '1' }),
		}),
		createElement('h2', null, __('Sessions showing WooCommerce interest', 'adaptive-customer-engagement')),
		createElement(SessionsTable, {
			items: data.repeat_sessions || [],
			onView: (id) => {
				navigateToAdminPage('sessions', { ace_session: id });
			},
		}),
		createElement('h2', null, __('Companies showing WooCommerce interest', 'adaptive-customer-engagement')),
		createElement(CompaniesTable, {
			items: data.repeat_companies || [],
			onView: (id) => {
				navigateToAdminPage('companies', { ace_company: id });
			},
		})
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
		createElement('h2', null, __('Saved segments', 'adaptive-customer-engagement')),
		createElement(
			'div',
			{ style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))', gap: '12px' } },
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

function SessionsView({ active, route }) {
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
		if (active) {
			load(filters, pagination.page);
		}
	}, [active]);

	useEffect(() => {
		const sessionId = route?.params?.get('ace_session');

		if (!active) {
			return;
		}

		if (!sessionId) {
			setDetail(null);
			return;
		}

		request(`/admin/sessions/${sessionId}`).then(setDetail);
	}, [active, route]);

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

	if (detail) {
		return createElement(SessionDetailPanel, {
			detail,
			onClose: () => {
				setDetail(null);
				clearQueryParam('ace_session');
			},
		});
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
			onView: (id) => {
				navigateToAdminPage('sessions', { ace_session: id });
			},
		}),
		createElement(PaginationControls, {
			pagination,
			onPageChange: (page) => load(filters, page),
		}),
	);
}

function SessionDetailPanel({ detail, onClose }) {
	const session = detail?.session || {};
	const events = detail?.events || [];
	const calls = detail?.calls || [];
	const pageviewCount = events.filter((item) => item.event_type === 'pageview').length;
	const distinctPaths = new Set(events.map((item) => item.path).filter(Boolean)).size;
	const eventMix = buildCountChart(events, (item) => item.event_name || (item.event_type || 'event').replace(/_/g, ' '), __('Event', 'adaptive-customer-engagement'), 6);
	const pathMix = buildCountChart(events.filter((item) => item.path), (item) => item.path || '/', '/', 6).map((item) => ({
		...item,
		navigateParams: { search: item.label || '/' },
	}));
	const callStatusMix = buildCountChart(calls, (item) => item.status || __('Unknown', 'adaptive-customer-engagement'), __('Unknown', 'adaptive-customer-engagement'), 6);
	const scoreBreakdownChart = (session.score_breakdown || []).map((item) => ({
		label: item.label,
		value: Math.abs(Number(item.points || 0)),
	}));
	const detailMetrics = [
		{ label: __('Events', 'adaptive-customer-engagement'), value: session.event_count || events.length || 0 },
		{ label: __('Page views', 'adaptive-customer-engagement'), value: pageviewCount },
		{ label: __('Call clicks', 'adaptive-customer-engagement'), value: session.call_clicks || 0 },
		{ label: __('Downloads', 'adaptive-customer-engagement'), value: session.download_count || 0 },
		{ label: __('Form submits', 'adaptive-customer-engagement'), value: session.form_count || 0 },
		{ label: __('Paths touched', 'adaptive-customer-engagement'), value: distinctPaths },
		{ label: __('Matched calls', 'adaptive-customer-engagement'), value: calls.length },
	];

	return createElement(
		'div',
		{ className: 'ace-admin-detail' },
		createElement(DetailHeader, {
			eyebrow: __('Session detail', 'adaptive-customer-engagement'),
			title: session.session_uuid || __('Session', 'adaptive-customer-engagement'),
			description: session.score_summary || __('Review the activity sequence, score signals, and buying-intent context for this visit.', 'adaptive-customer-engagement'),
			meta: [
				{ label: __('Score', 'adaptive-customer-engagement'), value: `${session.score || 0} (${session.score_label || 'noise'})` },
				{ label: __('Company', 'adaptive-customer-engagement'), value: session.company_name || '—' },
				{ label: __('Source', 'adaptive-customer-engagement'), value: session.utm_source || __('Direct / none', 'adaptive-customer-engagement') },
				{ label: __('Last seen', 'adaptive-customer-engagement'), value: session.last_seen || '—' },
			],
			onClose,
		}),
		createElement(DetailMetricGrid, { items: detailMetrics }),
		createElement(
			'div',
			{ className: 'ace-admin-dashboard-charts ace-admin-detail-charts' },
			createElement(DashboardPieChartCard, {
				title: __('Event mix', 'adaptive-customer-engagement'),
				items: eventMix,
				valueLabel: __('events', 'adaptive-customer-engagement'),
				emptyMessage: __('No events have been recorded for this session yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardBarChartCard, {
				title: __('Touched paths', 'adaptive-customer-engagement'),
				items: pathMix,
				targetPage: 'sessions',
				emptyMessage: __('No paths have been recorded for this session yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardBarChartCard, {
				title: __('Score breakdown', 'adaptive-customer-engagement'),
				items: scoreBreakdownChart,
				emptyMessage: __('No score breakdown is available for this session yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardPieChartCard, {
				title: __('Matched call status mix', 'adaptive-customer-engagement'),
				items: callStatusMix,
				valueLabel: __('calls', 'adaptive-customer-engagement'),
				emptyMessage: __('No matched calls are available for this session yet.', 'adaptive-customer-engagement'),
			})
		),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Session facts', 'adaptive-customer-engagement')),
				createElement(
					'table',
					{ className: 'widefat striped', style: { marginBottom: 0 } },
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
				)
			)
		),
		createElement(DetailBreakdownList, {
			title: __('Score factors', 'adaptive-customer-engagement'),
			items: session.score_breakdown || [],
		}),
		createElement(InterestSummaryPanel, { title: __('WooCommerce interest', 'adaptive-customer-engagement'), commerce: detail?.commerce }),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Matched calls', 'adaptive-customer-engagement')),
				createElement(CallsTable, {
					items: calls,
					onView: (id) => navigateToAdminPage('calls', { ace_call: id }),
					onNumberView: (id) => navigateToAdminPage('numbers', { ace_number: id }),
					onSessionView: (id) => navigateToAdminPage('sessions', { ace_session: id }),
					onCompanyView: (id) => navigateToAdminPage('companies', { ace_company: id }),
				})
			)
		),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Timeline', 'adaptive-customer-engagement')),
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
					createElement(
						'td',
						null,
						onView
							? createElement(
								'a',
								{
									href: getAdminPageUrl('companies', { ace_company: item.id }),
									onClick: (event) => {
										event.preventDefault();
										onView(item.id);
									},
								},
								item.name || '—'
							)
							: (item.name || '—')
					),
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
	const recentCalls = detail?.recent_calls || [];
	const sourceMix = buildCountChart(sessions, (item) => item.utm_source || __('Direct / none', 'adaptive-customer-engagement'), __('Direct / none', 'adaptive-customer-engagement'), 6).map((item) => ({
		...item,
		navigateParams: { source: item.label === __('Direct / none', 'adaptive-customer-engagement') ? '__direct__' : item.label },
	}));
	const scoreMix = buildCountChart(sessions, (item) => item.score_label || __('Unscored', 'adaptive-customer-engagement'), __('Unscored', 'adaptive-customer-engagement'), 6);
	const activityTimeline = buildTimelineChart(sessions, 'last_seen', 8);
	const callStatusMix = buildCountChart(recentCalls, (item) => item.status || __('Unknown', 'adaptive-customer-engagement'), __('Unknown', 'adaptive-customer-engagement'), 6);
	const priorityBreakdownChart = (detail.priority_breakdown || []).map((item) => ({
		label: item.label,
		value: Math.abs(Number(item.points || 0)),
	}));
	const detailMetrics = [
		{ label: __('Sessions', 'adaptive-customer-engagement'), value: detail.total_sessions || 0 },
		{ label: __('Events', 'adaptive-customer-engagement'), value: detail.total_events || 0 },
		{ label: __('Calls', 'adaptive-customer-engagement'), value: detail.total_calls || 0 },
		{ label: __('Recent sessions loaded', 'adaptive-customer-engagement'), value: sessions.length },
		{ label: __('Recent calls loaded', 'adaptive-customer-engagement'), value: recentCalls.length },
		{ label: __('Priority', 'adaptive-customer-engagement'), value: `${detail.priority_score || 0} (${detail.priority_label || 'noise'})` },
		{ label: __('Confidence', 'adaptive-customer-engagement'), value: detail.confidence || 'unknown' },
	];

	return createElement(
		'div',
		{ className: 'ace-admin-detail' },
		createElement(DetailHeader, {
			eyebrow: __('Company detail', 'adaptive-customer-engagement'),
			title: detail.name || __('Company', 'adaptive-customer-engagement'),
			description: detail.priority_summary || __('Review the recent sessions, score signals, and commercial context for this company record.', 'adaptive-customer-engagement'),
			meta: [
				{ label: __('Domain', 'adaptive-customer-engagement'), value: detail.domain || '—' },
				{ label: __('Type', 'adaptive-customer-engagement'), value: detail.type || '—' },
				{ label: __('Provider', 'adaptive-customer-engagement'), value: detail.source_provider || '—' },
				{ label: __('Last seen', 'adaptive-customer-engagement'), value: detail.last_seen || '—' },
			],
			onClose,
		}),
		createElement(DetailMetricGrid, { items: detailMetrics }),
		createElement(
			'div',
			{ className: 'ace-admin-dashboard-charts ace-admin-detail-charts' },
			createElement(DashboardBarChartCard, {
				title: __('Traffic sources', 'adaptive-customer-engagement'),
				items: sourceMix,
				targetPage: 'sessions',
				emptyMessage: __('No source data is available for this company yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardPieChartCard, {
				title: __('Session score mix', 'adaptive-customer-engagement'),
				items: scoreMix,
				emptyMessage: __('No scored sessions are available for this company yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardTimelineCard, {
				title: __('Recent session activity', 'adaptive-customer-engagement'),
				items: activityTimeline,
				emptyMessage: __('No recent activity is available for this company yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardBarChartCard, {
				title: __('Priority breakdown', 'adaptive-customer-engagement'),
				items: priorityBreakdownChart,
				emptyMessage: __('No priority breakdown is available for this company yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardPieChartCard, {
				title: __('Recent call status mix', 'adaptive-customer-engagement'),
				items: callStatusMix,
				valueLabel: __('calls', 'adaptive-customer-engagement'),
				emptyMessage: __('No recent matched calls are available for this company yet.', 'adaptive-customer-engagement'),
			})
		),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Company facts', 'adaptive-customer-engagement')),
				createElement(
					'table',
					{ className: 'widefat striped', style: { marginBottom: 0 } },
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
				)
			)
		),
		createElement(DetailBreakdownList, {
			title: __('Priority factors', 'adaptive-customer-engagement'),
			items: detail.priority_breakdown || [],
		}),
		createElement(InterestSummaryPanel, { title: __('WooCommerce interest', 'adaptive-customer-engagement'), commerce: detail?.commerce }),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Recent matched calls', 'adaptive-customer-engagement')),
				createElement(CallsTable, {
					items: recentCalls,
					onView: (id) => navigateToAdminPage('calls', { ace_call: id }),
					onNumberView: (id) => navigateToAdminPage('numbers', { ace_number: id }),
					onSessionView: (id) => navigateToAdminPage('sessions', { ace_session: id }),
					onCompanyView: (id) => navigateToAdminPage('companies', { ace_company: id }),
				})
			)
		),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Recent sessions', 'adaptive-customer-engagement')),
				createElement(SessionsTable, {
					items: sessions,
					onView: (id) => navigateToAdminPage('sessions', { ace_session: id }),
				})
			)
		)
	);
}

function CallsTable({ items, onView, onNumberView, onSessionView, onCompanyView }) {
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
				['When', 'Status', 'Source', 'Called number', 'Tracking number', 'Company', 'Session', 'Duration', 'Match confidence', 'Actions'].map((label) =>
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
					createElement(
						'td',
						null,
						onView
							? createElement(
								'a',
								{
									href: getAdminPageUrl('calls', { ace_call: item.id }),
									onClick: (event) => {
										event.preventDefault();
										onView(item.id);
									},
								},
								item.started_at || '—'
							)
							: (item.started_at || '—')
					),
					createElement('td', null, item.status || '—'),
					createElement('td', null, item.is_connect_import ? __('Amazon Connect import', 'adaptive-customer-engagement') : __('Local record', 'adaptive-customer-engagement')),
					createElement('td', null, item.called_number || '—'),
					createElement(
						'td',
						null,
						item.number_id && onNumberView
							? createElement(
								'a',
								{
									href: getAdminPageUrl('numbers', { ace_number: item.number_id }),
									onClick: (event) => {
										event.preventDefault();
										onNumberView(item.number_id);
									},
								},
								item.number_label || '—'
							)
							: (item.number_label || '—')
					),
					createElement(
						'td',
						null,
						item.matched_company_id && onCompanyView
							? createElement(
								'a',
								{
									href: getAdminPageUrl('companies', { ace_company: item.matched_company_id }),
									onClick: (event) => {
										event.preventDefault();
										onCompanyView(item.matched_company_id);
									},
								},
								item.company_name || '—'
							)
							: (item.company_name || '—')
					),
					createElement(
						'td',
						null,
						item.matched_session_id && onSessionView
							? createElement(
								'a',
								{
									href: getAdminPageUrl('sessions', { ace_session: item.matched_session_id }),
									onClick: (event) => {
										event.preventDefault();
										onSessionView(item.matched_session_id);
									},
								},
								item.session_uuid || '—'
							)
							: (item.session_uuid || '—')
					),
					createElement('td', null, formatDuration(item.duration_seconds)),
					createElement('td', null, item.match_confidence || 'unknown'),
					createElement(
						'td',
						null,
						onView ? createElement(Button, { variant: 'secondary', onClick: () => onView(item.id) }, __('View', 'adaptive-customer-engagement')) : '—'
					)
				)
			)
		)
	);
}

function CallDetailPanel({ detail, onClose }) {
	const call = detail?.call || {};
	const numberCalls = detail?.number_calls || [];
	const sessionEvents = detail?.session_events || [];
	const statusMix = buildCountChart(numberCalls, (item) => item.status || __('Unknown', 'adaptive-customer-engagement'), __('Unknown', 'adaptive-customer-engagement'), 6);
	const companyMix = buildCountChart(numberCalls.filter((item) => item.company_name), (item) => item.company_name || __('Unknown', 'adaptive-customer-engagement'), __('Unknown', 'adaptive-customer-engagement'), 6);
	const activityTimeline = buildTimelineChart(numberCalls, 'started_at', 8);
	const matchedSessionMix = buildCountChart(sessionEvents, (item) => item.event_name || item.event_type || __('Event', 'adaptive-customer-engagement'), __('Event', 'adaptive-customer-engagement'), 6);
	const matchedCalls = numberCalls.filter((item) => item.matched_session_id || item.matched_company_id).length;
	const detailMetrics = [
		{ label: __('Duration', 'adaptive-customer-engagement'), value: formatDuration(call.duration_seconds) },
		{ label: __('Match confidence', 'adaptive-customer-engagement'), value: call.match_confidence || 'unknown' },
		{ label: __('Calls on this number', 'adaptive-customer-engagement'), value: numberCalls.length },
		{ label: __('Matched calls on this number', 'adaptive-customer-engagement'), value: matchedCalls },
		{ label: __('Matched session events', 'adaptive-customer-engagement'), value: sessionEvents.length },
		{ label: __('Status', 'adaptive-customer-engagement'), value: call.status || '—' },
	];

	return createElement(
		'div',
		{ className: 'ace-admin-detail' },
		createElement(DetailHeader, {
			eyebrow: __('Call detail', 'adaptive-customer-engagement'),
			title: call.started_at || __('Stored call', 'adaptive-customer-engagement'),
			description: call.company_name
				? `${__('Review the routing, matching, and surrounding number activity for the call associated with', 'adaptive-customer-engagement')} ${call.company_name}.`
				: __('Review the routing, matching, and surrounding number activity for this stored call.', 'adaptive-customer-engagement'),
			meta: [
				{ label: __('Tracking number', 'adaptive-customer-engagement'), value: call.number_label || call.tracking_display_number || '—' },
				{ label: __('Company', 'adaptive-customer-engagement'), value: call.company_name || '—' },
				{ label: __('Session', 'adaptive-customer-engagement'), value: call.session_uuid || '—' },
				{ label: __('Started', 'adaptive-customer-engagement'), value: call.started_at || '—' },
			],
			onClose,
		}),
		createElement(DetailMetricGrid, { items: detailMetrics }),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Linked records', 'adaptive-customer-engagement')),
				createElement(
					'div',
					{ style: { display: 'flex', gap: '8px', flexWrap: 'wrap' } },
					call.number_id ? createElement(Button, { variant: 'secondary', onClick: () => navigateToAdminPage('numbers', { ace_number: call.number_id }) }, __('View tracking number', 'adaptive-customer-engagement')) : null,
					call.matched_session_id ? createElement(Button, { variant: 'secondary', onClick: () => navigateToAdminPage('sessions', { ace_session: call.matched_session_id }) }, __('View matched session', 'adaptive-customer-engagement')) : null,
					call.matched_company_id ? createElement(Button, { variant: 'secondary', onClick: () => navigateToAdminPage('companies', { ace_company: call.matched_company_id }) }, __('View matched company', 'adaptive-customer-engagement')) : null
				)
			)
		),
		createElement(
			'div',
			{ className: 'ace-admin-dashboard-charts ace-admin-detail-charts' },
			createElement(DashboardPieChartCard, {
				title: __('Number call status mix', 'adaptive-customer-engagement'),
				items: statusMix,
				valueLabel: __('calls', 'adaptive-customer-engagement'),
				emptyMessage: __('No number activity is available for this call yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardBarChartCard, {
				title: __('Companies reached on this number', 'adaptive-customer-engagement'),
				items: companyMix,
				emptyMessage: __('No company matches are available for this number yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardTimelineCard, {
				title: __('Recent call activity on this number', 'adaptive-customer-engagement'),
				items: activityTimeline,
				emptyMessage: __('No number activity is available for this call yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardPieChartCard, {
				title: __('Matched session event mix', 'adaptive-customer-engagement'),
				items: matchedSessionMix,
				valueLabel: __('events', 'adaptive-customer-engagement'),
				emptyMessage: __('No matched session activity is available for this call yet.', 'adaptive-customer-engagement'),
			})
		),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Call facts', 'adaptive-customer-engagement')),
				createElement(
					'table',
					{ className: 'widefat striped', style: { marginBottom: 0 } },
					createElement(
						'tbody',
						null,
						[
							['Call UUID', call.call_uuid || '—'],
							['Amazon contact ID', call.amazon_contact_id || '—'],
							['Source', call.is_connect_import ? __('Amazon Connect import', 'adaptive-customer-engagement') : __('Local record', 'adaptive-customer-engagement')],
							['Import object', call.connect_import_s3_key || '—'],
							['Import channel', call.connect_import_channel || '—'],
							['Called number', call.called_number || '—'],
							['Tracking display number', call.tracking_display_number || '—'],
							['Tracking E.164', call.tracking_e164_number || '—'],
							['Status', call.status || '—'],
							['Duration', formatDuration(call.duration_seconds)],
							['Queue', call.queue_name || '—'],
							['Agent', call.agent_name || '—'],
							['Match confidence', call.match_confidence || 'unknown'],
							['Source type', call.number_source_type || '—'],
							['Page rule', call.page_match_value || '—'],
							['Campaign rule', call.campaign_match || '—'],
							['Started', call.started_at || '—'],
							['Ended', call.ended_at || '—'],
						].map(([label, value]) => createElement('tr', { key: label }, createElement('th', null, __(label, 'adaptive-customer-engagement')), createElement('td', null, value)))
					)
				)
			)
		),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Recent calls on this number', 'adaptive-customer-engagement')),
				createElement(CallsTable, {
					items: numberCalls,
					onView: (id) => navigateToAdminPage('calls', { ace_call: id }),
					onSessionView: (id) => navigateToAdminPage('sessions', { ace_session: id }),
					onCompanyView: (id) => navigateToAdminPage('companies', { ace_company: id }),
				})
			)
		)
	);
}

function mapNumberToForm(item = {}) {
	return {
		id: item.id,
		label: item.label || '',
		display_number: item.display_number || '',
		e164_number: item.e164_number || '',
		source_type: item.source_type || 'default',
		source_value: item.source_value || '',
		page_match_type: item.page_match_type || 'contains',
		page_match_value: item.page_match_value || '',
		campaign_match: item.campaign_match || '',
		priority: Number(item.priority || 0),
		is_default: !!item.is_default,
		is_active: !!item.is_active,
		amazon_connect_phone_number_id: item.amazon_connect_phone_number_id || '',
		amazon_connect_contact_flow_id: item.amazon_connect_contact_flow_id || '',
	};
}

function buildNumberDraftFromConnectNumber(item, empty, defaultContactFlowId = '') {
	return {
		...empty,
		label: item.PhoneNumberDescription || item.PhoneNumber || '',
		display_number: item.PhoneNumber || '',
		e164_number: item.PhoneNumber || '',
		source_type: 'default',
		source_value: '',
		page_match_type: 'contains',
		page_match_value: '',
		campaign_match: '',
		priority: 10,
		is_default: false,
		is_active: true,
		amazon_connect_phone_number_id: item.PhoneNumberId || '',
		amazon_connect_contact_flow_id: defaultContactFlowId || '',
	};
}

async function createLinkedConnectTrackingRule({ connectItem, empty, load, loadConnectResources, defaultContactFlowId = '' }) {
	const payload = buildNumberDraftFromConnectNumber(connectItem, empty, defaultContactFlowId);
	const created = await request('/admin/numbers', {
		method: 'POST',
		data: payload,
	});

	await load();
	await loadConnectResources();

	return created;
}

function UnifiedNumbersTable({ localItems, connectItems, connectError, onView, onEdit, onDelete, onImport, busy }) {
	const localList = localItems || [];
	const connectList = connectItems || [];
	const rows = [];
	const matchedConnectIds = new Set();

	localList.forEach((item) => {
		const connectItem = item.amazon_connect_phone_number_id ? connectList.find((entry) => entry.PhoneNumberId === item.amazon_connect_phone_number_id) : null;

		if (connectItem?.PhoneNumberId) {
			matchedConnectIds.add(connectItem.PhoneNumberId);
		}

		rows.push({
			key: `local-${item.id}`,
			localItem: item,
			connectItem,
		});
	});

	connectList.forEach((item) => {
		if (matchedConnectIds.has(item.PhoneNumberId)) {
			return;
		}

		rows.push({
			key: `connect-${item.PhoneNumberId || item.PhoneNumber}`,
			localItem: null,
			connectItem: item,
		});
	});

	if (!rows.length) {
		if (connectError) {
			return createElement(Notice, { status: 'warning', isDismissible: false }, connectError);
		}

		return createElement(Notice, { status: 'info', isDismissible: false }, __('No numbers are available yet.', 'adaptive-customer-engagement'));
	}

	return createElement(
		Fragment,
		null,
		connectError ? createElement(Notice, { status: 'warning', isDismissible: false }, connectError) : null,
		createElement(
			'table',
			{ className: 'widefat striped' },
			createElement(
				'thead',
				null,
				createElement(
					'tr',
					null,
					['Number', 'Tracking rule', 'Source', 'Path rule', 'Priority', 'Status', 'Notes', 'Actions'].map((label) =>
						createElement('th', { key: label }, __(label, 'adaptive-customer-engagement'))
					)
				)
			),
			createElement(
				'tbody',
				null,
				rows.map(({ key, localItem, connectItem }) => {
					const hasLocal = !!localItem;
					const numberValue = localItem?.display_number || localItem?.e164_number || connectItem?.PhoneNumber || '—';
					const status = hasLocal
						? (localItem.is_active ? __('Active', 'adaptive-customer-engagement') : __('Inactive', 'adaptive-customer-engagement'))
						: __('Needs tracking rule', 'adaptive-customer-engagement');
					const notes = localItem?.is_sample ? __('Dev sample', 'adaptive-customer-engagement') : '—';

					return createElement(
						'tr',
						{ key },
						createElement('td', null, numberValue),
						createElement(
							'td',
							null,
							hasLocal
								? (onView
									? createElement(
										'a',
										{
											href: getAdminPageUrl('numbers', { ace_number: localItem.id }),
											onClick: (event) => {
												event.preventDefault();
												onView(localItem.id);
											},
										},
										localItem.label || localItem.display_number || '—'
									)
									: (localItem.label || localItem.display_number || '—'))
								: __('Not linked yet', 'adaptive-customer-engagement')
						),
						createElement('td', null, localItem?.source_type || '—'),
						createElement('td', null, localItem?.page_match_value || '—'),
						createElement('td', null, hasLocal ? localItem.priority : '—'),
						createElement('td', null, status),
						createElement('td', null, notes),
						createElement(
							'td',
							null,
							hasLocal
								? createElement(
									Fragment,
									null,
									onView ? createElement(Button, { variant: 'secondary', onClick: () => onView(localItem.id) }, __('View', 'adaptive-customer-engagement')) : null,
									onView ? ' ' : null,
										createElement(Button, { onClick: () => onEdit(localItem) }, __('Edit', 'adaptive-customer-engagement')),
										' ',
										createElement(Button, { isDestructive: true, onClick: () => onDelete(localItem.id), disabled: busy }, __('Delete', 'adaptive-customer-engagement'))
								)
								: createElement(Button, { variant: 'secondary', onClick: () => onImport(connectItem), disabled: busy }, __('Create tracking rule', 'adaptive-customer-engagement'))
						)
					);
				})
			)
		)
	);
}

function AvailableConnectNumbersTable({ items, onClaim, busy }) {
	if (!items.length) {
		return createElement(Notice, { status: 'info', isDismissible: false }, __('No available numbers were returned for that search yet.', 'adaptive-customer-engagement'));
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
				['Phone number', 'Type', 'Country', 'Actions'].map((label) =>
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
					{ key: item.PhoneNumber },
					createElement('td', null, item.PhoneNumber || '—'),
					createElement('td', null, item.PhoneNumberType || '—'),
					createElement('td', null, item.PhoneNumberCountryCode || '—'),
					createElement(
						'td',
						null,
						createElement(Button, { variant: 'primary', onClick: () => onClaim(item), disabled: busy }, __('Claim in Connect', 'adaptive-customer-engagement'))
					)
				)
			)
		)
	);
}

function ConnectAssistantsTable({ items }) {
	if (!items.length) {
		return createElement(Notice, { status: 'info', isDismissible: false }, __('No Amazon Q in Connect assistants exist yet for this account and region.', 'adaptive-customer-engagement'));
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
				['Name', 'Type', 'Status', 'Assistant ID'].map((label) =>
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
					{ key: item.assistantId || item.name },
					createElement('td', null, item.name || '—'),
					createElement('td', null, item.type || '—'),
					createElement('td', null, item.status || '—'),
					createElement('td', null, item.assistantId || '—')
				)
			)
		)
	);
}

function ConnectContactFlowsTable({ items }) {
	if (!items.length) {
		return createElement(Notice, { status: 'info', isDismissible: false }, __('No published contact flows are visible yet for this instance.', 'adaptive-customer-engagement'));
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
				['Name', 'Type', 'Status', 'State', 'Flow ID'].map((label) =>
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
					{ key: item.Id || item.Name },
					createElement('td', null, item.Name || '—'),
					createElement('td', null, item.ContactFlowType || '—'),
					createElement('td', null, item.ContactFlowStatus || '—'),
					createElement('td', null, item.ContactFlowState || '—'),
					createElement('td', null, item.Id || '—')
				)
			)
		)
	);
}

function ConnectQueuesTable({ items }) {
	if (!items.length) {
		return createElement(Notice, { status: 'info', isDismissible: false }, __('No standard queues are visible yet for this instance.', 'adaptive-customer-engagement'));
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
				['Name', 'Queue type', 'Queue ID'].map((label) =>
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
					{ key: item.Id || item.Name },
					createElement('td', null, item.Name || '—'),
					createElement('td', null, item.QueueType || '—'),
					createElement('td', null, item.Id || '—')
				)
			)
		)
	);
}

function ConnectQueueFlowsTable({ items }) {
	if (!items.length) {
		return createElement(Notice, { status: 'info', isDismissible: false }, __('No customer queue flows are visible yet for this instance.', 'adaptive-customer-engagement'));
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
				['Name', 'Status', 'State', 'Queue flow ID'].map((label) =>
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
					{ key: item.Id || item.Name },
					createElement('td', null, item.Name || '—'),
					createElement('td', null, item.ContactFlowStatus || '—'),
					createElement('td', null, item.ContactFlowState || '—'),
					createElement('td', null, item.Id || '—')
				)
			)
		)
	);
}

function CompaniesView({ active, route }) {
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
		if (active) {
			load(filters, pagination.page);
		}
	}, [active]);

	useEffect(() => {
		const companyId = route?.params?.get('ace_company');

		if (!active) {
			return;
		}

		if (!companyId) {
			setDetail(null);
			return;
		}

		request(`/admin/companies/${companyId}`).then(setDetail);
	}, [active, route]);

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

	if (detail) {
		return createElement(CompanyDetailPanel, {
			detail,
			onClose: () => {
				setDetail(null);
				clearQueryParam('ace_company');
			},
		});
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
			onView: (id) => navigateToAdminPage('companies', { ace_company: id }),
		}),
		createElement(PaginationControls, {
			pagination,
			onPageChange: (page) => load(filters, page),
		}),
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

function FilterPanel({ filters, onChange, onApply, onReset, selects = [], showSearch = true }) {
	return createElement(
		Card,
		{ style: { marginBottom: '16px' } },
		createElement(
			CardBody,
			null,
			createElement(
				'div',
				{ style: { display: 'grid', gap: '12px', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))' } },
				showSearch
					? createElement(TextControl, {
						label: __('Search', 'adaptive-customer-engagement'),
						value: filters.search || '',
						onChange: (next) => onChange({ ...filters, search: next }),
					})
					: null,
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

function SetupGuideCard({ title, description, links = [], initiallyExpanded = false }) {
	const [isExpanded, setIsExpanded] = useState(initiallyExpanded);

	return createElement(
		Card,
		{ className: 'ace-admin-settings-guide' },
		createElement(
			CardBody,
			{ className: 'ace-admin-settings-guide__body' },
			createElement(
				'div',
				{ className: 'ace-admin-settings-guide__header' },
				createElement('h3', { className: 'ace-admin-settings-guide__title' }, title),
				createElement(
					Button,
					{ variant: 'secondary', onClick: () => setIsExpanded(!isExpanded) },
					isExpanded ? __('Hide setup tips', 'adaptive-customer-engagement') : __('Show setup tips', 'adaptive-customer-engagement')
				)
			),
			isExpanded
				? createElement(
						'div',
						{ className: 'ace-admin-settings-guide__content' },
						description && createElement('p', { className: 'ace-admin-settings-guide__description' }, description),
						links.length
							? createElement(
									'ul',
									{ className: 'ace-admin-settings-guide__links' },
									links.map((link) =>
										createElement(
											'li',
											{ key: link.href },
											createElement(
												'a',
												{ href: link.href, target: '_blank', rel: 'noreferrer noopener', className: 'ace-admin-settings-guide__link' },
												link.label
											)
										)
									)
							  )
							: null
				  )
				: null
		)
	);
}

function SettingsPageIntro({ eyebrow, title, description, highlights = [] }) {
	return createElement(
		Card,
		{ className: 'ace-admin-settings-intro' },
		createElement(
			CardBody,
			{ className: 'ace-admin-settings-intro__body' },
			createElement('p', { className: 'ace-admin-settings-intro__eyebrow' }, eyebrow),
			createElement('h3', { className: 'ace-admin-settings-intro__title' }, title),
			createElement('p', { className: 'ace-admin-settings-intro__description' }, description),
			highlights.length
				? createElement(
						'div',
						{ className: 'ace-admin-settings-intro__highlights' },
						highlights.map((item) =>
							createElement(
								'div',
								{ className: 'ace-admin-settings-intro__highlight', key: item.label },
								createElement('span', { className: 'ace-admin-settings-intro__highlight-label' }, item.label),
								createElement('strong', { className: 'ace-admin-settings-intro__highlight-value' }, item.value)
							)
						)
				  )
				: null
		)
	);
}

function SettingsPanel({ title, description, children, tone = 'default', className = '' }) {
	return createElement(
		Card,
		{ className: `ace-admin-settings-panel ace-admin-settings-panel--${tone}${className ? ` ${className}` : ''}` },
		createElement(
			CardBody,
			{ className: 'ace-admin-settings-panel__body' },
			createElement(
				'div',
				{ className: 'ace-admin-settings-panel__header' },
				createElement('h3', { className: 'ace-admin-settings-panel__title' }, title),
				description ? createElement('p', { className: 'ace-admin-settings-panel__description' }, description) : null
			),
			children
		)
	);
}

function SettingsFieldGrid({ children, compact = false }) {
	return createElement('div', { className: `ace-admin-settings-grid${compact ? ' is-compact' : ''}` }, children);
}

function SettingsToggleList({ children }) {
	return createElement('div', { className: 'ace-admin-settings-toggle-list' }, children);
}

function SettingsActionRow({ children }) {
	return createElement('div', { className: 'ace-admin-settings-actions' }, children);
}

function SettingsResultCard({ items, className = '' }) {
	return createElement(
		Card,
		{ className: `ace-admin-settings-result${className ? ` ${className}` : ''}` },
		createElement(
			CardBody,
			null,
			createElement(
				'div',
				{ className: 'ace-admin-settings-result__grid' },
				items.map((item) =>
					createElement(
						'div',
						{ className: 'ace-admin-settings-result__item', key: item.label },
						createElement('span', { className: 'ace-admin-settings-result__label' }, item.label),
						createElement('strong', { className: 'ace-admin-settings-result__value' }, item.value || '—')
					)
				)
			)
		)
	);
}

function SettingsChecklist({ items = [], columns = 1, className = '' }) {
	const labels = {
		complete: __('Ready', 'adaptive-customer-engagement'),
		attention: __('Needs attention', 'adaptive-customer-engagement'),
		recommended: __('Recommended', 'adaptive-customer-engagement'),
	};

	return createElement(
		'div',
		{ className: `ace-admin-settings-checklist${columns === 2 ? ' is-two-column' : ''}${className ? ` ${className}` : ''}` },
		items.map((item) =>
			createElement(
				'div',
				{ className: `ace-admin-settings-checklist__item is-${item.status || 'recommended'}`, key: item.key || item.label },
				createElement(
					'div',
					{ className: 'ace-admin-settings-checklist__content' },
					createElement('strong', { className: 'ace-admin-settings-checklist__label' }, item.label),
					item.description ? createElement('p', { className: 'ace-admin-settings-checklist__description' }, item.description) : null
				),
				createElement('span', { className: 'ace-admin-settings-checklist__status' }, labels[item.status] || labels.recommended)
			)
		)
	);
}

function splitLines(value) {
	return value
		.split('\n')
		.map((entry) => entry.trim())
		.filter(Boolean);
}

function splitCommaList(value) {
	return value
		.split(',')
		.map((entry) => entry.trim())
		.filter(Boolean);
}

function NumberForm({ value, onChange, onSubmit, onReset, busy, label, resetLabel, connectFlows = [], connectFlowError = '', onRefreshConnectFlows = null, connectFlowBusy = false }) {
	const isEditing = !!value.id;
	const autoSourceValue = getAutoSourceValue(value.source_type);
	const connectFlowOptions = [{ label: __('No Connect flow assigned yet', 'adaptive-customer-engagement'), value: '' }].concat(
		(connectFlows || []).map((item) => ({
			label: `${item.Name || item.Id}${item.ContactFlowStatus ? ` (${item.ContactFlowStatus})` : ''}`,
			value: item.Id || '',
		}))
	);
	const hasCurrentFlowOption = value.amazon_connect_contact_flow_id && connectFlowOptions.some((item) => item.value === value.amazon_connect_contact_flow_id);

	if (value.amazon_connect_contact_flow_id && !hasCurrentFlowOption) {
		connectFlowOptions.push({
			label: sprintf(__('Current saved flow (%s)', 'adaptive-customer-engagement'), value.amazon_connect_contact_flow_id),
			value: value.amazon_connect_contact_flow_id,
		});
	}

	return createElement(
		Fragment,
		null,
		createElement(SettingsPanel, {
			title: isEditing ? __('Edit tracking number', 'adaptive-customer-engagement') : __('Add tracking number', 'adaptive-customer-engagement'),
			description: __('Set the label, public number, and matching source so the correct tracking number can be shown in the right journey.', 'adaptive-customer-engagement'),
		},
		createElement(SettingsFieldGrid, null,
			createElement(TextControl, { label: __('Label', 'adaptive-customer-engagement'), value: value.label, onChange: (next) => onChange({ ...value, label: next }) }),
			createElement(TextControl, { label: __('Display number', 'adaptive-customer-engagement'), value: value.display_number, onChange: (next) => onChange({ ...value, display_number: next }) }),
			createElement(TextControl, { label: __('E.164 number', 'adaptive-customer-engagement'), value: value.e164_number, onChange: (next) => onChange({ ...value, e164_number: next }) }),
			createElement(SelectControl, {
				label: __('Source type', 'adaptive-customer-engagement'),
				value: value.source_type,
				options: ['default', 'website', 'campaign', 'google_business_profile', 'bing', 'social', 'product_page', 'brand_page', 'brochure_qr'].map((entry) => ({ label: entry, value: entry })),
				onChange: (next) => onChange({
					...value,
					source_type: next,
					source_value: shouldReplaceAutoSourceValue(value.source_value, value.source_type) ? getAutoSourceValue(next) : value.source_value,
				}),
			}),
			createElement(TextControl, {
				label: __('Source value', 'adaptive-customer-engagement'),
				help: autoSourceValue ? sprintf(__('Auto-filled to %s for this source type unless I override it.', 'adaptive-customer-engagement'), autoSourceValue) : __('Leave this blank for a broad default rule, or set a more specific source marker when needed.', 'adaptive-customer-engagement'),
				value: value.source_value,
				onChange: (next) => onChange({ ...value, source_value: next }),
			}),
			createElement(TextControl, { label: __('Priority', 'adaptive-customer-engagement'), type: 'number', value: value.priority, onChange: (next) => onChange({ ...value, priority: Number(next || 0) }) }),
		)),
		createElement(SettingsPanel, {
			title: __('Routing and integrations', 'adaptive-customer-engagement'),
			description: __('Define where the number should appear and keep any Amazon Connect identifiers alongside the matching rule.', 'adaptive-customer-engagement'),
			tone: 'soft',
		},
		createElement(SettingsFieldGrid, null,
			createElement(SelectControl, {
				label: __('Page match type', 'adaptive-customer-engagement'),
				value: value.page_match_type,
				options: ['contains', 'exact', 'prefix', 'regex'].map((entry) => ({ label: entry, value: entry })),
				onChange: (next) => onChange({ ...value, page_match_type: next }),
			}),
			createElement(TextControl, { label: __('Page match value', 'adaptive-customer-engagement'), value: value.page_match_value, onChange: (next) => onChange({ ...value, page_match_value: next }) }),
			createElement(TextControl, { label: __('Campaign match', 'adaptive-customer-engagement'), value: value.campaign_match, onChange: (next) => onChange({ ...value, campaign_match: next }) }),
			createElement(TextControl, { label: __('Amazon Connect phone number ID', 'adaptive-customer-engagement'), value: value.amazon_connect_phone_number_id, onChange: (next) => onChange({ ...value, amazon_connect_phone_number_id: next }) }),
			createElement(SelectControl, {
				label: __('Amazon Connect contact flow', 'adaptive-customer-engagement'),
				help: __('Pick from the live CONTACT_FLOW entries in Amazon Connect. Saving this number will push the selected flow back onto the claimed Connect number as well.', 'adaptive-customer-engagement'),
				value: value.amazon_connect_contact_flow_id,
				options: connectFlowOptions,
				onChange: (next) => onChange({ ...value, amazon_connect_contact_flow_id: next }),
			}),
		),
		connectFlowError ? createElement(Notice, { status: 'warning', isDismissible: false }, connectFlowError) : null,
		onRefreshConnectFlows
			? createElement(SettingsActionRow, null,
				createElement(Button, { variant: 'secondary', onClick: onRefreshConnectFlows, disabled: busy || connectFlowBusy }, __('Refresh available Connect flows', 'adaptive-customer-engagement'))
			)
			: null,
		createElement(SettingsToggleList, null,
			createElement(ToggleControl, { label: __('Default number', 'adaptive-customer-engagement'), checked: !!value.is_default, onChange: (next) => onChange({ ...value, is_default: next }) }),
			createElement(ToggleControl, { label: __('Active', 'adaptive-customer-engagement'), checked: !!value.is_active, onChange: (next) => onChange({ ...value, is_active: next }) }),
		),
		createElement(SettingsActionRow, null,
			createElement(Button, { variant: 'primary', onClick: onSubmit, disabled: busy }, label),
			isEditing || value.label || value.display_number || value.e164_number || value.page_match_value || value.campaign_match
				? createElement(Button, { variant: 'secondary', onClick: onReset, disabled: busy }, resetLabel || (isEditing ? __('Start a new number', 'adaptive-customer-engagement') : __('Clear draft', 'adaptive-customer-engagement')))
				: null
		))
	);
}

function NumberDetailPanel({ detail, onClose, onEditStart, onEditChange, onSaveEdit, onCancelEdit, onDelete, onSyncFromConnect = null, isEditing = false, editValue = {}, busy = false, connectFlows = [], connectFlowError = '', onRefreshConnectFlows = null, connectFlowBusy = false }) {
	const number = detail?.number || {};
	const connectNumber = detail?.connect_number || null;
	const connectNumberError = detail?.connect_number_error || '';
	const recentCalls = detail?.recent_calls || [];
	const selectedConnectFlow = (connectFlows || []).find((item) => item.Id === number.amazon_connect_contact_flow_id);
	const statusMix = buildCountChart(recentCalls, (item) => item.status || __('Unknown', 'adaptive-customer-engagement'), __('Unknown', 'adaptive-customer-engagement'), 6);
	const companyMix = buildCountChart(recentCalls.filter((item) => item.company_name), (item) => item.company_name || __('Unknown', 'adaptive-customer-engagement'), __('Unknown', 'adaptive-customer-engagement'), 6);
	const activityTimeline = buildTimelineChart(recentCalls, 'started_at', 8);
	const detailMetrics = [
		{ label: __('Total calls', 'adaptive-customer-engagement'), value: number.total_calls || 0 },
		{ label: __('Matched calls', 'adaptive-customer-engagement'), value: number.matched_calls || 0 },
		{ label: __('Matched companies', 'adaptive-customer-engagement'), value: number.matched_companies || 0 },
		{ label: __('Total duration', 'adaptive-customer-engagement'), value: formatDuration(number.total_duration_seconds) },
		{ label: __('Priority', 'adaptive-customer-engagement'), value: number.priority ?? 0 },
		{ label: __('Status', 'adaptive-customer-engagement'), value: number.is_active ? __('Active', 'adaptive-customer-engagement') : __('Inactive', 'adaptive-customer-engagement') },
	];

	return createElement(
		'div',
		{ className: 'ace-admin-detail' },
		createElement(DetailHeader, {
			eyebrow: __('Tracking number detail', 'adaptive-customer-engagement'),
			title: number.label || number.display_number || __('Phone number', 'adaptive-customer-engagement'),
			description: __('Review routing rules, connected activity, and recent stored calls for this tracking number.', 'adaptive-customer-engagement'),
			meta: [
				{ label: __('Display number', 'adaptive-customer-engagement'), value: number.display_number || '—' },
				{ label: __('Source type', 'adaptive-customer-engagement'), value: number.source_type || '—' },
				{ label: __('Default', 'adaptive-customer-engagement'), value: number.is_default ? __('Yes', 'adaptive-customer-engagement') : __('No', 'adaptive-customer-engagement') },
				{ label: __('Last call', 'adaptive-customer-engagement'), value: number.last_call_started_at || '—' },
			],
			onClose,
		}),
		createElement(DetailMetricGrid, { items: detailMetrics }),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Actions', 'adaptive-customer-engagement')),
				createElement(
					'div',
					{ style: { display: 'flex', gap: '8px', flexWrap: 'wrap' } },
					onSyncFromConnect && number.amazon_connect_phone_number_id
						? createElement(Button, { variant: 'secondary', onClick: onSyncFromConnect, disabled: busy }, __('Refresh from Amazon Connect', 'adaptive-customer-engagement'))
						: null,
					isEditing
						? createElement(Button, { variant: 'secondary', onClick: onCancelEdit, disabled: busy }, __('Cancel editing', 'adaptive-customer-engagement'))
						: createElement(Button, { variant: 'secondary', onClick: onEditStart }, __('Edit routing rule', 'adaptive-customer-engagement')),
					createElement(Button, { isDestructive: true, onClick: () => onDelete(number.id), disabled: busy }, __('Delete tracking rule', 'adaptive-customer-engagement'))
				)
			)
		),
		isEditing
			? createElement(NumberForm, {
				value: editValue,
				onChange: onEditChange,
				onSubmit: onSaveEdit,
				onReset: onCancelEdit,
				busy,
				label: __('Update number', 'adaptive-customer-engagement'),
				resetLabel: __('Cancel editing', 'adaptive-customer-engagement'),
				connectFlows,
				connectFlowError,
				onRefreshConnectFlows,
				connectFlowBusy,
			})
			: null,
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Amazon Connect', 'adaptive-customer-engagement')),
				connectNumberError ? createElement(Notice, { status: 'warning', isDismissible: false }, connectNumberError) : null,
				createElement(
					'table',
					{ className: 'widefat striped', style: { marginBottom: 0 } },
					createElement(
						'tbody',
						null,
						[
							['Linked in Connect', number.amazon_connect_phone_number_id ? __('Yes', 'adaptive-customer-engagement') : __('No', 'adaptive-customer-engagement')],
							['Connect status', connectNumber?.Status || '—'],
							['Connect status message', connectNumber?.StatusMessage || '—'],
							['Connect description', connectNumber?.PhoneNumberDescription || '—'],
							['Connect number ID', number.amazon_connect_phone_number_id || '—'],
							['Connect phone type', connectNumber?.PhoneNumberType || '—'],
							['Connect country', connectNumber?.PhoneNumberCountryCode || '—'],
							['Assigned flow', selectedConnectFlow?.Name || number.amazon_connect_contact_flow_id || '—'],
						].map(([label, value]) => createElement('tr', { key: label }, createElement('th', null, __(label, 'adaptive-customer-engagement')), createElement('td', null, value)))
					)
				)
			)
		),
		createElement(
			'div',
			{ className: 'ace-admin-dashboard-charts ace-admin-detail-charts' },
			createElement(DashboardPieChartCard, {
				title: __('Call status mix', 'adaptive-customer-engagement'),
				items: statusMix,
				valueLabel: __('calls', 'adaptive-customer-engagement'),
				emptyMessage: __('No stored calls are available for this number yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardBarChartCard, {
				title: __('Matched companies', 'adaptive-customer-engagement'),
				items: companyMix,
				emptyMessage: __('No company matches are available for this number yet.', 'adaptive-customer-engagement'),
			}),
			createElement(DashboardTimelineCard, {
				title: __('Recent call activity', 'adaptive-customer-engagement'),
				items: activityTimeline,
				emptyMessage: __('No stored calls are available for this number yet.', 'adaptive-customer-engagement'),
			})
		),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Routing facts', 'adaptive-customer-engagement')),
				createElement(
					'table',
					{ className: 'widefat striped', style: { marginBottom: 0 } },
					createElement(
						'tbody',
						null,
						[
							['Label', number.label || '—'],
							['Display number', number.display_number || '—'],
							['E.164', number.e164_number || '—'],
							['Source type', number.source_type || '—'],
							['Source value', number.source_value || '—'],
							['Page match type', number.page_match_type || '—'],
							['Page match value', number.page_match_value || '—'],
							['Campaign match', number.campaign_match || '—'],
							['Amazon Connect flow', selectedConnectFlow?.Name || number.amazon_connect_contact_flow_id || '—'],
							['Default', number.is_default ? __('Yes', 'adaptive-customer-engagement') : __('No', 'adaptive-customer-engagement')],
							['Active', number.is_active ? __('Yes', 'adaptive-customer-engagement') : __('No', 'adaptive-customer-engagement')],
							['Priority', number.priority ?? 0],
						].map(([label, value]) => createElement('tr', { key: label }, createElement('th', null, __(label, 'adaptive-customer-engagement')), createElement('td', null, value)))
					)
				)
			)
		),
		createElement(
			Card,
			null,
			createElement(
				CardBody,
				null,
				createElement('h3', { style: { marginTop: 0 } }, __('Recent stored calls', 'adaptive-customer-engagement')),
				createElement(CallsTable, {
					items: recentCalls,
					onView: (id) => navigateToAdminPage('calls', { ace_call: id }),
					onSessionView: (id) => navigateToAdminPage('sessions', { ace_session: id }),
					onCompanyView: (id) => navigateToAdminPage('companies', { ace_company: id }),
				})
			)
		)
	);
}

function NumbersView({ active, route }) {
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
	const [detail, setDetail] = useState(null);
	const [detailEditing, setDetailEditing] = useState(false);
	const [detailDraft, setDetailDraft] = useState(empty);
	const [numberSettings, setNumberSettings] = useState(null);
	const [current, setCurrent] = useState(empty);
	const [busy, setBusy] = useState(false);
	const [notice, setNotice] = useState(null);
	const [connectResources, setConnectResources] = useState({ phone_numbers: [], assistants: [], contact_flows: [], errors: {} });
	const [availableConnectNumbers, setAvailableConnectNumbers] = useState([]);
	const [connectSearch, setConnectSearch] = useState({
		country_code: 'GB',
		phone_number_type: 'TOLL_FREE',
		phone_number_prefix: '',
		description: '',
	});

	const load = () => request('/admin/numbers').then((response) => setItems(response.items || []));
	const loadConnectResources = () => request('/admin/connect/resources').then(setConnectResources);
	const openNumberDetail = async (id, editMode = false) => {
		const response = await request(`/admin/numbers/${id}`);
		setDetail(response);
		setDetailEditing(editMode);
		setDetailDraft(mapNumberToForm(response.number || {}));
		navigateToAdminPage('numbers', { ace_number: id });
		return response;
	};

	useEffect(() => {
		if (active) {
			request('/admin/settings').then(setNumberSettings);
			load();
		}
	}, [active]);

	useEffect(() => {
		if (active && hasConnectConfig(numberSettings?.amazon_connect || {})) {
			loadConnectResources();
		}
	}, [active, numberSettings]);

	useEffect(() => {
		const numberId = route?.params?.get('ace_number');

		if (!active) {
			return;
		}

		if (!numberId) {
			setDetail(null);
			setDetailEditing(false);
			setDetailDraft(empty);
			return;
		}

		request(`/admin/numbers/${numberId}`).then((response) => {
			setDetail(response);
			setDetailEditing(false);
			setDetailDraft(mapNumberToForm(response.number || {}));
		});
	}, [active, route, empty]);

	const save = async () => {
		setBusy(true);
		try {
			const response = await request(current.id ? `/admin/numbers/${current.id}` : '/admin/numbers', {
				method: current.id ? 'PATCH' : 'POST',
				data: current,
			});
			setCurrent(empty);
			await load();
			setNotice({ status: 'success', message: __('Tracking rule saved.', 'adaptive-customer-engagement') });
			if (response?.id) {
				await openNumberDetail(response.id);
			}
		} catch (error) {
			setNotice({ status: 'error', message: error.message || __('The tracking rule could not be saved.', 'adaptive-customer-engagement') });
		}
		setBusy(false);
	};

	const saveDetailEdit = async () => {
		if (!detailDraft?.id) {
			return;
		}

		setBusy(true);
		try {
			await request(`/admin/numbers/${detailDraft.id}`, {
				method: 'PATCH',
				data: detailDraft,
			});
			await load();
			const refreshed = await request(`/admin/numbers/${detailDraft.id}`);
			setDetail(refreshed);
			setDetailDraft(mapNumberToForm(refreshed.number || {}));
			setDetailEditing(false);
			setNotice({ status: 'success', message: __('Tracking rule updated.', 'adaptive-customer-engagement') });
		} catch (error) {
			setNotice({ status: 'error', message: error.message || __('The tracking rule could not be updated.', 'adaptive-customer-engagement') });
		}
		setBusy(false);
	};

	const remove = async (id) => {
		setBusy(true);
		await request(`/admin/numbers/${id}`, { method: 'DELETE' });
		if (current.id === id) {
			setCurrent(empty);
		}
		if (detail?.number?.id === id) {
			setDetail(null);
			setDetailEditing(false);
			setDetailDraft(empty);
			clearQueryParam('ace_number');
		}
		await load();
		setNotice({ status: 'success', message: __('Tracking rule deleted.', 'adaptive-customer-engagement') });
		setBusy(false);
	};

	const searchConnectNumbers = async () => {
		setBusy(true);
		try {
			const response = await request('/admin/connect/phone-numbers/search', {
				method: 'POST',
				data: connectSearch,
			});
			setAvailableConnectNumbers(response.items || []);
			setNotice({ status: 'success', message: __('Available Connect numbers refreshed.', 'adaptive-customer-engagement') });
		} catch (error) {
			setAvailableConnectNumbers([]);
			setNotice({ status: 'error', message: error.message || __('The Connect number search failed.', 'adaptive-customer-engagement') });
		}
		setBusy(false);
	};

	const claimConnectNumber = async (item) => {
		setBusy(true);
		try {
			const response = await request('/admin/connect/phone-numbers/claim', {
				method: 'POST',
				data: {
					phone_number: item.PhoneNumber,
					description: connectSearch.description || __('Claimed from Adaptive Customer Engagement', 'adaptive-customer-engagement'),
					auto_link: true,
				},
			});
			await load();
			await loadConnectResources();
			if (response?.number?.id) {
				await openNumberDetail(response.number.id);
			}
			setNotice({ status: 'success', message: __('The Connect number was claimed, linked, and added to local tracking.', 'adaptive-customer-engagement') });
		} catch (error) {
			setNotice({ status: 'error', message: error.message || __('The Connect number could not be claimed.', 'adaptive-customer-engagement') });
		}
		setBusy(false);
	};

	const syncConnectNumbers = async () => {
		setBusy(true);
		try {
			const response = await request('/admin/connect/phone-numbers/sync', {
				method: 'POST',
			});
			await load();
			await loadConnectResources();
			const created = Number(response?.summary?.created || 0);
			const updated = Number(response?.summary?.updated || 0);
			setNotice({ status: 'success', message: sprintf(__('Amazon Connect numbers synced. %1$d created and %2$d updated.', 'adaptive-customer-engagement'), created, updated) });
		} catch (error) {
			setNotice({ status: 'error', message: error.message || __('The Connect number sync failed.', 'adaptive-customer-engagement') });
		}
		setBusy(false);
	};

	const syncCurrentNumberFromConnect = async () => {
		if (!detail?.number?.id) {
			return;
		}

		setBusy(true);
		try {
			const response = await request(`/admin/numbers/${detail.number.id}/connect-sync`, {
				method: 'POST',
			});
			setDetail((previous) => ({
				...(previous || {}),
				number: response.number || previous?.number || {},
				connect_number: response.connect_number || previous?.connect_number || null,
				connect_number_error: '',
			}));
			setDetailDraft(mapNumberToForm(response.number || {}));
			await load();
			await loadConnectResources();
			setNotice({ status: 'success', message: __('The local tracking record has been refreshed from Amazon Connect.', 'adaptive-customer-engagement') });
		} catch (error) {
			setNotice({ status: 'error', message: error.message || __('The tracking number could not be refreshed from Amazon Connect.', 'adaptive-customer-engagement') });
		}
		setBusy(false);
	};

	if (!items || !numberSettings) {
		return createElement(Spinner);
	}

	const connectConfigured = hasConnectConfig(numberSettings.amazon_connect || {});
	const connectConnected = connectConfigured && !connectResources?.errors?.phone_numbers;

	if (detail && connectConnected) {
		return createElement(NumberDetailPanel, {
			detail,
			isEditing: detailEditing,
			editValue: detailDraft,
			busy,
			onClose: () => {
				setDetail(null);
				setDetailEditing(false);
				setDetailDraft(empty);
				clearQueryParam('ace_number');
			},
			onEditStart: () => {
				setDetailEditing(true);
				setDetailDraft(mapNumberToForm(detail.number || {}));
			},
			onEditChange: setDetailDraft,
			onSaveEdit: saveDetailEdit,
			onCancelEdit: () => {
				setDetailEditing(false);
				setDetailDraft(mapNumberToForm(detail.number || {}));
			},
			onDelete: remove,
			onSyncFromConnect: syncCurrentNumberFromConnect,
			connectFlows: connectResources.contact_flows || [],
			connectFlowError: connectResources?.errors?.contact_flows || '',
			onRefreshConnectFlows: loadConnectResources,
			connectFlowBusy: busy,
		});
	}

	const routingItems = items.filter((item) => !item.is_sample);
	const sampleItems = items.filter((item) => !!item.is_sample);
	const commonHighlights = [
		{ label: __('Connect setup', 'adaptive-customer-engagement'), value: connectConnected ? __('Connected', 'adaptive-customer-engagement') : __('Needs Amazon Connect setup', 'adaptive-customer-engagement') },
		{ label: __('Live Connect numbers', 'adaptive-customer-engagement'), value: connectConnected ? `${(connectResources.phone_numbers || []).length}` : '—' },
		{ label: __('Demo rows', 'adaptive-customer-engagement'), value: connectConnected ? `${sampleItems.length}` : '—' },
	];
	const isEditing = !!current.id;

	return createElement(
		Fragment,
		null,
		notice && createElement(Notice, { status: notice.status || 'info', isDismissible: true, onRemove: () => setNotice(null) }, notice.message),
		createElement(SettingsPageIntro, {
			eyebrow: __('Number setup', 'adaptive-customer-engagement'),
			title: __('Manage tracking numbers through Amazon Connect rather than manual local number entry.', 'adaptive-customer-engagement'),
			description: __('Manage live Amazon Connect numbers and keep tracking records aligned with the active telephony service.', 'adaptive-customer-engagement'),
			highlights: commonHighlights,
		}),
		!connectConfigured
			? createElement(SettingsPanel, {
				title: __('Amazon Connect required', 'adaptive-customer-engagement'),
				description: __('Phone number management is available after Amazon Connect region, instance, and credential details have been saved.', 'adaptive-customer-engagement'),
			}, createElement(Notice, { status: 'info', isDismissible: false }, __('Once Amazon Connect is enabled and the connection details are saved, this page will reveal detected numbers and number-claiming tools automatically.', 'adaptive-customer-engagement')))
			: !connectConnected
				? createElement(SettingsPanel, {
					title: __('Amazon Connect connection still needed', 'adaptive-customer-engagement'),
					description: __('Phone tools become available when the service can read live numbers from the selected Amazon Connect instance.', 'adaptive-customer-engagement'),
				}, createElement(Notice, { status: 'warning', isDismissible: false }, connectResources?.errors?.phone_numbers || __('The Amazon Connect connection is not ready yet.', 'adaptive-customer-engagement')))
				: createElement(
					Fragment,
					null,
					createElement(SettingsPanel, {
						title: __('All numbers', 'adaptive-customer-engagement'),
						description: __('Review linked tracking rules, detected Amazon Connect numbers, and any numbers that still require a local tracking rule.', 'adaptive-customer-engagement'),
					},
					createElement(SettingsResultCard, {
						items: [
							{ label: __('Tracking rules', 'adaptive-customer-engagement'), value: `${routingItems.length}` },
							{ label: __('Linked live numbers', 'adaptive-customer-engagement'), value: `${routingItems.filter((item) => !!item.amazon_connect_phone_number_id).length}` },
							{ label: __('Detected live numbers', 'adaptive-customer-engagement'), value: `${(connectResources.phone_numbers || []).length}` },
							{ label: __('Dev samples', 'adaptive-customer-engagement'), value: `${sampleItems.length}` },
						],
					}),
					createElement(SettingsActionRow, null,
						createElement(Button, { variant: 'secondary', onClick: syncConnectNumbers, disabled: busy }, __('Sync detected Connect numbers into tracking', 'adaptive-customer-engagement'))
					),
					createElement(UnifiedNumbersTable, {
						localItems: items,
						connectItems: connectResources.phone_numbers || [],
						connectError: connectResources?.errors?.phone_numbers,
						onImport: async (item) => {
							setBusy(true);
							try {
								await createLinkedConnectTrackingRule({
									connectItem: item,
									empty,
									load,
									loadConnectResources,
									defaultContactFlowId: numberSettings?.amazon_connect?.default_contact_flow_id || '',
								});
								const refreshedItems = await request('/admin/numbers');
								setItems(refreshedItems.items || []);
								const createdItem = (refreshedItems.items || []).find((entry) => entry.amazon_connect_phone_number_id === item.PhoneNumberId);
								if (createdItem?.id) {
									await openNumberDetail(createdItem.id);
								}
								setNotice({ status: 'success', message: __('The tracking rule was created and linked to the Amazon Connect number.', 'adaptive-customer-engagement') });
							} catch (error) {
								setNotice({ status: 'error', message: error.message || __('The tracking rule could not be created for that Connect number.', 'adaptive-customer-engagement') });
							}
							setBusy(false);
						},
						onView: (id) => navigateToAdminPage('numbers', { ace_number: id }),
						onEdit: (item) => openNumberDetail(item.id, true),
						onDelete: remove,
						busy,
					})),
					createElement(SettingsPanel, {
						title: __('Claim a new Connect number', 'adaptive-customer-engagement'),
						description: __('Search available Amazon Connect numbers, claim a suitable number, and add it to tracking automatically.', 'adaptive-customer-engagement'),
						tone: 'soft',
					},
					createElement(SettingsFieldGrid, { compact: true },
						createElement(TextControl, {
							label: __('Country code', 'adaptive-customer-engagement'),
							value: connectSearch.country_code,
							onChange: (next) => setConnectSearch({ ...connectSearch, country_code: next.toUpperCase() }),
						}),
						createElement(SelectControl, {
							label: __('Phone number type', 'adaptive-customer-engagement'),
							value: connectSearch.phone_number_type,
							options: ['TOLL_FREE', 'DID'].map((entry) => ({ label: entry, value: entry })),
							onChange: (next) => setConnectSearch({ ...connectSearch, phone_number_type: next }),
						}),
						createElement(TextControl, {
							label: __('Preferred prefix', 'adaptive-customer-engagement'),
							value: connectSearch.phone_number_prefix,
							onChange: (next) => setConnectSearch({ ...connectSearch, phone_number_prefix: next }),
						}),
						createElement(TextControl, {
							label: __('Claim description', 'adaptive-customer-engagement'),
							value: connectSearch.description,
							onChange: (next) => setConnectSearch({ ...connectSearch, description: next }),
						}),
					),
					createElement(SettingsActionRow, null,
						createElement(Button, { variant: 'secondary', onClick: searchConnectNumbers, disabled: busy }, __('Search available Connect numbers', 'adaptive-customer-engagement'))
					),
					availableConnectNumbers.length
						? createElement(AvailableConnectNumbersTable, {
							items: availableConnectNumbers,
							onClaim: claimConnectNumber,
							busy,
						})
						: null
					)
				),
		createElement(SetupGuideCard, {
			title: __('Routing notes', 'adaptive-customer-engagement'),
			description: __('Provision and manage service numbers in Amazon Connect first, then use this page to keep tracking, routing, and flow assignment aligned in WordPress.', 'adaptive-customer-engagement'),
		})
	);
}

function SettingsView({ section = 'settings', active }) {
	const [settings, setSettings] = useState(null);
	const [notice, setNotice] = useState(null);
	const [busy, setBusy] = useState(false);
	const [settingsImportFile, setSettingsImportFile] = useState(null);
	const [settingsImportName, setSettingsImportName] = useState('');
	const [settingsImportInputKey, setSettingsImportInputKey] = useState(0);
	const [testIp, setTestIp] = useState('');
	const [testResult, setTestResult] = useState(null);
	const [connectReadiness, setConnectReadiness] = useState(null);
	const [connectImportStatus, setConnectImportStatus] = useState(null);
	const [assistantData, setAssistantData] = useState(null);
	const [lexBotData, setLexBotData] = useState(null);
	const [contactFlowData, setContactFlowData] = useState(null);
	const [queueFlowData, setQueueFlowData] = useState(null);
	const [queueData, setQueueData] = useState(null);
	const [flowDraft, setFlowDraft] = useState(CONNECT_FLOW_DRAFT_DEFAULTS);
	const [assistantDraft, setAssistantDraft] = useState(CONNECT_ASSISTANT_DRAFT_DEFAULTS);
	const [lexBotDraft, setLexBotDraft] = useState(LEX_BOT_DRAFT_DEFAULTS);
	const [isLexBotCreateModalOpen, setIsLexBotCreateModalOpen] = useState(false);
	const [lexBotCreateResult, setLexBotCreateResult] = useState(null);

	useEffect(() => {
		if (active && !settings) {
			request('/admin/settings').then(setSettings);
		}
	}, [active, settings]);

	useEffect(() => {
		if (active && section === 'amazon-connect' && settings && hasConnectConfig(settings.amazon_connect || {}) && !connectReadiness) {
			request('/admin/connect-readiness').then(setConnectReadiness);
		}
	}, [active, section, settings, connectReadiness]);

	useEffect(() => {
		if (active && (section === 'amazon-connect' || section === 'ai-agent') && settings && hasConnectConfig(settings.amazon_connect || {}) && !assistantData) {
			request('/admin/connect/assistants').then(setAssistantData);
		}
	}, [active, section, settings, assistantData]);

	useEffect(() => {
		if (active && (section === 'amazon-connect' || section === 'ai-agent') && settings && hasConnectConfig(settings.amazon_connect || {}) && !lexBotData) {
			request('/admin/connect/lex-bots').then((response) => {
				setLexBotData(response);
				applyLexSelectionToSettings(response);
			});
		}
	}, [active, section, settings, lexBotData]);

	useEffect(() => {
		if (active && section === 'amazon-connect' && settings && hasConnectConfig(settings.amazon_connect || {}) && !connectImportStatus) {
			request('/admin/connect/calls/import-status').then(setConnectImportStatus);
		}
	}, [active, section, settings, connectImportStatus]);

	useEffect(() => {
		if (active && section === 'amazon-connect' && settings && hasConnectConfig(settings.amazon_connect || {}) && !contactFlowData) {
			request('/admin/connect/contact-flows').then(setContactFlowData);
		}
	}, [active, section, settings, contactFlowData]);

	useEffect(() => {
		if (active && section === 'amazon-connect' && settings && hasConnectConfig(settings.amazon_connect || {}) && !queueData) {
			request('/admin/connect/queues').then(setQueueData);
		}
	}, [active, section, settings, queueData]);

	useEffect(() => {
		if (active && section === 'amazon-connect' && settings && hasConnectConfig(settings.amazon_connect || {}) && !queueFlowData) {
			request('/admin/connect/queue-flows').then(setQueueFlowData);
		}
	}, [active, section, settings, queueFlowData]);

	useEffect(() => {
		if (!settings) {
			return;
		}

		setLexBotDraft((current) => ({
			...current,
			role_arn: current.role_arn || (isServiceLinkedLexRoleArn(settings.amazon_connect?.lex_bot_role_arn || '') ? '' : (settings.amazon_connect?.lex_bot_role_arn || '')),
			locale_id: current.locale_id || settings.amazon_connect?.lex_bot_locale_id || 'en_GB',
		}));
	}, [settings]);

	const applyLexSelectionToSettings = (response) => {
		if (!response?.selected) {
			return;
		}

		setSettings((current) => {
			if (!current) {
				return current;
			}

			return {
				...current,
				amazon_connect: {
					...(current.amazon_connect || {}),
					lex_bot_id: response.selected.bot_id || current.amazon_connect?.lex_bot_id || '',
					lex_bot_alias_id: response.selected.alias_id || current.amazon_connect?.lex_bot_alias_id || '',
					lex_bot_locale_id: response.selected.locale_id || current.amazon_connect?.lex_bot_locale_id || '',
					lex_bot_intent_name: response.selected.intent_name || current.amazon_connect?.lex_bot_intent_name || '',
					lex_bot_role_arn: response.selected.role_arn || current.amazon_connect?.lex_bot_role_arn || '',
					lex_bot_console_url: response.selected.console_url || response.console_url || current.amazon_connect?.lex_bot_console_url || '',
				},
			};
		});

		setLexBotDraft((current) => ({
			...current,
			role_arn: current.role_arn || (isServiceLinkedLexRoleArn(response.selected.role_arn || '') ? '' : (response.selected.role_arn || '')),
		}));
	};

	if (!settings) {
		return createElement(Spinner);
	}

	const save = async () => {
		setBusy(true);
		const response = await request('/admin/settings', { method: 'POST', data: settings });
		setSettings(response);
		if (section === 'amazon-connect' || section === 'ai-agent') {
			setConnectReadiness(await request('/admin/connect-readiness'));
			setConnectImportStatus(await request('/admin/connect/calls/import-status'));
			setAssistantData(await request('/admin/connect/assistants'));
			await refreshLexBotData(
				response?.amazon_connect?.lex_bot_id || '',
				response?.amazon_connect?.lex_bot_locale_id || ''
			);
			setContactFlowData(await request('/admin/connect/contact-flows'));
			setQueueFlowData(await request('/admin/connect/queue-flows'));
			setQueueData(await request('/admin/connect/queues'));
		}
		setNotice(__('Settings saved.', 'adaptive-customer-engagement'));
		setBusy(false);
	};

	const exportSettingsConfig = async () => {
		setBusy(true);
		try {
			const response = await request('/admin/settings/export');
			const blob = new Blob([JSON.stringify(response, null, 2)], { type: 'application/json' });
			const objectUrl = window.URL.createObjectURL(blob);
			const link = document.createElement('a');
			link.href = objectUrl;
			link.download = `adaptive-customer-engagement-settings-${new Date().toISOString().slice(0, 10)}.json`;
			document.body.appendChild(link);
			link.click();
			link.remove();
			window.URL.revokeObjectURL(objectUrl);
			setNotice({ status: 'success', message: __('Settings export downloaded.', 'adaptive-customer-engagement') });
		} catch (error) {
			setNotice({ status: 'error', message: getApiErrorMessage(error, __('The settings export could not be downloaded.', 'adaptive-customer-engagement')) });
		}
		setBusy(false);
	};

	const handleSettingsImportSelection = async (event) => {
		const file = event?.target?.files?.[0];

		if (!file) {
			setSettingsImportFile(null);
			setSettingsImportName('');
			return;
		}

		try {
			const parsed = JSON.parse(await file.text());
			setSettingsImportFile(parsed);
			setSettingsImportName(file.name || '');
			setNotice({ status: 'info', message: sprintf(__('Ready to import settings from %s.', 'adaptive-customer-engagement'), file.name || __('the selected file', 'adaptive-customer-engagement')) });
		} catch (error) {
			setSettingsImportFile(null);
			setSettingsImportName('');
			setSettingsImportInputKey((current) => current + 1);
			setNotice({ status: 'error', message: __('That file could not be read as a valid settings export.', 'adaptive-customer-engagement') });
		}
	};

	const importSettingsConfig = async () => {
		if (!settingsImportFile) {
			return;
		}

		setBusy(true);

		try {
			const response = await request('/admin/settings/import', {
				method: 'POST',
				data: settingsImportFile,
			});
			const importedSettings = response?.settings || {};
			setSettings(importedSettings);
			setTestResult(null);
			setSettingsImportFile(null);
			setSettingsImportName('');
			setSettingsImportInputKey((current) => current + 1);

			if (hasConnectConfig(importedSettings.amazon_connect || {})) {
				setConnectReadiness(await request('/admin/connect-readiness'));
				setConnectImportStatus(await request('/admin/connect/calls/import-status'));
				setAssistantData(await request('/admin/connect/assistants'));
				await refreshLexBotData(
					importedSettings?.amazon_connect?.lex_bot_id || '',
					importedSettings?.amazon_connect?.lex_bot_locale_id || ''
				);
				setContactFlowData(await request('/admin/connect/contact-flows'));
				setQueueFlowData(await request('/admin/connect/queue-flows'));
				setQueueData(await request('/admin/connect/queues'));
			} else {
				setConnectReadiness(null);
				setConnectImportStatus(null);
				setAssistantData(null);
				setLexBotData(null);
				setContactFlowData(null);
				setQueueFlowData(null);
				setQueueData(null);
			}

			setNotice({ status: 'success', message: __('Settings imported.', 'adaptive-customer-engagement') });
		} catch (error) {
			setNotice({ status: 'error', message: getApiErrorMessage(error, __('The settings import could not be completed.', 'adaptive-customer-engagement')) });
		}

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

	const refreshConnectFlows = async () => {
		setBusy(true);
		setContactFlowData(await request('/admin/connect/contact-flows'));
		setQueueFlowData(await request('/admin/connect/queue-flows'));
		setQueueData(await request('/admin/connect/queues'));
		setNotice(__('Amazon Connect flows refreshed.', 'adaptive-customer-engagement'));
		setBusy(false);
	};

	const refreshConnectImportStatus = async () => {
		setBusy(true);
		setConnectImportStatus(await request('/admin/connect/calls/import-status'));
		setNotice(__('Amazon Connect import status refreshed.', 'adaptive-customer-engagement'));
		setBusy(false);
	};

	const importConnectCalls = async () => {
		setBusy(true);
		try {
			const response = await request('/admin/connect/calls/import', {
				method: 'POST',
				data: {
					lookback_hours: 72,
					max_objects: 50,
				},
			});
			setConnectImportStatus({
				...(connectImportStatus || {}),
				summary: response.status || null,
				last_run: response.last_run || null,
			});
			setNotice(sprintf(__('Amazon Connect call import finished. %1$d created and %2$d updated.', 'adaptive-customer-engagement'), response.summary?.created || 0, response.summary?.updated || 0));
		} catch (error) {
			setConnectImportStatus(await request('/admin/connect/calls/import-status'));
			setNotice(error.message || __('The Amazon Connect call import could not be completed.', 'adaptive-customer-engagement'));
		}
		setBusy(false);
	};

	const createConnectAssistant = async () => {
		setBusy(true);
		try {
			const response = await request('/admin/connect/assistants', {
				method: 'POST',
				data: assistantDraft,
			});
			setAssistantData({
				items: response.items || [],
				error: '',
			});
			if (response?.settings) {
				setSettings(response.settings);
			}
			setAssistantDraft({
				...CONNECT_ASSISTANT_DRAFT_DEFAULTS,
				link_to_ai_agent: assistantDraft.link_to_ai_agent,
			});
			setNotice(__('Amazon Q in Connect assistant created.', 'adaptive-customer-engagement'));
		} catch (error) {
			setNotice(error.message || __('The Amazon Q in Connect assistant could not be created.', 'adaptive-customer-engagement'));
		}
		setBusy(false);
	};

	const refreshLexBotData = async (botId = '', localeId = '') => {
		const params = new URLSearchParams();

		if (botId) {
			params.set('bot_id', botId);
		}

		if (localeId) {
			params.set('locale_id', localeId);
		}

		const path = `/admin/connect/lex-bots${params.toString() ? `?${params.toString()}` : ''}`;
		const response = await request(path);
		setLexBotData(response);
		applyLexSelectionToSettings(response);
		return response;
	};

	const createConnectFlow = async () => {
		setBusy(true);
		try {
			const response = await request('/admin/connect/contact-flows', {
				method: 'POST',
				data: {
					...flowDraft,
					lex_bot_alias_arn: selectedLexBot?.aliasArn || lexBotData?.selected?.alias_arn || '',
				},
			});
			const refreshedFlows = await request('/admin/connect/contact-flows');
			const refreshedQueueFlows = await request('/admin/connect/queue-flows');
			const refreshedQueues = await request('/admin/connect/queues');
			setContactFlowData(refreshedFlows);
			setQueueFlowData(refreshedQueueFlows);
			setQueueData(refreshedQueues);
			if (response?.settings) {
				setSettings(response.settings);
			}
			setFlowDraft({
				...CONNECT_FLOW_DRAFT_DEFAULTS,
				set_as_default: flowDraft.set_as_default,
				set_as_chat_flow: flowDraft.set_as_chat_flow,
			});
			setNotice(
				response?.settings?.amazon_connect?.chat_contact_flow_id === response?.item?.Id
					? __('Amazon Connect flow created and linked as the website chat flow.', 'adaptive-customer-engagement')
					: __('Amazon Connect flow created.', 'adaptive-customer-engagement')
			);
		} catch (error) {
			setNotice(error.message || __('The Amazon Connect flow could not be created.', 'adaptive-customer-engagement'));
		}
		setBusy(false);
	};

	const seedBotKnowledge = async () => {
		setBusy(true);
		try {
			const response = await request('/admin/connect/bot-knowledge/seed', {
				method: 'POST',
				data: {
					limit: 8,
				},
			});
			setAiAgent({ bot_knowledge_entries: response.items || [] });
			setNotice(__('Knowledge entries were seeded from the latest published site content.', 'adaptive-customer-engagement'));
		} catch (error) {
			setNotice(error.message || __('The site knowledge entries could not be seeded.', 'adaptive-customer-engagement'));
		}
		setBusy(false);
	};

	const addBotKnowledgeEntry = () => {
		const entries = (settings.ai_agent?.bot_knowledge_entries || []).slice();
		entries.push({
			id: `manual-${Date.now()}`,
			question: '',
			answer: '',
			source_type: 'manual',
			source_id: 0,
			source_label: '',
			url: '',
			enabled: true,
		});
		setAiAgent({ bot_knowledge_entries: entries });
	};

	const updateBotKnowledgeEntry = (index, patch) => {
		const entries = (settings.ai_agent?.bot_knowledge_entries || []).map((entry, entryIndex) => (
			entryIndex === index ? { ...entry, ...patch } : entry
		));
		setAiAgent({ bot_knowledge_entries: entries });
	};

	const removeBotKnowledgeEntry = (index) => {
		const entries = (settings.ai_agent?.bot_knowledge_entries || []).filter((entry, entryIndex) => entryIndex !== index);
		setAiAgent({ bot_knowledge_entries: entries });
	};

	const createLexBot = async () => {
		setBusy(true);
		setLexBotCreateResult(null);
		try {
			const response = await request('/admin/connect/lex-bots', {
				method: 'POST',
				data: {
					name: lexBotDraft.name,
					description: lexBotDraft.description,
					locale_id: inferredLexBotLocale,
					knowledge_entries: settings.ai_agent?.bot_knowledge_entries || [],
					link_to_settings: lexBotDraft.link_to_settings,
				},
			});
			if (response?.settings) {
				setSettings(response.settings);
			}
			try {
				setLexBotData(await refreshLexBotData(response?.item?.botId || '', inferredLexBotLocale));
			} catch (refreshError) {
				setLexBotData({
					items: response.items || [],
					aliases: response.aliases || [],
					locales: response.locales || [],
					intents: response.intents || [],
					error: response.build_error || refreshError.message || '',
				});
			}
			setLexBotCreateResult(response);
			setLexBotDraft({
				...LEX_BOT_DRAFT_DEFAULTS,
				link_to_settings: lexBotDraft.link_to_settings,
			});
			setNotice(response?.message || __('Amazon Lex bot created.', 'adaptive-customer-engagement'));
		} catch (error) {
			setNotice(getApiErrorMessage(error, __('The Amazon Lex bot could not be created.', 'adaptive-customer-engagement')));
		}
		setBusy(false);
	};

	const connectExistingLexBot = async () => {
		setBusy(true);
		try {
			const response = await request('/admin/settings', { method: 'POST', data: settings });
			setSettings(response);
			await refreshLexBotData(
				response?.amazon_connect?.lex_bot_id || '',
				response?.amazon_connect?.lex_bot_locale_id || ''
			);
			setNotice(__('Existing Amazon Connect bot linked into the plugin settings.', 'adaptive-customer-engagement'));
		} catch (error) {
			setNotice(getApiErrorMessage(error, __('The Amazon Connect bot could not be linked into the plugin settings.', 'adaptive-customer-engagement')));
		}
		setBusy(false);
	};

	const disconnectLexBot = async () => {
		setBusy(true);
		try {
			const response = await request('/admin/connect/lex-bots/disconnect', {
				method: 'POST',
				data: {},
			});
			if (response?.settings) {
				setSettings(response.settings);
			}
			setLexBotData((current) => ({
				...(current || {}),
				items: response?.items || current?.items || [],
				selected: response?.selected || {},
			}));
			setNotice(response?.message || __('The site bot has been disconnected.', 'adaptive-customer-engagement'));
		} catch (error) {
			setNotice(getApiErrorMessage(error, __('The site bot could not be disconnected.', 'adaptive-customer-engagement')));
		}
		setBusy(false);
	};

	const cleanupLexBots = async () => {
		setBusy(true);
		try {
			const response = await request('/admin/connect/lex-bots/cleanup', {
				method: 'POST',
				data: {
					keep_bot_id: settings.amazon_connect?.lex_bot_id || '',
				},
			});
			setLexBotData((current) => ({
				...(current || {}),
				items: response?.items || [],
				selected: response?.selected || current?.selected || {},
				error: (response?.errors || []).join(' '),
			}));
			setNotice(response?.message || __('Amazon Connect bot cleanup completed.', 'adaptive-customer-engagement'));
		} catch (error) {
			setNotice(getApiErrorMessage(error, __('Amazon Connect bot cleanup could not be completed.', 'adaptive-customer-engagement')));
		}
		setBusy(false);
	};

	const setTracking = (next) => setSettings({ ...settings, tracking: { ...settings.tracking, ...next } });
	const setPrivacy = (next) => setSettings({ ...settings, privacy: { ...settings.privacy, ...next } });
	const setEnrichment = (next) => setSettings({ ...settings, enrichment: { ...settings.enrichment, ...next } });
	const setAmazonConnect = (next) => setSettings({ ...settings, amazon_connect: { ...settings.amazon_connect, ...next } });
	const setAiAgent = (next) => setSettings({ ...settings, ai_agent: { ...settings.ai_agent, ...next } });
	const trackingEnabled = !!settings.enabled;
	const enrichmentProviderChosen = !!(settings.enrichment?.provider && settings.enrichment.provider !== 'none');
	const enrichmentConnected = hasEnrichmentConfig(settings.enrichment || {});
	const connectEnabled = !!settings.amazon_connect?.enabled;
	const connectConfigured = hasConnectConfig(settings.amazon_connect || {});
	const aiEnabled = !!settings.ai_agent?.enabled;
	const connectLiveReady = connectConfigured && !contactFlowData?.error;
	const aiProviderReady = aiEnabled
		&& connectConfigured
		&& !!(settings.ai_agent.assistant_id || '').trim()
		&& !assistantData?.error;
	const connectAssistantOptions = [{ label: __('Choose an Amazon Q in Connect assistant', 'adaptive-customer-engagement'), value: '' }].concat(
		((assistantData?.items) || []).map((item) => ({
			label: `${item.name || item.assistantId}${item.status ? ` (${item.status})` : ''}`,
			value: item.assistantId || '',
		}))
	);
	const selectedAssistant = ((assistantData?.items) || []).find((item) => item.assistantId === settings.ai_agent.assistant_id);
	const lexBotOptions = [{ label: __('Choose an Amazon Lex V2 bot', 'adaptive-customer-engagement'), value: '' }].concat(
		((lexBotData?.items) || []).map((item) => ({
			label: `${item.botName || item.botId}${item.botStatus ? ` (${item.botStatus})` : ''}`,
			value: item.botId || '',
		}))
	);
	const selectedLexBot = ((lexBotData?.items) || []).find((item) => item.botId === (settings.amazon_connect.lex_bot_id || ''));
	const siteBotConnected = !!(settings.amazon_connect?.lex_bot_id || '').trim();
	const lexAliasOptions = [{ label: __('Choose a bot alias', 'adaptive-customer-engagement'), value: '' }].concat(
		((lexBotData?.aliases) || []).map((item) => ({
			label: `${item.botAliasName || item.botAliasId}${item.botAliasStatus ? ` (${item.botAliasStatus})` : ''}`,
			value: item.botAliasId || '',
		}))
	);
	const selectedLexAlias = ((lexBotData?.aliases) || []).find((item) => item.botAliasId === (settings.amazon_connect.lex_bot_alias_id || ''));
	const lexLocaleOptions = [{ label: __('Choose a bot locale', 'adaptive-customer-engagement'), value: '' }].concat(
		((lexBotData?.locales) || []).map((item) => ({
			label: `${item.localeId || item.localeName}${item.botLocaleStatus ? ` (${item.botLocaleStatus})` : ''}`,
			value: item.localeId || '',
		}))
	);
	const selectedLexLocale = ((lexBotData?.locales) || []).find((item) => item.localeId === (settings.amazon_connect.lex_bot_locale_id || ''));
	const lexIntentOptions = [{ label: __('Choose a preferred Lex intent', 'adaptive-customer-engagement'), value: '' }].concat(
		((lexBotData?.intents) || []).map((item) => ({
			label: item.intentName || item.intentDisplayName || item.intentId,
			value: item.intentName || '',
		}))
	);
	const selectedLexIntent = ((lexBotData?.intents) || []).find((item) => item.intentName === (settings.amazon_connect.lex_bot_intent_name || ''));
	const knowledgeEntries = settings.ai_agent?.bot_knowledge_entries || [];
	const enabledKnowledgeEntries = knowledgeEntries.filter((entry) => entry.enabled && (entry.question || '').trim() && (entry.answer || '').trim());
	const inferredLexBotLocale = (settings.amazon_connect?.lex_bot_locale_id || lexBotData?.selected?.locale_id || 'en_GB').trim() || 'en_GB';
	const storedLexRoleArn = (settings.amazon_connect?.lex_bot_role_arn || lexBotData?.selected?.role_arn || '').trim();
	const usingSavedDedicatedLexRole = !!storedLexRoleArn && !isServiceLinkedLexRoleArn(storedLexRoleArn);
	const inferredLexRoleSummary = usingSavedDedicatedLexRole
		? __('Reuse the saved dedicated Lex bot role', 'adaptive-customer-engagement')
		: __('Create or reuse a dedicated Lex bot role automatically', 'adaptive-customer-engagement');
	const canCreateLexBot = !!lexBotDraft.name.trim() && enabledKnowledgeEntries.length > 0;
	const defaultChatApiEndpoint = `${config.root.replace(/\/$/, '')}/${config.namespace}/bot/site-context/answer`;
	const siteSearchApiEndpoint = `${config.root.replace(/\/$/, '')}/${config.namespace}/bot/site-context/search`;
	const siteDocumentApiEndpointBase = `${config.root.replace(/\/$/, '')}/${config.namespace}/bot/site-context/document`;
	const chatApiEndpoint = (settings.amazon_connect.chat_api_endpoint || '').trim() || defaultChatApiEndpoint;
	const connectFlowOptions = [{ label: __('Choose a contact flow', 'adaptive-customer-engagement'), value: '' }].concat(
		((contactFlowData?.items) || []).map((item) => ({
			label: `${item.Name || item.Id}${item.ContactFlowStatus ? ` (${item.ContactFlowStatus})` : ''}`,
			value: item.Id || '',
		}))
	);
	const queueFlowOptions = [{ label: __('No customer queue flow override', 'adaptive-customer-engagement'), value: '' }].concat(
		((queueFlowData?.items) || []).map((item) => ({
			label: `${item.Name || item.Id}${item.ContactFlowStatus ? ` (${item.ContactFlowStatus})` : ''}`,
			value: item.Id || '',
		}))
	);
	const queueOptions = [{ label: __('Choose a queue', 'adaptive-customer-engagement'), value: '' }].concat(
		((queueData?.items) || []).map((item) => ({
			label: item.Name || item.Id,
			value: item.Id || '',
		}))
	);
	const connectHighlights = connectReadiness
		? [
			{ label: __('Testing status', 'adaptive-customer-engagement'), value: connectReadiness.summary?.is_ready_for_testing ? __('Ready for Connect testing', 'adaptive-customer-engagement') : __('Pre-flight still needed', 'adaptive-customer-engagement') },
			{ label: __('Active numbers', 'adaptive-customer-engagement'), value: `${connectReadiness.summary?.active_numbers || 0}` },
			{ label: __('Mapped numbers', 'adaptive-customer-engagement'), value: `${(connectReadiness.summary?.connect_phone_ids || 0) + (connectReadiness.summary?.connect_flow_ids || 0)}` },
			{ label: __('Imported calls', 'adaptive-customer-engagement'), value: `${connectImportStatus?.summary?.imported_total || 0}` },
		]
		: [{ label: __('Status', 'adaptive-customer-engagement'), value: __('Loading readiness', 'adaptive-customer-engagement') }];
	const commonHighlights = [
		{ label: __('Shell', 'adaptive-customer-engagement'), value: __('Native WordPress admin', 'adaptive-customer-engagement') },
		{ label: __('Defaults', 'adaptive-customer-engagement'), value: __('Privacy-first', 'adaptive-customer-engagement') },
	];
	const resolvedNotice = typeof notice === 'string' ? { status: 'success', message: notice } : notice;
	const openLexBotCreateModal = () => {
		setLexBotCreateResult(null);
		setIsLexBotCreateModalOpen(true);
	};
	const closeLexBotCreateModal = () => {
		if (busy) {
			return;
		}
		setIsLexBotCreateModalOpen(false);
		setLexBotCreateResult(null);
	};

	const sections = {
		settings: createElement(
			Fragment,
			null,
			createElement(SettingsPageIntro, {
				eyebrow: __('Tracking configuration', 'adaptive-customer-engagement'),
				title: __('Control what the plugin tracks and how long visitors stay stitched together.', 'adaptive-customer-engagement'),
				description: __('Set the first-party tracking rules, session behaviour, and collection scope used across the service.', 'adaptive-customer-engagement'),
				highlights: commonHighlights.concat([
					{ label: __('Scope', 'adaptive-customer-engagement'), value: __('Sessions, calls, forms, downloads', 'adaptive-customer-engagement') },
				]),
			}),
			createElement(SettingsPanel, {
				title: __('Collection and exclusions', 'adaptive-customer-engagement'),
				description: __('Enable or disable the key first-party signals collected by the service.', 'adaptive-customer-engagement'),
			},
			createElement(SettingsToggleList, null,
				createElement(ToggleControl, { label: __('Enable tracking', 'adaptive-customer-engagement'), checked: !!settings.enabled, onChange: (next) => setSettings({ ...settings, enabled: next }) }),
				createElement(ToggleControl, { label: __('Track pageviews', 'adaptive-customer-engagement'), checked: !!settings.tracking.track_pageviews, onChange: (next) => setTracking({ track_pageviews: next }) }),
				createElement(ToggleControl, { label: __('Track click-to-call events', 'adaptive-customer-engagement'), checked: !!settings.tracking.track_click_to_call, onChange: (next) => setTracking({ track_click_to_call: next }) }),
				createElement(ToggleControl, { label: __('Track download events', 'adaptive-customer-engagement'), checked: !!settings.tracking.track_downloads, onChange: (next) => setTracking({ track_downloads: next }) }),
				createElement(ToggleControl, { label: __('Track native form submissions', 'adaptive-customer-engagement'), checked: !!settings.tracking.track_forms, onChange: (next) => setTracking({ track_forms: next }) }),
				createElement(ToggleControl, { label: __('Ignore logged-in admins', 'adaptive-customer-engagement'), checked: !!settings.tracking.ignore_logged_in_admins, onChange: (next) => setTracking({ ignore_logged_in_admins: next }) }),
				createElement(ToggleControl, { label: __('Respect Do Not Track', 'adaptive-customer-engagement'), checked: !!settings.tracking.respect_dnt, onChange: (next) => setTracking({ respect_dnt: next }) }),
			)),
			createElement(SettingsPanel, {
				title: __('Cookies and session behaviour', 'adaptive-customer-engagement'),
				description: __('Configure cookie names and retention periods for stable, privacy-conscious session tracking.', 'adaptive-customer-engagement'),
			},
			trackingEnabled
				? createElement(SettingsFieldGrid, null,
					createElement(TextControl, { label: __('Session cookie name', 'adaptive-customer-engagement'), value: settings.tracking.cookie_name, onChange: (next) => setTracking({ cookie_name: next }) }),
					createElement(TextControl, { label: __('Visitor cookie name', 'adaptive-customer-engagement'), value: settings.tracking.visitor_cookie_name, onChange: (next) => setTracking({ visitor_cookie_name: next }) }),
					createElement(TextControl, { label: __('Session lifetime (minutes)', 'adaptive-customer-engagement'), type: 'number', value: settings.tracking.session_lifetime_minutes, onChange: (next) => setTracking({ session_lifetime_minutes: Number(next || 30) }) }),
					createElement(TextControl, { label: __('Visitor lifetime (days)', 'adaptive-customer-engagement'), type: 'number', value: settings.tracking.visitor_lifetime_days, onChange: (next) => setTracking({ visitor_lifetime_days: Number(next || 90) }) }),
				)
				: createElement(Notice, { status: 'info', isDismissible: false }, __('Enable tracking first to reveal the cookie and session controls.', 'adaptive-customer-engagement'))
			),
			createElement(SettingsPanel, {
				title: __('Call intent selectors', 'adaptive-customer-engagement'),
				description: __('Add any additional selectors that should be treated as call-intent triggers alongside standard tel: links.', 'adaptive-customer-engagement'),
			},
			trackingEnabled
				? createElement(TextareaControl, {
					label: __('Call tracking selectors', 'adaptive-customer-engagement'),
					help: __('One CSS selector per line. These are used alongside tel: links when the frontend tracker watches for call intent.', 'adaptive-customer-engagement'),
					value: (settings.tracking.call_track_selectors || []).join('\n'),
					onChange: (next) => setTracking({ call_track_selectors: splitLines(next) }),
				})
				: createElement(Notice, { status: 'info', isDismissible: false }, __('Enable tracking first to reveal the call-selector controls.', 'adaptive-customer-engagement')))
		),
		privacy: createElement(
			Fragment,
			null,
			createElement(SettingsPageIntro, {
				eyebrow: __('Privacy controls', 'adaptive-customer-engagement'),
				title: __('Manage privacy, retention, and exclusion rules for service data.', 'adaptive-customer-engagement'),
				description: __('Manage retention windows, exclusions, and cleanup rules for sensitive service data.', 'adaptive-customer-engagement'),
				highlights: commonHighlights.concat([
					{ label: __('Focus', 'adaptive-customer-engagement'), value: __('Retention and exclusion rules', 'adaptive-customer-engagement') },
				]),
			}),
			createElement(SettingsPanel, {
				title: __('Retention windows', 'adaptive-customer-engagement'),
				description: __('Set separate retention periods for raw data and longer-lived reporting records.', 'adaptive-customer-engagement'),
			},
			createElement(SettingsFieldGrid, { compact: true },
				createElement(TextControl, { label: __('Raw IP retention (days)', 'adaptive-customer-engagement'), type: 'number', value: settings.privacy.raw_ip_retention_days, onChange: (next) => setPrivacy({ raw_ip_retention_days: Number(next || 1) }) }),
				createElement(TextControl, { label: __('Raw phone retention (days)', 'adaptive-customer-engagement'), type: 'number', value: settings.privacy.raw_phone_retention_days, onChange: (next) => setPrivacy({ raw_phone_retention_days: Number(next || 1) }) }),
				createElement(TextControl, { label: __('Session retention policy (days)', 'adaptive-customer-engagement'), type: 'number', value: settings.privacy.session_retention_days, onChange: (next) => setPrivacy({ session_retention_days: Number(next || 30) }) }),
				createElement(TextControl, { label: __('Bot retention policy (days)', 'adaptive-customer-engagement'), type: 'number', value: settings.privacy.bot_retention_days, onChange: (next) => setPrivacy({ bot_retention_days: Number(next || 1) }) }),
			)),
			createElement(SettingsPanel, {
				title: __('Exclusions and cleanup', 'adaptive-customer-engagement'),
				description: __('Control service exclusions and run a manual cleanup when required.', 'adaptive-customer-engagement'),
			},
			createElement(SettingsToggleList, null,
				createElement(ToggleControl, { label: __('Ignore internal/private IPs', 'adaptive-customer-engagement'), checked: !!settings.privacy.ignore_internal_ips, onChange: (next) => setPrivacy({ ignore_internal_ips: next }) })
			),
			createElement(SettingsActionRow, null,
				createElement(Button, { variant: 'secondary', onClick: purge, disabled: busy }, __('Run privacy purge now', 'adaptive-customer-engagement'))
			))
		),
		enrichment: createElement(
			Fragment,
			null,
			createElement(SettingsPageIntro, {
				eyebrow: __('IP enrichment setup', 'adaptive-customer-engagement'),
				title: __('Configure provider-backed company and network lookups for enriched reporting.', 'adaptive-customer-engagement'),
				description: __('Choose the enrichment provider, configure cache behaviour, and verify service connectivity from one screen.', 'adaptive-customer-engagement'),
				highlights: commonHighlights.concat([
					{ label: __('Mode', 'adaptive-customer-engagement'), value: __('Optional live lookup', 'adaptive-customer-engagement') },
				]),
			}),
			createElement(SetupGuideCard, {
				title: __('IP enrichment setup', 'adaptive-customer-engagement'),
				description: __('Provider resources and account links for live enrichment setup.', 'adaptive-customer-engagement'),
				links: [
					{ label: __('Get an ipregistry API key', 'adaptive-customer-engagement'), href: 'https://ipregistry.co/' },
					{ label: __('Get an ipinfo API key', 'adaptive-customer-engagement'), href: 'https://ipinfo.io/' },
					{ label: __('Read the ipregistry dashboard docs', 'adaptive-customer-engagement'), href: 'https://ipregistry.co/dashboard' },
					{ label: __('Read the ipinfo account docs', 'adaptive-customer-engagement'), href: 'https://ipinfo.io/account/home' },
				],
			}),
			createElement(SettingsPanel, {
				title: __('Provider connection', 'adaptive-customer-engagement'),
				description: __('Select the enrichment provider and save the service API key.', 'adaptive-customer-engagement'),
			},
			createElement(SettingsFieldGrid, { compact: true },
				createElement(SelectControl, {
					label: __('Provider', 'adaptive-customer-engagement'),
					value: settings.enrichment.provider,
					options: ['none', 'ipregistry', 'ipinfo'].map((entry) => ({ label: entry, value: entry })),
					onChange: (next) => setEnrichment({ provider: next }),
				}),
				createElement(TextControl, { label: __('API key', 'adaptive-customer-engagement'), type: 'password', value: settings.enrichment.api_key, onChange: (next) => setEnrichment({ api_key: next }) }),
			),
			!enrichmentProviderChosen
				? createElement(Notice, { status: 'info', isDismissible: false }, __('Choose an enrichment provider to reveal the live enrichment settings.', 'adaptive-customer-engagement'))
				: !enrichmentConnected
					? createElement(Notice, { status: 'info', isDismissible: false }, __('Save a working API key for the chosen provider to reveal the enrichment controls and lookup tools.', 'adaptive-customer-engagement'))
					: null),
			enrichmentConnected && createElement(SettingsPanel, {
				title: __('Caching and enrichment rules', 'adaptive-customer-engagement'),
				description: __('Control cache retention and lookup rules for the live enrichment service.', 'adaptive-customer-engagement'),
			},
			createElement(SettingsFieldGrid, { compact: true },
				createElement(TextControl, { label: __('Cache length (days)', 'adaptive-customer-engagement'), type: 'number', value: settings.enrichment.cache_days, onChange: (next) => setEnrichment({ cache_days: Number(next || 1) }) }),
			),
			createElement(SettingsToggleList, null,
				createElement(ToggleControl, { label: __('Allow enrichment for bots', 'adaptive-customer-engagement'), checked: !!settings.enrichment.enrich_bots, onChange: (next) => setEnrichment({ enrich_bots: next }) }),
				createElement(ToggleControl, { label: __('Allow enrichment for private IPs', 'adaptive-customer-engagement'), checked: !!settings.enrichment.enrich_private_ips, onChange: (next) => setEnrichment({ enrich_private_ips: next }) })
			)),
			enrichmentConnected && createElement(SettingsPanel, {
				title: __('Lookup test', 'adaptive-customer-engagement'),
				description: __('Run a test lookup to confirm provider connectivity and response quality.', 'adaptive-customer-engagement'),
			},
			createElement(SettingsFieldGrid, { compact: true },
				createElement(TextControl, { label: __('Test lookup IP', 'adaptive-customer-engagement'), value: testIp, onChange: setTestIp })
			),
			createElement(SettingsActionRow, null,
				createElement(Button, { variant: 'secondary', onClick: runEnrichmentTest, disabled: busy || !testIp }, __('Run enrichment test', 'adaptive-customer-engagement'))
			),
			testResult && createElement(
				SettingsResultCard,
				{
					items: [
						{ label: __('Provider', 'adaptive-customer-engagement'), value: testResult.provider },
						{ label: __('Company', 'adaptive-customer-engagement'), value: testResult.company_name },
						{ label: __('Domain', 'adaptive-customer-engagement'), value: testResult.company_domain },
						{ label: __('Type', 'adaptive-customer-engagement'), value: testResult.company_type },
						{ label: __('Location', 'adaptive-customer-engagement'), value: [testResult.city, testResult.region, testResult.country_code].filter(Boolean).join(', ') },
						{ label: __('Network', 'adaptive-customer-engagement'), value: testResult.isp || testResult.asn },
						{ label: __('Confidence', 'adaptive-customer-engagement'), value: testResult.confidence || 'unknown' },
					],
				}
			)
			)
		),
		'amazon-connect': createElement(
			Fragment,
			null,
			createElement(SettingsPageIntro, {
				eyebrow: __('Amazon Connect preparation', 'adaptive-customer-engagement'),
				title: __('Configure Amazon Connect for live number, flow, and telephony operations.', 'adaptive-customer-engagement'),
				description: __('Manage AWS instance details, service resources, and readiness checks for the Amazon Connect integration.', 'adaptive-customer-engagement'),
				highlights: commonHighlights.concat(connectHighlights),
			}),
			createElement(SetupGuideCard, {
				title: __('Amazon Connect setup', 'adaptive-customer-engagement'),
				description: __('AWS and Amazon Connect resources for service setup, flow management, and operational readiness.', 'adaptive-customer-engagement'),
				links: [
					{ label: __('Open the Amazon Connect console', 'adaptive-customer-engagement'), href: 'https://console.aws.amazon.com/connect/home' },
					{ label: __('Create or review AWS access keys', 'adaptive-customer-engagement'), href: 'https://console.aws.amazon.com/iam/home#/security_credentials' },
					{ label: __('Amazon Connect administrator guide', 'adaptive-customer-engagement'), href: 'https://docs.aws.amazon.com/connect/latest/adminguide/what-is-amazon-connect.html' },
					{ label: __('Amazon Connect contact flow guide', 'adaptive-customer-engagement'), href: 'https://docs.aws.amazon.com/connect/latest/adminguide/contact-flow-import-export.html' },
					{ label: __('Amazon Connect contact trace record storage docs', 'adaptive-customer-engagement'), href: 'https://docs.aws.amazon.com/connect/latest/adminguide/data-streaming.html' },
					{ label: __('Amazon Connect flow logs and CloudWatch docs', 'adaptive-customer-engagement'), href: 'https://docs.aws.amazon.com/connect/latest/adminguide/monitoring-cloudwatch.html' },
				],
			}),
			connectConfigured && connectReadiness && createElement(
				SettingsPanel,
				{
					title: __('Connect testing readiness', 'adaptive-customer-engagement'),
					description: __('Review the required checks before enabling live Amazon Connect testing and import activity.', 'adaptive-customer-engagement'),
				},
				createElement(SettingsResultCard, {
					items: [
						{ label: __('Stored calls', 'adaptive-customer-engagement'), value: `${connectReadiness.summary?.stored_calls_total || 0}` },
						{ label: __('Matched calls', 'adaptive-customer-engagement'), value: `${connectReadiness.summary?.matched_calls_total || 0}` },
						{ label: __('Company records', 'adaptive-customer-engagement'), value: `${connectReadiness.summary?.company_records_total || 0}` },
						{ label: __('Connect-ready numbers', 'adaptive-customer-engagement'), value: `${connectReadiness.summary?.connect_phone_ids || 0}` },
					],
				}),
				createElement(SettingsChecklist, { items: connectReadiness.checklist || [], columns: 2 })
			),
			connectConfigured && connectImportStatus && createElement(
				SettingsPanel,
				{
					title: __('Call import and matching', 'adaptive-customer-engagement'),
					description: __('Import recent Amazon Connect contact trace records from the configured S3 export path and review how many calls are being matched back to tracked journeys.', 'adaptive-customer-engagement'),
				},
				createElement(SettingsResultCard, {
					items: [
						{ label: __('Imported calls', 'adaptive-customer-engagement'), value: `${connectImportStatus.summary?.imported_total || 0}` },
						{ label: __('Matched calls', 'adaptive-customer-engagement'), value: `${connectImportStatus.summary?.matched_total || 0}` },
						{ label: __('Number-linked calls', 'adaptive-customer-engagement'), value: `${connectImportStatus.summary?.number_matched_total || 0}` },
						{ label: __('Last import', 'adaptive-customer-engagement'), value: connectImportStatus.summary?.last_imported_at || '—' },
						{ label: __('Latest stored Connect call', 'adaptive-customer-engagement'), value: connectImportStatus.summary?.latest_call_started_at || '—' },
						{ label: __('Export path', 'adaptive-customer-engagement'), value: [connectImportStatus.config?.s3_bucket, connectImportStatus.config?.s3_prefix].filter(Boolean).join(' / ') || '—' },
					],
				}),
				connectImportStatus.last_run
					? createElement(SettingsResultCard, {
						items: [
							{ label: __('Last run status', 'adaptive-customer-engagement'), value: connectImportStatus.last_run?.status || 'idle' },
							{ label: __('Run started', 'adaptive-customer-engagement'), value: connectImportStatus.last_run?.started_at || '—' },
							{ label: __('Run completed', 'adaptive-customer-engagement'), value: connectImportStatus.last_run?.completed_at || '—' },
							{ label: __('Objects scanned', 'adaptive-customer-engagement'), value: `${connectImportStatus.last_run?.objects_scanned || 0}` },
							{ label: __('Records found', 'adaptive-customer-engagement'), value: `${connectImportStatus.last_run?.records_found || 0}` },
							{ label: __('Import window', 'adaptive-customer-engagement'), value: connectImportStatus.last_run?.lookback_hours ? sprintf(__('%d hours', 'adaptive-customer-engagement'), connectImportStatus.last_run.lookback_hours) : '—' },
						],
					})
					: null,
				createElement(SettingsActionRow, null,
					createElement(Button, { variant: 'primary', onClick: importConnectCalls, disabled: busy }, __('Import recent Connect calls', 'adaptive-customer-engagement')),
					createElement(Button, { variant: 'secondary', onClick: refreshConnectImportStatus, disabled: busy }, __('Refresh import status', 'adaptive-customer-engagement'))
				),
				connectImportStatus.last_run?.errors?.length
					? createElement(
						Notice,
						{ status: connectImportStatus.last_run?.status === 'error' ? 'error' : 'warning', isDismissible: false },
						createElement('div', null, __('The latest import reported warnings or errors:', 'adaptive-customer-engagement')),
						createElement(
							'ul',
							{ style: { marginBottom: 0 } },
							(connectImportStatus.last_run.errors || []).map((message, index) =>
								createElement('li', { key: `${index}-${message}` }, message)
							)
						)
					)
					: null,
				createElement(Notice, { status: 'info', isDismissible: false }, __('The current import reads recent voice contact trace records from S3, stores the call details locally, and matches them to linked numbers plus the nearest recent click-to-call session where one is available.', 'adaptive-customer-engagement'))
			),
			connectConfigured && assistantData && createElement(
				SettingsPanel,
				{
					title: __('Amazon Q in Connect assistants', 'adaptive-customer-engagement'),
					description: __('Review the assistants visible to the current AWS credentials and confirm access to the Amazon Q in Connect service.', 'adaptive-customer-engagement'),
				},
				assistantData.error
					? createElement(Notice, { status: 'warning', isDismissible: false }, assistantData.error)
					: createElement(
						Fragment,
						null,
						createElement(SettingsResultCard, {
							items: [
								{ label: __('Assistants found', 'adaptive-customer-engagement'), value: `${(assistantData.items || []).length}` },
							],
						}),
						createElement(ConnectAssistantsTable, { items: assistantData.items || [] })
					)
			),
			connectConfigured && contactFlowData && createElement(
				SettingsPanel,
				{
					title: __('Contact flows', 'adaptive-customer-engagement'),
					description: __('Create and review Amazon Connect contact flows for routing, queueing, and forwarding services.', 'adaptive-customer-engagement'),
				},
				contactFlowData.error
					? createElement(Notice, { status: 'warning', isDismissible: false }, contactFlowData.error)
					: createElement(
						Fragment,
						null,
						createElement(SettingsFieldGrid, null,
							createElement(SelectControl, {
								label: __('Template type', 'adaptive-customer-engagement'),
								value: flowDraft.template_type,
								options: [
									{ label: __('Greeting then disconnect', 'adaptive-customer-engagement'), value: 'message_disconnect' },
									{ label: __('Website chat via connected bot', 'adaptive-customer-engagement'), value: 'lex_chat' },
									{ label: __('Queue routing', 'adaptive-customer-engagement'), value: 'queue_transfer' },
									{ label: __('Customer queue / hold message', 'adaptive-customer-engagement'), value: 'customer_queue' },
									{ label: __('Call forwarding', 'adaptive-customer-engagement'), value: 'call_forward' },
								],
								onChange: (next) => setFlowDraft({
									...flowDraft,
									template_type: next,
									queue_id: next === 'queue_transfer' ? flowDraft.queue_id : '',
									queue_flow_id: next === 'queue_transfer' ? flowDraft.queue_flow_id : '',
									target_phone_number: next === 'call_forward' ? flowDraft.target_phone_number : '',
									caller_id_number: next === 'call_forward' ? flowDraft.caller_id_number : '',
									timeout_seconds: next === 'call_forward' ? flowDraft.timeout_seconds : CONNECT_FLOW_DRAFT_DEFAULTS.timeout_seconds,
									dtmf_sequence: next === 'call_forward' ? flowDraft.dtmf_sequence : '',
									resume_after_disconnect: next === 'call_forward' ? flowDraft.resume_after_disconnect : false,
									failure_message: (next === 'queue_transfer' || next === 'call_forward' || next === 'lex_chat') ? flowDraft.failure_message : CONNECT_FLOW_DRAFT_DEFAULTS.failure_message,
									set_as_chat_flow: next === 'lex_chat' ? true : CONNECT_FLOW_DRAFT_DEFAULTS.set_as_chat_flow,
								}),
							}),
							createElement(TextControl, {
								label: __('New flow name', 'adaptive-customer-engagement'),
								value: flowDraft.name,
								onChange: (next) => setFlowDraft({ ...flowDraft, name: next }),
							}),
							createElement(TextControl, {
								label: __('New flow description', 'adaptive-customer-engagement'),
								value: flowDraft.description,
								onChange: (next) => setFlowDraft({ ...flowDraft, description: next }),
							}),
							createElement(TextareaControl, {
								label: flowDraft.template_type === 'queue_transfer' ? __('Greeting message', 'adaptive-customer-engagement') : flowDraft.template_type === 'customer_queue' ? __('Queue / hold message', 'adaptive-customer-engagement') : flowDraft.template_type === 'lex_chat' ? __('Opening chat message', 'adaptive-customer-engagement') : __('Prompt message', 'adaptive-customer-engagement'),
								help: flowDraft.template_type === 'queue_transfer'
									? __('This message plays before the caller is placed into the selected Amazon Connect queue.', 'adaptive-customer-engagement')
									: flowDraft.template_type === 'customer_queue'
										? __('This becomes the customer queue flow message that callers hear while they are waiting in queue.', 'adaptive-customer-engagement')
										: flowDraft.template_type === 'lex_chat'
											? __('This welcome message is sent before the connected site bot takes over the website chat.', 'adaptive-customer-engagement')
									: __('This creates a published greeting-and-disconnect flow that can be assigned immediately and extended later in Amazon Connect if required.', 'adaptive-customer-engagement'),
								value: flowDraft.message,
								onChange: (next) => setFlowDraft({ ...flowDraft, message: next }),
							}),
							flowDraft.template_type === 'lex_chat'
								? createElement(Notice, { status: selectedLexBot ? 'info' : 'warning', isDismissible: false }, selectedLexBot
									? sprintf(__('This flow will invoke the connected bot %s through Amazon Connect and can be saved straight into the website chat setting.', 'adaptive-customer-engagement'), selectedLexBot.botName || selectedLexBot.botId)
									: __('Connect a site bot first before creating a website chat flow.', 'adaptive-customer-engagement'))
								: null,
							flowDraft.template_type === 'queue_transfer'
								? createElement(SelectControl, {
									label: __('Amazon Connect queue', 'adaptive-customer-engagement'),
									value: flowDraft.queue_id,
									options: queueOptions,
									onChange: (next) => setFlowDraft({ ...flowDraft, queue_id: next }),
								})
								: null,
							flowDraft.template_type === 'queue_transfer'
								? createElement(SelectControl, {
									label: __('Customer queue flow', 'adaptive-customer-engagement'),
									help: __('Optional. The selected queue flow will be applied to the waiting experience when callers are transferred into the chosen queue.', 'adaptive-customer-engagement'),
									value: flowDraft.queue_flow_id,
									options: queueFlowOptions,
									onChange: (next) => setFlowDraft({ ...flowDraft, queue_flow_id: next }),
								})
								: null,
							flowDraft.template_type === 'call_forward'
								? createElement(TextControl, {
									label: __('Forward to number', 'adaptive-customer-engagement'),
									help: __('Use E.164 format, for example +441234567890.', 'adaptive-customer-engagement'),
									value: flowDraft.target_phone_number,
									onChange: (next) => setFlowDraft({ ...flowDraft, target_phone_number: next }),
								})
								: null,
							flowDraft.template_type === 'call_forward'
								? createElement(TextControl, {
									label: __('Caller ID number', 'adaptive-customer-engagement'),
									help: __('Optional. Use one of your valid Connect numbers in E.164 format if you want a specific caller ID presented.', 'adaptive-customer-engagement'),
									value: flowDraft.caller_id_number,
									onChange: (next) => setFlowDraft({ ...flowDraft, caller_id_number: next }),
								})
								: null,
							flowDraft.template_type === 'call_forward'
								? createElement(TextControl, {
									label: __('Transfer timeout (seconds)', 'adaptive-customer-engagement'),
									type: 'number',
									value: flowDraft.timeout_seconds,
									onChange: (next) => setFlowDraft({ ...flowDraft, timeout_seconds: Number(next || CONNECT_FLOW_DRAFT_DEFAULTS.timeout_seconds) }),
								})
								: null,
							flowDraft.template_type === 'call_forward'
								? createElement(TextControl, {
									label: __('DTMF sequence', 'adaptive-customer-engagement'),
									help: __('Optional. Enter any post-answer DTMF you want Connect to send to the forwarded destination.', 'adaptive-customer-engagement'),
									value: flowDraft.dtmf_sequence,
									onChange: (next) => setFlowDraft({ ...flowDraft, dtmf_sequence: next }),
								})
								: null,
							(flowDraft.template_type === 'queue_transfer' || flowDraft.template_type === 'call_forward' || flowDraft.template_type === 'lex_chat')
								? createElement(TextareaControl, {
									label: __('Fallback message', 'adaptive-customer-engagement'),
									help: flowDraft.template_type === 'queue_transfer'
										? __('This message plays if the queue cannot accept the caller or another queueing error occurs.', 'adaptive-customer-engagement')
										: flowDraft.template_type === 'lex_chat'
											? __('This message is sent if Amazon Connect cannot hand the customer over to the selected site bot.', 'adaptive-customer-engagement')
										: __('This message plays if the forwarding destination fails or another transfer error occurs.', 'adaptive-customer-engagement'),
									value: flowDraft.failure_message,
									onChange: (next) => setFlowDraft({ ...flowDraft, failure_message: next }),
								})
								: null,
						),
						createElement(SettingsToggleList, null,
							flowDraft.template_type === 'call_forward'
								? createElement(ToggleControl, {
									label: __('Resume the flow after the external party disconnects', 'adaptive-customer-engagement'),
									checked: !!flowDraft.resume_after_disconnect,
									onChange: (next) => setFlowDraft({ ...flowDraft, resume_after_disconnect: next }),
								})
								: null,
							createElement(ToggleControl, {
								label: __('Set this as the default contact flow after creation', 'adaptive-customer-engagement'),
								checked: !!flowDraft.set_as_default,
								onChange: (next) => setFlowDraft({ ...flowDraft, set_as_default: next }),
							}),
							flowDraft.template_type === 'lex_chat'
								? createElement(ToggleControl, {
									label: __('Use this as the website chat flow', 'adaptive-customer-engagement'),
									checked: !!flowDraft.set_as_chat_flow,
									onChange: (next) => setFlowDraft({ ...flowDraft, set_as_chat_flow: next }),
								})
								: null
						),
						createElement(SettingsActionRow, null,
							createElement(Button, { variant: 'primary', onClick: createConnectFlow, disabled: busy || !flowDraft.name.trim() || !flowDraft.message.trim() || (flowDraft.template_type === 'queue_transfer' && !flowDraft.queue_id) || (flowDraft.template_type === 'call_forward' && !flowDraft.target_phone_number.trim()) || (flowDraft.template_type === 'lex_chat' && !selectedLexBot) }, flowDraft.template_type === 'queue_transfer' ? __('Create queue flow in Connect', 'adaptive-customer-engagement') : flowDraft.template_type === 'customer_queue' ? __('Create customer queue flow in Connect', 'adaptive-customer-engagement') : flowDraft.template_type === 'call_forward' ? __('Create forwarding flow in Connect', 'adaptive-customer-engagement') : flowDraft.template_type === 'lex_chat' ? __('Create website chat flow in Connect', 'adaptive-customer-engagement') : __('Create standard flow in Connect', 'adaptive-customer-engagement')),
							createElement(Button, { variant: 'secondary', onClick: refreshConnectFlows, disabled: busy }, __('Refresh flows', 'adaptive-customer-engagement'))
						),
						createElement(SettingsResultCard, {
							items: [
								{ label: __('Visible flows', 'adaptive-customer-engagement'), value: `${(contactFlowData.items || []).length}` },
								{ label: __('Visible queue flows', 'adaptive-customer-engagement'), value: `${(queueFlowData?.items || []).length}` },
								{ label: __('Visible queues', 'adaptive-customer-engagement'), value: `${(queueData?.items || []).length}` },
							],
						}),
						queueFlowData?.error ? createElement(Notice, { status: 'warning', isDismissible: false }, queueFlowData.error) : null,
						queueData?.error ? createElement(Notice, { status: 'warning', isDismissible: false }, queueData.error) : null,
						createElement(ConnectContactFlowsTable, { items: contactFlowData.items || [] }),
						createElement(ConnectQueueFlowsTable, { items: queueFlowData?.items || [] }),
						createElement(ConnectQueuesTable, { items: queueData?.items || [] })
					)
			),
			createElement(SettingsPanel, {
				title: __('Connection mode', 'adaptive-customer-engagement'),
				description: __('Choose how the service should authenticate with AWS. Saved access keys are used first; IAM role credentials are used as a fallback when keys are not present.', 'adaptive-customer-engagement'),
			},
			createElement(SettingsToggleList, null,
				createElement(ToggleControl, { label: __('Enable Amazon Connect features', 'adaptive-customer-engagement'), checked: !!settings.amazon_connect.enabled, onChange: (next) => setAmazonConnect({ enabled: next }) }),
				createElement(ToggleControl, { label: __('Allow IAM role fallback when saved keys are not present', 'adaptive-customer-engagement'), checked: !!settings.amazon_connect.use_iam_role, onChange: (next) => setAmazonConnect({ use_iam_role: next }) })
			)),
			createElement(SettingsPanel, {
				title: __('Instance and credentials', 'adaptive-customer-engagement'),
				description: __('Enter the Amazon Connect region, instance, storage targets, and access credentials used by the service.', 'adaptive-customer-engagement'),
			},
			connectEnabled
				? createElement(SettingsFieldGrid, null,
				createElement(TextControl, { label: __('AWS region', 'adaptive-customer-engagement'), value: settings.amazon_connect.region, onChange: (next) => setAmazonConnect({ region: next }) }),
				createElement(TextControl, { label: __('Amazon Connect instance ID', 'adaptive-customer-engagement'), help: __('If you leave this blank but save the Connect access URL and working AWS keys, the plugin will try to resolve the instance ID automatically.', 'adaptive-customer-engagement'), value: settings.amazon_connect.instance_id, onChange: (next) => setAmazonConnect({ instance_id: next }) }),
				createElement(TextControl, { label: __('Amazon Connect instance URL', 'adaptive-customer-engagement'), help: __('You can paste the Connect access URL here instead of hunting for the instance ID. When the AWS keys are valid, the plugin will try to match it back to the correct instance automatically.', 'adaptive-customer-engagement'), value: settings.amazon_connect.instance_url || '', onChange: (next) => setAmazonConnect({ instance_url: next }) }),
				createElement(TextControl, { label: __('S3 bucket', 'adaptive-customer-engagement'), value: settings.amazon_connect.s3_bucket || '', onChange: (next) => setAmazonConnect({ s3_bucket: next }) }),
				createElement(TextControl, { label: __('S3 prefix', 'adaptive-customer-engagement'), value: settings.amazon_connect.s3_prefix || '', onChange: (next) => setAmazonConnect({ s3_prefix: next }) }),
				createElement(TextControl, { label: __('Flow log group', 'adaptive-customer-engagement'), value: settings.amazon_connect.flow_logs_group || '', onChange: (next) => setAmazonConnect({ flow_logs_group: next }) }),
				createElement(TextControl, { label: __('AWS access key ID', 'adaptive-customer-engagement'), value: settings.amazon_connect.access_key_id, onChange: (next) => setAmazonConnect({ access_key_id: next }) }),
				createElement(TextControl, { label: __('AWS secret access key', 'adaptive-customer-engagement'), type: 'password', value: settings.amazon_connect.secret_access_key, onChange: (next) => setAmazonConnect({ secret_access_key: next }) }),
			)
				: createElement(Notice, { status: 'info', isDismissible: false }, __('Enable Amazon Connect features first to reveal the connection fields.', 'adaptive-customer-engagement'))),
			connectEnabled && !connectConfigured
				? createElement(Notice, { status: 'info', isDismissible: false }, __('Save the Amazon Connect region and instance ID, then add AWS access keys or enable IAM role fallback to unlock the remaining service settings.', 'adaptive-customer-engagement'))
				: null,
			connectConfigured && createElement(SettingsPanel, {
				title: __('Flows and webhook handling', 'adaptive-customer-engagement'),
				description: __('Manage default flow selection, webhook security, and the live site-content runtime endpoint used by bot integrations.', 'adaptive-customer-engagement'),
				tone: 'soft',
			},
			connectLiveReady
				? createElement(SettingsFieldGrid, null,
					createElement(SelectControl, { label: __('Default contact flow', 'adaptive-customer-engagement'), value: settings.amazon_connect.default_contact_flow_id, options: connectFlowOptions, onChange: (next) => setAmazonConnect({ default_contact_flow_id: next }) }),
					createElement(SelectControl, { label: __('Chat contact flow', 'adaptive-customer-engagement'), value: settings.amazon_connect.chat_contact_flow_id, options: connectFlowOptions, onChange: (next) => setAmazonConnect({ chat_contact_flow_id: next }) }),
					createElement(TextControl, { label: __('Chat API endpoint', 'adaptive-customer-engagement'), help: __('Use this endpoint from a Lambda hook or other bot runtime when you want live answers from the current pages, posts, and products. Leave it blank to use the built-in WordPress endpoint shown below.', 'adaptive-customer-engagement'), value: settings.amazon_connect.chat_api_endpoint || '', onChange: (next) => setAmazonConnect({ chat_api_endpoint: next }) }),
					createElement(TextControl, { label: __('Webhook secret', 'adaptive-customer-engagement'), value: settings.amazon_connect.webhook_secret, onChange: (next) => setAmazonConnect({ webhook_secret: next }) }),
				)
				: createElement(Notice, { status: 'info', isDismissible: false }, __('Finish the live Amazon Connect connection first to reveal the flow-default and webhook settings.', 'adaptive-customer-engagement')),
			connectLiveReady
				? createElement(Notice, { status: settings.amazon_connect.chat_contact_flow_id ? 'success' : 'info', isDismissible: false }, settings.amazon_connect.chat_contact_flow_id
					? __('The frontend widget now starts chat through the selected Chat contact flow, so that flow should be the one that invokes the site bot inside Amazon Connect.', 'adaptive-customer-engagement')
					: __('If Chat contact flow is left blank, the hosted widget keeps using whatever flow is baked into the Connect snippet. Set it here to make WordPress start chat against the chosen flow explicitly.', 'adaptive-customer-engagement'))
				: null,
			connectLiveReady
				? createElement(Notice, { status: settings.amazon_connect.webhook_secret ? 'success' : 'warning', isDismissible: false }, settings.amazon_connect.webhook_secret
					? sprintf(__('Live answer endpoint: %1$s. Search endpoint: %2$s. Document endpoint base: %3$s/{postId}. Send the webhook secret in the X-ACE-Webhook-Secret header from the bot runtime.', 'adaptive-customer-engagement'), chatApiEndpoint, siteSearchApiEndpoint, siteDocumentApiEndpointBase)
					: __('Save a webhook secret before using the live site answer, search, or document endpoints from a bot runtime.', 'adaptive-customer-engagement'))
				: null),
			connectConfigured
				? createElement(Notice, { status: connectReadiness?.summary?.is_ready_for_testing ? 'success' : 'info', isDismissible: false }, connectReadiness?.summary?.is_ready_for_testing ? __('Amazon Connect configuration is ready for live testing and service validation.', 'adaptive-customer-engagement') : __('Amazon Connect configuration has been saved. Complete the readiness checklist before enabling live import, sync, and call matching.', 'adaptive-customer-engagement'))
				: null
		),
		'ai-agent': createElement(
			Fragment,
			null,
			createElement(SettingsPageIntro, {
				eyebrow: __('Connect AI and Lex bots', 'adaptive-customer-engagement'),
				title: __('Configure the Connect assistant, managed Lex bot, and site knowledge used for assisted service workflows.', 'adaptive-customer-engagement'),
				description: connectConfigured
					? __('Choose the live Connect assistant, manage the Lex chat bot and its site knowledge, and prepare the handoff rules from one control surface.', 'adaptive-customer-engagement')
					: __('This page becomes available once Amazon Connect is connected so the plugin can read the live assistants for this account and region.', 'adaptive-customer-engagement'),
				highlights: commonHighlights.concat([
					{ label: __('Runtime', 'adaptive-customer-engagement'), value: __('Amazon Q in Connect only', 'adaptive-customer-engagement') },
				]),
			}),
			createElement(SetupGuideCard, {
				title: __('Amazon Q in Connect setup', 'adaptive-customer-engagement'),
				description: __('Use the existing Amazon Connect estate for assistant setup so call handling, routing, and AI context stay on the same operational stack.', 'adaptive-customer-engagement'),
				links: [
					{ label: __('Open the Amazon Connect console', 'adaptive-customer-engagement'), href: 'https://console.aws.amazon.com/connect/home' },
					{ label: __('Amazon Q in Connect administrator guide', 'adaptive-customer-engagement'), href: 'https://docs.aws.amazon.com/connect/latest/adminguide/amazon-q-connect.html' },
					{ label: __('Amazon Q in Connect assistant setup docs', 'adaptive-customer-engagement'), href: 'https://docs.aws.amazon.com/connect/latest/adminguide/create-ai-assistant.html' },
					{ label: __('Amazon Connect contact attributes docs', 'adaptive-customer-engagement'), href: 'https://docs.aws.amazon.com/connect/latest/adminguide/connect-attrib-list.html' },
				],
			}),
			connectConfigured
				? createElement(SettingsPanel, {
					title: __('Create site assistant', 'adaptive-customer-engagement'),
					description: __('Create a single Amazon Q in Connect assistant for this site and optionally link it as the active assistant straight away.', 'adaptive-customer-engagement'),
				},
				createElement(SettingsFieldGrid, { compact: true },
					createElement(TextControl, {
						label: __('Assistant name', 'adaptive-customer-engagement'),
						value: assistantDraft.name,
						onChange: (next) => setAssistantDraft({ ...assistantDraft, name: next }),
					}),
					createElement(TextControl, {
						label: __('Assistant description', 'adaptive-customer-engagement'),
						value: assistantDraft.description,
						onChange: (next) => setAssistantDraft({ ...assistantDraft, description: next }),
					}),
				),
				createElement(SettingsToggleList, null,
					createElement(ToggleControl, {
						label: __('Link this assistant as the site assistant after creation', 'adaptive-customer-engagement'),
						checked: !!assistantDraft.link_to_ai_agent,
						onChange: (next) => setAssistantDraft({ ...assistantDraft, link_to_ai_agent: next }),
					})
				),
				(assistantData?.items || []).length
					? createElement(Notice, { status: 'info', isDismissible: false }, __('A single sitewide assistant is usually enough here. Create another only if you want to replace or separate the current Connect assistant setup.', 'adaptive-customer-engagement'))
					: null,
				createElement(SettingsActionRow, null,
					createElement(Button, { variant: 'primary', onClick: createConnectAssistant, disabled: busy || !assistantDraft.name.trim() }, __('Create Amazon Q in Connect assistant', 'adaptive-customer-engagement'))
				))
				: null,
			!connectConfigured
				? createElement(Notice, { status: 'info', isDismissible: false }, __('Connect the Amazon Connect account first. Once the connection is working, this page will automatically switch over to the live Amazon Q in Connect assistant options.', 'adaptive-customer-engagement'))
				: createElement(SettingsPanel, {
					title: __('Mode and assistant', 'adaptive-customer-engagement'),
					description: __('Enable the AI surface, choose how it should behave operationally, and select the live Amazon Q in Connect assistant.', 'adaptive-customer-engagement'),
				},
				createElement(SettingsToggleList, null,
					createElement(ToggleControl, { label: __('Enable AI agent features', 'adaptive-customer-engagement'), checked: !!settings.ai_agent.enabled, onChange: (next) => setAiAgent({ enabled: next }) }),
					aiEnabled ? createElement(ToggleControl, { label: __('Allow human handoff', 'adaptive-customer-engagement'), checked: !!settings.ai_agent.handoff_to_human, onChange: (next) => setAiAgent({ handoff_to_human: next }) }) : null
				),
				assistantData?.error
					? createElement(Notice, { status: 'warning', isDismissible: false }, assistantData.error)
					: null,
				aiEnabled
					? createElement(SettingsFieldGrid, { compact: true },
						createElement(SelectControl, {
							label: __('Mode', 'adaptive-customer-engagement'),
							value: settings.ai_agent.mode,
							options: [
								{ label: 'off', value: 'off' },
								{ label: 'assist', value: 'assist' },
								{ label: 'handoff', value: 'handoff' },
							],
							onChange: (next) => setAiAgent({ mode: next }),
						}),
						createElement(SelectControl, {
							label: __('Amazon Q in Connect assistant', 'adaptive-customer-engagement'),
							value: settings.ai_agent.assistant_id || '',
							options: connectAssistantOptions,
							onChange: (next) => {
								const nextAssistant = ((assistantData?.items) || []).find((item) => item.assistantId === next);
								setAiAgent({
									assistant_id: next,
									assistant_arn: nextAssistant?.assistantArn || '',
								});
							},
						}),
						createElement(TextControl, { label: __('Assistant ARN', 'adaptive-customer-engagement'), value: settings.ai_agent.assistant_arn || '', onChange: (next) => setAiAgent({ assistant_arn: next }), disabled: !!selectedAssistant?.assistantArn }),
					)
					: createElement(Notice, { status: 'info', isDismissible: false }, __('Enable AI agent features first to reveal the live Amazon Q in Connect assistant settings.', 'adaptive-customer-engagement')),
				aiEnabled && !assistantData?.error && !((assistantData?.items || []).length)
					? createElement(Notice, { status: 'info', isDismissible: false }, __('No Amazon Q in Connect assistants are visible yet for this account and region. Create or publish one in Amazon Connect first, then return here to link it.', 'adaptive-customer-engagement'))
					: null,
				selectedAssistant
					? createElement(SettingsResultCard, {
						items: [
							{ label: __('Assistant name', 'adaptive-customer-engagement'), value: selectedAssistant.name || '—' },
							{ label: __('Assistant status', 'adaptive-customer-engagement'), value: selectedAssistant.status || '—' },
							{ label: __('Assistant type', 'adaptive-customer-engagement'), value: selectedAssistant.type || '—' },
						],
					})
					: null
				),
			connectConfigured && createElement(SettingsPanel, {
				title: __('Site knowledge base', 'adaptive-customer-engagement'),
				description: __('Seed a small, editable FAQ-style knowledge base from published site content, then use it to provision or refresh the chat bot from inside WordPress.', 'adaptive-customer-engagement'),
				tone: 'soft',
			},
			createElement(SettingsActionRow, null,
				createElement(Button, { variant: 'secondary', onClick: seedBotKnowledge, disabled: busy }, __('Seed from latest site content', 'adaptive-customer-engagement')),
				createElement(Button, { variant: 'secondary', onClick: addBotKnowledgeEntry, disabled: busy || knowledgeEntries.length >= 25 }, __('Add manual entry', 'adaptive-customer-engagement'))
			),
			knowledgeEntries.length
				? createElement('div', { className: 'ace-settings-stack' },
					knowledgeEntries.map((entry, index) => createElement(SettingsPanel, {
						key: entry.id || `bot-knowledge-${index}`,
						title: entry.source_label || sprintf(__('Knowledge entry %d', 'adaptive-customer-engagement'), index + 1),
						description: entry.url ? entry.url : __('Manually managed entry', 'adaptive-customer-engagement'),
						tone: 'soft',
						className: 'ace-settings-subpanel',
					},
					createElement(SettingsToggleList, null,
						createElement(ToggleControl, {
							label: __('Use this entry when provisioning the Lex bot', 'adaptive-customer-engagement'),
							checked: !!entry.enabled,
							onChange: (next) => updateBotKnowledgeEntry(index, { enabled: next }),
						})
					),
					createElement(SettingsFieldGrid, { compact: true },
						createElement(TextControl, {
							label: __('Question', 'adaptive-customer-engagement'),
							value: entry.question || '',
							onChange: (next) => updateBotKnowledgeEntry(index, { question: next }),
						}),
						createElement(TextControl, {
							label: __('Source label', 'adaptive-customer-engagement'),
							value: entry.source_label || '',
							onChange: (next) => updateBotKnowledgeEntry(index, { source_label: next }),
						}),
					),
					createElement(TextareaControl, {
						label: __('Answer', 'adaptive-customer-engagement'),
						help: __('Keep the answer concise and factual. This text is used as the intent response inside the generated Lex bot.', 'adaptive-customer-engagement'),
						value: entry.answer || '',
						onChange: (next) => updateBotKnowledgeEntry(index, { answer: next }),
					}),
					createElement(SettingsActionRow, null,
						createElement(Button, { variant: 'tertiary', onClick: () => removeBotKnowledgeEntry(index), disabled: busy }, __('Remove entry', 'adaptive-customer-engagement'))
					)))
				)
				: createElement(Notice, { status: 'info', isDismissible: false }, __('No bot knowledge entries have been added yet. Seed them from the latest site content or add them manually before creating a Lex bot.', 'adaptive-customer-engagement'))
			),
			connectConfigured && !siteBotConnected && createElement(SettingsPanel, {
				title: __('Create Lex bot from site knowledge', 'adaptive-customer-engagement'),
				description: __('Open the guided creator to build a new Amazon Connect-ready Lex bot from the site knowledge base while keeping the existing-bot chooser unchanged.', 'adaptive-customer-engagement'),
				tone: 'soft',
			},
			createElement(SettingsResultCard, {
				items: [
					{ label: __('Enabled knowledge entries', 'adaptive-customer-engagement'), value: `${enabledKnowledgeEntries.length}` },
					{ label: __('Locale', 'adaptive-customer-engagement'), value: inferredLexBotLocale },
					{ label: __('Role handling', 'adaptive-customer-engagement'), value: inferredLexRoleSummary },
					{ label: __('Alias', 'adaptive-customer-engagement'), value: __('WebsiteChat', 'adaptive-customer-engagement') },
				],
			}),
			createElement(Notice, { status: storedLexRoleArn && isServiceLinkedLexRoleArn(storedLexRoleArn) ? 'warning' : 'info', isDismissible: false },
				storedLexRoleArn && isServiceLinkedLexRoleArn(storedLexRoleArn)
					? __('A saved service-linked Lex role will be ignored here. The plugin will prepare a dedicated Lex bot role automatically instead.', 'adaptive-customer-engagement')
					: __('The creator now hides AWS values the plugin can infer from your saved Connect credentials and setup. You only need to provide the bot details that are specific to this site assistant.', 'adaptive-customer-engagement')
			),
			createElement(SettingsActionRow, null,
				createElement(Button, { variant: 'primary', onClick: openLexBotCreateModal, disabled: busy || !enabledKnowledgeEntries.length }, __('Open Lex bot creator', 'adaptive-customer-engagement'))
			),
			createElement(Notice, { status: 'info', isDismissible: false }, __('The guided creator will provision the bot, locale, version, alias, Connect tags, Connect association, and website chat flow automatically.', 'adaptive-customer-engagement'))
			),
			connectConfigured && createElement(SettingsPanel, {
				title: __('Conversational bot', 'adaptive-customer-engagement'),
				description: siteBotConnected
					? __('The frontend chat should use a single Amazon Connect bot with your site context. Disconnect it if you need to reconnect a different existing Connect bot or create a replacement.', 'adaptive-customer-engagement')
					: __('Reconnect one of the bots already associated with Amazon Connect, or create a fresh site bot from the knowledge base below.', 'adaptive-customer-engagement'),
				tone: 'soft',
			},
			lexBotData?.error
				? createElement(Notice, { status: 'warning', isDismissible: false }, lexBotData.error)
				: null,
			siteBotConnected
				? createElement(
					Fragment,
					null,
					createElement(SettingsResultCard, {
						items: [
							{ label: __('Connected bots in Amazon Connect', 'adaptive-customer-engagement'), value: `${lexBotData?.connected_count || 0}` },
							{ label: __('Bot name', 'adaptive-customer-engagement'), value: selectedLexBot?.botName || '—' },
							{ label: __('Bot status', 'adaptive-customer-engagement'), value: selectedLexBot?.botStatus || '—' },
							{ label: __('Selected alias', 'adaptive-customer-engagement'), value: selectedLexAlias?.botAliasName || settings.amazon_connect.lex_bot_alias_id || '—' },
							{ label: __('Selected locale', 'adaptive-customer-engagement'), value: selectedLexLocale?.localeId || settings.amazon_connect.lex_bot_locale_id || '—' },
							{ label: __('Preferred intent', 'adaptive-customer-engagement'), value: selectedLexIntent?.intentName || settings.amazon_connect.lex_bot_intent_name || '—' },
						],
					}),
					createElement(SettingsActionRow, null,
						createElement(Button, { variant: 'secondary', onClick: disconnectLexBot, disabled: busy }, __('Disconnect site bot', 'adaptive-customer-engagement')),
						createElement(Button, { variant: 'secondary', onClick: cleanupLexBots, disabled: busy || !(settings.amazon_connect.lex_bot_id || '').trim() }, __('Remove other Amazon Connect bots', 'adaptive-customer-engagement'))
					),
					!settings.amazon_connect?.chat_contact_flow_id
						? createElement(Notice, { status: 'warning', isDismissible: false }, __('This bot is connected, but no website chat flow is selected yet. Create a “Website chat via connected bot” flow below or recreate the bot to let the plugin wire that up automatically.', 'adaptive-customer-engagement'))
						: null,
					createElement(Notice, { status: 'info', isDismissible: false }, __('This connected view is limited to bots already associated with Amazon Connect. Disconnect the site bot before reconnecting another existing bot or creating a replacement.', 'adaptive-customer-engagement'))
				)
				: createElement(
					Fragment,
					null,
					createElement(SettingsFieldGrid, { compact: true },
						createElement(SelectControl, {
							label: __('Amazon Connect bot', 'adaptive-customer-engagement'),
							value: settings.amazon_connect.lex_bot_id || '',
							options: lexBotOptions,
							onChange: async (next) => {
								const response = await refreshLexBotData(next, settings.amazon_connect.lex_bot_locale_id || '');
								const firstAlias = response?.aliases?.[0]?.botAliasId || '';
								const preferredLocale = response?.selected?.locale_id || response?.locales?.[0]?.localeId || '';
								const preferredIntent = response?.selected?.intent_name || response?.intents?.[0]?.intentName || '';
								setAmazonConnect({
									lex_bot_id: next,
									lex_bot_alias_id: next ? (response?.selected?.alias_id || firstAlias) : '',
									lex_bot_locale_id: next ? preferredLocale : '',
									lex_bot_intent_name: next ? preferredIntent : '',
									lex_bot_console_url: next ? (response?.console_url || response?.selected?.console_url || '') : '',
								});
							},
						}),
						createElement(SelectControl, {
							label: __('Bot alias', 'adaptive-customer-engagement'),
							value: settings.amazon_connect.lex_bot_alias_id || '',
							options: lexAliasOptions,
							onChange: (next) => setAmazonConnect({ lex_bot_alias_id: next }),
						}),
						createElement(SelectControl, {
							label: __('Bot locale', 'adaptive-customer-engagement'),
							value: settings.amazon_connect.lex_bot_locale_id || '',
							options: lexLocaleOptions,
							onChange: async (next) => {
								const response = await refreshLexBotData(settings.amazon_connect.lex_bot_id || '', next);
								setAmazonConnect({
									lex_bot_locale_id: next,
									lex_bot_intent_name: response?.selected?.intent_name || settings.amazon_connect.lex_bot_intent_name || '',
									lex_bot_console_url: response?.console_url || response?.selected?.console_url || '',
								});
							},
						}),
						createElement(SelectControl, {
							label: __('Preferred Lex intent', 'adaptive-customer-engagement'),
							value: settings.amazon_connect.lex_bot_intent_name || '',
							options: lexIntentOptions,
							onChange: (next) => {
								const locale = settings.amazon_connect.lex_bot_locale_id || '';
								const botId = settings.amazon_connect.lex_bot_id || '';
								const baseUrl = settings.amazon_connect.instance_url || '';
								const originMatch = baseUrl.match(/^https?:\/\/[^/]+/i);
								const origin = originMatch ? originMatch[0] : '';
								const consoleUrl = botId && origin
									? `${origin}/bots/configuration/${encodeURIComponent(botId)}${locale ? `/locale/${encodeURIComponent(locale)}` : ''}${next ? `/intent/${encodeURIComponent(next)}` : ''}`
									: '';
								setAmazonConnect({
									lex_bot_intent_name: next,
									lex_bot_console_url: consoleUrl || '',
								});
							},
						}),
						createElement(TextControl, {
							label: __('Connect bot console URL', 'adaptive-customer-engagement'),
							value: settings.amazon_connect.lex_bot_console_url || '',
							onChange: (next) => setAmazonConnect({ lex_bot_console_url: next }),
						}),
					),
					createElement(SettingsActionRow, null,
						createElement(Button, { variant: 'primary', onClick: connectExistingLexBot, disabled: busy || !(settings.amazon_connect.lex_bot_id || '').trim() }, __('Reconnect selected Amazon Connect bot', 'adaptive-customer-engagement'))
					),
					((lexBotData?.items || []).length)
						? createElement(Notice, { status: 'info', isDismissible: false }, __('Only bots already associated with Amazon Connect are listed here. Raw Lex bots that sit outside Connect are intentionally hidden.', 'adaptive-customer-engagement'))
						: createElement(Notice, { status: 'info', isDismissible: false }, __('No Amazon Connect bots are currently associated with this instance. Create the site bot below to provision one from WordPress.', 'adaptive-customer-engagement'))
				),
			selectedLexBot && !siteBotConnected
				? createElement(SettingsResultCard, {
					items: [
						{ label: __('Bot name', 'adaptive-customer-engagement'), value: selectedLexBot.botName || '—' },
						{ label: __('Bot status', 'adaptive-customer-engagement'), value: selectedLexBot.botStatus || '—' },
						{ label: __('Selected alias', 'adaptive-customer-engagement'), value: selectedLexAlias?.botAliasName || settings.amazon_connect.lex_bot_alias_id || '—' },
						{ label: __('Selected locale', 'adaptive-customer-engagement'), value: selectedLexLocale?.localeId || settings.amazon_connect.lex_bot_locale_id || '—' },
						{ label: __('Preferred intent', 'adaptive-customer-engagement'), value: selectedLexIntent?.intentName || settings.amazon_connect.lex_bot_intent_name || '—' },
					],
				})
				: null,
			createElement(Notice, { status: 'info', isDismissible: false }, __('Important: the hosted website widget still starts through an Amazon Connect chat flow. The Lex bot sits inside that Connect flow, rather than replacing the flow entirely.', 'adaptive-customer-engagement'))
			),
			connectConfigured && aiEnabled && createElement(SettingsPanel, {
				title: __('Live site knowledge runtime', 'adaptive-customer-engagement'),
				description: __('Use the built-in WordPress endpoints below when the chat bot needs current products, pages, and posts instead of pre-generated FAQ intents.', 'adaptive-customer-engagement'),
				tone: 'soft',
			},
			createElement(SettingsFieldGrid, { compact: true },
				createElement(TextControl, { label: __('Answer endpoint', 'adaptive-customer-engagement'), value: chatApiEndpoint, disabled: true }),
				createElement(TextControl, { label: __('Search endpoint', 'adaptive-customer-engagement'), value: siteSearchApiEndpoint, disabled: true }),
				createElement(TextControl, { label: __('Document endpoint base', 'adaptive-customer-engagement'), value: siteDocumentApiEndpointBase, disabled: true }),
			),
			createElement(Notice, { status: settings.amazon_connect.webhook_secret ? 'success' : 'warning', isDismissible: false }, settings.amazon_connect.webhook_secret
				? __('These endpoints read live published WordPress content. Use the saved webhook secret in the X-ACE-Webhook-Secret header when the bot runtime calls them.', 'adaptive-customer-engagement')
				: __('These endpoints stay locked until you save a webhook secret on the Amazon Connect settings screen.', 'adaptive-customer-engagement'))
			),
			connectConfigured && aiEnabled && createElement(SettingsPanel, {
				title: __('Frontend test chat', 'adaptive-customer-engagement'),
				description: __('Embed the Amazon Connect chat widget on the frontend for logged-in admin testing so the sitewide assistant and any linked Lex-backed chat flow can be exercised without exposing the test widget to visitors.', 'adaptive-customer-engagement'),
				tone: 'soft',
			},
			createElement(SettingsToggleList, null,
				createElement(ToggleControl, { label: __('Enable frontend test chat', 'adaptive-customer-engagement'), checked: !!settings.ai_agent.frontend_test_enabled, onChange: (next) => setAiAgent({ frontend_test_enabled: next }) }),
				createElement(ToggleControl, { label: __('Restrict frontend test chat to logged-in admins', 'adaptive-customer-engagement'), checked: !!settings.ai_agent.frontend_test_admin_only, onChange: (next) => setAiAgent({ frontend_test_admin_only: next }) })
			),
			settings.ai_agent.frontend_test_enabled
				? createElement(SettingsFieldGrid, { compact: true },
					createElement(TextControl, { label: __('Widget script URL', 'adaptive-customer-engagement'), help: __('Use the Amazon Connect hosted widget client URL for this instance.', 'adaptive-customer-engagement'), value: settings.amazon_connect.chat_widget_script_url || '', onChange: (next) => setAmazonConnect({ chat_widget_script_url: next }) }),
					createElement(TextControl, { label: __('Widget ID', 'adaptive-customer-engagement'), help: __('This widget ID is used by the hosted chat client and the secured JWT token flow.', 'adaptive-customer-engagement'), value: settings.amazon_connect.chat_widget_id || '', onChange: (next) => setAmazonConnect({ chat_widget_id: next }) }),
					createElement(TextControl, { label: __('Widget snippet ID', 'adaptive-customer-engagement'), help: __('Use the hosted widget snippet ID from Amazon Connect.', 'adaptive-customer-engagement'), value: settings.amazon_connect.chat_widget_snippet_id || '', onChange: (next) => setAmazonConnect({ chat_widget_snippet_id: next }) }),
					createElement(TextControl, { label: __('Widget security key', 'adaptive-customer-engagement'), type: 'password', help: __('Needed when the hosted widget uses JWT security. The plugin uses it server-side to issue chat tokens.', 'adaptive-customer-engagement'), value: settings.amazon_connect.chat_widget_security_key || '', onChange: (next) => setAmazonConnect({ chat_widget_security_key: next }) }),
					createElement(TextControl, { label: __('Amazon Connect instance URL', 'adaptive-customer-engagement'), help: __('The plugin can use this access URL, together with the saved AWS keys, to backfill the matching Connect instance details on save. It is not used to load the hosted website widget.', 'adaptive-customer-engagement'), value: settings.amazon_connect.instance_url || '', onChange: (next) => setAmazonConnect({ instance_url: next }) }),
				)
				: createElement(Notice, { status: 'info', isDismissible: false }, __('Turn on the frontend test chat when you are ready to load the Amazon Connect chat widget on the frontend for admin testing.', 'adaptive-customer-engagement')),
			createElement(Notice, { status: 'info', isDismissible: false }, __('Amazon Q in Connect is not itself the website widget. This test mode uses the hosted Amazon Connect communications widget snippet, while any live Lex bot behaviour still needs to be wired into the Connect chat flow behind that widget.', 'adaptive-customer-engagement'))
			),
			connectConfigured && aiEnabled && createElement(SettingsPanel, {
				title: __('Context shared into Connect', 'adaptive-customer-engagement'),
				description: __('Decide which tracked site signals should be prepared for the linked Connect assistant and how that context should be packaged into contact attributes later on.', 'adaptive-customer-engagement'),
				tone: 'soft',
			},
			aiProviderReady
				? createElement(
					Fragment,
					null,
					createElement(SettingsToggleList, null,
						createElement(ToggleControl, { label: __('Share session summaries', 'adaptive-customer-engagement'), checked: !!settings.ai_agent.share_session_context, onChange: (next) => setAiAgent({ share_session_context: next }) }),
						createElement(ToggleControl, { label: __('Share matched company context', 'adaptive-customer-engagement'), checked: !!settings.ai_agent.share_company_context, onChange: (next) => setAiAgent({ share_company_context: next }) }),
						createElement(ToggleControl, { label: __('Share number and routing context', 'adaptive-customer-engagement'), checked: !!settings.ai_agent.share_number_context, onChange: (next) => setAiAgent({ share_number_context: next }) }),
						createElement(ToggleControl, { label: __('Share WooCommerce interest summaries', 'adaptive-customer-engagement'), checked: !!settings.ai_agent.share_woocommerce_context, onChange: (next) => setAiAgent({ share_woocommerce_context: next }) })
					),
					createElement(SettingsFieldGrid, { compact: true },
						createElement(TextControl, { label: __('Session summary attribute key', 'adaptive-customer-engagement'), value: settings.ai_agent.session_summary_attribute || '', onChange: (next) => setAiAgent({ session_summary_attribute: next }) }),
						createElement(TextControl, { label: __('Company summary attribute key', 'adaptive-customer-engagement'), value: settings.ai_agent.company_summary_attribute || '', onChange: (next) => setAiAgent({ company_summary_attribute: next }) }),
						createElement(TextControl, { label: __('Number summary attribute key', 'adaptive-customer-engagement'), value: settings.ai_agent.number_summary_attribute || '', onChange: (next) => setAiAgent({ number_summary_attribute: next }) }),
					),
					createElement(TextareaControl, {
						label: __('Context instructions', 'adaptive-customer-engagement'),
						help: __('Use this to define how the plugin should summarise site behaviour before passing that context into Amazon Connect.', 'adaptive-customer-engagement'),
						value: settings.ai_agent.context_instructions || '',
						onChange: (next) => setAiAgent({ context_instructions: next }),
					})
				)
				: createElement(Notice, { status: 'info', isDismissible: false }, __('Choose a live Amazon Q in Connect assistant first so the site-context settings only appear once they can actually be attached to a real Connect assistant.', 'adaptive-customer-engagement'))),
			connectConfigured && aiEnabled && createElement(SettingsPanel, {
				title: __('Tools and guardrails', 'adaptive-customer-engagement'),
				description: __('Define the allowed actions and service guardrails that should apply around the Connect-side assistant experience.', 'adaptive-customer-engagement'),
				tone: 'soft',
			},
			aiProviderReady
				? createElement(
					Fragment,
					null,
					createElement(TextareaControl, {
						label: __('Allowed tools', 'adaptive-customer-engagement'),
						help: __('One tool or action key per line. Leave blank until the Connect-side automation path is ready to consume them.', 'adaptive-customer-engagement'),
						value: (settings.ai_agent.allowed_tools || []).join('\n'),
						onChange: (next) => setAiAgent({ allowed_tools: splitLines(next) }),
					}),
					createElement(TextareaControl, {
						label: __('Guardrails', 'adaptive-customer-engagement'),
						help: __('Enter one guardrail per line to define the policy rules applied to the AI service.', 'adaptive-customer-engagement'),
						value: (settings.ai_agent.guardrails || []).join('\n'),
						onChange: (next) => setAiAgent({ guardrails: splitLines(next) }),
					})
				)
				: createElement(Notice, { status: 'info', isDismissible: false }, __('Choose the Connect assistant basics first so the deeper tool and guardrail settings only appear once they are actually relevant.', 'adaptive-customer-engagement'))),
			connectConfigured
				? createElement(Notice, { status: 'info', isDismissible: false }, __('This screen now assumes Amazon Q in Connect is the AI runtime. Once the Connect assistant is linked here, the remaining live runtime and attribute-delivery path can build on the same Connect estate already used for telephony.', 'adaptive-customer-engagement'))
				: null
		),
	};

	return createElement(
		Fragment,
		null,
		resolvedNotice && createElement(Notice, { status: resolvedNotice.status || 'info', isDismissible: true, onRemove: () => setNotice(null) }, resolvedNotice.message),
		isLexBotCreateModalOpen && createElement(
			Modal,
			{
				title: __('Create Amazon Lex bot', 'adaptive-customer-engagement'),
				onRequestClose: closeLexBotCreateModal,
				shouldCloseOnClickOutside: !busy,
			},
			lexBotCreateResult
				? createElement(
					Fragment,
					null,
					createElement(Notice, { status: 'success', isDismissible: false }, lexBotCreateResult.message || __('Amazon Lex bot created.', 'adaptive-customer-engagement')),
					createElement(SettingsResultCard, {
						items: [
							{ label: __('Bot', 'adaptive-customer-engagement'), value: lexBotCreateResult?.item?.botName || lexBotCreateResult?.item?.botId || '—' },
							{ label: __('Locale', 'adaptive-customer-engagement'), value: lexBotCreateResult?.inferred?.locale_id || inferredLexBotLocale },
							{ label: __('Alias', 'adaptive-customer-engagement'), value: lexBotCreateResult?.published_alias?.botAliasName || lexBotCreateResult?.inferred?.alias_name || 'WebsiteChat' },
							{ label: __('Role handling', 'adaptive-customer-engagement'), value: lexBotCreateResult?.inferred?.role_source === 'saved'
								? __('Used saved dedicated role', 'adaptive-customer-engagement')
								: lexBotCreateResult?.inferred?.role_source === 'provided'
									? __('Used provided dedicated role', 'adaptive-customer-engagement')
									: __('Prepared a dedicated role automatically', 'adaptive-customer-engagement') },
						],
					}),
					Array.isArray(lexBotCreateResult?.creation_checks) && lexBotCreateResult.creation_checks.length
						? createElement('ul', { className: 'ace-settings-list' },
							lexBotCreateResult.creation_checks.map((check) => createElement('li', { key: check.key },
								`${check.label}: ${check.value || (check.status === 'complete' ? __('Done', 'adaptive-customer-engagement') : check.status)}`
							))
						)
						: null,
					createElement(SettingsActionRow, null,
						createElement(Button, { variant: 'primary', onClick: closeLexBotCreateModal, disabled: busy }, __('Done', 'adaptive-customer-engagement'))
					)
				)
				: createElement(
					Fragment,
					null,
					createElement(SettingsFieldGrid, { compact: true },
						createElement(TextControl, {
							label: __('Bot name', 'adaptive-customer-engagement'),
							help: __('Letters, numbers, hyphens, and underscores only. The plugin will clean this up for Lex automatically if needed.', 'adaptive-customer-engagement'),
							value: lexBotDraft.name,
							onChange: (next) => setLexBotDraft({ ...lexBotDraft, name: next }),
						}),
					),
					createElement(TextareaControl, {
						label: __('Bot description', 'adaptive-customer-engagement'),
						value: lexBotDraft.description,
						onChange: (next) => setLexBotDraft({ ...lexBotDraft, description: next }),
					}),
					createElement(SettingsToggleList, null,
						createElement(ToggleControl, {
							label: __('Link the new bot into the saved plugin settings after creation', 'adaptive-customer-engagement'),
							checked: !!lexBotDraft.link_to_settings,
							onChange: (next) => setLexBotDraft({ ...lexBotDraft, link_to_settings: next }),
						})
					),
					createElement(SettingsResultCard, {
						items: [
							{ label: __('Enabled knowledge entries', 'adaptive-customer-engagement'), value: `${enabledKnowledgeEntries.length}` },
							{ label: __('Locale', 'adaptive-customer-engagement'), value: inferredLexBotLocale },
							{ label: __('Role handling', 'adaptive-customer-engagement'), value: inferredLexRoleSummary },
							{ label: __('Alias', 'adaptive-customer-engagement'), value: __('WebsiteChat', 'adaptive-customer-engagement') },
						],
					}),
					createElement(Notice, { status: 'info', isDismissible: false }, __('Adaptive Customer Engagement will automatically handle the locale build, version publishing, Connect tags, Connect association, and website chat flow creation for this bot.', 'adaptive-customer-engagement')),
					createElement(SettingsActionRow, null,
						createElement(Button, { variant: 'tertiary', onClick: closeLexBotCreateModal, disabled: busy }, __('Cancel', 'adaptive-customer-engagement')),
						createElement(Button, { variant: 'primary', onClick: createLexBot, disabled: busy || !canCreateLexBot }, __('Create Amazon Lex bot', 'adaptive-customer-engagement'))
					)
				)
		),
		sections[section] || sections.settings,
		createElement(SettingsPanel, {
			title: __('Settings import and export', 'adaptive-customer-engagement'),
			description: __('Download or restore plugin configuration only. This does not include sample data, tracked sessions, companies, calls, or reporting history.', 'adaptive-customer-engagement'),
			tone: 'soft',
		},
		createElement(SettingsFieldGrid, { compact: true },
			createElement(
				'div',
				null,
				createElement('label', { className: 'components-base-control__label', htmlFor: `ace-settings-import-${section}` }, __('Import settings file', 'adaptive-customer-engagement')),
				createElement('input', {
					key: settingsImportInputKey,
					id: `ace-settings-import-${section}`,
					type: 'file',
					accept: 'application/json,.json',
					onChange: handleSettingsImportSelection,
					disabled: busy,
				}),
				createElement('p', { className: 'components-base-control__help' }, settingsImportName
					? sprintf(__('Selected file: %s', 'adaptive-customer-engagement'), settingsImportName)
					: __('Choose a previously exported JSON settings file.', 'adaptive-customer-engagement'))
			)
		),
		createElement(SettingsActionRow, null,
			createElement(Button, { variant: 'secondary', onClick: exportSettingsConfig, disabled: busy }, __('Export settings', 'adaptive-customer-engagement')),
			createElement(Button, { variant: 'primary', onClick: importSettingsConfig, disabled: busy || !settingsImportFile }, __('Import settings', 'adaptive-customer-engagement'))
		),
		createElement(Notice, { status: 'info', isDismissible: false }, __('Secrets such as AWS keys, widget security keys, webhook secrets, and the current plugin configuration are included in the export so you can move the setup between environments.', 'adaptive-customer-engagement'))
		),
		createElement(
			'div',
			{ className: 'ace-admin-settings-savebar' },
			createElement('p', { className: 'ace-admin-settings-savebar__text' }, __('Save when you are happy with the setup on this page.', 'adaptive-customer-engagement')),
			createElement(Button, { variant: 'primary', onClick: save, disabled: busy }, __('Save settings', 'adaptive-customer-engagement'))
		)
	);
}

function PlaceholderView({ title, message }) {
	return createElement(Notice, { status: 'info', isDismissible: false }, `${title}: ${message}`);
}

function ScreenMount({ active, children }) {
	return createElement(
		'div',
		{
			className: `ace-admin-screen-mount${active ? ' is-active' : ''}`,
			hidden: !active,
			'aria-hidden': !active,
		},
		children
	);
}

function App() {
	const initialRoute = getHashRoute();
	const initialSection = initialRoute.section;
	const [page, setPage] = useState(initialSection);
	const [route, setRoute] = useState(initialRoute);
	const [visited, setVisited] = useState([initialSection]);

	useEffect(() => {
		const currentPage = (config.page || 'dashboard').replace(/^ace-/, '');

		if (currentPage !== 'dashboard' || !window.location.hash) {
			window.history.replaceState({}, '', getAdminPageUrl(currentPage || 'dashboard'));
		}

		const syncRoute = () => {
			const nextRoute = getHashRoute();
			const nextPage = nextRoute.section;
			setRoute(nextRoute);
			setPage(nextPage);
			setVisited((currentVisited) => (currentVisited.includes(nextPage) ? currentVisited : currentVisited.concat(nextPage)));
		};

		syncRoute();
		window.addEventListener('hashchange', syncRoute);
		window.addEventListener('popstate', syncRoute);

		return () => {
			window.removeEventListener('hashchange', syncRoute);
			window.removeEventListener('popstate', syncRoute);
		};
	}, []);

	useEffect(() => {
		syncWpAdminSidebar(page);
	}, [page]);

	useEffect(() => {
		const adminMenu = document.getElementById('adminmenu');

		if (!adminMenu) {
			return undefined;
		}

		const handleClick = (event) => {
			const link = event.target.closest('a[href*="page=ace-"]');

			if (!link) {
				return;
			}

			const targetPage = getAceSectionFromUrl(link.href);

			if (!targetPage) {
				return;
			}

			event.preventDefault();

			const nextUrl = new URL(getAdminPageUrl(targetPage), window.location.origin);
			const nextHash = nextUrl.hash || `#${targetPage}`;

			if (window.location.hash === nextHash) {
				syncWpAdminSidebar(targetPage);
				return;
			}

			window.location.hash = nextHash;
		};

		adminMenu.addEventListener('click', handleClick);

		return () => {
			adminMenu.removeEventListener('click', handleClick);
		};
	}, []);

	const screenOrder = ['dashboard', 'sessions', 'companies', 'commerce', 'calls', 'numbers', 'settings', 'privacy', 'enrichment', 'amazon-connect', 'ai-agent'];
	const screenMap = {
		dashboard: createElement(DashboardView, { active: page === 'dashboard' }),
		sessions: createElement(SessionsView, { active: page === 'sessions', route }),
		companies: createElement(CompaniesView, { active: page === 'companies', route }),
		commerce: createElement(CommerceView, { active: page === 'commerce' }),
		calls: createElement(CallsView, { active: page === 'calls', route }),
		numbers: createElement(NumbersView, { active: page === 'numbers', route }),
		settings: createElement(SettingsView, { section: 'settings', active: page === 'settings' }),
		privacy: createElement(SettingsView, { section: 'privacy', active: page === 'privacy' }),
		enrichment: createElement(SettingsView, { section: 'enrichment', active: page === 'enrichment' }),
		'amazon-connect': createElement(SettingsView, { section: 'amazon-connect', active: page === 'amazon-connect' }),
		'ai-agent': createElement(SettingsView, { section: 'ai-agent', active: page === 'ai-agent' }),
	};

	return createElement(
		AdminShell,
		{ page },
		screenOrder.map((section) =>
			visited.includes(section)
				? createElement(ScreenMount, { key: section, active: page === section }, screenMap[section])
				: null
		),
		!screenMap[page] && createElement(PlaceholderView, { title: __('Adaptive Customer Engagement', 'adaptive-customer-engagement'), message: __('This screen is not built yet.', 'adaptive-customer-engagement') })
	);
}

const root = document.getElementById('ace-admin-root');

if (root) {
	render(createElement(App), root);
}
