<?php
/**
 * PayNow.gg callback/webhook endpoint for WHMCS.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(400);
    die('Module Not Activated');
}

$rawPayload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYNOW_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_PAYNOW_TIMESTAMP'] ?? '';
$secret = (string) ($gatewayParams['webhookSigningSecret'] ?? '');
$debugMode = !empty($gatewayParams['debugMode']);

if ($debugMode) {
    logModuleCall('PayNow.gg', 'incoming webhook', array(
        'signature' => $signature,
        'timestamp' => $timestamp,
        'payload' => $rawPayload,
    ), null, null, array($secret));
}

if (!paynowgg_callback_validateSignature($rawPayload, $timestamp, $signature, $secret)) {
    logTransaction($gatewayParams['name'], $rawPayload, 'PayNow Hash Verification Failure');
    http_response_code(401);
    echo 'Invalid PayNow signature';
    exit;
}

$event = json_decode($rawPayload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

$eventType = (string) ($event['event_type'] ?? '');
$body = is_array($event['body'] ?? null) ? $event['body'] : array();
logTransaction($gatewayParams['name'], $rawPayload, $eventType ?: 'PayNow Webhook');

try {
    switch ($eventType) {
        case 'ON_ORDER_COMPLETED':
            paynowgg_callback_handleOrderCompleted($gatewayModuleName, $gatewayParams, $body);
            http_response_code(200);
            echo 'OK';
            break;

        case 'ON_SUBSCRIPTION_ACTIVATED':
        case 'ON_SUBSCRIPTION_RENEWED':
            paynowgg_callback_handleSubscriptionEvent($gatewayParams, $body);
            http_response_code(200);
            echo 'OK';
            break;

        case 'ON_REFUND':
        case 'ON_CHARGEBACK':
            // WHMCS does not require an automatic reversal here. Logging the signed event keeps an audit trail.
            http_response_code(200);
            echo 'Logged';
            break;

        default:
            // Return 200 for unhandled but valid signed events so PayNow does not retry indefinitely.
            http_response_code(200);
            echo 'Ignored';
            break;
    }
} catch (Exception $e) {
    logModuleCall('PayNow.gg', 'webhook processing error', $event, array('error' => $e->getMessage()), array('error' => $e->getMessage()), array($secret));
    http_response_code(500);
    echo 'Webhook processing failed';
}

function paynowgg_callback_validateSignature($rawPayload, $timestamp, $providedSignature, $secret)
{
    if ($rawPayload === '' || $timestamp === '' || $providedSignature === '' || $secret === '') {
        return false;
    }

    if (!ctype_digit((string) $timestamp)) {
        return false;
    }

    // PayNow timestamps are Unix milliseconds. Reject payloads older/newer than 5 minutes.
    $nowMs = (int) round(microtime(true) * 1000);
    $timestampMs = (int) $timestamp;
    if (abs($nowMs - $timestampMs) > 5 * 60 * 1000) {
        return false;
    }

    $expected = base64_encode(hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret, true));

    return hash_equals($expected, $providedSignature);
}

function paynowgg_callback_handleOrderCompleted($gatewayModuleName, array $gatewayParams, array $body)
{
    $metadata = array();
    if (isset($body['checkout']['metadata']) && is_array($body['checkout']['metadata'])) {
        $metadata = $body['checkout']['metadata'];
    }

    $invoiceId = $metadata['whmcs_invoice_id'] ?? null;
    if (!$invoiceId && isset($body['checkout']['lines'][0]['metadata']['whmcs_invoice_id'])) {
        $invoiceId = $body['checkout']['lines'][0]['metadata']['whmcs_invoice_id'];
    }

    if (!$invoiceId) {
        throw new Exception('Missing whmcs_invoice_id metadata on PayNow order.');
    }

    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

    $transactionId = (string) ($body['id'] ?? $body['order_id'] ?? $body['checkout_id'] ?? '');
    if ($transactionId === '') {
        throw new Exception('Missing PayNow order/transaction id.');
    }

    checkCbTransID($transactionId);

    $paymentCurrency = (string) ($body['currency'] ?? '');
    $totalMinor = isset($body['total_amount']) ? (int) $body['total_amount'] : null;
    $feeMinor = (int) ($body['gateway_fee_amount'] ?? 0) + (int) ($body['platform_fee_amount'] ?? 0);

    $paymentAmount = $totalMinor !== null ? paynowgg_callback_fromMinorUnits($totalMinor, $paymentCurrency) : 0.0;
    $paymentFee = $feeMinor > 0 ? paynowgg_callback_fromMinorUnits($feeMinor, $paymentCurrency) : 0.0;

    // Fetch invoice to get WHMCS invoice currency and amount
    $invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
    $invoiceCurrency = isset($invoice['currency']) ? (string) $invoice['currency'] : '';
    $invoiceTotal = isset($invoice['total']) ? (float) $invoice['total'] : 0.0;

    // Handle currency mismatch: if PayNow payment currency differs from invoice currency,
    // use the invoice amount as the authoritative value to prevent overpayment issues
    // when customers change currencies during checkout
    if ($paymentCurrency !== '' && $invoiceCurrency !== '' && strtoupper($paymentCurrency) !== strtoupper($invoiceCurrency)) {
        logModuleCall('PayNow.gg', 'currency mismatch detected', array(
            'invoice_id' => $invoiceId,
            'payment_currency' => $paymentCurrency,
            'payment_amount' => $paymentAmount,
            'invoice_currency' => $invoiceCurrency,
            'invoice_total' => $invoiceTotal,
        ), array('status' => 'using invoice total'));

        // Use the invoice amount in its own currency to prevent overpayment credits
        // when customer pays in different currency at different exchange rate
        $paymentAmount = $invoiceTotal;
        $paymentFee = 0.0; // Don't charge fees when converting currencies
    }

    addInvoicePayment($invoiceId, $transactionId, $paymentAmount, $paymentFee, $gatewayModuleName);
}

function paynowgg_callback_handleSubscriptionEvent(array $gatewayParams, array $body)
{
    $subscriptionId = (string) ($body['id'] ?? $body['subscription_id'] ?? '');
    if ($subscriptionId === '') {
        return;
    }

    $metadata = array();
    if (isset($body['order']['checkout']['lines'][0]['metadata']) && is_array($body['order']['checkout']['lines'][0]['metadata'])) {
        $metadata = $body['order']['checkout']['lines'][0]['metadata'];
    } elseif (isset($body['checkout']['lines'][0]['metadata']) && is_array($body['checkout']['lines'][0]['metadata'])) {
        $metadata = $body['checkout']['lines'][0]['metadata'];
    }

    $relid = $metadata['whmcs_subscription_relid'] ?? null;
    $type = $metadata['whmcs_subscription_type'] ?? null;

    if (!$relid || !$type) {
        return;
    }

    if ($type === 'hosting') {
        \WHMCS\Database\Capsule::table('tblhosting')->where('id', '=', (int) $relid)->update(array('subscriptionid' => $subscriptionId));
    } elseif ($type === 'addon') {
        \WHMCS\Database\Capsule::table('tblhostingaddons')->where('id', '=', (int) $relid)->update(array('subscriptionid' => $subscriptionId));
    }

    // Handle subscription renewals - create invoices for recurring charges
    $eventType = (string) ($body['event_type'] ?? '');
    if ($eventType === 'ON_SUBSCRIPTION_RENEWED') {
        paynowgg_callback_handleSubscriptionRenewal($gatewayParams, $body, $relid, $type);
    }
}

function paynowgg_callback_handleSubscriptionRenewal(array $gatewayParams, array $body, $relid, $type)
{
    try {
        if ($type === 'hosting') {
            $resource = \WHMCS\Database\Capsule::table('tblhosting')->where('id', '=', (int) $relid)->first();
        } else {
            $resource = \WHMCS\Database\Capsule::table('tblhostingaddons')->where('id', '=', (int) $relid)->first();
        }

        if (!$resource) {
            logModuleCall('PayNow.gg', 'subscription renewal: resource not found', array(
                'type' => $type,
                'relid' => $relid,
            ), array('status' => 'failed'));
            return;
        }

        $userId = (int) $resource->userid;
        $renewalAmount = null;
        $renewalCurrency = 'USD';

        // Extract renewal amount from PayNow webhook
        // The webhook body should contain the order details with the renewal charge
        if (isset($body['order']) && is_array($body['order'])) {
            $order = $body['order'];
            if (isset($order['total_amount'])) {
                $renewalAmount = paynowgg_callback_fromMinorUnits((int) $order['total_amount'], (string) ($order['currency'] ?? 'USD'));
                $renewalCurrency = (string) ($order['currency'] ?? 'USD');
            }
        }

        // If we couldn't get amount from order, try to get it from the subscription or event body
        if ($renewalAmount === null && isset($body['amount'])) {
            $renewalAmount = paynowgg_callback_fromMinorUnits((int) $body['amount'], (string) ($body['currency'] ?? 'USD'));
            $renewalCurrency = (string) ($body['currency'] ?? 'USD');
        }

        // If we still don't have a renewal amount, we can't proceed
        if ($renewalAmount === null || $renewalAmount <= 0) {
            logModuleCall('PayNow.gg', 'subscription renewal: unable to extract amount', array(
                'type' => $type,
                'relid' => $relid,
                'body' => $body,
            ), array('status' => 'skipped'));
            return;
        }

        // Get the user's store currency
        $userDetails = localAPI('GetClientsDetails', array('userid' => $userId, 'stats' => false));
        $storeCurrency = isset($userDetails['currency']) ? (string) $userDetails['currency'] : 'USD';

        // Adjust amount if currency differs (log the difference for manual review if needed)
        $adjustedAmount = $renewalAmount;
        if ($storeCurrency && $renewalCurrency && strtoupper($renewalCurrency) !== strtoupper($storeCurrency)) {
            logModuleCall('PayNow.gg', 'subscription renewal: currency conversion', array(
                'type' => $type,
                'relid' => $relid,
                'renewal_amount' => $renewalAmount,
                'renewal_currency' => $renewalCurrency,
                'store_currency' => $storeCurrency,
            ), array('status' => 'currency mismatch - using renewal amount as-is'));
            // Note: WHMCS will handle currency conversion based on store settings
        }

        // Create invoice for renewal
        $invoiceParams = array(
            'userid' => $userId,
            'date' => date('Y-m-d'),
            'duedate' => date('Y-m-d', strtotime('+7 days')),
            'itemdescription' => '',
            'itemamount' => '',
            'itemtaxed' => '',
            'notes' => 'PayNow Subscription Renewal (ID: ' . (string) ($body['id'] ?? 'unknown') . ')',
        );

        $invoiceResult = localAPI('CreateInvoice', $invoiceParams);
        if (!isset($invoiceResult['invoiceid'])) {
            throw new Exception('Failed to create renewal invoice');
        }

        $invoiceId = (int) $invoiceResult['invoiceid'];

        // Add the renewal line item to the invoice
        $description = '';
        if ($type === 'hosting' && isset($resource->domain)) {
            $description = 'Renewal: ' . (string) $resource->domain;
        } elseif ($type === 'addon') {
            // Try to get addon name from metadata or database
            $addonName = isset($resource->addonid) ? $resource->addonid : 'Addon';
            try {
                $addonData = \WHMCS\Database\Capsule::table('tbladdons')->where('id', '=', (int) $resource->addonid)->first();
                if ($addonData) {
                    $addonName = (string) $addonData->name;
                }
            } catch (Exception $e) {
                // Use default addon name
            }
            $description = 'Renewal: ' . $addonName;
        }

        $lineParams = array(
            'invoiceid' => $invoiceId,
            'description' => $description ?: 'Subscription Renewal',
            'amount' => $adjustedAmount,
            'taxed' => 0,
        );

        localAPI('InvoiceAddItem', $lineParams);

        // Mark invoice as sent to trigger payment processing
        localAPI('UpdateInvoice', array(
            'invoiceid' => $invoiceId,
            'status' => 'Sent',
        ));

        logModuleCall('PayNow.gg', 'subscription renewal: invoice created', array(
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'type' => $type,
            'relid' => $relid,
            'renewal_amount' => $renewalAmount,
            'renewal_currency' => $renewalCurrency,
        ), array('status' => 'success'));
    } catch (Exception $e) {
        logModuleCall('PayNow.gg', 'subscription renewal: error', array(
            'type' => $type,
            'relid' => $relid,
            'event_id' => $body['id'] ?? 'unknown',
        ), array('error' => $e->getMessage()), array('error' => $e->getMessage()));
    }
}

function paynowgg_callback_fromMinorUnits($amount, $currencyCode)
{
    $zeroDecimalCurrencies = array(
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
    );

    $divisor = in_array(strtoupper($currencyCode), $zeroDecimalCurrencies, true) ? 1 : 100;
    return round(((int) $amount) / $divisor, 2);
}
