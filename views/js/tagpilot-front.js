/**
 * TagPilot - Frontend GA4 Event Tracking
 * Handles client-side events: add_to_cart, remove_from_cart, select_item
 */
(function () {
    'use strict';

    var TP = {
        debug: document.querySelector('script[src*="tagpilot"]') !== null,

        log: function (msg, data) {
            if (this.debug && window.console) {
                console.log('[TagPilot] ' + msg, data || '');
            }
        },

        push: function (event) {
            window.dataLayer = window.dataLayer || [];
            // Clear ecommerce before each push
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push(event);
            this.log('dataLayer.push:', event);
        },

        // Extract product data from a product miniature element
        getProductDataFromElement: function (el) {
            var data = {};

            // Try data attributes first (PS 8/9 standard)
            var productEl = el.closest('[data-id-product]') || el.closest('.js-product-miniature') || el.closest('.product-miniature');
            if (!productEl) return null;

            data.item_id = productEl.getAttribute('data-id-product') || '';
            data.item_name = '';
            data.price = 0;

            // Product name
            var nameEl = productEl.querySelector('.product-title a, h2 a, h3 a, .product-name a');
            if (nameEl) {
                data.item_name = nameEl.textContent.trim();
            }

            // Price
            var priceEl = productEl.querySelector('.product-price-and-shipping .price, .price');
            if (priceEl) {
                var priceText = priceEl.textContent.replace(/[^\d.,]/g, '').replace(',', '.');
                data.price = parseFloat(priceText) || 0;
            }

            return data;
        },

        init: function () {
            this.bindAddToCart();
            this.bindRemoveFromCart();
            this.bindSelectItem();
            this.bindCheckoutSteps();
            this.log('Frontend tracking initialized');
        },

        // ── add_to_cart ──────────────────────────────────────────
        bindAddToCart: function () {
            var self = this;

            // PrestaShop 8/9 fires a custom event
            if (typeof prestashop !== 'undefined') {
                prestashop.on('updateCart', function (event) {
                    if (!event || !event.reason || !event.reason.idProduct) return;
                    if (event.reason.linkAction !== 'add-to-cart') return;

                    var item = {
                        item_id: String(event.reason.idProduct),
                        item_name: '',
                        quantity: parseInt(event.reason.idProductAttribute ? 1 : 1),
                        price: 0
                    };

                    if (event.reason.idProductAttribute) {
                        item.item_variant = String(event.reason.idProductAttribute);
                    }

                    // Try to get name and price from page
                    var productName = document.querySelector('h1[itemprop="name"], h1.product-name, .product-detail h1');
                    if (productName) {
                        item.item_name = productName.textContent.trim();
                    }

                    var productPrice = document.querySelector('[itemprop="price"], .current-price .price, .product-price');
                    if (productPrice) {
                        var content = productPrice.getAttribute('content') || productPrice.textContent;
                        item.price = parseFloat(content.replace(/[^\d.,]/g, '').replace(',', '.')) || 0;
                    }

                    self.push({
                        event: 'add_to_cart',
                        ecommerce: {
                            currency: self.getCurrency(),
                            value: item.price * item.quantity,
                            items: [item]
                        }
                    });
                });
            }
        },

        // ── remove_from_cart ─────────────────────────────────────
        bindRemoveFromCart: function () {
            var self = this;

            if (typeof prestashop !== 'undefined') {
                prestashop.on('updateCart', function (event) {
                    if (!event || !event.reason) return;
                    if (event.reason.linkAction !== 'delete-from-cart') return;

                    self.push({
                        event: 'remove_from_cart',
                        ecommerce: {
                            currency: self.getCurrency(),
                            items: [{
                                item_id: String(event.reason.idProduct || ''),
                                quantity: 1
                            }]
                        }
                    });
                });
            }
        },

        // ── select_item (product click from listing) ─────────────
        bindSelectItem: function () {
            var self = this;

            document.addEventListener('click', function (e) {
                var link = e.target.closest('.product-miniature a[href], .js-product-miniature a[href]');
                if (!link) return;

                var productData = self.getProductDataFromElement(link);
                if (!productData || !productData.item_id) return;

                self.push({
                    event: 'select_item',
                    ecommerce: {
                        currency: self.getCurrency(),
                        items: [productData]
                    }
                });
            });
        },

        // ── Checkout step events ─────────────────────────────────
        bindCheckoutSteps: function () {
            var self = this;

            if (typeof prestashop === 'undefined') return;

            // add_shipping_info - when delivery step is completed
            document.addEventListener('click', function (e) {
                var deliveryBtn = e.target.closest('[name="confirmDeliveryOption"], .js-delivery .continue');
                if (!deliveryBtn) return;

                var selectedCarrier = document.querySelector('input[name="delivery_option[' + ']"]:checked, input[name^="delivery_option"]:checked');
                var shippingMethod = selectedCarrier ? selectedCarrier.value : 'unknown';

                self.push({
                    event: 'add_shipping_info',
                    ecommerce: {
                        currency: self.getCurrency(),
                        shipping_tier: shippingMethod
                    }
                });
            });

            // add_payment_info - when payment method is selected
            document.addEventListener('click', function (e) {
                var paymentBtn = e.target.closest('#payment-confirmation button, .js-payment .continue, [data-link-action="select-payment"]');
                if (!paymentBtn) return;

                var selectedPayment = document.querySelector('input[name="payment-option"]:checked');
                var paymentMethod = 'unknown';
                if (selectedPayment) {
                    var label = document.querySelector('label[for="' + selectedPayment.id + '"]');
                    paymentMethod = label ? label.textContent.trim() : selectedPayment.value;
                }

                self.push({
                    event: 'add_payment_info',
                    ecommerce: {
                        currency: self.getCurrency(),
                        payment_type: paymentMethod
                    }
                });
            });
        },

        getCurrency: function () {
            // Try prestashop global
            if (typeof prestashop !== 'undefined' && prestashop.currency && prestashop.currency.iso_code) {
                return prestashop.currency.iso_code;
            }
            // Try meta tag
            var meta = document.querySelector('meta[property="product:price:currency"]');
            if (meta) return meta.getAttribute('content');
            return 'PLN';
        }
    };

    // Init when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { TP.init(); });
    } else {
        TP.init();
    }
})();
