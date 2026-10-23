<?php
/**
 * Обработчик формы заявки.
 * Отправляет заявку на почту и (по желанию) в Telegram.
 * Требуется PHP 7.4+ и функция mail() на хостинге (есть на большинстве тарифов).
 *
 * Секреты (токен Telegram и т. п.) не храните в этом файле: репозиторий публичный.
 * Положите их в config.local.php рядом с этим файлом (он в .gitignore и закрыт .htaccess)
 * или в переменные окружения хостинга. Пример — в config.local.example.php.
 */

// ===== Настройки по умолчанию =====
$config = [
    'to_email'        => 'energostroy2026@mail.ru', // куда приходят заявки
    'from_email'      => 'no-reply@example.ru',     // адрес на домене сайта, например no-reply@ваш-домен.ru
    'site_name'       => 'ЭнергоСтройРесурс',
    'telegram_token'  => getenv('ESR_TELEGRAM_TOKEN') ?: '',
    'telegram_chat'   => getenv('ESR_TELEGRAM_CHAT') ?: '',
    // Домены, с которых разрешено отправлять форму. Пусто — только текущий домен сайта.
    'allowed_hosts'   => [],
    // Антиспам
    'per_ip_limit'    => 5,    // заявок с одного IP...
    'per_ip_window'   => 600,  // ...за 10 минут
    'global_limit'    => 60,   // всего заявок...
    'global_window'   => 3600, // ...за час
    'min_fill_secs'   => 3,    // быстрее человек форму не заполнит
    'max_form_age'    => 86400,
    'max_body_bytes'  => 20000,
];
if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) {
        $config = array_merge($config, $local);
    }
}
// =====================

ini_set('display_errors', '0');
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

function respond(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_line(string $value, int $max): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_substr($value, 0, $max, 'UTF-8');
}

function clean_text(string $value, int $max): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $value) ?? '';
    return mb_substr(trim($value), 0, $max, 'UTF-8');
}

function post_string(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}

/**
 * Скользящее окно ограничения частоты. Состояние хранится вне каталога сайта,
 * IP-адреса не сохраняются в открытом виде (только хэш).
 */
function rate_limited(string $bucket, int $limit, int $window): bool
{
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/esr-ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false; // не удалось создать хранилище — не блокируем людей
    }
    $file = $dir . '/' . hash('sha256', $bucket) . '.json';
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return false;
    }
    $now = time();
    $limited = false;
    if (flock($fh, LOCK_EX)) {
        $raw = stream_get_contents($fh);
        $hits = json_decode($raw ?: '[]', true);
        $hits = is_array($hits) ? array_values(array_filter($hits, function ($t) use ($now, $window) {
            return is_int($t) && $t > $now - $window;
        })) : [];
        if (count($hits) >= $limit) {
            $limited = true;
        } else {
            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            fflush($fh);
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $limited;
}

// 1. Только POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// 2. Размер и тип тела запроса
$length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length <= 0 || $length > (int) $config['max_body_bytes']) {
    respond(413, ['ok' => false, 'error' => 'payload_too_large']);
}
$type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($type, 'multipart/form-data') !== 0 && strpos($type, 'application/x-www-form-urlencoded') !== 0) {
    respond(415, ['ok' => false, 'error' => 'unsupported_media_type']);
}
if (!empty($_FILES)) {
    respond(400, ['ok' => false, 'error' => 'files_not_allowed']);
}

// 3. Запрос должен прийти со страницы этого сайта (защита от отправки с чужих сайтов)
$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$allowed = array_map('strtolower', array_filter(array_merge([$host], (array) $config['allowed_hosts'])));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$source = $origin !== '' ? $origin : $referer;
$sourceHost = strtolower((string) parse_url($source, PHP_URL_HOST));
if ($sourceHost === '' || !in_array($sourceHost, $allowed, true)) {
    respond(403, ['ok' => false, 'error' => 'forbidden_origin']);
}
$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    respond(403, ['ok' => false, 'error' => 'forbidden_origin']);
}

// 4. Поле-ловушка: люди его не видят, боты заполняют. Отвечаем «успехом», чтобы бот не подбирал обход.
if (post_string('website') !== '') {
    respond(200, ['ok' => true]);
}

// 5. Время заполнения формы
$ts = post_string('ts');
$elapsed = ctype_digit($ts) ? time() - (int) floor(((int) $ts) / 1000) : -1;
if ($elapsed < (int) $config['min_fill_secs'] || $elapsed > (int) $config['max_form_age']) {
    respond(200, ['ok' => true]); // похоже на бота — молча не отправляем
}

// 6. Ограничение частоты: с одного IP и в целом по сайту
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (rate_limited('ip:' . $ip, (int) $config['per_ip_limit'], (int) $config['per_ip_window'])
    || rate_limited('global', (int) $config['global_limit'], (int) $config['global_window'])) {
    header('Retry-After: 600');
    respond(429, ['ok' => false, 'error' => 'too_many_requests']);
}

// 7. Поля формы
$name    = clean_line(post_string('name'), 100);
$phone   = clean_line(post_string('phone'), 30);
$company = clean_line(post_string('company'), 150);
$message = clean_text(post_string('message'), 3000);
$consent = post_string('consent') === '1';

$allowedServices = [
    'Светотехника', 'Кабель и провод', 'Электрощитовое оборудование', 'Электромонтаж',
    'Котельные', 'Вентиляция и кондиционирование', 'Кровля',
];
$services = [];
if (isset($_POST['services']) && is_array($_POST['services'])) {
    foreach ($_POST['services'] as $service) {
        if (is_string($service) && in_array($service, $allowedServices, true) && !in_array($service, $services, true)) {
            $services[] = $service;
        }
    }
}

$digits = preg_replace('/\D+/', '', $phone);
if (mb_strlen($name, 'UTF-8') < 2 || strlen($digits) !== 11 || !$consent) {
    respond(422, ['ok' => false, 'error' => 'validation']);
}

$lines = [
    'Новая заявка с сайта ' . $config['site_name'],
    '',
    'Имя: ' . $name,
    'Телефон: ' . $phone,
    'Компания / объект: ' . ($company !== '' ? $company : '—'),
    'Направления: ' . ($services ? implode(', ', $services) : '—'),
    '',
    'Задача:',
    $message !== '' ? $message : '—',
    '',
    'Дата: ' . date('d.m.Y H:i'),
];
$text = implode("\n", $lines);

$sent = false;

// Почта. Адреса берутся только из настроек, никогда из формы.
$to = filter_var($config['to_email'], FILTER_VALIDATE_EMAIL);
$from = filter_var($config['from_email'], FILTER_VALIDATE_EMAIL);
if ($to && $from) {
    $subject = '=?UTF-8?B?' . base64_encode('Заявка с сайта: ' . $name) . '?=';
    $headers = implode("\r\n", [
        'From: =?UTF-8?B?' . base64_encode($config['site_name']) . '?= <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: ESR-form',
    ]);
    $sent = @mail($to, $subject, chunk_split(base64_encode($text)), $headers, '-f' . $from) || $sent;
}

// Telegram
$token = (string) $config['telegram_token'];
$chat = (string) $config['telegram_chat'];
if ($token !== '' && $chat !== '' && preg_match('/^\d+:[A-Za-z0-9_-]{30,}$/', $token)) {
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => http_build_query(['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true']),
            'timeout'       => 8,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $result = @file_get_contents('https://api.telegram.org/bot' . $token . '/sendMessage', false, $context);
    $decoded = is_string($result) ? json_decode($result, true) : null;
    $sent = (is_array($decoded) && !empty($decoded['ok'])) || $sent;
}

if (!$sent) {
    respond(500, ['ok' => false, 'error' => 'send_failed']);
}

respond(200, ['ok' => true]);
