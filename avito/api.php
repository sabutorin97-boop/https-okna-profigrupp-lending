<?php
/**
 * Работа с API Авито: токен с кэшем, отправка сообщений, подписка на вебхук.
 *
 * Ключей пока нет (см. AVITO-BOT.md, «Главное препятствие») — эти функции
 * писались и проверялись без живого API, по официальной спецификации
 * (MissiaL/avito-api). Когда ключи появятся, проверить их можно будет
 * функцией avito_get_self() — она первой упадёт, если что-то не так
 * с client_id/client_secret.
 *
 * $avito — массив настроек: client_id, client_secret, user_id (когда
 * известен), state_dir (папка для кэша токена, вне сайта, как и okna-leads
 * у delivery.php).
 */

declare(strict_types=1);

const AVITO_BASE_URL = 'https://api.avito.ru';
const AVITO_MESSAGE_LIMIT = 1000;

if (!function_exists('mb_strlen')) {
    // Тот же запасной вариант, что и в delivery.php — на случай, если
    // api.php запускают отдельно, до подключения остального бота.
    function mb_strlen(string $s): int
    {
        return (int)(strlen($s) - substr_count($s, "\xD0") - substr_count($s, "\xD1"));
    }

    function mb_substr(string $s, int $start, ?int $length = null): string
    {
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }

    function mb_strrpos(string $haystack, string $needle): int|false
    {
        return strrpos($haystack, $needle);
    }
}

/**
 * Токен живёт в файле вне папки сайта и обновляется только когда истёк —
 * иначе на каждое сообщение уходил бы лишний запрос.
 */
function avito_get_token(array $avito): ?string
{
    $dir = rtrim((string)$avito['state_dir'], '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $file = $dir . '/token.json';

    if (is_file($file)) {
        $cached = json_decode((string)file_get_contents($file), true);
        // Запас в минуту, чтобы не отправить сообщение токеном,
        // который истечёт на середине запроса
        if (is_array($cached) && (int)($cached['expires_at'] ?? 0) > time() + 60) {
            return (string)$cached['access_token'];
        }
    }

    $ch = curl_init(AVITO_BASE_URL . '/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => (string)$avito['client_id'],
            'client_secret' => (string)$avito['client_secret'],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $answer = curl_exec($ch);
    $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log('Авито API: не удалось получить токен, код ' . $code . ' ' . $err);
        return null;
    }

    $data = json_decode((string)$answer, true);
    if (!is_array($data) || empty($data['access_token'])) {
        error_log('Авито API: ответ на /token без access_token');
        return null;
    }

    $cache = [
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int)($data['expires_in'] ?? 0),
    ];
    @file_put_contents($file, json_encode($cache), LOCK_EX);

    return (string)$data['access_token'];
}

/**
 * Запрос к API с готовым токеном. Возвращает разобранный JSON или null,
 * если Авито ответил ошибкой — вызывающий код сам решает, что делать
 * (например, для мелких действий вроде «отметить прочитанным» ошибка
 * не критична и её можно проигнорировать).
 */
function avito_request(string $method, string $path, string $token, ?array $body = null): ?array
{
    $headers = ['Authorization: Bearer ' . $token];
    $options = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init(AVITO_BASE_URL . $path);
    curl_setopt_array($ch, $options);
    $answer = curl_exec($ch);
    $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log('Авито API: ' . $method . ' ' . $path . ' ответил ' . $code . ' ' . $err . ' '
            . substr((string)$answer, 0, 300));
        return null;
    }

    $decoded = json_decode((string)$answer, true);
    return is_array($decoded) ? $decoded : [];
}

/** Свой user_id — нужен и для адреса чатов, и как первая проверка ключей. */
function avito_get_self(string $token): ?array
{
    return avito_request('GET', '/core/v1/accounts/self', $token);
}

/**
 * Отправляет текст в чат. Авито режет сообщение длиннее 1000 знаков молча,
 * а не отклоняет запрос — поэтому длинный ответ бьётся на части заранее,
 * чтобы он не обрывался на полуслове.
 */
function avito_send_message(array $avito, string $token, string $chatId, string $text): bool
{
    $ok = true;
    foreach (avito_split_message($text) as $part) {
        $path = '/messenger/v1/accounts/' . rawurlencode((string)$avito['user_id'])
            . '/chats/' . rawurlencode($chatId) . '/messages';
        $answer = avito_request('POST', $path, $token, ['type' => 'text', 'message' => ['text' => $part]]);
        $ok = $ok && $answer !== null;
    }
    return $ok;
}

/** Режет текст по лимиту, стараясь не рвать предложение посередине. */
function avito_split_message(string $text, int $limit = AVITO_MESSAGE_LIMIT): array
{
    if (mb_strlen($text) <= $limit) {
        return [$text];
    }

    $parts = [];
    while (mb_strlen($text) > $limit) {
        $chunk = mb_substr($text, 0, $limit);
        $cut = mb_strrpos($chunk, "\n");
        // Перенос слишком близко к началу — резать по нему смысла нет,
        // проще отрезать ровно по лимиту
        if ($cut === false || $cut < (int)($limit / 2)) {
            $cut = $limit;
        }
        $parts[] = rtrim(mb_substr($text, 0, $cut));
        $text = ltrim(mb_substr($text, $cut));
    }
    if ($text !== '') {
        $parts[] = $text;
    }
    return $parts;
}

function avito_mark_read(array $avito, string $token, string $chatId): bool
{
    $path = '/messenger/v1/accounts/' . rawurlencode((string)$avito['user_id'])
        . '/chats/' . rawurlencode($chatId) . '/read';
    return avito_request('POST', $path, $token, []) !== null;
}

/** Включает уведомления о новых сообщениях — вызывается один раз при настройке. */
function avito_subscribe_webhook(string $token, string $url, string $secret): bool
{
    return avito_request('POST', '/messenger/v3/webhook', $token, ['url' => $url, 'secret' => $secret]) !== null;
}

function avito_list_subscriptions(string $token): ?array
{
    return avito_request('POST', '/messenger/v1/subscriptions', $token, []);
}

function avito_unsubscribe_webhook(string $token, string $url): bool
{
    return avito_request('POST', '/messenger/v1/webhook/unsubscribe', $token, ['url' => $url]) !== null;
}
