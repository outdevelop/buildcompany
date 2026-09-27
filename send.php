<?php
/**
 * Обработчик формы заявки.
 * Отправляет заявку на почту и (по желанию) в Telegram.
 * Требуется PHP 7.4+ и функция mail() на хостинге (есть на большинстве тарифов).
 */

// ===== Настройки =====
$TO_EMAIL   = 'energostroy2026@mail.ru'; // куда приходят заявки
$FROM_EMAIL = 'no-reply@example.ru';     // замените на адрес на домене сайта, например no-reply@ваш-домен.ru
$SITE_NAME  = 'ЭнергоСтройРесурс';

// Telegram (необязательно): токен бота от @BotFather и ID чата
$TELEGRAM_BOT_TOKEN = '';
$TELEGRAM_CHAT_ID   = '';
// =====================

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function field(string $key, int $max): string
{
    $value = isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
    $value = trim(str_replace(["\0", "\r"], '', $value));
    return mb_substr($value, 0, $max, 'UTF-8');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// Поле-ловушка: люди его не видят, боты заполняют
if (!empty($_POST['website'])) {
    respond(200, ['ok' => true]);
}

$name    = str_replace("\n", ' ', field('name', 100));
$phone   = str_replace("\n", ' ', field('phone', 30));
$company = str_replace("\n", ' ', field('company', 150));
$message = field('message', 3000);
$consent = !empty($_POST['consent']);

$services = [];
if (isset($_POST['services']) && is_array($_POST['services'])) {
    foreach (array_slice($_POST['services'], 0, 20) as $service) {
        if (is_string($service)) {
            $services[] = mb_substr(trim(str_replace(["\0", "\r", "\n"], '', $service)), 0, 60, 'UTF-8');
        }
    }
}

$digits = preg_replace('/\D+/', '', $phone);
if (mb_strlen($name, 'UTF-8') < 2 || strlen($digits) !== 11 || !$consent) {
    respond(422, ['ok' => false, 'error' => 'validation']);
}

$lines = [
    'Новая заявка с сайта ' . $SITE_NAME,
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
    'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '—'),
];
$text = implode("\n", $lines);

$sent = false;

// Почта
if ($TO_EMAIL !== '') {
    $subject = '=?UTF-8?B?' . base64_encode('Заявка с сайта: ' . $name) . '?=';
    $headers = implode("\r\n", [
        'From: =?UTF-8?B?' . base64_encode($SITE_NAME) . '?= <' . $FROM_EMAIL . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    $sent = @mail($TO_EMAIL, $subject, $text, $headers) || $sent;
}

// Telegram
if ($TELEGRAM_BOT_TOKEN !== '' && $TELEGRAM_CHAT_ID !== '') {
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query(['chat_id' => $TELEGRAM_CHAT_ID, 'text' => $text]),
            'timeout' => 10,
        ],
    ]);
    $result = @file_get_contents('https://api.telegram.org/bot' . $TELEGRAM_BOT_TOKEN . '/sendMessage', false, $context);
    $sent = ($result !== false) || $sent;
}

if (!$sent) {
    respond(500, ['ok' => false, 'error' => 'send_failed']);
}

respond(200, ['ok' => true]);
