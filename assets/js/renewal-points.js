(function () {
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
	var cfg = window.freyaYwparRenewal;
	var worthTimer;

	function updateWorth() {
		if (!pointsInput || !worthEl || !cfg) {
			return;
		}

		var points = parseInt(pointsInput.value, 10) || 0;

		clearTimeout(worthTimer);
		worthTimer = setTimeout(function () {
			var body = new FormData();
			body.append('action', 'ywpar_calculate_worth_from_points_on_share_points');
			body.append('points', String(points));
			body.append('customer', cfg.customerId);
			body.append('security', cfg.shareNonce);

			fetch(cfg.ajaxUrl, {
				method: 'POST',
				body: body,
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
