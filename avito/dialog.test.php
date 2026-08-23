<?php
/**
 * Проверка логики диалога без всякого Авито — запускается прямо сейчас,
 * пока нет ключей API. Обычный CLI-скрипт, как и остальные помощники
 * в проекте (setup-config.php, max-chat-id.php), без сборки и фреймворков.
 *
 * Запуск:
 *   php avito/dialog.test.php
 */

declare(strict_types=1);

require __DIR__ . '/dialog.php';

$failed = 0;
$passed = 0;

function check(bool $condition, string $label): void
{
    global $failed, $passed;
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
    } else {
        $failed++;
        echo "FAIL  {$label}\n";
    }
}

echo "Полный путь диалога\n";
$state = dialog_new_state();

$step = dialog_step($state, 'Здравствуйте, сколько стоит окно?');
check($step['reply'] !== null && str_contains($step['reply'], 'замерщик'), 'приветствие не называет цену');
check($step['state']['step'] === 'ask_name', 'после старта — вопрос про имя');
check($step['lead'] === null, 'заявки ещё нет');
$state = $step['state'];

$step = dialog_step($state, 'Ирина');
check($step['state']['name'] === 'Ирина', 'имя запомнено');
check($step['state']['step'] === 'ask_phone', 'после имени — вопрос про телефон');
check(str_contains((string)$step['reply'], 'privacy.html'), 'вопрос про телефон со ссылкой на политику');
$state = $step['state'];

$step = dialog_step($state, 'плюс семь девять двенадцать триста45 67 89');
check($step['reply'] !== null && str_contains($step['reply'], 'цифрами'), 'нечисловой телефон — просит повторить');
check($state['step'] === $step['state']['step'], 'шаг не продвинулся без нормального номера');

$step = dialog_step($state, '+7 912 345-67-89');
check($step['state']['phone'] === '+7 912 345-67-89', 'телефон запомнен');
check($step['state']['step'] === 'done', 'диалог завершён');
check($step['lead'] !== null, 'заявка собрана');
check($step['lead']['name'] === 'Ирина' && $step['lead']['phone'] === '+7 912 345-67-89', 'в заявке верные имя и телефон');
$state = $step['state'];

$step = dialog_step($state, 'А когда ждать звонок?');
check($step['lead'] === null, 'после завершения повторная заявка не создаётся');
check($step['reply'] !== null, 'но бот всё равно отвечает');

echo "\nКороткое имя\n";
$state = dialog_step(dialog_new_state(), 'привет')['state'];
$step = dialog_step($state, 'я');
check($step['state']['step'] === 'ask_name', 'односимвольное имя не принято, шаг тот же');
check($step['state']['name'] === null, 'имя не записано');

echo "\nСтоп-слово сразу\n";
$state = dialog_new_state();
$step = dialog_step($state, 'позовите менеджера');
check($step['state']['silenced'] === true, 'диалог заглушён');
check($step['lead'] !== null, 'заявка всё равно создана — обращение не теряется');
check(str_contains($step['lead']['text'], 'попросил менеджера'), 'в заявке есть пометка о причине');
check($step['lead']['name'] === '', 'имени в заявке нет — его и не спрашивали');

$next = dialog_step($step['state'], 'ау, вы тут?');
check($next['reply'] === null, 'после заглушения бот молчит');
check($next['lead'] === null, 'и заявку повторно не шлёт');

echo "\nСтоп-слово после имени\n";
$state = dialog_new_state();
$state = dialog_step($state, 'привет')['state'];
$state = dialog_step($state, 'Пётр')['state'];
$step = dialog_step($state, 'хочу живого человека');
check($step['lead']['name'] === 'Пётр', 'в заявке сохранилось известное имя');
check(str_contains($step['lead']['text'], '(не оставил)'), 'телефон помечен как неизвестный, а не потерян молча');

echo "\nИтог: {$passed} прошло, {$failed} не прошло\n";
exit($failed > 0 ? 1 : 0);
