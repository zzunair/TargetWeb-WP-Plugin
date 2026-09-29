/**
 * TargetWeb Parts Portal — host-page glue for the embedded Parts Portal
 * iframe (#iframId), adapted from the Shopify theme's targetWeb.js for
 * WooCommerce:
 *
 *  - resize passthrough: unchanged, generic (iframe tells us its height).
 *  - infinite scroll: unchanged, generic (we tell the iframe to load more
 *    as it nears the bottom of the viewport).
 *  - add to cart: Shopify's /cart/add.js has no WordPress equivalent, so
 *    this uses the WooCommerce Store API (/wp-json/wc/store/v1/cart/add-item)
 *    instead, keyed by the WooCommerce product id the iframe sends us
 *    (entity.externalProductId — WooCommerce syncs simple products, so
 *    there's no separate variant id the way Shopify has one).
 */
(function () {
	function getIframe() {
		return document.getElementById('iframId');
	}

	/** Derived from the iframe's own src, so no build-time base URL is needed. */
	function getFrameOrigin() {
		var iframe = getIframe();
		if (!iframe || !iframe.src) {
			return null;
		}
		try {
			return new URL(iframe.src, window.location.href).origin;
		} catch (e) {
			return null;
		}
	}

	window.addEventListener('message', function (event) {
		var frameOrigin = getFrameOrigin();
		if (frameOrigin && event.origin !== frameOrigin) {
			return;
		}

		var iframe = getIframe();

		if (event.data && event.data.eventType === 'resize') {
			if (iframe && typeof event.data.height === 'number') {
				iframe.style.height = event.data.height + 'px';
			}
			return;
		}

		var entity = event.data && event.data.data && event.data.data.entity;
		var productId = entity && entity.externalProductId;
		if (!productId) {
			return;
		}

		addToWooCommerceCart(productId, iframe, frameOrigin);
	});

	/**
	 * The Store API rejects cart-mutating requests without a `Nonce` header
	 * (its own CSRF check, separate from the standard WP REST nonce) - that
	 * header's value comes from a prior GET to the cart endpoint.
	 */
	function getCartNonce() {
		var cartUrl = (window.twPartsConfig && window.twPartsConfig.cartUrl) || '/wp-json/wc/store/v1/cart';
		return fetch(cartUrl, { credentials: 'same-origin' }).then(function (response) {
			return response.headers.get('Nonce') || '';
		});
	}

	function addToWooCommerceCart(productId, iframe, frameOrigin) {
		var addUrl = (window.twPartsConfig && window.twPartsConfig.addItemUrl) || '/wp-json/wc/store/v1/cart/add-item';

		getCartNonce()
			.then(function (nonce) {
				return fetch(addUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', Nonce: nonce },
					body: JSON.stringify({ id: Number(productId), quantity: 1 }),
				});
			})
			.then(function (response) {
				return response.json().then(function (cart) {
					return { ok: response.ok, cart: cart };
				});
			})
			.then(function (result) {
				if (!result.ok) {
					console.warn('TargetWeb Parts Portal: add-item failed', result.cart);
					return;
				}

				// Refresh the classic mini-cart widget/fragments if the theme uses them.
				if (window.jQuery) {
					window.jQuery(document.body).trigger('added_to_cart', [null, null, null]);
					window.jQuery(document.body).trigger('wc_fragment_refresh');
				}

				if (iframe && iframe.contentWindow && frameOrigin) {
					iframe.contentWindow.postMessage(
						{ eventType: 'itemAddedTocart', data: { message: 'Item added to cart' } },
						frameOrigin
					);
				}
			})
			.catch(function (error) {
				console.error('TargetWeb Parts Portal: add-item request failed', error);
			});
	}

	(function () {
		var ticking = false;
		window.addEventListener(
			'scroll',
			function () {
				if (ticking) {
					return;
				}
				ticking = true;
				requestAnimationFrame(function () {
					ticking = false;
					var iframe = getIframe();
					var frameOrigin = getFrameOrigin();
					if (!iframe || !iframe.contentWindow || !frameOrigin) {
						return;
					}
					var rect = iframe.getBoundingClientRect();
					if (rect.bottom - window.innerHeight < 800) {
						iframe.contentWindow.postMessage({ eventType: 'loadMore' }, frameOrigin);
					}
				});
			},
			{ passive: true }
		);
	})();
})();
