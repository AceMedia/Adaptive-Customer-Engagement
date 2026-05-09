import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, Notice, SelectControl, Spinner, TextControl, ToggleControl } from '@wordpress/components';
import { createElement, Fragment, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { render } from '@wordpress/element';

const config = window.ACEAdminConfig || {};

function request(route, options = {}) {
	return apiFetch({
		url: `${config.root}${config.namespace}${route}`,
		headers: {
			'X-WP-Nonce': config.nonce,
		},
		...options,
	});
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
				['Session', 'Landing page', 'Source', 'Campaign', 'Events', 'Call clicks', 'Score', 'Last seen', 'Actions'].map((label) =>
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

function SessionsView() {
	const [items, setItems] = useState(null);
	const [detail, setDetail] = useState(null);

	useEffect(() => {
		request('/admin/sessions').then((response) => setItems(response.items || []));
	}, []);

	if (!items) {
		return createElement(Spinner);
	}

	return createElement(
		Fragment,
		null,
		createElement(SessionsTable, {
			items,
			onView: async (id) => {
				const response = await request(`/admin/sessions/${id}`);
				setDetail(response);
			},
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
		? ['Company', 'Domain', 'Confidence', 'Sessions', 'Events', 'Last seen', 'Actions']
		: ['Company', 'Type', 'Domain', 'Confidence', 'Sessions', 'Events', 'Calls', 'Last seen', 'Actions'];

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
						['Country', detail.country_code || '—'],
						['Sessions', detail.total_sessions || 0],
						['Events', detail.total_events || 0],
						['Calls', detail.total_calls || 0],
						['First seen', detail.first_seen || '—'],
						['Last seen', detail.last_seen || '—'],
					].map(([label, value]) => createElement('tr', { key: label }, createElement('th', null, __(label, 'adaptive-customer-engagement')), createElement('td', null, value)))
				)
			),
			createElement('h3', null, __('Recent sessions', 'adaptive-customer-engagement')),
			createElement(SessionsTable, { items: sessions })
		)
	);
}

function CompaniesView() {
	const [items, setItems] = useState(null);
	const [detail, setDetail] = useState(null);

	useEffect(() => {
		request('/admin/companies').then((response) => setItems(response.items || []));
	}, []);

	if (!items) {
		return createElement(Spinner);
	}

	return createElement(
		Fragment,
		null,
		createElement(CompaniesTable, {
			items,
			onView: async (id) => {
				const response = await request(`/admin/companies/${id}`);
				setDetail(response);
			},
		}),
		detail && createElement(CompanyDetailPanel, { detail, onClose: () => setDetail(null) })
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
				options: ['none', 'ipregistry', 'ipdata', 'ipinfo', 'ipapiis'].map((entry) => ({ label: entry, value: entry })),
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
		case 'calls':
			return createElement(PlaceholderView, { title: __('Calls', 'adaptive-customer-engagement'), message: __('Call import and matching sit on the next implementation phase.', 'adaptive-customer-engagement') });
		default:
			return createElement(PlaceholderView, { title: __('Adaptive Customer Engagement', 'adaptive-customer-engagement'), message: __('This screen is not built yet.', 'adaptive-customer-engagement') });
	}
}

const root = document.getElementById('ace-admin-root');

if (root) {
	render(createElement(App), root);
}
