(function () {
	'use strict';

	var box = document.querySelector('.ace-basket-nudge');
	if (!box) {
		return;
	}

	var context = box.getAttribute('data-context') || 'cart';
	var form = box.querySelector('.ace-basket-nudge__form');
	var errorEl = box.querySelector('.ace-basket-nudge__error');

	// GA4: tell the dataLayer, once per session, that a shopper arrived through a recovery link.
	try {
		if (/[?&]utm_source=basket-recovery/.test(window.location.search) && window.dataLayer && !window.sessionStorage.getItem('aceBasketRestored')) {
			window.sessionStorage.setItem('aceBasketRestored', '1');
			window.dataLayer.push({ event: 'basket_recovery_restored' });
		}
	} catch (e) { /* storage blocked: skip quietly */ }

	function showSaved(data) {
		if (data && data.restore) {
			var restore = box.querySelector('.ace-basket-nudge__restore');
			if (restore) { restore.setAttribute('href', data.restore); }
		}
		if (data && data.signup) {
			box.querySelectorAll('.ace-basket-nudge__signup').forEach(function (a) { a.setAttribute('href', data.signup); });
		}
		box.setAttribute('data-state', 'saved');
	}

	function save(email, button, source) {
		var body = new window.URLSearchParams();
		body.set('email', email);
		body.set('nonce', box.getAttribute('data-nonce') || '');
		body.set('source', source || context);
		if (button) { button.disabled = true; }
		return window.fetch(box.getAttribute('data-ajax'), { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (json && json.success) {
					showSaved(json.data);
					return true;
				}
				throw new Error((json && json.data && json.data.message) || 'error');
			})
			.catch(function (err) {
				if (errorEl) {
					errorEl.textContent = err && err.message && err.message !== 'error' ? err.message : 'Sorry, we could not save your basket just now.';
					errorEl.hidden = false;
				}
				return false;
			})
			.then(function (ok) { if (button) { button.disabled = false; } return ok; });
	}

	if (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			var input = form.querySelector('input[type=email]');
			var email = input ? input.value.trim() : '';
			if (errorEl) { errorEl.hidden = true; }
			if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
				if (errorEl) { errorEl.textContent = 'Please enter a valid email address.'; errorEl.hidden = false; }
				return;
			}
			save(email, form.querySelector('button'));
		});
	}

	// Block checkout: the shopper typing their email in the checkout form saves the basket too.
	if (context === 'checkout') {
		document.addEventListener('focusout', function (event) {
			var el = event.target;
			if (!el || el.tagName !== 'INPUT' || el.type !== 'email' || el.closest('.ace-basket-nudge')) { return; }
			var email = (el.value || '').trim();
			if (box.getAttribute('data-state') !== 'saved' && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
				save(email, null, 'checkout_field');
			}
		});
	}
})();
