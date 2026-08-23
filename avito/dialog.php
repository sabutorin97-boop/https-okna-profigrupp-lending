<?php
/**
 * Логика разговора бота Авито.
 *
 * Чистые функции — ни сети, ни файлов, ни времени. Поэтому диалог можно
 * проверить обычными тестами (dialog.test.php) прямо сейчас, не дожидаясь
 * ключей API (см. AVITO-BOT.md, «С чего начать»).
 *
 * Правила — оттуда же, «Принципы, которые стоит соблюсти»: коротко (два-три
 * вопроса, не шесть, как в квизе на сайте), стоп-слово работает на любом
 * шаге, телефон запрашивается со ссылкой на политику, цена не называется —
 * это может сказать только замерщик после выезда.
 *
 * «Не разговаривать с собой» и «замолкать, когда пришёл менеджер» — забота
 * webhook.php: он знает author_id и историю чата, сюда попадают только
 * настоящие сообщения клиента, на которые правда нужно ответить.
 */

declare(strict_types=1);

// Домен подтверждён в README.md сайта и совпадает с punycode в nginx.
const DIALOG_PRIVACY_URL = 'https://окна-курган-профигруп.рф/privacy.html';

if (!function_exists('mb_strlen')) {
    // Тот же запасной вариант, что и в delivery.php — на случай, если
    // dialog.php запускают отдельно, до подключения остального бота.
    function mb_strlen(string $s): int
    {
        return (int)(strlen($s) - substr_count($s, "\xD0") - substr_count($s, "\xD1"));
    }

    function mb_substr(string $s, int $start, ?int $length = null): string
    {
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }

    function mb_strtolower(string $s): string
    {
        return strtolower($s);
    }
}

/** Свежий диалог: ни имени, ни телефона, менеджер не подключался. */
function dialog_new_state(): array
{
    return [
        'step'     => 'start',
        'name'     => null,
        'phone'    => null,
        'silenced' => false,
    ];
}

/**
 * Один шаг диалога: входящее сообщение клиента → ответ бота и новое
 * состояние. Состояние хранит и передаёт вызывающий код (webhook.php,
 * файл на чат в avito/state/) — здесь только правила перехода.
 *
 * $reply === null значит «промолчать»: диалог уже отдан менеджеру.
 * $lead !== null значит «пора доставлять заявку» — вызывающий код передаёт
 * его в deliver_lead() из delivery.php с меткой «Авито».
 *
 * @return array{state: array, reply: ?string, lead: ?array}
 */
function dialog_step(array $state, string $message): array
{
    $message = trim($message);

    if (!empty($state['silenced'])) {
        return ['state' => $state, 'reply' => null, 'lead' => null];
    }

    if (dialog_wants_human($message)) {
        $state['silenced'] = true;
        return [
            'state' => $state,
            'reply' => 'Понял, подключаю менеджера — он ответит вам в этом чате в ближайшее время.',
            'lead'  => dialog_build_lead($state, 'Клиент попросил менеджера'),
        ];
    }

    switch ($state['step']) {
        case 'start':
            $state['step'] = 'ask_name';
            return [
                'state' => $state,
                'reply' => "Здравствуйте! Подскажу по остеклению и запишу на бесплатный замер — "
                    . "точную цену сможет назвать только замерщик на месте.\n\nКак к вам обращаться?",
                'lead'  => null,
            ];

        case 'ask_name':
            if (mb_strlen($message) < 2) {
                return ['state' => $state, 'reply' => 'Подскажите, пожалуйста, как вас зовут?', 'lead' => null];
            }
            $state['name'] = mb_substr($message, 0, 100);
            $state['step'] = 'ask_phone';
            return [
                'state' => $state,
                'reply' => $state['name'] . ', приятно познакомиться! Оставьте номер телефона — замерщик '
                    . "перезвонит и согласует удобное время.\n\nОтправляя номер, вы соглашаетесь с политикой "
                    . 'обработки персональных данных: ' . DIALOG_PRIVACY_URL,
                'lead'  => null,
            ];

        case 'ask_phone':
            $digits = preg_replace('/\D/', '', $message) ?? '';
            if (mb_strlen($digits) < 10) {
                return [
                    'state' => $state,
                    'reply' => 'Не разобрал номер — продиктуйте цифрами, например 8 912 345-67-89.',
                    'lead'  => null,
                ];
            }
            $state['phone'] = mb_substr($message, 0, 30);
            $state['step'] = 'done';
            return [
                'state' => $state,
                'reply' => 'Спасибо! Передал заявку замерщику, он свяжется с вами в ближайшее время.',
                'lead'  => dialog_build_lead($state, null),
            ];

        default: // 'done' и всё, что после
            return [
                'state' => $state,
                'reply' => 'Заявка уже у замерщика, ждите звонка. Если срочно — напишите, подключу менеджера.',
                'lead'  => null,
            ];
    }
}

/** Стоп-слово: клиент просит живого человека — бот должен сразу отойти. */
function dialog_wants_human(string $message): bool
{
    $needle = mb_strtolower($message);
    $triggers = ['менеджер', 'оператор', 'человек', 'живой', 'не бот', 'перезвоните', 'консультант'];

    foreach ($triggers as $trigger) {
        if (str_contains($needle, $trigger)) {
            return true;
        }
    }
    return false;
}

/**
 * Собирает заявку из того, что успели узнать. Вызывается и когда диалог
 * дошёл до конца, и когда клиент раньше времени попросил менеджера —
 * терять обращение нельзя, даже если известно только имя или вообще ничего.
 */
function dialog_build_lead(array $state, ?string $note): array
{
    $lines = ['Авито — заявка на замер', ''];
    $lines[] = 'Имя: ' . ($state['name'] ?? '(не назвал)');
    $lines[] = 'Телефон: ' . ($state['phone'] ?? '(не оставил)');
    if ($note !== null) {
        $lines[] = $note;
    }

    return [
        'name'  => $state['name'] ?? '',
        'phone' => $state['phone'] ?? '',
        'text'  => implode("\n", $lines),
    ];
}
