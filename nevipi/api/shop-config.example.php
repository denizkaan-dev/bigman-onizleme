<?php
/**
 * Copy to shop-config.php on the server and fill in. Never commit the copy:
 * it holds the PayTR keys.
 *
 * PayTR merchant panel > Destek & Kurulum > Ayarlar:
 *   Bildirim URL: https://nevipi.com/api/paytr-notify.php  (https, not http)
 */
return [
    'site_url'        => 'https://nevipi.com',
    'allowed_origins' => ['https://nevipi.com'],

    // Who gets the "new order" mail, and who it comes from (plain ASCII name).
    'notify_to' => 'siparis@nevipi.com',
    'from'      => 'NEVI pi <no-reply@nevipi.com>',

    // Order records. Best kept outside public_html, e.g.
    // dirname(__DIR__, 2) . '/nevipi-orders'
    'orders_dir' => __DIR__ . '/orders',

    // Behind Cloudflare, the shopper's address is in this header.
    'ip_header' => null, // 'HTTP_CF_CONNECTING_IP'

    'paytr' => [
        'merchant_id'     => '',
        'merchant_key'    => '',
        'merchant_salt'   => '',
        // 1 while testing with PayTR's test cards; 0 to take real payments.
        'test_mode'       => 1,
        'debug_on'        => 0,
        // 1 hides instalments; with 0, max_installment caps them (0 = PayTR's limit).
        'no_installment'  => 1,
        'max_installment' => 0,
    ],
];
