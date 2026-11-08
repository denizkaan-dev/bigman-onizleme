<?php
/**
 * Checkout: validates the order, prices it from catalog.php (never from the
 * browser), stores it, and asks PayTR for a payment page token.
 *
 * POST application/json from the checkout page. Answers JSON:
 *   { ok: true, order, token }  or  { ok: false, error, fields? }
 */

declare(strict_types=1);

require __DIR__ . '/shop-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$config  = shop_config();
$catalog = shop_catalog();

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, $config['allowed_origins'], true)) {
    json_out(403, ['ok' => false, 'error' => 'forbidden_origin']);
}

// At most five orders per address per ten minutes.
$ip = client_ip();
$bucket = sys_get_temp_dir() . '/shop_order_' . md5($ip);
$recent = array_values(array_filter(
    array_map('intval', explode(',', (string) @file_get_contents($bucket))),
    static fn (int $t): bool => $t > time() - 600,
));
if (count($recent) >= 5) {
    json_out(429, ['ok' => false, 'error' => 'too_many_requests']);
}
$recent[] = time();
@file_put_contents($bucket, implode(',', $recent), LOCK_EX);

$in = json_decode((string) file_get_contents('php://input', false, null, 0, 32768), true);
if (!is_array($in)) {
    json_out(400, ['ok' => false, 'error' => 'invalid_input']);
}
// Honeypot: a person never fills this in.
if (trim((string) ($in['website'] ?? '')) !== '') {
    json_out(422, ['ok' => false, 'error' => 'invalid_input']);
}
if (!$catalog['open']) {
    json_out(503, ['ok' => false, 'error' => 'shop_closed']);
}

$text = static function (mixed $value, int $max): string {
    $v = is_string($value) ? $value : '';
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($v)) ?? '';
    return mb_substr(trim($v), 0, $max);
};

$lang     = ($in['lang'] ?? '') === 'en' ? 'en' : 'tr';
$contact  = is_array($in['contact'] ?? null) ? $in['contact'] : [];
$delivery = is_array($in['delivery'] ?? null) ? $in['delivery'] : [];
$invoice  = is_array($in['invoice'] ?? null) ? $in['invoice'] : [];

$c = [
    'name'  => $text($contact['name'] ?? '', 60),
    'email' => $text($contact['email'] ?? '', 100),
    'phone' => $text($contact['phone'] ?? '', 20),
];
$d = [
    'city'     => $text($delivery['city'] ?? '', 40),
    'district' => $text($delivery['district'] ?? '', 40),
    'address'  => $text($delivery['address'] ?? '', 300),
    'postcode' => $text($delivery['postcode'] ?? '', 5),
];
$isCompany = ($invoice['type'] ?? '') === 'company';
$same = ($invoice['same'] ?? true) !== false;
$i = [
    'type'      => $isCompany ? 'company' : 'personal',
    'same'      => $same,
    'name'      => $text($invoice['name'] ?? '', 60),
    'company'   => $isCompany ? $text($invoice['company'] ?? '', 120) : '',
    'taxOffice' => $isCompany ? $text($invoice['taxOffice'] ?? '', 60) : '',
    'taxNumber' => $isCompany ? preg_replace('/\D/', '', $text($invoice['taxNumber'] ?? '', 11)) : '',
    'city'      => $same ? '' : $text($invoice['city'] ?? '', 40),
    'district'  => $same ? '' : $text($invoice['district'] ?? '', 40),
    'address'   => $same ? '' : $text($invoice['address'] ?? '', 300),
];
$note = $text($in['note'] ?? '', 500);

$bad = [];
if (mb_strlen($c['name']) < 3) $bad[] = 'name';
// PayTR takes only plain ASCII addresses, which is what FILTER_VALIDATE_EMAIL accepts.
if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $bad[] = 'email';
$digits = preg_replace('/\D/', '', $c['phone']);
if (strlen($digits) < 10 || strlen($digits) > 13) $bad[] = 'phone';
foreach (['city', 'district', 'address'] as $k) {
    if ($d[$k] === '') $bad[] = $k;
}
if ($d['postcode'] !== '' && !preg_match('/^\d{5}$/', $d['postcode'])) $bad[] = 'postcode';
if ($isCompany) {
    if ($i['company'] === '') $bad[] = 'company';
    if ($i['taxOffice'] === '') $bad[] = 'taxOffice';
    if (!preg_match('/^\d{10,11}$/', $i['taxNumber'])) $bad[] = 'taxNumber';
}
if (!$same) {
    if (!$isCompany && $i['name'] === '') $bad[] = 'invoiceName';
    foreach (['city' => 'invoiceCity', 'district' => 'invoiceDistrict', 'address' => 'invoiceAddress'] as $k => $field) {
        if ($i[$k] === '') $bad[] = $field;
    }
}
if (($in['accept'] ?? false) !== true) $bad[] = 'accept';
if ($bad) {
    json_out(422, ['ok' => false, 'error' => 'invalid_input', 'fields' => $bad]);
}

// --- Price the basket from our own list --------------------------------------
$items = $in['items'] ?? null;
if (!is_array($items) || $items === [] || count($items) > 30) {
    json_out(422, ['ok' => false, 'error' => 'invalid_input']);
}
$lines = [];
$unavailable = [];
$seen = [];
foreach ($items as $row) {
    $slug = is_array($row) ? (string) ($row[0] ?? '') : '';
    $qty  = is_array($row) && is_int($row[1] ?? null) ? $row[1] : 0;
    if (!preg_match('/^[a-z0-9-]{1,60}$/', $slug) || $qty < 1 || $qty > 20 || isset($seen[$slug])) {
        json_out(422, ['ok' => false, 'error' => 'invalid_input']);
    }
    $seen[$slug] = true;
    $p = $catalog['products'][$slug] ?? null;
    if ($p === null || $p['price'] === null || !$p['in_stock']) {
        $unavailable[] = $slug;
        continue;
    }
    $lines[] = [
        'slug' => $slug,
        'name' => $p['name'][$lang] ?? $p['name']['tr'],
        'size' => $p['size'] === null ? null : ($p['size'][$lang] ?? $p['size']['tr']),
        'qty'  => $qty,
        'unit' => $p['price'],
        'line' => $p['price'] * $qty,
    ];
}
if ($unavailable) {
    json_out(409, ['ok' => false, 'error' => 'unavailable', 'items' => $unavailable]);
}

$subtotal = array_sum(array_column($lines, 'line'));
$shipping = shipping_for($subtotal, $catalog['shipping']);
if ($shipping === null) {
    json_out(503, ['ok' => false, 'error' => 'shop_closed']);
}
$total = $subtotal + $shipping;
// The shopper saw a total before pressing the button; never charge a different one.
if (array_key_exists('expectedTotal', $in) && (int) $in['expectedTotal'] !== $total) {
    json_out(409, ['ok' => false, 'error' => 'price_changed', 'total' => $total]);
}

// --- Store the order -------------------------------------------------------
$paytr = $config['paytr'];
$no = 'NP' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
$order = [
    'no'        => $no,
    'status'    => 'pending',
    'created'   => date('c'),
    'lang'      => $lang,
    'ip'        => $ip,
    'contact'   => $c,
    'delivery'  => $d,
    'invoice'   => $i,
    'note'      => $note,
    'lines'     => $lines,
    'subtotal'  => $subtotal,
    'shipping'  => $shipping,
    'total'     => $total,
    'terms_accepted_at' => date('c'),
    'test_mode' => (int) ($paytr['test_mode'] ?? 0) === 1,
];
order_save($order);

// --- Ask PayTR for the payment page (iFrame API, step 1) -------------------
$basket = array_map(
    static fn (array $l): array => [
        $l['name'] . ($l['size'] ? ' ' . $l['size'] : ''),
        number_format($l['unit'] / 100, 2, '.', ''),
        $l['qty'],
    ],
    $lines,
);
if ($shipping > 0) {
    $basket[] = [$lang === 'tr' ? 'Kargo' : 'Delivery', number_format($shipping / 100, 2, '.', ''), 1];
}

$userBasket     = base64_encode((string) json_encode($basket));
$noInstallment  = (string) (int) ($paytr['no_installment'] ?? 1);
$maxInstallment = (string) (int) ($paytr['max_installment'] ?? 0);
$currency       = 'TL';
$testMode       = (string) (int) ($paytr['test_mode'] ?? 0);
$amount         = (string) $total;

$hashStr = $paytr['merchant_id'] . $ip . $no . $c['email'] . $amount . $userBasket
    . $noInstallment . $maxInstallment . $currency . $testMode;
$paytrToken = base64_encode(hash_hmac('sha256', $hashStr . $paytr['merchant_salt'], $paytr['merchant_key'], true));

$siteUrl = rtrim($config['site_url'], '/');
$orderPage = $siteUrl . ($lang === 'tr' ? '/siparis/' : '/en/order/');

$fields = [
    'merchant_id'       => $paytr['merchant_id'],
    'user_ip'           => $ip,
    'merchant_oid'      => $no,
    'email'             => $c['email'],
    'payment_amount'    => $amount,
    'paytr_token'       => $paytrToken,
    'user_basket'       => $userBasket,
    'debug_on'          => (string) (int) ($paytr['debug_on'] ?? 0),
    'no_installment'    => $noInstallment,
    'max_installment'   => $maxInstallment,
    'user_name'         => $c['name'],
    'user_address'      => mb_substr("{$d['address']}, {$d['district']} / {$d['city']}", 0, 400),
    'user_phone'        => mb_substr($c['phone'], 0, 20),
    'merchant_ok_url'   => "{$orderPage}?no={$no}&durum=tamam",
    'merchant_fail_url' => "{$orderPage}?no={$no}&durum=hata",
    'timeout_limit'     => '30',
    'currency'          => $currency,
    'test_mode'         => $testMode,
    'lang'              => $lang,
];

$ch = curl_init($paytr['endpoint'] ?? 'https://www.paytr.com/odeme/api/get-token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $fields,
    CURLOPT_FRESH_CONNECT  => true,
    CURLOPT_TIMEOUT        => 20,
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

$data = is_string($response) ? json_decode($response, true) : null;
if (!is_array($data) || ($data['status'] ?? '') !== 'success' || !is_string($data['token'] ?? null)) {
    $order['status'] = 'token_failed';
    $order['error'] = $curlError !== '' ? $curlError : (string) ($data['reason'] ?? 'no response');
    order_save($order);
    shop_log("token_failed {$no}: {$order['error']}");
    json_out(502, ['ok' => false, 'error' => 'payment_unavailable']);
}

$order['status'] = 'awaiting_payment';
order_save($order);
json_out(200, ['ok' => true, 'order' => $no, 'token' => $data['token']]);
