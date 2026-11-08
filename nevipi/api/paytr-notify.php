<?php
/**
 * PayTR's server-to-server payment notification (iFrame API, step 2).
 * Set this as the "Bildirim URL" in the PayTR merchant panel:
 *   https://<domain>/api/paytr-notify.php
 *
 * PayTR may call it more than once for the same payment; only the first call
 * changes anything. It must answer with the two letters OK and nothing else.
 */

declare(strict_types=1);

require __DIR__ . '/shop-lib.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$paytr  = shop_config()['paytr'];
$no     = (string) ($_POST['merchant_oid'] ?? '');
$status = (string) ($_POST['status'] ?? '');
$total  = (string) ($_POST['total_amount'] ?? '');
$hash   = (string) ($_POST['hash'] ?? '');

$expected = base64_encode(hash_hmac('sha256', $no . $paytr['merchant_salt'] . $status . $total, $paytr['merchant_key'], true));
if (!hash_equals($expected, $hash)) {
    shop_log('bad hash for ' . preg_replace('/[^A-Z0-9]/', '', $no));
    http_response_code(400);
    exit('PAYTR notification failed: bad hash');
}

if (order_load($no) === null) {
    // Signed by PayTR but not ours to settle: record it and stop the retries.
    shop_log("notification for unknown order {$no}");
    exit('OK');
}

with_order_lock($no, static function () use ($no, $status, $total): void {
    $order = order_load($no);
    if (in_array($order['status'], ['paid', 'failed', 'amount_mismatch'], true)) {
        return; // a repeat of a notification already handled
    }

    if ($status !== 'success') {
        $order['status'] = 'failed';
        $order['failed_at'] = date('c');
        $order['failed_reason'] = trim(($_POST['failed_reason_code'] ?? '') . ' ' . ($_POST['failed_reason_msg'] ?? ''));
        order_save($order);
        return;
    }

    // Instalments can add to the amount; less than the order total never settles it.
    if ((int) $total < (int) $order['total']) {
        $order['status'] = 'amount_mismatch';
        $order['charged'] = (int) $total;
        order_save($order);
        shop_log("amount mismatch {$no}: charged {$total}, order {$order['total']}");
        send_mail(shop_config()['notify_to'], "[NEVİ pi] Tutar uyuşmazlığı {$no}", "Sipariş {$no} için PayTR {$total} kuruş bildirdi, sipariş tutarı {$order['total']} kuruş. Merchant panelinden kontrol edin.");
        return;
    }

    $order['status'] = 'paid';
    $order['paid_at'] = date('c');
    $order['charged'] = (int) $total;
    $order['payment_type'] = (string) ($_POST['payment_type'] ?? '');
    $order['test_mode'] = ($_POST['test_mode'] ?? '0') === '1';
    order_save($order);

    notify_paid($order);
});

echo 'OK';

/** One mail to the shop with everything needed to pack and invoice, one to the customer. */
function notify_paid(array $order): void
{
    $catalog = shop_catalog();
    $config = shop_config();
    $tr = $order['lang'] === 'tr';
    $site = rtrim($config['site_url'], '/');
    $seller = $catalog['seller'];
    $test = $order['test_mode'] ? ' (TEST)' : '';

    $lines = array_map(
        static fn (array $l): string => sprintf('- %s%s × %d — %s', $l['name'], $l['size'] ? " {$l['size']}" : '', $l['qty'], tl($l['line'])),
        $order['lines'],
    );
    $d = $order['delivery'];
    $address = "{$d['address']}, {$d['district']} / {$d['city']}" . ($d['postcode'] !== '' ? " {$d['postcode']}" : '');
    $sums = [
        ($tr ? 'Ara toplam: ' : 'Subtotal: ') . tl($order['subtotal']),
        ($tr ? 'Kargo: ' : 'Delivery: ') . ($order['shipping'] === 0 ? ($tr ? 'Ücretsiz' : 'Free') : tl($order['shipping'])),
        ($tr ? 'Toplam (KDV dahil): ' : 'Total (VAT included): ') . tl($order['total']),
    ];

    // --- The shop ---
    $i = $order['invoice'];
    $invoice = $i['type'] === 'company'
        ? "Kurumsal: {$i['company']}, {$i['taxOffice']} V.D. {$i['taxNumber']}"
        : 'Bireysel' . ($i['name'] !== '' ? ": {$i['name']}" : '');
    $invoice .= $i['same'] ? ' (teslimat adresine)' : "\nFatura adresi: {$i['address']}, {$i['district']} / {$i['city']}";
    $shop = implode("\n", [
        "Yeni sipariş {$order['no']}{$test}",
        '',
        ...$lines,
        '',
        ...$sums,
        'PayTR tahsilat: ' . tl($order['charged']) . " ({$order['payment_type']})",
        '',
        "Müşteri: {$order['contact']['name']}",
        "E-posta: {$order['contact']['email']}",
        "Telefon: {$order['contact']['phone']}",
        "Teslimat: {$address}",
        "Fatura: {$invoice}",
        $order['note'] !== '' ? "Not: {$order['note']}" : 'Not: -',
        '',
        "Sipariş zamanı: {$order['created']}, ödeme: {$order['paid_at']}, dil: {$order['lang']}, IP: {$order['ip']}",
    ]);
    send_mail($config['notify_to'], "[NEVİ pi] Yeni sipariş {$order['no']} — " . tl($order['total']) . $test, $shop, $order['contact']['email']);

    // --- The customer: the order and the terms they accepted, on a durable medium ---
    $paths = $tr
        ? ['/on-bilgilendirme-formu/', '/mesafeli-satis-sozlesmesi/', '/iade-ve-cayma/']
        : ['/en/pre-contract-information/', '/en/distance-sales-agreement/', '/en/returns/'];
    $days = $catalog['shipping']['dispatch_days'];
    $sellerLine = implode(', ', array_filter([$seller['legalName'], $seller['address'], $seller['phone'], $seller['email']]));
    $customer = implode("\n", $tr ? [
        "Merhaba {$order['contact']['name']},",
        '',
        "{$order['no']} numaralı siparişinizin ödemesi alındı. Teşekkür ederiz.",
        '',
        ...$lines,
        '',
        ...$sums,
        '',
        "Teslimat adresi: {$address}",
        $days ? "Siparişiniz {$days} iş günü içinde kargoya verilir; takip numarasını ayrıca göndereceğiz." : 'Kargoya verildiğinde takip numarasını ayrıca göndereceğiz.',
        '',
        'Siparişiniz sırasında onayladığınız metinler:',
        "Ön bilgilendirme formu: {$site}{$paths[0]}",
        "Mesafeli satış sözleşmesi: {$site}{$paths[1]}",
        "İade ve cayma hakkı: {$site}{$paths[2]}",
        '',
        "Satıcı: {$sellerLine}",
    ] : [
        "Hello {$order['contact']['name']},",
        '',
        "Payment for order {$order['no']} has been received. Thank you.",
        '',
        ...$lines,
        '',
        ...$sums,
        '',
        "Delivery address: {$address}",
        $days ? "Your order leaves us within {$days} working days; we will send the tracking number separately." : 'We will send the tracking number when it ships.',
        '',
        'The terms you accepted with this order:',
        "Pre-contract information: {$site}{$paths[0]}",
        "Distance sales agreement: {$site}{$paths[1]}",
        "Returns and withdrawal: {$site}{$paths[2]}",
        '',
        "Seller: {$sellerLine}",
    ]);
    $subject = $tr ? "NEVİ pi siparişiniz {$order['no']}" : "Your NEVİ pi order {$order['no']}";
    send_mail($order['contact']['email'], $subject . $test, $customer, $seller['email'] ?: null);
}
