=== Gain Commerce NMI Payment Gateway for WooCommerce ===
Contributors: gaincommerce
Tags: nmi, woocommerce, payment gateway, credit card, checkout
Requires at least: 6.8
Tested up to: 7.1
Stable tag: 1.16.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: woocommerce

Accept credit cards, Apple Pay & Google Pay via NMI. Includes PCI compliance, card surcharging, bot defense & full HPOS support.

== Description ==

Accept credit cards, Apple Pay, and Google Pay seamlessly using the **Gain Commerce NMI Payment Gateway for WooCommerce**. 

Engineered natively for WooCommerce Checkout Blocks and High-Performance Order Storage (HPOS), this plugin tokenizes cardholder data via NMI Collect.js—offloading PCI compliance away from your web server while providing a frictionless checkout experience.

### Why Choose Gain Commerce NMI Payment Gateway for WooCommerce?
* **One-Click Mobile Checkout:** Native support for Apple Pay and Google Pay to boost mobile conversions.
* **Zero PCI Friction:** Uses NMI Collect.js tokenization—card data never touches your web server.
* **Modern Checkout Ready:** Built natively for WooCommerce Checkout Blocks and traditional legacy checkouts.
* **Bot & Card-Testing Defense:** Built-in protection via Google reCAPTCHA v3 or Cloudflare Turnstile to block automated attacks.
* **Built-in Surcharging:** Offset transaction costs by adding flat or percentage-based credit card surcharges directly at checkout.

### Free Features
* **Express Digital Wallets:** Native support for Apple Pay and Google Pay.
* **PCI-DSS Compliant Tokenization:** Secure Collect.js integration ensures zero sensitive card storage on your server.
* **Bot Defense:** Block card-spinning scripts using Google reCAPTCHA v3 or Cloudflare Turnstile.
* **Credit Card Surcharges:** Automatically pass processing fees to buyers via flexible flat or percentage rules.
* **Custom Decline Messages:** Tailor customer-facing error messages for specific decline scenarios to reduce cart abandonment and support tickets.
* **Checkout Address Autocomplete:** Speed up checkout and reduce address typos with real-time address suggestions powered by Google Places.
* **Full Order Dashboard Control:** Process Authorize, Capture and Partial/Full Refunds directly from the WooCommerce order screen.
* **Card Brand Restrictions:** Restrict or allow specific card brands (Visa, Mastercard, Amex, Discover) based on your merchant agreement.
* **Fraud & Ledger Sync:** Log AVS/CVV response codes to order notes and auto-sync WooCommerce shipping data to NMI.
* **Dynamic Descriptors:** Set custom statement descriptors passed directly to customer credit card statements.
* **Dedicated Sandbox & Test Modes:** Safely test transactions without affecting live data using an instant environment switcher with separate Sandbox credentials.

### Supercharge Your Store with Premium Features
Upgrade to the **Gain Commerce NMI Premium Add-on** to unlock recurring revenue and enterprise vaulting tools:

* **WooCommerce Subscriptions:** Automated recurring payments and renewal order processing.
* **NMI Customer Vault:** Stored payment methods allowing returning customers one-click checkouts.
* **ACH Electronic Checks:** Direct bank transfer processing alongside credit cards.
* **3D Secure 2.0 (3DS2):** SCA/PSD2 compliant step-up authentication for liability shift and fraud reduction.

== Installation ==

1. Upload the `gaincommerce-nmi-payment-gateway-for-woocommerce` folder to `/wp-content/plugins/`, or search and install via **Plugins > Add New** in WordPress.
2. Activate the plugin through the **Plugins** menu.
3. Navigate to **WooCommerce > Settings > Payments > Gain Commerce NMI Payment Gateway**.
4. Enter your NMI API credentials (**Private Security Key** and **Public Security Key**).
5. (Optional) Configure Bot Protection (reCAPTCHA/Turnstile), Credit Card Surcharging and Address Autocomplete (needs your own Google Places API key).
6. Set Environment to **Live** or **Test Mode** and save changes.

= Testing Environment Setup =

Enable **Test Mode** in gateway settings and use these standard NMI test card numbers:

* **Visa:** `4111 1111 1111 1111`
* **Mastercard:** `5431 1111 1111 1111`
* **American Express:** `3411 1111 1111 1111`
* **Discover:** `6011 0009 9130 0009`
* **Expiration Date:** Any valid future date (e.g., `12/28`)
* **CVV Code:** Any 3 digits (4 digits for Amex)

== Frequently Asked Questions ==

= Does this plugin require a WooCommerce installation? =
Yes. WooCommerce 8.0 or higher must be installed and active on your WordPress site.

= Does this plugin require an active NMI account? =
Yes. An active NMI payment gateway account is required. Contact [Allied Payments](https://www.alliedpayments.com), our preferred provider, to set up your merchant account.

= Is this plugin PCI Compliant? =
Yes. Utilizing NMI's Collect.js script to tokenize payment data in the browser, sensitive credit card numbers never touch or pass through your web server, keeping your store fully PCI-DSS compliant.

= Is an SSL Certificate required? =
Yes. A valid SSL certificate is required on your website to maintain PCI-DSS compliance and ensure end-to-end security during customer checkout.

= Does the free plugin store customer credit card details? =
No. Credit card numbers are tokenized in real time by NMI and are never stored on your WordPress server. Storing cards securely in the NMI Customer Vault for returning customers is available when paired with our Premium Add-on.

= How does credit card surcharging work? =
You can enable surcharging in gateway settings to pass a flat fee or a percentage-based processing charge to your customers at checkout. The fee dynamically updates on the checkout page so customers see a transparent disclosure before placing their order.

= How does bot protection defend against card testing? =
You can supply your own Google reCAPTCHA v3 or Cloudflare Turnstile API keys in the settings. Once enabled, the system automatically checks checkout attempts to block automated card-spinning scripts from testing stolen card lists on your store.

= Where can I get support or integration assistance? =
You can visit our official support portal at [gaincommerce.com/support](https://gaincommerce.com/support) for documentation and help desk assistance.

== System Requirements ==

* Active NMI payment gateway account (via [Allied Payments](https://www.alliedpayments.com) or your merchant account provider)
* WooCommerce version 8.0 or higher
* Valid SSL Certificate (HTTPS)
* PHP version 7.4 or higher
* Fully compatible with WooCommerce HPOS (High-Performance Order Storage) and WooCommerce Checkout Blocks

== External Services ==

This plugin relies on external third-party services to process payments and prevent automated fraud:

* **NMI Payment Gateway (Network Merchants, LLC):** Connects to NMI to securely tokenize card details in the browser via Collect.js before processing transactions. Order and shipping details are sent to NMI for transaction ledger processing.
  * [NMI Terms of Service](https://www.nmi.com/terms-of-service/)
  * [NMI Privacy Policy](https://www.nmi.com/privacy-policy/)

* **Google reCAPTCHA v3 (Optional):** Evaluates checkout traffic to block automated card-testing attacks when enabled by the merchant.
  * [Google Terms of Service](https://policies.google.com/terms)
  * [Google Privacy Policy](https://policies.google.com/privacy)

* **Cloudflare Turnstile (Optional):** Provides CAPTCHA protection against automated card spinning when configured by the merchant.
  * [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/)

* **Google Places API / Maps JavaScript API (Optional):** When the merchant enables Address Autocomplete and saves their own Google API key, the shopper's browser loads Google's script after the shopper clicks into a checkout address field, and sends the text typed in that field to Google to get address suggestions. Nothing is loaded or sent unless the feature is enabled with a key. Usage is billed to the merchant's own Google Cloud account.
  * [Google Terms of Service](https://policies.google.com/terms)
  * [Google Privacy Policy](https://policies.google.com/privacy)

= Source Code & Build Instructions =

The source code for minified assets is available in the [Gain Commerce GitHub Repository](https://github.com/gaincommerce/gaincommerce-nmi-payment-gateway-for-woocommerce/).

Build instructions:
1. Clone the repository.
2. Run `npm install` to install dependencies.
3. Run `npm run build` to generate production assets.

== Screenshots ==

1. **Quick Access:** Access plugin configuration directly from the WordPress Plugins page.
2. **General Settings:** Enable the NMI payment gateway and select your operating environment.
3. **Environment Modes:** Easily switch between Live, Test, and Sandbox modes.
4. **Bot Protection:** Secure your checkout with Google reCAPTCHA v3 or Cloudflare Turnstile.
5. **Address Autocomplete:** Speed up checkout using your own Google Places API key.
6. **Developer Tools:** Manage debug logging, the debug console, and the admin bar shortcut.
7. **Custom Decline Messages:** Configure user-friendly error messages with a live customer preview.
8. **Checkout Experience:** Real-time address autocomplete suggestions on the checkout form.
9. **Express & Card Payments:** Hosted, PCI-compliant card fields alongside Apple Pay and Google Pay.
10. **Detailed Order Notes:** View full NMI transaction responses, including AVS and CVV verification results.

== Changelog ==

= 1.16.9 =
* Add optional Address Autocomplete for the billing and shipping address fields on both the block and classic checkout, using your own Google Places API key (Settings > NMI Settings > Address Autocomplete). It is off by default; nothing is loaded and nothing is sent to Google until you enable it and save a key. Suggestions follow the country chosen on the form, filling in street, city/suburb, state and postcode, which helps reduce AVS mismatches. A bad key or any Google error is ignored and checkout keeps working with plain fields. Google usage is billed to your own Google Cloud account.
* Redesign the classic (legacy) checkout card fields to match the block checkout: consistent borders that are no longer clipped, 3px corners, a fixed field height so the page does not jump, green/red valid and invalid states, a focus ring, and a layout that stacks on narrow screens.
* Fix the Card Processing Fee amount drifting to the middle of its row in the classic checkout order review on themes that only right-align the Subtotal, Shipping and Total rows.
* Improve the block checkout card fields: a responsive layout (one line on desktop, two on tablets, one per line on phones), field borders drawn on the container so the right-hand edge is never cut off, field-specific validation messages (card number, expiry, security code), and space reserved for the Google Pay button so the page no longer shifts while it loads.
* Fix duplicate and generic payment errors on the block checkout. A card error is now shown once, in plain words, instead of WooCommerce's "There was a problem with your payment option".
* Fix an old payment error blocking the next Place Order click on the block checkout after the shopper corrected a card field.
* Fix a declined payment's message appearing again at the top of the checkout on the next page load, sometimes with older wording.
* Fix an order moving to Processing or Completed when NMI rejected the capture of an authorized payment. The order is now put back On hold with a note explaining that the capture failed, so it can be retried.
* Fix the authorize-only order note showing a stray "1" in front of the NMI transaction ID.
* Fix the decline message boxes in the NMI Settings tab appearing twice, and the grey default wording not showing in empty boxes. Decline message boxes are now hidden by default on new installs (your saved wording is still used).
* The checkout script now uses WordPress's own React JSX runtime instead of a bundled copy, so it keeps working with newer versions of WordPress.
* Developer: the "Tested up to" value now lives only in this readme file, text domains are plain strings, and the classic checkout script and stylesheet are cache-busted by file modification time.
* Add an "Environment" dropdown (Live / Test / Sandbox) to the NMI Settings tab, replacing the separate "Test mode" and "Pathfinder Sandbox" checkboxes. Existing stores keep their current mode automatically, and nothing changes until you save.
* Add a colour-coded status box at the top of the NMI Settings tab that says which environment is active, plus a "Test cards and scenarios" helper while testing.
* Add an optional "NMI Settings" shortcut to the admin bar (off by default; turn it on with "Admin bar shortcut"), and a "no real payments" notice on the classic and block checkout while not live.
* Add separate Sandbox Tokenization Key and Sandbox Private Key fields, shown only in Sandbox, so your live keys are never overwritten. Stores that already typed sandbox keys into the main fields keep working until the sandbox fields are filled.
* Show the Sandbox fields only in Sandbox, and the live key fields only in Live and Test. The Private Key is now a password field.
* Add friendly decline messages: shoppers now see a clear instruction (try another card, check the security code, try again later, ...) instead of NMI's technical text. Fraud and store-configuration reasons are never shown to shoppers. NMI's original text and numeric code are kept in the order note and log. Change the wording of the six main messages in the NMI Settings tab (leave a box empty to use the default), show or hide the message boxes with "Show Decline Message" / "Hide Decline Message" (hiding only tidies the page; your saved wording keeps being used), or change any message with the `gaincommerce_nmi_decline_message` filter.
* Fix the broken "documentation" link in the checkout test-mode message.
* Add the Address Autocomplete script to the BulletProof Security Pro whitelist, so a plugin firewall no longer blocks it with a 403.

= 1.16.8 =
* Fix card transactions showing the web server's IP address in NMI instead of the customer's. The shopper's IP is now sent as `ipaddress` on sale, auth and validate requests.
* Fix the Cloudflare Turnstile "Verify you are human" box showing on the order-received (thank-you) page. Bot Protection now loads only on the cart and checkout pages.
* Rename the gateway in WooCommerce > Settings > Payments to "NMI Payment Gateway by Gain Commerce".
* Change the "Manage" button on the Payments screen to open the NMI Settings tab, so there is a single place to manage the gateway.
* Add review prompts: a milestone notice on WooCommerce settings screens (after 30 orders, or 30 days of use with at least one sale), a review box with a quick satisfaction check on the NMI Settings tab, and a one-time "payment successful" card after the first successful transaction. All prompts can be dismissed permanently, and "Maybe Later" snoozes the notice for 7 days.
* Review links now open the WordPress.org review form with 5 stars pre-selected, and a WooCommerce-style "please leave a 5-star rating" line appears in the footer of the NMI settings screens until dismissed.
* Add uninstall cleanup for the review-prompt options.

= 1.16.7 =
* Add an "NMI Settings" tab to WooCommerce > Settings, directly after Payments, so the gateway settings are easier to find and manage. It edits the same settings as Payments > Manage.
* Change the "Settings" link on the Plugins screen to open the new NMI Settings tab.
* Fix a cancelled Apple Pay sheet on the Checkout Block placing an order with an empty payment token (declined by NMI with "Payment token is empty"). A cancelled sheet now returns the shopper to checkout with no order created.
* Fix a failed Apple Pay / Google Pay token update on the Checkout Block placing an order with an incomplete token. The shopper now sees "Your Apple Pay / Google Pay payment could not be completed and you have not been charged" and no order is created.
* Fix an empty wallet block and the "or pay with card" divider showing on the Checkout Block when no wallet is available. Google Pay is now shown only once Google confirms it is ready on the shopper's device.
* Fix Cloudflare Turnstile logging an "Unknown parameter ?ver" warning by loading its script without a version parameter.
* Add the Bot Protection, debug console and trace scripts to the BulletProof Security Pro whitelist, so a plugin firewall no longer blocks them with a 403.

= 1.16.6 =
* Fix PHP warning "Undefined array key "default"" shown at the top of admin pages, and written to the PHP error log, until the gateway settings were saved for the first time. The warning also pushed the footer into the middle of the WooCommerce settings screen.
* Change the gateway to be enabled by default until the settings are first saved. Sites that have already saved their gateway settings are not affected.
* Change Test Mode to be off by default until the settings are first saved. Sites that have already saved their gateway settings are not affected.
* Fix the WooCommerce Checkout Block not using the "enabled" default before the gateway settings were first saved.
* Add a "Get Enterprise" link on the Plugins screen. It is hidden when the Enterprise add-on is active.

= 1.16.5 =
* Add optional Bot Protection setting (Google reCAPTCHA v3 or Cloudflare Turnstile) to help block automated card-testing ("card spinning") attempts at checkout. Disabled by default.
* Add dynamic surcharge label to the Credit Card payment option at checkout — shows "(Includes $X surcharge)" or "(Includes X% surcharge)" based on the configured Processing Fee, updating automatically with the fee setting.
* Rename the "Processing Fee" settings section to "Credit Card Surcharge" with updated description text.

= 1.16.4 =
* Change card processing fee setting to a dropdown: No Processing Fee, Percentage Fee, or Flat Rate Fee.
* Add flat rate processing fee option, in addition to the existing percentage-based fee.
* Add live card-type highlighting at checkout: as the customer types their card number, the accepted-card icons narrow down to the matching brand.
* Improve the Restrict Card Types setting to use a proper multi-select picker with clear "click to remove" tags, and clarify that leaving it empty accepts all card types.
* Fix card processing fee not being applied on the WooCommerce Checkout Block (it previously only appeared on the classic checkout).
* Fix new card entry occasionally resetting mid-checkout on the Checkout Block, caused by the Apple Pay / Google Pay amount sync re-initializing the card fields more often than necessary.

= 1.16.3 =
* Fix extra blank space shown on checkout below the saved payment method option.
* Fix card type icons to display as real card logos instead of text placeholders.
* Fix Apple Pay / Google Pay showing the original cart total after a coupon, shipping change, or fee updated the order total.
* Add optional card processing fee (percentage-based surcharge) for card payments, configurable in gateway settings.
* Improve Apple Pay / Google Pay reliability so wallet payments are still recognized correctly even if a supporting script fails to load.

= 1.16.2 =
* Updated with WordPress Latest Version

= 1.16.1 =
* Fix issue on JS checkout both legacy and block.
* Maintenance

= 1.15.1 =
* Fix issue in NMI timeout

= 1.14.8 =
* Support NMI Digital wallet payment, Google Pay and Apple Pay.

= 1.13.1 =
* Add WooCommerce Subscriptions support for automatic recurring payments. Requires WooCommerce Subscriptions plugin and Premium Add-on.
* Maintenance

= 1.12.0 =
* Fix checkout issue in loading NMI in legacy and block base.
* Fix UI fields in checkout page both in legacy and block base.
* Maintenance.

= 1.11.0 =
* Added support for 3D Secure (3DS) authentication when the Premium Add-on plugin is installed. The free plugin now enables 3DS for enhanced security, but requires the Premium plugin to activate this feature.

= 1.10.0 =
* Fix and replace deprecated reduced_stock_qty to wc_reduce_stock_levels
* Add ability for customers to store payment method for future transactions in card vault by choosing “Save Payment Method” during checkout. Available only in Premium Plugin
* Choose "Saved Payment Method" stores card account in secure PCI-Compliant environment. Customer can replace card in checkout process by choosing “Save Payment Method”.
* Test to latest WooCommerce version 10.4.3

= 1.8.1 =
* Test to latest WordPress 6.9 version.
* Test to latest WooCommerce 10.4.2
* Add Description for premium plugin in readme file.

= 1.8.0 =
* Add Shipping Fields from WooCommerce order to NMI merchants.
* Ability to enable Dynamic Descriptor and pass descriptor data.
* Add AVS/CVV notes in order.
* Add additional order notes as Response code and NMI payment status.

= 1.7.6 =
* Integrate restrict card type in block checkout
* Integrate restrict card type in legacy checkout
* Integrate CollectJS into WC checkout blocks
* Integrate WC blocks
* Add CollectJS to legacy checkout
* WooCommerce Legacy checkout work on NMI
* Add Auth Feature and when changed to order processing or complete then capture
* Add Gateway Class
* Add Logger Class
* Add API class for NMI
* Add API Factory
* Add API Credit Card Sale
* Add API Refund
* Add API Void
* Add API Auth, Capture and Auth + Capture

= 1.0.0 =
* Initial release

== Upgrade Notice ==
= 1.0.0 =
Initial release of Gain Commerce NMI Payment Gateway for WooCommerce plugin.
