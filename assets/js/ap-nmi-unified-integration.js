jQuery(document).ready(function($) {
    'use strict';
    
    // Check if we're in a blocks checkout - if so, don't run legacy scripts
    if ($('div.wc-block-checkout').length > 0) {
        console.log('Blocks checkout detected - skipping legacy CollectJS integration');
        return;
    }
    
    // Wait for CollectJS to load
    if (typeof CollectJS === 'undefined') {
        console.error('CollectJS not loaded');
        return;
    }

    console.log('Legacy checkout detected - initializing CollectJS integration');

    // Tracing is a dependency of this script, but never let a missing trace helper
    // break checkout — fall back to console-only.
    var NMITrace = window.NMITrace || {
        id: function () { return ''; },
        log: function (stage, detail) { console.log('NMI ' + stage, detail || ''); },
        warn: function (stage, detail) { console.warn('NMI ' + stage, detail || ''); },
        error: function (stage, detail) { console.error('NMI ' + stage, detail || ''); },
        flush: function () {}
    };

    // Wallet-response detection normally comes from window.NMIWalletSupport
    // (assets/js/nmi-wallet-support.js). That script also gates whether the
    // Apple Pay / Google Pay fields get added to CollectJS.configure() at all —
    // if it fails to load, the PHP-side data-field-apple-pay-selector /
    // data-field-google-pay-selector attributes on the Collect.js script tag
    // (see enqueue-scripts.php) still wire the buttons up. But recognizing a
    // completed wallet payment in the tokenize callback below is a separate,
    // simple check — inlined here so it can never be silently lost even if
    // NMIWalletSupport is unavailable for any reason.
    var NMI_WALLET_TOKEN_TYPES = ['applePay', 'googlePay'];
    function nmiIsWalletResponse(response) {
        return !!response && NMI_WALLET_TOKEN_TYPES.indexOf(response.tokenType) !== -1;
    }
    function nmiWalletTypeOf(response) {
        return nmiIsWalletResponse(response) ? response.tokenType : 'unknown';
    }

    /**
     * Highlights the accepted-card icon row to reflect the brand Collect.js's BIN
     * lookup detected for the digits typed into the ccnumber iframe so far (see the
     * lookUpCallback below), fading out every other icon. Passing an empty/unknown
     * type clears back to the plain "all accepted cards shown" state the row starts
     * in — used once the card number field is emptied again.
     *
     * Card number itself never reaches this page (Collect.js hosts it in a secure
     * iframe for PCI reasons), so BIN detection — and this highlight — can only ever
     * happen through Collect.js's own callbacks, never by reading the field directly.
     *
     * @param {string} cardType Brand key from Collect.js (e.g. 'visa'), or '' to reset.
     * @return {void}
     */
    function nmiHighlightCardIcon(cardType) {
        var safeType = (typeof cardType === 'string' && /^[a-z0-9_-]+$/i.test(cardType)) ? cardType : '';
        $('.ap-nmi-card-icons').each(function () {
            var $container = $(this);
            $container.find('.card-icon').removeClass('active');
            if (safeType) {
                $container.addClass('has-active');
                $container.find('.card-icon.' + safeType).addClass('active');
            } else {
                $container.removeClass('has-active');
            }
        });
    }

    /**
     * Attach the trace id to the checkout form so process_payment logs under the same
     * id as the browser entries. Idempotent.
     *
     * @return {void}
     */
    function nmiAttachTraceId() {
        var field = (window.apNmiTraceConfig && window.apNmiTraceConfig.field) || 'ap_nmi_trace_id';
        var $form = $('form.woocommerce-checkout');
        if (!$form.length) {
            return;
        }
        $form.find('[name="' + field + '"]').remove();
        $('<input>').attr({ type: 'hidden', name: field, value: NMITrace.id() }).appendTo($form);
    }

    /**
     * True once a wallet token has been captured and the form is being submitted for
     * it. A wallet payment fills in no card iframes, so the card validation in the
     * checkout_place_order handler would reject it with "Please enter a valid card
     * number". The handler checks this flag and stands aside.
     *
     * @type {boolean}
     */
    var nmiWalletSubmitting = false;

    /**
     * The card brand CollectJS's own tokenization response reported for the card
     * currently being submitted (e.g. 'visa', 'amex') — captured here so
     * nmiSubmitFormWithToken can attach it to the request. The server has no other
     * way to know the brand for a tokenized card (it never sees the raw digits),
     * so this is what lets process_payment() enforce "Restrict Card Types" instead
     * of relying solely on this same check in the browser, which a modified or
     * replayed request could skip.
     *
     * @type {string}
     */
    var nmiDetectedCardType = '';

    // Log NMI configuration for debugging (especially useful for Apple Pay/Google Pay issues)
    if (typeof window.NMI_Debug !== 'undefined') {
        console.log('NMI Configuration:', {
            public_key_present: !!(ap_nmi_params.public_key && ap_nmi_params.public_key.length > 0),
            public_key_prefix: ap_nmi_params.public_key ? ap_nmi_params.public_key.substring(0, 10) + '...' : 'none',
            apple_pay_enabled: ap_nmi_params.apple_pay_enabled,
            google_pay_enabled: ap_nmi_params.google_pay_enabled,
            apple_merchant_id: ap_nmi_params.apple_merchant_id || 'not set',
            google_merchant_id: ap_nmi_params.google_merchant_id || 'not set',
            country: ap_nmi_params.country,
            currency: ap_nmi_params.currency,
            cart_total: ap_nmi_params.cart_total,
            is_checkout_page: ap_nmi_params.is_checkout_page
        });
        
        // Add to debug panel
        window.NMI_Debug.addSystemInfo('Apple Pay Enabled (Settings)', ap_nmi_params.apple_pay_enabled);
        window.NMI_Debug.addSystemInfo('Google Pay Enabled (Settings)', ap_nmi_params.google_pay_enabled);
        window.NMI_Debug.addSystemInfo('Apple Merchant ID', ap_nmi_params.apple_merchant_id || 'not set');
        window.NMI_Debug.addSystemInfo('Google Merchant ID', ap_nmi_params.google_merchant_id || 'not set');
        window.NMI_Debug.addSystemInfo('Cart Total', ap_nmi_params.currency + ' ' + ap_nmi_params.cart_total);
        window.NMI_Debug.addSystemInfo('Country', ap_nmi_params.country);
        window.NMI_Debug.addSystemInfo('Public Key Present', !!(ap_nmi_params.public_key && ap_nmi_params.public_key.length > 0));
        window.NMI_Debug.addSystemInfo('CollectJS Loaded', typeof CollectJS !== 'undefined');
    }

    // Track if CollectJS has been configured to prevent duplicate initialization
    window.nmiCollectJSConfigured = false;

    // Initialize validation tracking
    window.nmiFieldValidation = {
        ccnumber: false,
        ccexp: false,
        cvv: false
    };

    /**
     * Helper function to safely build 3DS data object
     * Only includes fields that are strings and not empty/null
     * Prevents REST API type validation errors
     */
    window.nmiSafe3DSData = function(threeDSResponse) {
        const data = {};
        
        // Only add fields if they are strings and not empty
        if (typeof threeDSResponse.cavv === 'string' && threeDSResponse.cavv) {
            data.cavv = threeDSResponse.cavv;
        }
        if (typeof threeDSResponse.xid === 'string' && threeDSResponse.xid) {
            data.xid = threeDSResponse.xid;
        }
        if (typeof threeDSResponse.eci === 'string' && threeDSResponse.eci) {
            data.eci = threeDSResponse.eci;
        }
        if (typeof threeDSResponse.cardHolderAuth === 'string' && threeDSResponse.cardHolderAuth) {
            data.cardholder_auth = threeDSResponse.cardHolderAuth;
        }
        if (typeof threeDSResponse.threeDsVersion === 'string' && threeDSResponse.threeDsVersion) {
            data.three_ds_version = threeDSResponse.threeDsVersion;
        }
        if (typeof threeDSResponse.directoryServerId === 'string' && threeDSResponse.directoryServerId) {
            data.directory_server_id = threeDSResponse.directoryServerId;
        }
        if (typeof threeDSResponse.cardHolderInfo === 'string' && threeDSResponse.cardHolderInfo) {
            data.cardholder_info = threeDSResponse.cardHolderInfo;
        }
        
        console.log('NMI Safe 3DS data prepared', {
            has_cavv: !!data.cavv,
            has_xid: !!data.xid,
            three_ds_version: data.three_ds_version || 'none'
        });
        
        return data;
    };

    // Function to query CollectJS iframes after they're created
    window.queryCollectJSIframes = function() {
        console.log('Querying CollectJS iframes...');
        
        var fieldContainers = {
            ccnumber: '#ap-nmi-card-number',
            ccexp: '#ap-nmi-expiry-date', 
            cvv: '#ap-nmi-card-cvv'
        };
        
        // Check each container for iframes
        Object.keys(fieldContainers).forEach(function(fieldName) {
            var container = $(fieldContainers[fieldName]);
            var iframe = container.find('iframe');
            
            if (iframe.length > 0) {
                console.log(fieldName + ' iframe found:', {
                    id: iframe.attr('id'),
                    src: iframe.attr('src'),
                    class: iframe.attr('class'),
                    width: iframe.attr('width'),
                    height: iframe.attr('height')
                });
                
                // Try to detect if iframe has content (limited by cross-origin)
                try {
                    var iframeDoc = iframe[0].contentDocument || iframe[0].contentWindow.document;
                    if (iframeDoc) {
                        console.log(fieldName + ' iframe document accessible');
                        // Note: This will likely fail due to cross-origin restrictions
                        var inputs = $(iframeDoc).find('input');
                        console.log(fieldName + ' inputs found:', inputs.length);
                    }
                } catch (e) {
                    console.log(fieldName + ' iframe content not accessible (cross-origin):', e.message);
                }
            } else {
                console.log(fieldName + ' iframe not found in container');
            }
        });
        
        // Query all CollectJS iframes globally
        var allIframes = $('.CollectJSInlineIframe');
        console.log('Total CollectJS iframes found:', allIframes.length);
        
        allIframes.each(function(index, iframe) {
            var $iframe = $(iframe);
            console.log('Iframe ' + index + ':', {
                id: $iframe.attr('id'),
                parent: $iframe.parent().attr('id'),
                src: $iframe.attr('src')
            });
        });
    };

    // Function to check if all iframes are loaded
    window.checkCollectJSIframesLoaded = function() {
        var expectedIframes = 3; // ccnumber, ccexp, cvv
        var loadedIframes = $('.CollectJSInlineIframe').length;
        
        console.log('Iframe load status:', loadedIframes + '/' + expectedIframes);
        
        return loadedIframes >= expectedIframes;
    };

    /**
     * Collect device data for 3DS authentication.
     * Per latest NMI docs, helps prevent timeouts.
     *
     * Declared here at the top level of this closure, not nested inside
     * nmiSubmitFormWithVaultAndThreeDS — both 3DS call sites live in sibling
     * functions and a nested declaration is not in scope for them.
     *
     * @returns {Object} Device data fields
     */
    function nmiCollectDeviceData() {
        let browserJavaEnabled = 'false';

        // window.navigator.javaEnabled() is deprecated, use try/catch
        try {
            if (navigator.javaEnabled && typeof navigator.javaEnabled === 'function') {
                browserJavaEnabled = navigator.javaEnabled() ? 'true' : 'false';
            }
        } catch(e) {
            browserJavaEnabled = 'false';
        }

        return {
            browserJavaEnabled: browserJavaEnabled,
            browserJavascriptEnabled: 'true',
            browserLanguage: window.navigator.language || window.navigator.userLanguage || 'en-US',
            browserColorDepth: String(window.screen.colorDepth || '24'),
            browserScreenHeight: String(window.screen.height || '768'),
            browserScreenWidth: String(window.screen.width || '1024'),
            browserTimeZone: String(new Date().getTimezoneOffset()),
            deviceChannel: 'Browser'
        };
    }

    /**
     * Snapshot of the shared CollectJS state, attached to tokenization failures.
     *
     * CollectJS is a singleton and more than one gateway configures it (the card
     * gateway here, the ACH gateway in the enterprise plugin). Config.update()
     * deep-merges, so `fields` accumulate across gateways while scalars such as
     * `callback`, `timeoutDuration` and `paymentSelector` are overwritten by
     * whichever configure() ran last. When tokenization stalls, this snapshot shows
     * who ended up owning the object and which iframes were actually messaged.
     *
     * @return {Object} Diagnostic snapshot.
     */
    function nmiTokenizationTrace() {
        if (typeof CollectJS === 'undefined') {
            return { collectJS: 'undefined' };
        }

        var trace = {
            // Which fields CollectJS thinks it has, i.e. whether ACH fields merged in.
            configuredFields: CollectJS.config ? Object.keys(CollectJS.config.fields || {}) : null,
            // Which iframes startPaymentRequest() actually posts SaveMultipartToken to.
            iframeKeys: CollectJS.iframes ? Object.keys(CollectJS.iframes) : null,
            inlineIframesInDom: document.querySelectorAll('.CollectJSInlineIframe').length,
            inSubmission: CollectJS.inSubmission,
            timeoutDuration: CollectJS.config ? CollectJS.config.timeoutDuration : null,
            paymentSelector: CollectJS.config ? CollectJS.config.paymentSelector : null,
        };

        // The iframe ignores SaveMultipartToken unless the token the parent sends
        // matches the token in its own hidden input, so a divergence here is silent
        // and looks exactly like a timeout.
        if (CollectJS.iframes) {
            trace.iframeTokens = {};
            Object.keys(CollectJS.iframes).forEach(function (key) {
                trace.iframeTokens[key] = CollectJS.iframes[key].contentWindow ? 'live' : 'detached';
            });
        }

        return trace;
    }

    /**
     * Check if any configured wallet button container has been populated by
     * CollectJS (i.e. CollectJS injected an iframe / child element into it).
     * Shows the express wrapper when at least one button has rendered.
     *
     * @param  {Object} walletFields  The walletFields object from initializeCollectJS.
     * @return {boolean}              True if at least one button is rendered.
     */
    function nmiCheckWalletButtons(walletFields) {
        if (!walletFields || Object.keys(walletFields).length === 0) {
            console.log('NMI: No wallet fields configured to check');
            return false;
        }
        var rendered = false;
        Object.keys(walletFields).forEach(function(type) {
            var sel = walletFields[type].selector;
            var $container = $(sel);
            var childrenCount = $container.children().length;
            
            console.log('NMI: Checking wallet button for', type, '— selector:', sel, 'children:', childrenCount);
            
            if (childrenCount > 0) {
                rendered = true;
                console.log('NMI: Wallet button rendered for', type, '— showing express wrapper.');
                
                // Log details about what was rendered
                $container.children().each(function(idx, child) {
                    console.log('NMI: Button child element', idx, ':', {
                        tagName: child.tagName,
                        className: child.className,
                        id: child.id
                    });
                });
            } else {
                console.log('NMI: Wallet button NOT yet rendered for', type);
            }
        });
        if (rendered) {
            $('.nmi-wallet-express-wrap').show();
        }
        return rendered;
    }

    /**
     * Initialize CollectJS - should only be called once per page load.
     * Called eagerly on document.ready so that the Google Pay express button
     * is visible above payment methods before any method is selected.
     * The CC field containers are already in the DOM at this point (WooCommerce
     * renders all payment method HTML on page load, just hidden).
     */
    function initializeCollectJS() {
        // Prevent duplicate initialization
        if (window.nmiCollectJSConfigured) {
            console.log('CollectJS already configured');
            return;
        }

        // Check if CC field containers exist (they should be in DOM at page load)
        if ($('#ap-nmi-card-number').length === 0) {
            console.log('NMI credit card field containers not found, skipping initialization');
            return;
        }

        NMITrace.log('legacy:configure:start');

        // Show loading indicator while CollectJS populates fields
        $('#ap-nmi-wc-fields-container .ap-nmi-fields-loader').show();

        // Configure CollectJS with shared settings

        // Build wallet fields when enabled (must be in the same configure call as CC fields)
        var walletFields = {};

        // Wallet preconditions are checked before we hand anything to CollectJS —
        // see assets/js/nmi-wallet-support.js. An unmet precondition is logged at
        // console.info and the wallet is simply left out of the config; it must never
        // affect the card fields or block checkout.
        var walletSupport = window.NMIWalletSupport;

        // Apple Pay: no merchant ID gate — display is allowed during merchant approval
        // review. When the account is approved and the ID is saved in settings, it
        // activates automatically with no code changes needed.
        if ( walletSupport && walletSupport.canUseApplePay( ap_nmi_params.apple_pay_enabled === 'yes', '#nmi-apple-pay-express' ) ) {
            // Key is camelCase `applePay` — CollectJS's Config accepts `applePay` and
            // silently ignores anything else, so a lowercase `applepay` configures
            // nothing at all.
            walletFields.applePay = {
                selector: '#nmi-apple-pay-express',
            };
            console.log('NMI: Including Apple Pay express field in CollectJS config');
        }

        // Google Pay: use the express container rendered above payment methods.
        // No merchant ID required during Google Pay merchant approval review.
        if ( walletSupport && walletSupport.canUseGooglePay( ap_nmi_params.google_pay_enabled === 'yes', '#nmi-google-pay-express' ) ) {
            // Only the properties in CollectJS's googlePay allowlist may appear here.
            // An unknown key makes configure() throw, which aborts before the card
            // iframes are built. The merchant ID is not one of them — CollectJS takes
            // it from the tokenization response.
            walletFields.googlePay = {
                selector:    '#nmi-google-pay-express',
                buttonType:  'buy',
                buttonColor: 'black',
            };
            console.log('NMI: Including Google Pay express field in CollectJS config');
        }

        var collectJsCcFields = {
            ccnumber: {
                selector: "#ap-nmi-card-number",
                title: "Card Number",
                placeholder: "0000 0000 0000 0000",
            },
            ccexp: {
                selector: "#ap-nmi-expiry-date",
                title: "Card Expiration",
                placeholder: "MM/YY",
            },
            cvv: {
                display: "show",
                selector: "#ap-nmi-card-cvv",
                title: "CVV Code",
                placeholder: "123",
            }
        };

        function doCollectJSConfigure(fields) {
            CollectJS.configure({
            paymentSelector: '#place_order, .wc-block-components-checkout-place-order-button',
            variant: "inline",
            country:  ap_nmi_params.country  || 'US',
            currency: ap_nmi_params.currency || 'USD',
            price:    ap_nmi_params.cart_total || '0.00',
            // The visible border is drawn on the container (see ap-nmi-unified-styles.css),
            // so the Collect.js input inside the iframe is borderless and fills it. Left to
            // its own devices, Collect.js copies the page's input width/borders into the
            // iframe, which clipped the right-hand borders.
            customCss: (function () {
                var css = {
                    "width": "100%",
                    "height": "42px",
                    "min-height": "0",
                    "margin-top": "0",
                    "margin-bottom": "0",
                    "padding": "0 12px",
                    "box-sizing": "border-box",
                    "box-shadow": "none",
                    "outline": "none",
                    "background-color": "transparent",
                    "font-family": "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif",
                    "font-size": "16px"
                };
                ["top", "right", "bottom", "left"].forEach(function (side) {
                    css["border-" + side + "-width"] = "0px";
                    css["border-" + side + "-style"] = "none";
                    css["border-" + side + "-color"] = "transparent";
                });
                ["top-left", "top-right", "bottom-right", "bottom-left"].forEach(function (corner) {
                    css["border-" + corner + "-radius"] = "0px";
                });
                return css;
            })(),
            invalidCss: {
                color: "#dc3232",
            },
            validCss: {
                color: "black",
            },
            placeholderCss: {
                color: "darkgray",
                "background-color": "#ffffff",
            },
            focusCss: {
                color: "black",
            },
            fields: Object.assign({}, collectJsCcFields, fields),
            // Fires as soon as Collect.js's BIN lookup can identify the brand from the
            // digits typed so far — well before the number is complete or the form is
            // submitted. This is the only live signal available for the icon row, since
            // the raw card number never reaches this page (see nmiHighlightCardIcon).
            lookUpCallback: function (response) {
                nmiHighlightCardIcon(response && response.type);
            },
            timeoutDuration: 10000,
            timeoutCallback: function () {
                // console.error, not console.log: this is a real checkout failure and
                // must be visible when the console is filtered to errors.
                NMITrace.error('legacy:tokenize:timeout', nmiTokenizationTrace());
                nmiShowError('Payment processing timeout. Please try again.');
                nmiEnableSubmitButton();
            },
            fieldsAvailableCallback: function () {
                NMITrace.log('legacy:fieldsAvailable', nmiTokenizationTrace());
                // Hide loading indicator
                $('#ap-nmi-wc-fields-container .ap-nmi-fields-loader').hide();
                // Reset validation when fields are available
                window.nmiFieldValidation = {
                    ccnumber: false,
                    ccexp: false,
                    cvv: false
                };
                // Wallet buttons may already be rendered — do an immediate check
                // (polling below will catch late renders too)
                nmiCheckWalletButtons(walletFields);
            },
            callback: function(response) {
                // Wallet payment — submit the checkout form with the wallet token.
                //
                // Dispatch on tokenType, NOT on response.wallet. CollectJS always sets
                // response.wallet, and for a card tokenization it is an object of null
                // fields — always truthy. Testing it sent every card payment down this
                // branch, which re-submitted the form mid-callback and left the
                // tokenization to time out.
                NMITrace.log('legacy:token:received', {
                    tokenType: response.tokenType,
                    hasToken: !!response.token,
                    cardType: response.card && response.card.type,
                    isWallet: nmiIsWalletResponse(response)
                });

                if (nmiIsWalletResponse(response)) {
                    var walletType = nmiWalletTypeOf(response);
                    console.log('NMI: Wallet token received for', walletType);
                    var $form = $('form.woocommerce-checkout');
                    $form.find('[name="payment_token"]').remove();
                    $form.find('[name="nmi_wallet_type"]').remove();
                    // A wallet response carries no card brand. Also drop any leftover
                    // 'card_type' field a prior, failed card-entry attempt on this same
                    // page may have appended — otherwise process_payment() would judge
                    // this wallet payment against that stale brand.
                    $form.find('[name="card_type"]').remove();
                    nmiDetectedCardType = '';
                    nmiHighlightCardIcon('');
                    $('<input>').attr({ type: 'hidden', name: 'payment_token',  value: response.token   }).appendTo($form);
                    $('<input>').attr({ type: 'hidden', name: 'nmi_wallet_type', value: walletType }).appendTo($form);
                    nmiAttachTraceId();

                    // Detach checkout_place_order before submitting, exactly as the card
                    // path does in nmiSubmitFormWithToken. A plain $form.submit() runs
                    // WooCommerce's submit handler, which re-fires checkout_place_order
                    // and re-enters our handler below; that handler then validates the
                    // card iframes, which a wallet payment never fills in, and aborts
                    // with "Please enter a valid card number". The flag is belt-and-braces
                    // in case another script has re-bound the event.
                    nmiWalletSubmitting = true;
                    $form.off('checkout_place_order').submit();
                    return;
                }

                // CC payment (existing logic).
                // Anything that throws in here leaves CollectJS permanently wedged:
                // MessageHandler sets inSubmission = false and calls retokenize() only
                // *after* this callback returns, so a throw means every later Place Order
                // is a silent no-op. Keep this branch defensive.
                var restricted_card = ap_nmi_params.restricted_card_types || '';
                var cardType = response.card && response.card.type;
                nmiDetectedCardType = cardType || '';

                if (cardType && restricted_card.includes(cardType)) {
                    nmiShowError('This card type is not accepted.');
                    nmiEnableSubmitButton();
                    return;
                }

                nmiHandleTokenResponse(response);
            },
            validationCallback: function(field, status, message) {
                nmiHandleValidation(field, status, message);
            }
            });
        }

        // CollectJS throws "Could not create PaymentRequestAbstraction" *asynchronously*
        // (inside a Promise) when the Apple Pay merchant session fails — e.g. NMI account
        // not yet activated for Apple Pay, or domain validation handshake rejected.
        // A synchronous try-catch won't catch it; we need an unhandledrejection listener.
        if ( Object.keys(walletFields).length > 0 ) {
            var nmiAsyncWalletErrHandler = function(event) {
                var msg = event.reason && (event.reason.message || String(event.reason));
                if ( msg && msg.indexOf('PaymentRequestAbstraction') !== -1 ) {
                    console.warn('NMI: Apple Pay merchant session failed (async). ' +
                        'Verify NMI merchant account has Apple Pay activated. Hiding button.');
                    event.preventDefault();
                    $('.nmi-wallet-express-wrap').hide();
                    window.removeEventListener('unhandledrejection', nmiAsyncWalletErrHandler);
                }
            };
            window.addEventListener('unhandledrejection', nmiAsyncWalletErrHandler);
        }

        // Also guard the synchronous path (some environments throw sync).
        //
        // Any throw out of configure() must degrade to card-only rather than propagate:
        // Config.update() assigns the new config *before* it validates, so a rejected
        // config leaves CollectJS mutated but skips buildInlineIframes() — mutated
        // config and no card fields at all. Card-only is always the safe fallback.
        try {
            doCollectJSConfigure(walletFields);
        } catch (e) {
            if ( Object.keys(walletFields).length === 0 ) {
                // Nothing left to drop — the card config itself is bad.
                console.error('NMI: CollectJS.configure() failed with CC fields only:', e.message, e);
                nmiShowError('The payment form failed to load. Please reload the page.');
                return;
            }

            console.warn('NMI: CollectJS.configure() rejected the wallet fields, retrying CC-only:', e.message);
            $('.nmi-wallet-express-wrap').hide();
            walletFields = {};
            try {
                doCollectJSConfigure({});
            } catch (ccError) {
                console.error('NMI: CC-only CollectJS.configure() also failed:', ccError.message, ccError);
                nmiShowError('The payment form failed to load. Please reload the page.');
                return;
            }
        }

        // Mark as configured
        window.nmiCollectJSConfigured = true;
        NMITrace.log('legacy:configure:done', { wallets: Object.keys(walletFields), state: nmiTokenizationTrace() });

        // Poll for wallet button render (CollectJS renders wallet buttons asynchronously
        // and independently from the CC fieldsAvailableCallback).
        // Checks every 300 ms for up to 15 seconds.
        if (Object.keys(walletFields).length > 0) {
            console.log('NMI: Starting wallet button polling for:', Object.keys(walletFields).join(', '));
            var walletPollCount = 0;
            var walletPollMax   = 50; // 50 × 300 ms = 15 s
            var walletPollId    = setInterval(function () {
                walletPollCount++;
                var found = nmiCheckWalletButtons(walletFields);
                if (found || walletPollCount >= walletPollMax) {
                    clearInterval(walletPollId);
                    if (!found) {
                        // console.info, not console.error: a wallet button that never
                        // renders is a diagnostic, not a checkout failure. Card payments
                        // are unaffected either way.
                        console.info('NMI wallets: buttons did not render after 15 seconds (' + walletPollCount + ' checks). ' +
                            'Usual causes: the tokenization key is not authorised for Apple Pay / Google Pay, ' +
                            'the NMI account is still in merchant approval review, or the domain is not ' +
                            'registered in the NMI Apple Pay settings.', walletFields);
                    } else {
                        console.log('NMI: Wallet button(s) successfully rendered after', walletPollCount, 'checks (~' + (walletPollCount * 0.3) + 's)');
                    }
                }
            }, 300);
        } else {
            console.log('NMI: No wallet fields to poll - skipping wallet button check');
        }
    }

    /**
     * Check if NMI credit card payment method is currently selected
     */
    function isNMIPaymentMethodSelected() {
        var selectedMethod = $('input[name="payment_method"]:checked').val();
        return selectedMethod === ap_nmi_params.ap_nmi_gateway_id;
    }

    /**
     * Get the CC payment box jQuery element
     */
    function getCCPaymentBox() {
        return $('div.payment_box.payment_method_' + ap_nmi_params.ap_nmi_gateway_id);
    }

    /**
     * Handle payment method selection.
     * CollectJS is already configured at page load (eager init).
     * We only need to show the CC field containers when NMI is selected.
     */
    $(document.body).on('payment_method_selected', function() {
        if (isNMIPaymentMethodSelected()) {
            console.log('NMI credit card payment method selected.');

            var $ccBox = getCCPaymentBox();
            var $savedCardRadio = $ccBox.find('.ap-nmi-saved-card-selection input[name="use_save_payment_method"][value="1"]');
            var hasSavedCard = $savedCardRadio.length > 0;

            if (hasSavedCard) {
                $ccBox.find('.ap-nmi-payment-form').removeClass('loading');
                if (!$savedCardRadio.is(':checked')) {
                    $savedCardRadio.prop('checked', true);
                    $('#ap-nmi-wc-fields-container').hide();
                    $('.ap-nmi-save-payment-row').hide();
                }
            } else {
                // Fields should already be in DOM; ensure container is visible.
                // CollectJS iframes may have been destroyed by another gateway — reinit if needed.
                if (!window.nmiCollectJSConfigured) {
                    console.log('NMI selected and CollectJS not configured, reinitializing...');
                    initializeCollectJS();
                }
            }
        }
    });

    /**
     * Handle checkout updates - reset and reinitialize if needed
     */
    /**
     * Handle checkout updates (e.g. shipping method change).
     * WooCommerce replaces the payment section via AJAX fragments, which destroys
     * CollectJS iframes. Reset the flag and reinitialize so the CC fields and
     * Google Pay express button are restored.
     */
    /**
     * Refresh ap_nmi_params.cart_total from the WooCommerce Store API before
     * CollectJS reinitializes.
     *
     * ap_nmi_params.cart_total is only ever set once, by PHP, when the page first
     * renders. Nothing else in this file ever reassigns it. So although
     * 'updated_checkout' already reinitializes CollectJS below, doing that alone
     * just rebuilds the wallet PaymentRequest with the same stale amount —
     * Apple Pay / Google Pay kept showing the pre-adjustment total even after a
     * coupon, shipping change, or fee updated the on-page order total correctly.
     *
     * The Store API's cart endpoint gives an unambiguous numeric total (with an
     * explicit minor-unit count), so no currency-symbol/locale parsing of the
     * rendered DOM total is needed. Always calls back — even on failure — so a
     * transient network hiccup never blocks CollectJS from reinitializing.
     *
     * @param {Function} callback
     * @return {void}
     */
    function nmiRefreshCartTotal(callback) {
        if (!ap_nmi_params.store_api_cart_url) {
            callback();
            return;
        }
        $.ajax({
            url: ap_nmi_params.store_api_cart_url,
            method: 'GET',
            dataType: 'json'
        }).done(function(cart) {
            if (cart && cart.totals && typeof cart.totals.total_price !== 'undefined') {
                var minorUnit = typeof cart.totals.currency_minor_unit === 'number'
                    ? cart.totals.currency_minor_unit
                    : 2;
                var liveTotal = (parseInt(cart.totals.total_price, 10) / Math.pow(10, minorUnit)).toFixed(2);
                console.log('NMI: Refreshed cart total for wallet payment request:', liveTotal);
                ap_nmi_params.cart_total = liveTotal;
            }
        }).fail(function(xhr) {
            console.warn('NMI: Could not refresh cart total from Store API — wallet amount may be stale.', xhr && xhr.status);
        }).always(function() {
            callback();
        });
    }

    $(document.body).on('updated_checkout', function() {
        console.log('Checkout updated — reinitializing CollectJS...');
        window.nmiCollectJSConfigured = false;

        var $ccBox = getCCPaymentBox();
        var $savedCardRadio = $ccBox.find('.ap-nmi-saved-card-selection input[name="use_save_payment_method"][value="1"]');
        var hasSavedCard = $savedCardRadio.length > 0;

        if (hasSavedCard) {
            if (!$savedCardRadio.is(':checked')) {
                $savedCardRadio.prop('checked', true);
            }
            $('#ap-nmi-wc-fields-container').hide();
            $('.ap-nmi-save-payment-row').hide();
            $ccBox.find('.ap-nmi-payment-form').removeClass('loading');
        }

        // Always reinitialize on checkout update — the Google Pay express button
        // container is also rebuilt by the fragment refresh. Refresh the cart
        // total first so the wallet PaymentRequest reflects the current amount.
        nmiRefreshCartTotal(function() {
            initializeCollectJS();
        });
    });

    /**
     * Handle saved payment method radio button toggle (scoped to CC payment box)
     * Show/hide new card fields based on selection
     */
    $(document).on('change', '.ap-nmi-saved-card-selection input[name="use_save_payment_method"]', function() {
        var useNew = $(this).val() === '0';
        var $fieldsContainer = $('#ap-nmi-wc-fields-container');
        var $saveRow = $('.ap-nmi-save-payment-row');
        
        if (useNew) {
            console.log('User selected "Use a new card", showing fields...');
            $fieldsContainer.slideDown(200);
            $saveRow.slideDown(200);
            
            // Reset flag - iframes may have been destroyed by another gateway's configure call
            window.nmiCollectJSConfigured = false;
            initializeCollectJS();
        } else {
            console.log('User selected "Use saved card", hiding fields...');
            $fieldsContainer.slideUp(200);
            $saveRow.slideUp(200);
        }
    });

    /**
     * Eager initialization on page load.
     * CollectJS is configured immediately so the Google Pay express button
     * is visible above payment methods before any method is selected.
     * The CC field containers are already in the DOM (WooCommerce renders all
     * payment method HTML at page load, just hidden).
     */
    $(function() {
        var $ccBox = getCCPaymentBox();
        var $savedCardRadio = $ccBox.find('.ap-nmi-saved-card-selection input[name="use_save_payment_method"][value="1"]');
        var hasSavedCard = $savedCardRadio.length > 0;

        if (hasSavedCard) {
            $ccBox.find('.ap-nmi-payment-form').removeClass('loading');
            if (!$savedCardRadio.is(':checked')) {
                $savedCardRadio.prop('checked', true);
                $('#ap-nmi-wc-fields-container').hide();
                $('.ap-nmi-save-payment-row').hide();
            }
        }

        // Always configure CollectJS on page load so the Google Pay express button
        // renders immediately regardless of which payment method is pre-selected.
        console.log('NMI: Eagerly initializing CollectJS on page load...');
        initializeCollectJS();
    });

    // Shared token response handler
    window.nmiHandleTokenResponse = function(response) {
        if (response.token) {
            console.log('Token generated successfully:', response.token);
            
            // Check if 3DS is enabled
            if (typeof ap_nmi_threeds_config !== 'undefined' && ap_nmi_threeds_config.enable_3ds === 'yes') {
                console.log('3DS enabled, initiating authentication...');
                nmiHandle3DSAuthentication(response.token, false);
            } else {
                // No 3DS, proceed with normal flow
                nmiSubmitFormWithToken(response.token, {});
            }
        } else {
            console.error('Token generation failed:', response);
            nmiShowError('Payment processing failed. Please check your card details and try again.');
            nmiEnableSubmitButton();
        }
    };

    // Handle 3DS authentication flow
    window.nmiHandle3DSAuthentication = function(paymentToken, isUsingSavedCard) {
        if (typeof Gateway === 'undefined') {
            console.error('Gateway.js not loaded');
            nmiShowError('3D Secure authentication unavailable. Please try again.');
            nmiEnableSubmitButton();
            return;
        }

        // Show 3DS message
        nmiShow3DSMessage('Verifying your card...');
        
        // Disable place order button
        $('#place_order').prop('disabled', true);
        
        try {
            const gateway = Gateway.create(ap_nmi_threeds_config.public_key);
            const threeDS = gateway.get3DSecure();
            
            // Prepare 3DS options
            let options = {
                currency: ap_nmi_threeds_config.currency,
                amount: ap_nmi_threeds_config.amount
            };
            
            if (isUsingSavedCard) {
                // Using saved payment method from customer vault
                const customerVaultId = $('input[name="use_save_payment_method"]').val();
                if (customerVaultId) {
                    options.customerVaultId = customerVaultId;
                }
            } else {
                // Using new card with payment token
                options.paymentToken = paymentToken;
                
                // Add billing data
                if (ap_nmi_threeds_config.billing_data) {
                    Object.assign(options, ap_nmi_threeds_config.billing_data);
                }
                
                // Add device data to prevent timeouts (per latest NMI docs)
                Object.assign(options, nmiCollectDeviceData());
            }
            
            console.log('3DS options:', options);
            
            // Create 3DS interface
            const threeDSecureInterface = threeDS.createUI(options);

            // Mount to DOM
            threeDSecureInterface.start('#threeDSMountPoint');

            // Handle challenge event
            threeDSecureInterface.on('challenge', function(e) {
                console.log('3DS Challenge initiated');
                nmiShow3DSMessage('Please complete the verification...');
            });
            
            // Handle complete event
            threeDSecureInterface.on('complete', function(e) {
                console.log('3DS Authentication complete:', e);
                nmiHide3DSMessage();
                
                // Use helper to safely extract only valid 3DS fields
                const threeDSData = window.nmiSafe3DSData(e);
                
                // Submit form with token and 3DS data
                nmiSubmitFormWithToken(paymentToken, threeDSData);
            });
            
            // Handle failure event
            threeDSecureInterface.on('failure', function(e) {
                console.error('3DS Authentication failed:', e);
                nmiHide3DSMessage();
                
                const failureAction = ap_nmi_threeds_config['3ds_failure_action'] || 'decline';
                
                if (failureAction === 'decline') {
                    nmiShowError('Card verification failed. Please try a different payment method.');
                    nmiEnableSubmitButton();
                } else if (failureAction === 'continue_without_3ds') {
                    console.log('Continuing without 3DS as per settings');
                    nmiSubmitFormWithToken(paymentToken, {});
                } else if (failureAction === 'continue_with_warning') {
                    console.log('Continuing with warning as per settings');
                    nmiSubmitFormWithToken(paymentToken, { threeds_warning: 'authentication_failed' });
                }
            });
            
            // Handle gateway errors
            gateway.on('error', function(e) {
                console.error('Gateway.js error:', e);
                nmiHide3DSMessage();
                
                // Check if 3DS is inactive on merchant account
                if (e.message && e.message.includes('3DSecure is inactive')) {
                    console.error('NMI 3DS Error: 3-D Secure is not enabled on your NMI merchant account');
                    nmiShowError('Secure payment verification is currently unavailable. Please contact the store or try a different payment method.');
                } else {
                    nmiShowError('Payment verification error. Please try again.');
                }
                
                nmiEnableSubmitButton();
            });
            
        } catch (error) {
            console.error('3DS initialization error:', error);
            nmiHide3DSMessage();
            nmiShowError('Unable to initialize card verification. Please try again.');
            nmiEnableSubmitButton();
        }
    };

    // Submit form with token and optional 3DS data
    window.nmiSubmitFormWithToken = function(token, threeDSData) {
        var form = $('form.woocommerce-checkout');
        
        // Add token to form
        if (form.find('input[name="payment_token"]').length === 0) {
            form.append('<input type="hidden" name="payment_token" value="' + token + '">');
        } else {
            form.find('input[name="payment_token"]').val(token);
        }

        // Add the detected card brand so the server can enforce "Restrict Card
        // Types" too, not just this browser's own check above.
        if (form.find('input[name="card_type"]').length === 0) {
            form.append('<input type="hidden" name="card_type" value="' + nmiDetectedCardType + '">');
        } else {
            form.find('input[name="card_type"]').val(nmiDetectedCardType);
        }

        // Add 3DS data to form if present
        if (threeDSData && Object.keys(threeDSData).length > 0) {
            Object.keys(threeDSData).forEach(function(key) {
                const fieldName = 'threeds_' + key;
                const value = threeDSData[key] || '';
                
                if (form.find('input[name="' + fieldName + '"]').length === 0) {
                    form.append('<input type="hidden" name="' + fieldName + '" value="' + value + '">');
                } else {
                    form.find('input[name="' + fieldName + '"]').val(value);
                }
            });
        }
        
        // Add save payment method checkbox value
        var savePaymentCheckbox = $('#save_payment_method');
        var savePayment = savePaymentCheckbox.length > 0 && savePaymentCheckbox.is(':checked') ? '1' : '0';
        console.log('Save payment checkbox found:', savePaymentCheckbox.length > 0);
        console.log('Save payment checkbox checked:', savePaymentCheckbox.is(':checked'));
        console.log('Save payment value:', savePayment);
        
        if (form.find('input[name="save_payment_method"]').length === 0) {
            form.append('<input type="hidden" name="save_payment_method" value="' + savePayment + '">');
        } else {
            form.find('input[name="save_payment_method"]').val(savePayment);
        }
        
        // Submit the form
        form.off('checkout_place_order').submit();
    };

    // Show 3DS message
    window.nmiShow3DSMessage = function(message) {
        var messageDiv = $('#threeDSMessage');
        if (messageDiv.length) {
            messageDiv.text(message).show();
        }
    };

    // Hide 3DS message
    window.nmiHide3DSMessage = function() {
        var messageDiv = $('#threeDSMessage');
        if (messageDiv.length) {
            messageDiv.hide();
        }
    };
    
    // Shared validation handler with visual feedback
    window.nmiHandleValidation = function(field, status, message) {
        console.log('Field validation:', field.field || field, status, message);
        
        var fieldName = field.field || field;
        var fieldElement = $(field.selector);
        var parent = fieldElement.closest('.form-row, .nmi-form-row');
        
        // Update validation tracking
        if (window.nmiFieldValidation && fieldName) {
            window.nmiFieldValidation[fieldName] = status;
        }
        
        // Colour the field's border: green when valid, red when not.
        if (fieldElement.length) {
            fieldElement.toggleClass('is-valid', !!status).toggleClass('is-invalid', !status);
        }

        // Visual feedback
        parent.removeClass('nmi-validated nmi-invalid woocommerce-invalid woocommerce-invalid-required-field');
        parent.find('.nmi-error').remove();
        
        if (status) {
            parent.addClass('nmi-validated');
        } else {
            parent.addClass('nmi-invalid woocommerce-invalid woocommerce-invalid-required-field');
            if (message) {
                parent.append('<span class="nmi-error" style="color: #e74c3c; font-size: 12px; display: block; margin-top: 5px;">' + message + '</span>');
            }
            // Collect.js only reports a brand via lookUpCallback while there are digits
            // to identify — it never fires again once the shopper clears the field back
            // out, so the icon highlight has to be reset from here instead, on the
            // "field is empty" validation message the ccnumber iframe reports for a
            // blank field (as opposed to "invalid"/incomplete-number messages, which
            // leave whatever brand was last detected highlighted).
            if (fieldName === 'ccnumber' && message && /empty/i.test(message)) {
                nmiHighlightCardIcon('');
            }
        }
    };
    
    // Helper function to enable submit button
    window.nmiEnableSubmitButton = function() {
        $('#place_order').prop('disabled', false).text('Place order');
        $('.wc-block-components-checkout-place-order-button').prop('disabled', false);
    };
    
    // Shared error display function
    window.nmiShowError = function(message) {
        // Remove existing errors
        $('.woocommerce-error, .nmi-error-message').remove();
        
        var errorHtml = '<div class="woocommerce-error" role="alert">' + message + '</div>';
        var errorDisplayed = false;
        
        // For legacy checkout
        if ($('form.woocommerce-checkout').length) {
            $('form.woocommerce-checkout').prepend(errorHtml);
            errorDisplayed = true;
        }
        
        // For blocks checkout
        if ($('div.wc-block-checkout').length) {
            $('.wc-block-components-checkout-form').prepend(errorHtml);
            errorDisplayed = true;
        }
        
        // Fallback: if error wasn't displayed via DOM, show alert
        if (!errorDisplayed) {
            console.error('NMI: Unable to display error in checkout form, using alert');
            alert('Payment Error: ' + message);
        }
        
        // Scroll to error with safety checks
        var errorElement = $('.woocommerce-error').first();
        if (errorElement.length && errorElement.offset()) {
            $('html, body').animate({
                scrollTop: errorElement.offset().top - 100
            }, 500);
        }
    };
    
    // Legacy checkout integration
    if ($('form.woocommerce-checkout').length && !$('div.wc-block-checkout').length) {
        console.log('Legacy checkout detected - setting up validation');
        
        // Override WooCommerce form submission for our gateway
        $('form.woocommerce-checkout').on('checkout_place_order', function(e) {
            // A wallet token is already on the form — let WooCommerce submit it as-is.
            // Card validation below would reject it: a wallet payment fills in none of
            // the card iframes.
            if (nmiWalletSubmitting) {
                NMITrace.log('legacy:place_order:wallet_passthrough');
                return true;
            }

            nmiAttachTraceId();
            NMITrace.log('legacy:place_order:clicked', {
                method: $('input[name="payment_method"]:checked').val()
            });
            // Only intercept if our gateway is selected
            if ($('input[name="payment_method"]:checked').val() === ap_nmi_params.ap_nmi_gateway_id) {
                e.preventDefault();
                
                console.log('AP NMI payment method selected, validating fields...');
                
                // Clear previous errors
                $('.woocommerce-error, .nmi-error-message').remove();
                
                // Check if using saved payment method
                // Scoped to the CC saved-card block. WooCommerce renders every gateway's
                // payment box into the same <form>, and the ACH gateway historically used
                // the same field name, which made the two a single radio group. The
                // toggling code around here is already scoped the same way.
                var useSavedCard = $('.ap-nmi-saved-card-selection input[name="use_save_payment_method"]:checked').val() === '1';
                
                if (useSavedCard) {
                    console.log('Using saved payment method');
                    
                    // Check if 3DS is enabled for saved cards
                    if (typeof ap_nmi_threeds_config !== 'undefined' && ap_nmi_threeds_config.enable_3ds === 'yes') {
                        console.log('3DS enabled for saved card, initiating authentication...');
                        
                        // Disable submit button
                        $('#place_order').prop('disabled', true).text('Processing...');
                        
                        // Get customer vault ID from hidden field or data attribute
                        var customerVaultId = $('input[name="customer_vault_id"]').val();
                        if (!customerVaultId) {
                            // Try to get from saved card display element
                            customerVaultId = $('.ap-nmi-saved-card-info').data('vault-id');
                        }
                        
                        if (customerVaultId) {
                            nmiHandle3DSAuthenticationForVault(customerVaultId);
                        } else {
                            console.error('Customer vault ID not found');
                            nmiShowError('Unable to process saved payment method. Please use a new card.');
                            nmiEnableSubmitButton();
                        }
                    } else {
                        // No 3DS for saved cards, submit directly
                        $(this).off('checkout_place_order').submit();
                    }
                    
                    return false;
                }
                
                // Using new card - check if CollectJS iframes are loaded
                var iframes = $('.CollectJSInlineIframe');
                if (iframes.length < 3) {
                    NMITrace.error('legacy:tokenize:fields_not_ready', nmiTokenizationTrace());
                    nmiShowError('Payment form is not ready. Please refresh the page and try again.');
                    return false;
                }
                
                // Validate all required fields
                var validationErrors = [];
                
                if (window.nmiFieldValidation.ccnumber !== true) {
                    validationErrors.push('<p>Please enter a valid card number.</p>');
                    $('#ap-nmi-card-number').closest('.form-row').addClass('woocommerce-invalid woocommerce-invalid-required-field');
                }
                
                if (window.nmiFieldValidation.ccexp !== true) {
                    validationErrors.push('<p>Please enter a valid expiry date.</p>');
                    $('#ap-nmi-expiry-date').closest('.form-row').addClass('woocommerce-invalid woocommerce-invalid-required-field');
                }
                
                // if (window.nmiFieldValidation.cvv !== true) {
                //     validationErrors.push('<p>Please enter a valid CVV code.</p>');
                //     $('#ap-nmi-card-cvv').closest('.form-row').addClass('woocommerce-invalid woocommerce-invalid-required-field');
                // }
                
                if (validationErrors.length > 0) {
                    nmiShowError(validationErrors.join(' '));
                    return false;
                }
                
                // Disable submit button to prevent multiple submissions
                $('#place_order').prop('disabled', true).text('Processing...');
                
                NMITrace.log('legacy:tokenize:start', nmiTokenizationTrace());

                // All fields are valid, proceed with tokenization
                try {
                    CollectJS.startPaymentRequest();
                } catch (error) {
                    console.error('Error starting payment request:', error);
                    nmiShowError('Payment processing error. Please try again.');
                    nmiEnableSubmitButton();
                }
                
                return false; // Prevent default form submission
            }
            
            return true; // Allow other payment methods to proceed normally
        });
        
        // Handle 3DS for saved vault cards
        window.nmiHandle3DSAuthenticationForVault = function(customerVaultId) {
            if (typeof Gateway === 'undefined') {
                console.error('Gateway.js not loaded');
                nmiShowError('3D Secure authentication unavailable. Please try again.');
                nmiEnableSubmitButton();
                return;
            }

            // Show 3DS message
            nmiShow3DSMessage('Verifying your saved card...');
            
            try {
                const gateway = Gateway.create(ap_nmi_threeds_config.public_key);
                const threeDS = gateway.get3DSecure();
                
                // Prepare 3DS options for customer vault
                const options = {
                    customerVaultId: customerVaultId,
                    currency: ap_nmi_threeds_config.currency,
                    amount: ap_nmi_threeds_config.amount
                };
                
                // Add device data to prevent timeouts (per latest NMI docs)
                Object.assign(options, nmiCollectDeviceData());
                
                console.log('3DS options for vault:', options);
                
                // Create 3DS interface
                const threeDSecureInterface = threeDS.createUI(options);

                // Mount to DOM
                threeDSecureInterface.start('#threeDSMountPoint');

                // Handle challenge event
                threeDSecureInterface.on('challenge', function(e) {
                    console.log('3DS Challenge initiated for saved card');
                    nmiShow3DSMessage('Please complete the verification...');
                });
                
                // Handle complete event
                threeDSecureInterface.on('complete', function(e) {
                    console.log('3DS Authentication complete for saved card:', e);
                    nmiHide3DSMessage();
                    
                    // Use helper to safely extract only valid 3DS fields
                    const threeDSData = window.nmiSafe3DSData(e);
                    
                    // Submit form with 3DS data (no token needed for vault)
                    nmiSubmitFormWithVaultAndThreeDS(threeDSData);
                });
                
                // Handle failure event
                threeDSecureInterface.on('failure', function(e) {
                    console.error('3DS Authentication failed for saved card:', e);
                    nmiHide3DSMessage();
                    
                    const failureAction = ap_nmi_threeds_config['3ds_failure_action'] || 'decline';
                    
                    if (failureAction === 'decline') {
                        nmiShowError('Card verification failed. Please try a different payment method.');
                        nmiEnableSubmitButton();
                    } else if (failureAction === 'continue_without_3ds') {
                        console.log('Continuing without 3DS as per settings');
                        $('form.woocommerce-checkout').off('checkout_place_order').submit();
                    } else if (failureAction === 'continue_with_warning') {
                        console.log('Continuing with warning as per settings');
                        nmiSubmitFormWithVaultAndThreeDS({ threeds_warning: 'authentication_failed' });
                    }
                });
                
                // Handle gateway errors
                gateway.on('error', function(e) {
                    console.error('Gateway.js error for saved card:', e);
                    nmiHide3DSMessage();
                    
                    // Check if 3DS is inactive on merchant account
                    if (e.message && e.message.includes('3DSecure is inactive')) {
                        console.error('NMI 3DS Error: 3-D Secure is not enabled on your NMI merchant account');
                        nmiShowError('Secure payment verification is currently unavailable. Please contact the store or try a different payment method.');
                    } else {
                        nmiShowError('Payment verification error. Please try again.');
                    }
                    
                    nmiEnableSubmitButton();
                });
                
            } catch (error) {
                console.error('3DS initialization error for saved card:', error);
                nmiHide3DSMessage();
                nmiShowError('Unable to initialize card verification. Please try again.');
                nmiEnableSubmitButton();
            }
        };
        
        // Submit form with vault and 3DS data
        window.nmiSubmitFormWithVaultAndThreeDS = function(threeDSData) {
            var form = $('form.woocommerce-checkout');
            
            // Add 3DS data to form if present
            if (threeDSData && Object.keys(threeDSData).length > 0) {
                Object.keys(threeDSData).forEach(function(key) {
                    const fieldName = 'threeds_' + key;
                    const value = threeDSData[key] || '';
                    
                    if (form.find('input[name="' + fieldName + '"]').length === 0) {
                        form.append('<input type="hidden" name="' + fieldName + '" value="' + value + '">');
                    } else {
                        form.find('input[name="' + fieldName + '"]').val(value);
                    }
                });
            }

            // Submit the form
            form.off('checkout_place_order').submit();
        };
    }
});