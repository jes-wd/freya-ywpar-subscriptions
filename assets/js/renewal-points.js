(function () {
	'use strict';

	var cfg = window.freyaYwparRenewal || {};

	function reloadSharePointView() {
		var sharePointsEl = document.querySelector('#share_points');
		var wasActive = sharePointsEl && sharePointsEl.classList.contains('active');

		return fetch(document.location.href, { credentials: 'same-origin' })
			.then(function (response) {
				return response.text();
			})
			.then(function (html) {
				if (!html) {
					return;
				}

				var wrapper = document.createElement('div');
				wrapper.innerHTML = html;
				var sharePoints = wrapper.querySelector('#share_points');
				var currentPoints = wrapper.querySelector('.ywpar_myaccount_entry_info');

				if (sharePoints && sharePointsEl) {
					sharePointsEl.innerHTML = sharePoints.innerHTML;
					if (wasActive) {
						sharePointsEl.classList.add('active');
						sharePointsEl.style.display = 'block';
					}
				}

				if (currentPoints) {
					var pointsTarget = document.querySelector('.ywpar_myaccount_entry_info');
					if (pointsTarget) {
						pointsTarget.innerHTML = currentPoints.innerHTML;
					}
				}
			});
	}

	function parseJsonResponse(response) {
		return response.text().then(function (text) {
			try {
				return JSON.parse(text);
			} catch (error) {
				throw new Error(cfg.deleteCouponError || 'Request failed. Please refresh and try again.');
			}
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('.freya-ywpar-delete-shared-coupon');
		if (!button || button.disabled || !cfg.deleteCouponNonce) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();

		var coupon = button.getAttribute('data-coupon') || '';
		if (!coupon) {
			return;
		}

		if (cfg.deleteCouponConfirm && !window.confirm(cfg.deleteCouponConfirm)) {
			return;
		}

		var originalText = button.textContent;
		button.disabled = true;
		button.textContent = cfg.deleteCouponDeleting || 'Deleting…';

		var body = new FormData();
		body.append('action', 'freya_ywpar_delete_shared_coupon');
		body.append('coupon', coupon);
		body.append('security', cfg.deleteCouponNonce);

		fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
		})
			.then(parseJsonResponse)
			.then(function (response) {
				if (!response || !response.success) {
					throw new Error(
						(response && response.data && response.data.message) ||
							cfg.deleteCouponError ||
							'Error'
					);
				}

				return reloadSharePointView();
			})
			.catch(function (error) {
				window.alert(error && error.message ? error.message : (cfg.deleteCouponError || 'Error'));
				button.disabled = false;
				button.textContent = originalText;
			});
	});

	var select = document.getElementById('freya-ywpar-subscription');
	var summary = document.getElementById('freya-ywpar-summary');
	var loadFields = document.getElementById('freya-ywpar-load-fields');
	var loadedBox = document.getElementById('freya-ywpar-loaded');
	var loadedText = document.getElementById('freya-ywpar-loaded-text');
	var removeSubId = document.getElementById('freya-ywpar-remove-sub-id');
	var loadSubId = document.getElementById('freya-ywpar-load-sub-id');

	if (!select || !summary) {
		return;
	}

	function renderSummary() {
		var opt = select.options[select.selectedIndex];
		var loaded = parseInt(opt.dataset.loaded || '0', 10);

		summary.innerHTML =
			'<span class="freya-ywpar-renewal-points__status">' + opt.dataset.status + '</span>' +
			'<span><strong>Renewal total:</strong> ' + opt.dataset.total + '</span>' +
			'<span><strong>Next payment:</strong> ' + opt.dataset.next + '</span>';

		if (removeSubId) {
			removeSubId.value = opt.value;
		}

		if (loadSubId) {
			loadSubId.value = opt.value;
		}

		if (loadFields) {
			loadFields.hidden = loaded > 0;
		}

		if (loadedBox) {
			loadedBox.hidden = loaded <= 0;
		}

		if (loadedText && loaded > 0) {
			loadedText.textContent = opt.dataset.loadedText || '';
		}
	}

	select.addEventListener('change', renderSummary);
	renderSummary();

	var pointsInput = document.getElementById('freya-ywpar-points');
	var worthEl = document.getElementById('freya-ywpar-worth-price');
	var worthTimer;

	function updateWorth() {
		if (!pointsInput || !worthEl || !cfg.shareNonce) {
			return;
		}

		var points = parseInt(pointsInput.value, 10) || 0;

		clearTimeout(worthTimer);
		worthTimer = setTimeout(function () {
			var worthBody = new FormData();
			worthBody.append('action', 'ywpar_calculate_worth_from_points_on_share_points');
			worthBody.append('points', String(points));
			worthBody.append('customer', cfg.customerId);
			worthBody.append('security', cfg.shareNonce);

			fetch(cfg.ajaxUrl, {
				method: 'POST',
				body: worthBody,
				credentials: 'same-origin',
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (response) {
					if (response.success && response.data && response.data.worth) {
						worthEl.innerHTML = response.data.worth;
					}
				});
		}, 200);
	}

	if (pointsInput && worthEl) {
		pointsInput.addEventListener('input', updateWorth);
		pointsInput.addEventListener('change', updateWorth);
	}
})();
