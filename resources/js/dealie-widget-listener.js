/**
 * Dealie AI Widget Listener for Storehause Multi-Vendor Storefronts.
 * Listens for `dealie:deal_closed` event and applies negotiated price + deal_token to checkout state.
 */
(function () {
  'use strict';

  window.addEventListener('dealie:deal_closed', function (event) {
    const detail = event.detail || {};
    const agreedPrice = detail.agreed_price;
    const dealToken = detail.deal_token;
    const productId = detail.product_id;

    if (!dealToken || !agreedPrice) {
      console.warn('[Storehause Dealie Integration] Received deal_closed event missing token or price.', detail);
      return;
    }

    console.log('[Storehause Dealie Integration] Deal closed successfully:', {
      agreedPrice,
      dealToken,
      productId
    });

    // Store in SessionStorage for Checkout placement
    sessionStorage.setItem('dealie_agreed_price', agreedPrice);
    sessionStorage.setItem('dealie_token', dealToken);
    sessionStorage.setItem('dealie_product_id', productId || '');

    // Notify any local storefront cart handlers
    if (window.StorehauseCart && typeof window.StorehauseCart.applyNegotiatedDeal === 'function') {
      window.StorehauseCart.applyNegotiatedDeal({
        price: agreedPrice,
        dealToken: dealToken,
        productId: productId
      });
    }

    // Show toast message if notification UI exists
    if (window.showToast) {
      window.showToast(`Dealie AI discount applied! Special price: ${agreedPrice}`, 'success');
    }
  });
})();
