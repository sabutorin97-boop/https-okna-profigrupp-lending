<?php
/**
 * Приём заявок с лендинга.
 *
 * Здесь только разбор того, что прислала форма, и проверки. Сама доставка —
 * журнал, письмо, сообщение в MAX — живёт в delivery.php: тем же кодом
 * пользуется бот Авито, и расходиться этим двум путям нельзя.
 *
 * Настройки — в файле config.php рядом (см. config.sample.php).
 * Без него сайт работает, просто письма не отправляются.
 */

declare(strict_types=1);

require __DIR__ . '/delivery.php';

const THROTTLE_SECONDS = 20;

$config = lead_config(__DIR__);

header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, string $error = '', int $code = 200): never
{
    http_response_code($code);
    echo json_encode($error === '' ? ['ok' => $ok] : ['ok' => $ok, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Метод не поддерживается', 405);
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

/* Ловушка для ботов: поле скрыто от людей, заполнить его мог только робот.
   Отвечаем успехом, чтобы бот не искал обходной путь. */
if (!empty($data['company'])) {
    respond(true);
}

$name  = clean((string)($data['name'] ?? ''), 100);
$phone = clean((string)($data['phone'] ?? ''), 30);
$way   = clean((string)($data['way'] ?? ''), 60);
$text  = (string)($data['text'] ?? '');

$digits = preg_replace('/\D/', '', $phone) ?? '';

if (mb_strlen($name) < 2 || mb_strlen($digits) < 10) {
    respond(false, 'Проверьте имя и телефон', 422);
}

/* Ограничение частоты — чтобы форму не залили сотней заявок разом. */
$ip   = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$lock = sys_get_temp_dir() . '/lead-' . md5($ip) . '.lock';
if (is_file($lock) && (time() - (int)filemtime($lock)) < THROTTLE_SECONDS) {
    respond(false, 'Слишком часто, попробуйте через минуту', 429);
}
@touch($lock);

/* ---------------------------------------------------------------- текст */

// Метка источника: с какого сайта пришла заявка.
// Приходит от формы, иначе берётся из настроек сервера.
$label = clean((string)($data['source_label'] ?? ''), 60);
if ($label === '') {
    $label = (string)$config['source_label'];
}

if ($text === '') {
    $lines = [
        $label . ' — заявка на замер',
        '',
        'Имя: ' . $name,
        'Телефон: ' . $phone,
        'Способ связи: ' . $way,
    ];
    if (!empty($data['solution']) && is_array($data['solution'])) {
        $lines[] = '';
        $lines[] = 'Подобрано:';
        foreach ($data['solution'] as $item) {
            $lines[] = '• ' . clean((string)$item, 300);
        }
    }
    $text = implode("\n", $lines);
}

$text = str_replace("\0", '', $text);
$text .= "\n\nСтраница: " . clean((string)($data['page'] ?? ''), 300);

// Источник показываем, только если он есть: пустая строка «Источник:»
// в заявке лишь занимает место и сбивает с толку
$source = clean((string)($data['source'] ?? ''), 300);
if ($source !== '') {
    $text .= "\nИсточник: " . $source;
}

$text .= "\nIP: " . $ip;
$text .= "\nВремя: " . date('d.m.Y H:i');

/* ------------------------------------------------------------- доставка */

$sent = deliver_lead($config, $text, $label . ': ' . $name . ', ' . $phone);

// Заявка принята, если она хотя бы записана в журнал: письмо и сообщение
// можно отправить позже, а вот потерять обращение клиента нельзя.
if ($sent['saved'] || $sent['mailed'] || $sent['maxed']) {
    respond(true);
}

error_log('Заявка: не удалось ни записать в журнал, ни отправить письмо, ни доставить в MAX');
respond(false, 'Не получилось сохранить заявку', 500);
