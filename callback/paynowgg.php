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

    $currency = (string) ($body['currency'] ?? '');
    $totalMinor = isset($body['total_amount']) ? (int) $body['total_amount'] : null;
    $feeMinor = (int) ($body['gateway_fee_amount'] ?? 0) + (int) ($body['platform_fee_amount'] ?? 0);

    $paymentAmount = $totalMinor !== null ? paynowgg_callback_fromMinorUnits($totalMinor, $currency) : 0.0;
    $paymentFee = $feeMinor > 0 ? paynowgg_callback_fromMinorUnits($feeMinor, $currency) : 0.0;

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
}

function paynowgg_callback_fromMinorUnits($amount, $currencyCode)
{
    $zeroDecimalCurrencies = array(
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
    );

    $divisor = in_array(strtoupper($currencyCode), $zeroDecimalCurrencies, true) ? 1 : 100;
    return round(((int) $amount) / $divisor, 2);
}
