PayNow.gg WHMCS Gateway
=======================

Install
-------
1. Upload `paynowgg.php` to:
   
   `/modules/gateways/paynowgg.php`
   
2. Upload `callback/paynowgg.php` and `callback/paynowgg_return.php` to:
   
   `/modules/gateways/callback/paynowgg.php` and `/modules/gateways/callback/paynowgg_return.php`
   
3. In WHMCS Admin, activate the PayNow.gg gateway.
   
4. Configure:
   - Store ID
   - API Key token
   - Webhook Signing Secret
     
5. In PayNow, create a JSON webhook endpoint pointing to:
   
   `https://YOUR-WHMCS-DOMAIN/modules/gateways/callback/paynowgg.php`
   
6. Subscribe at minimum to `ON_ORDER_COMPLETED`. Optional events logged/handled: `ON_REFUND`, `ON_CHARGEBACK`, `ON_SUBSCRIPTION_ACTIVATED`, `ON_SUBSCRIPTION_RENEWED`.

<img width="1485" height="637" alt="image" src="https://github.com/user-attachments/assets/41cc8694-1763-40db-9326-97795ab0318b" />

## Completing the PayNow.GG Onboarding

1. Upload `paynow_test_callback.php` to:

   `/modules/gateways/callback/`

2. In your PayNow dashboard, create a webhook and set the endpoint URL to:

   `https://YOUR-WHMCS-DOMAIN/modules/gateways/callback/paynow_test_callback.php`

3. Generate a test transaction by either:
   - Applying a **100% discount coupon** to an invoice, or
   - Creating a **$0.01 invoice** and completing the checkout process.

4. Once the test payment is processed, the callback script should receive the webhook request and verify that your webhook configuration is working correctly.

If the test completes successfully, webhook/callback functionality has been verified. Note: The only way to create an order on a headless store is to do it through WHMCS. You must invoice yourself from WHMCS then you can create a coupon code from PayNow to complete the order.

Notes
-----
- The module creates a PayNow customer and checkout from the WHMCS invoice.
- Checkout metadata includes whmcs_invoice_id so the signed webhook can mark the invoice paid.
- Subscription support is experimental and only requested when the WHMCS invoice has a single recurring Hosting or Addon line and Allow Subscriptions is enabled.

Changelog
---------
1.0.1 - Added Callback Test and updated README.

1.0.0 - Initial commit
