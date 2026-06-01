PayNow.gg WHMCS Gateway
=======================

Install
-------
1. Upload paynowgg.php to: /modules/gateways/paynowgg.php
2. Upload callback/paynowgg.php and callback/paynowgg_return.php to: /modules/gateways/callback/paynowgg.php and /modules/gateways/callback/paynowgg_return.php
3. In WHMCS Admin, activate the PayNow.gg gateway.
4. Configure:
   - Store ID
   - API Key token
   - Webhook Signing Secret
5. In PayNow, create a JSON webhook endpoint pointing to:
   https://YOUR-WHMCS-DOMAIN/modules/gateways/callback/paynowgg.php
6. Subscribe at minimum to ON_ORDER_COMPLETED. Optional events logged/handled: ON_REFUND, ON_CHARGEBACK, ON_SUBSCRIPTION_ACTIVATED, ON_SUBSCRIPTION_RENEWED.

<img width="1485" height="637" alt="image" src="https://github.com/user-attachments/assets/41cc8694-1763-40db-9326-97795ab0318b" />

Notes
-----
- The module creates a PayNow customer and checkout from the WHMCS invoice.
- Checkout metadata includes whmcs_invoice_id so the signed webhook can mark the invoice paid.
- Subscription support is experimental and only requested when the WHMCS invoice has a single recurring Hosting or Addon line and Allow Subscriptions is enabled.

Changelog
---------
1.0.0 - Initial commit
