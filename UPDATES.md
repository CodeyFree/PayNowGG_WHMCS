# PayNow.gg WHMCS Gateway - Updates & Bug Fixes

## Version 1.0.5

### 🐛 Critical Bug Fix: Currency Conversion Issue

**Problem**: When customers paid for an invoice in a different currency than their account's base currency, WHMCS incorrectly interpreted the payment amount, resulting in overpayment credits.

**Example**: 
- Invoice: £5.00 (GBP)
- Customer selects USD at checkout: $6.68
- PayNow correctly charges: $6.68
- **Bug**: WHMCS thought customer paid £6.68, created £1.68 overpayment credit
- **Fix**: Now uses invoice's original amount as authoritative value when currencies don't match

**Changes**:
- Modified `paynowgg_callback_handleOrderCompleted()` to detect currency mismatches
- When payment currency differs from invoice currency, uses the invoice's billing amount (in its own currency) to prevent overpayment
- Logs currency mismatches to gateway log for audit trail

**Impact**: Fixes overpayment issues when customers change currencies during PayNow checkout

---

### ✨ New Feature: Subscription Auto-Charging

**Feature**: PayNow subscriptions now automatically create renewal invoices in WHMCS when PayNow sends subscription renewal events.

**How it works**:
1. Customer purchases hosting/addon with subscription enabled
2. PayNow stores subscription details and tracks renewal dates
3. When renewal is due, PayNow sends `ON_SUBSCRIPTION_RENEWED` webhook
4. WHMCS automatically creates a renewal invoice with the amount PayNow charged
5. Invoice is marked "Sent" and can be auto-billed per your WHMCS settings

**Setup Requirements**:
1. Enable "Allow Subscriptions" in WHMCS PayNow.gg gateway settings ✓ (now defaults to ON)
2. Configure PayNow webhook to send: `ON_SUBSCRIPTION_RENEWED` events
3. Ensure PayNow webhook endpoint is: `https://YOUR-DOMAIN/modules/gateways/callback/paynowgg.php`

**Invoice Generation**:
- Renewal invoices are created automatically with:
  - Renewal amount from PayNow
  - Description: "Renewal: [domain/addon name]"
  - Due date: 7 days from creation
  - Status: "Sent" (ready for auto-billing)

**Currency Handling**:
- Renewal amounts use PayNow's currency
- If different from store currency, the system logs it and uses the renewal amount as-is
- WHMCS handles multi-currency conversions internally

**Enable Auto-Billing** (Optional but recommended):
- Set up auto-billing in WHMCS for subscriptions to auto-charge customer payment methods
- WHMCS → Configuration → Settings → Invoices → Auto-bill enabled invoices

---

## Configuration Checklist

### PayNow Dashboard Setup:
- [ ] Create JSON webhook endpoint pointing to: `https://YOUR-DOMAIN/modules/gateways/callback/paynowgg.php`
- [ ] Subscribe to these webhook events:
  - [ ] `ON_ORDER_COMPLETED` (required for one-time payments)
  - [ ] `ON_SUBSCRIPTION_ACTIVATED` (tracks subscriptions)
  - [ ] `ON_SUBSCRIPTION_RENEWED` (handles renewal invoices) ← NEW
  - [ ] `ON_REFUND` (optional, for audit trail)
  - [ ] `ON_CHARGEBACK` (optional, for audit trail)

### WHMCS Configuration:
1. Go to Setup → Payment Gateways → PayNow.gg
2. Ensure "Allow Subscriptions" is enabled (defaults to ON)
3. Configure auto-billing if desired:
   - Setup → Settings → Invoices
   - Check "Enable Auto-Billing" option
   - Set acceptable payment methods

### Testing Subscription Renewals:
1. Create a hosting product with "Billing Cycle" set to "Monthly" (or any recurring cycle)
2. Create an invoice with this product
3. Pay through PayNow using subscription checkout
4. In PayNow Dashboard, manually trigger a test `ON_SUBSCRIPTION_RENEWED` event OR wait for next renewal date
5. Check WHMCS - renewal invoice should be created automatically

---

## Technical Changes

### Files Modified:
- `callback/paynowgg.php`: Currency handling & subscription renewal logic
- `paynowgg.php`: Documentation update for subscription feature

### Key Functions:
- `paynowgg_callback_handleOrderCompleted()`: Added currency mismatch detection
- `paynowgg_callback_handleSubscriptionEvent()`: Added renewal event routing
- `paynowgg_callback_handleSubscriptionRenewal()`: NEW - Creates renewal invoices

### Logging:
All currency mismatches and subscription renewals are logged to WHMCS gateway log for auditing:
- View: Setup → Logs → Module Log → PayNow.gg

---

## Troubleshooting

### Subscription Renewals Not Creating Invoices:
1. Check webhook configuration in PayNow dashboard
2. Verify webhook is sending `ON_SUBSCRIPTION_RENEWED` events
3. Check WHMCS gateway log: Setup → Logs → Module Log → PayNow.gg
4. Ensure subscription was properly linked (look for "subscription_id" in hosting record)

### Currency Mismatch Still Showing Overpayment:
1. Clear WHMCS cache: Go to Utilities → System Cleanup
2. Refresh payment gateway settings
3. Check gateway log for "currency mismatch" entries
4. If issue persists, contact PayNow support to verify exchange rates used

### Renewal Invoices Have Wrong Amount:
1. Check PayNow dashboard - verify renewal charge amount
2. Check WHMCS gateway log for any currency conversion notices
3. If WHMCS currency differs from PayNow currency, system logs the difference

---

## Rollback (if needed)
If you need to revert to version 1.0.4:
1. Replace `callback/paynowgg.php` and `paynowgg.php` with backup copies
2. Disable subscription events in PayNow webhook configuration
3. Restart WHMCS cache

---

## Support & Reporting Issues

When reporting issues, please include:
1. WHMCS version
2. PHP version
3. PayNow.gg module version (shown in gateway settings)
4. Relevant entries from: Setup → Logs → Module Log → PayNow.gg
5. Invoice details (ID, amount, currency)
6. PayNow order/subscription ID (from receipt or PayNow dashboard)
