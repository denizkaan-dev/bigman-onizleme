<?php
/**
 * Career and contact form handler for the BIGMAN Group sites.
 *
 * Static hosting cannot run the site's own code, but cPanel gives us PHP, so
 * the forms post here. Copy this folder to public_html/api/ on each domain and
 * set the recipient in config.php (which is never committed).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// Only accept posts from our own sites.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, $config['allowed_origins'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden_origin']);
    exit;
}

// Honeypot: a real applicant never fills this in.
if (trim((string) ($_POST['company'] ?? '')) !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

// One application per IP per minute.
$throttleFile = sys_get_temp_dir() . '/bigman_' . md5($_SERVER['REMOTE_ADDR'] ?? 'cli');
if (file_exists($throttleFile) && (time() - filemtime($throttleFile)) < 60) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'too_many_requests']);
    exit;
}
touch($throttleFile);

$field = static fn (string $key, int $max = 2000): string =>
    mb_substr(trim(strip_tags((string) ($_POST[$key] ?? ''))), 0, $max);

$name     = $field('name', 120);
$email    = $field('email', 160);
$phone    = $field('phone', 40);
$position = $field('position', 160);
$message  = $field('message', 4000);
$brand    = $field('brand', 40);
$lang     = $field('lang', 5) === 'en' ? 'en' : 'tr';
$consent  = isset($_POST['kvkk']);

if ($name === '' || $phone === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$consent) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_input']);
    exit;
}

// --- CV upload --------------------------------------------------------------
$attachment = null;
if (isset($_FILES['cv']) && ($_FILES['cv']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $tmp  = $_FILES['cv']['tmp_name'];
    $size = (int) $_FILES['cv']['size'];

    if ($size > $config['max_upload_bytes']) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'file_too_large']);
        exit;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if ($mime !== 'application/pdf') {
        http_response_code(415);
        echo json_encode(['ok' => false, 'error' => 'pdf_only']);
        exit;
    }

    $inbox = __DIR__ . '/inbox';
    if (!is_dir($inbox)) {
        mkdir($inbox, 0700, true);
    }
    $stored = sprintf('%s/%s-%s.pdf', $inbox, date('Ymd-His'), bin2hex(random_bytes(4)));
    if (move_uploaded_file($tmp, $stored)) {
        chmod($stored, 0600);
        $attachment = $stored;
    }
}

// --- Mail -------------------------------------------------------------------
$subject = sprintf('[%s] %s — %s', strtoupper($brand ?: 'bigman'), $position ?: 'Başvuru', $name);

$lines = [
    "Marka / Brand : {$brand}",
    "Ad / Name     : {$name}",
    "E-posta       : {$email}",
    "Telefon       : {$phone}",
    "Pozisyon      : {$position}",
    "Dil / Lang    : {$lang}",
    "IP            : " . ($_SERVER['REMOTE_ADDR'] ?? '-'),
    "Tarih         : " . date('c'),
    '',
    $message,
];
if ($attachment !== null) {
    $lines[] = '';
    $lines[] = 'CV: ' . basename($attachment) . ' (sunucuda api/inbox/ klasöründe)';
}
$body = implode("\n", $lines);

$boundary = bin2hex(random_bytes(16));
$headers  = implode("\r\n", [
    'From: ' . $config['from'],
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    $attachment !== null
        ? "Content-Type: multipart/mixed; boundary=\"{$boundary}\""
        : 'Content-Type: text/plain; charset=utf-8',
]);

if ($attachment !== null) {
    $payload = "--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n\r\n"
        . $body . "\r\n\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: application/pdf; name=\"" . basename($attachment) . "\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "Content-Disposition: attachment; filename=\"" . basename($attachment) . "\"\r\n\r\n"
        . chunk_split(base64_encode((string) file_get_contents($attachment))) . "\r\n"
        . "--{$boundary}--";
} else {
    $payload = $body;
}

$sent = mail($config['to'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $payload, $headers);

if (!$sent) {
    // Never lose an application because the mail server refused it.
    $fallback = __DIR__ . '/inbox/applications.log';
    @file_put_contents($fallback, $body . "\n\n----\n\n", FILE_APPEND | LOCK_EX);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'mail_failed']);
    exit;
}

echo json_encode(['ok' => true]);
