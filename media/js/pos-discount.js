/**
 * Phoca Cart POS Discount - adds a manual discount control (amount or percentage)
 * to the POS "Apply Benefits" panel.
 *
 * It does not replace anything: it asks the server (com_ajax) for a single-use
 * coupon code, drops the code into Phoca's own coupon field and clicks Phoca's own
 * Apply button, so applying, refresh, totals and messages are Phoca behaviour.
 *
 * @copyright (C) 2026 David Leeman. GNU General Public License version 2 or later.
 */
(() => {
	'use strict';

	const o = Joomla.getOptions('plgPhocaCartPosDiscount');

	if (!o) {
		return;
	}

	const text = (key) => Joomla.Text._('PLG_SYSTEM_PHOCACARTPOSDISCOUNT_JS_' + key, key);
	const sprintf = (str, value) => str.replace('%s', value).replace('%%', '%');

	const el = (tag, attrs = {}, children = []) => {
		const node = document.createElement(tag);

		Object.entries(attrs).forEach(([name, value]) => {
			if (name === 'text') {
				node.textContent = value;
			} else {
				node.setAttribute(name, value);
			}
		});
		[].concat(children).forEach((child) => child && node.appendChild(child));

		return node;
	};

	const ticketParams = () => {
		const query = new URLSearchParams(window.location.search);

		return ['ticketid', 'unitid', 'sectionid'].map((name) => [name, query.get(name) || (name === 'ticketid' ? '1' : '0')]);
	};

	const build = (form, coupon, nativeApply) => {
		let mode = o.allowPercent ? 'percent' : 'flat';
		const active = coupon.value.indexOf(o.prefix) === 0;
		const idBase = 'plgPdisc' + Math.random().toString(36).slice(2, 7);

		const message = el('div', { class: 'plg-pdisc__msg', role: 'status', 'aria-live': 'polite' });
		const input = el('input', {
			type: 'number', inputmode: 'decimal', step: '0.01', min: '0',
			class: 'form-control plg-pdisc__input', id: idBase + '-value',
			'aria-label': text('LABEL'), autocomplete: 'off'
		});
		const apply = el('button', { type: 'button', class: 'btn btn-success plg-pdisc__apply', text: text('APPLY') });
		const toggles = [];
		const hint = el('div', { class: 'plg-pdisc__hint' });

		const updateHint = () => {
			const cap = mode === 'percent' ? o.maxPercent : o.maxAmount;

			if (cap > 0) {
				hint.textContent = mode === 'percent'
					? sprintf(text('MAX_PERCENT'), cap)
					: sprintf(text('MAX_AMOUNT'), (o.symbol || '') + cap.toFixed(2));
			} else {
				hint.textContent = '';
			}
			input.setAttribute('max', mode === 'percent' ? '100' : (cap > 0 ? String(cap) : ''));
		};

		const setMode = (next) => {
			mode = next;
			toggles.forEach(([name, button]) => {
				const on = name === mode;

				button.classList.toggle('is-active', on);
				button.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
			updateHint();
		};

		const group = el('div', { class: 'plg-pdisc__mode', role: 'group', 'aria-label': text('LABEL') });

		[['percent', o.allowPercent, text('PERCENT')], ['flat', o.allowFlat, (o.symbol || '') || text('FLAT')]].forEach(([name, allowed, label]) => {
			if (!allowed) {
				return;
			}

			const button = el('button', { type: 'button', class: 'btn plg-pdisc__toggle', 'aria-pressed': 'false', text: label });

			button.addEventListener('click', () => setMode(name));
			toggles.push([name, button]);
			group.appendChild(button);
		});

		let reason = null;

		if (o.requireReason || o.reasons.length) {
			if (o.reasons.length) {
				reason = el('select', { class: 'form-select plg-pdisc__reason', 'aria-label': text('REASON') },
					[el('option', { value: '', text: text('REASON_CHOOSE') })]
						.concat(o.reasons.map((r) => el('option', { value: r, text: r }))));
			} else {
				reason = el('input', { type: 'text', class: 'form-control plg-pdisc__reason', 'aria-label': text('REASON'), placeholder: text('REASON'), maxlength: '255' });
			}
		}

		const say = (msg, isError) => {
			message.textContent = msg;
			message.classList.toggle('is-error', !!isError);
		};

		const run = async () => {
			const value = parseFloat(String(input.value).replace(',', '.'));

			if (!(value > 0)) {
				say(text('ERR_VALUE'), true);
				input.focus();

				return;
			}

			if (o.requireReason && reason && !reason.value.trim()) {
				say(text('ERR_REASON'), true);
				reason.focus();

				return;
			}

			const body = new FormData();

			body.append(o.token, '1');
			body.append('mode', mode);
			body.append('value', String(value));
			body.append('reason', reason ? reason.value : '');
			ticketParams().forEach(([name, v]) => body.append(name, v));

			apply.disabled = true;
			say(text('WORKING'), false);

			try {
				const response = await fetch(o.url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
				const json = await response.json();
				const data = Array.isArray(json.data) ? json.data[0] : null;

				if (json.success === false && !data) {
					say(json.message || text('ERR_NETWORK'), true);
				} else if (data && data.ok) {
					say('', false);
					coupon.value = data.code;
					// Phoca's own Apply button takes it from here.
					nativeApply.click();
				} else {
					say((data && data.message) || text('ERR_NETWORK'), true);
				}
			} catch (e) {
				say(text('ERR_NETWORK'), true);
			}

			apply.disabled = false;
		};

		apply.addEventListener('click', run);
		input.addEventListener('keydown', (e) => {
			if (e.key === 'Enter') {
				e.preventDefault();
				run();
			}
		});

		const row = el('div', { class: 'plg-pdisc__row' }, [group, input, apply]);
		const block = el('div', { class: 'plg-pdisc', 'data-plg-pdisc': '1' }, [
			el('div', { class: 'plg-pdisc__label', text: text('LABEL') }),
			row,
			reason,
			hint,
			message
		]);

		if (active) {
			const remove = el('button', { type: 'button', class: 'btn btn-outline-secondary plg-pdisc__remove', text: text('REMOVE') });

			remove.addEventListener('click', () => {
				coupon.value = '';
				nativeApply.click();
			});
			block.insertBefore(el('div', { class: 'plg-pdisc__active' }, [el('span', { text: text('ACTIVE') + ' ' }), remove]), row);
		}

		setMode(mode);

		return block;
	};

	const enhance = () => {
		const coupon = document.getElementById('phcoupon');

		if (!coupon) {
			return;
		}

		const form = coupon.closest('form');

		if (!form || form.querySelector('[data-plg-pdisc]')) {
			return;
		}

		const nativeApply = form.querySelector('button.editMainContent');

		if (!nativeApply) {
			return;
		}

		// Full width, above the row that holds the coupon field and Phoca's own Apply button.
		const anchor = coupon.closest('.row') || form.querySelector('.ph-pos-coupon-reward-box') || coupon.parentElement;

		anchor.parentNode.insertBefore(build(form, coupon, nativeApply), anchor);
	};

	// The POS loads each panel by AJAX into the page, so watch for the Apply Benefits form.
	let pending = false;

	new MutationObserver(() => {
		if (pending) {
			return;
		}
		pending = true;
		window.requestAnimationFrame(() => {
			pending = false;
			enhance();
		});
	}).observe(document.body, { childList: true, subtree: true });

	enhance();
})();
