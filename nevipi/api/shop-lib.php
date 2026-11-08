<?php
/**
 * Shared by order.php and paytr-notify.php. Copied into api/ of every site
 * that sells online by scripts/sync-deploy.mjs.
 *
 * Orders are kept as one JSON file each in a folder the web server never
 * serves. Card data never passes through here: PayTR takes the payment.
 */

declare(strict_types=1);

function shop_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/shop-config.php';
        if (!is_file($file)) {
            json_out(503, ['ok' => false, 'error' => 'shop_closed']);
        }
        $config = require $file;
    }
    return $config;
}

/** Prices, stock and delivery, generated from content/shop/<brand>.json. */
function shop_catalog(): array
{
    static $catalog = null;
    return $catalog ??= require __DIR__ . '/catalog.php';
}

function json_out(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function orders_dir(): string
{
    $dir = shop_config()['orders_dir'] ?? (__DIR__ . '/orders');
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
        // In case the folder sits inside public_html after all.
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

function valid_order_no(string $no): bool
{
    return (bool) preg_match('/^[A-Z0-9]{8,64}$/', $no);
}

function order_path(string $no): string
{
    if (!valid_order_no($no)) {
        throw new InvalidArgumentException('bad order number');
    }
    return orders_dir() . "/{$no}.json";
}

function order_save(array $order): void
{
    $path = order_path($order['no']);
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    chmod($tmp, 0600);
    rename($tmp, $path);
}

function order_load(string $no): ?array
{
    if (!valid_order_no($no)) {
        return null;
    }
    $path = order_path($no);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/** Runs $fn holding an exclusive lock on the order, so a repeated notification waits its turn. */
function with_order_lock(string $no, callable $fn): mixed
{
    $lock = fopen(order_path($no) . '.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function shipping_for(int $subtotal, array $shipping): ?int
{
    if ($subtotal <= 0) {
        return 0;
    }
    if ($shipping['free_over'] !== null && $subtotal >= $shipping['free_over']) {
        return 0;
    }
    return $shipping['fee'];
}

/** 123456 kuruş -> "1.234,56 TL" */
function tl(int $kurus): string
{
    return number_format($kurus / 100, 2, ',', '.') . ' TL';
}

function client_ip(): string
{
    $header = shop_config()['ip_header'] ?? null;
    $ip = $header ? ($_SERVER[$header] ?? '') : '';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    return mb_substr($ip, 0, 39);
}

function send_mail(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    $headers = [
        'From: ' . shop_config()['from'],
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}

function shop_log(string $line): void
{
    @file_put_contents(orders_dir() . '/shop.log', date('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}
