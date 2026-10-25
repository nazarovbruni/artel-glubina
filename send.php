<?php
// Отправка заявок с сайта artelglubina.ru
// Работает на хостинге с PHP (masterhost). На GitHub Pages PHP нет —
// там форма автоматически откроет почтовую программу.

// ===== НАСТРОЙКИ =====
$TO_EMAIL   = 'gallery@glubinagallery.ru';    // куда приходят заявки
$FROM_EMAIL = 'noreply@artelglubina.ru';      // от кого (адрес на домене сайта)

// Дублирование заявок в Telegram.
// Токен бота и номер чата НЕ хранятся здесь (репозиторий публичный).
// Они лежат в файле tg-config.php рядом с этим файлом — только на хостинге.
// Нет файла — заявки идут только на почту.
$TG_TOKEN   = '';
$TG_CHAT_ID = '';
if (is_file(__DIR__ . '/tg-config.php')) {
    include __DIR__ . '/tg-config.php';
}
// =====================

date_default_timezone_set('Europe/Moscow');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

// Ловушка для спам-ботов: настоящий посетитель это поле не видит
if (!empty($_POST['website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

function field($name, $max = 1000) {
    $v = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    if (function_exists('mb_substr')) { $v = mb_substr($v, 0, $max, 'UTF-8'); }
    else { $v = substr($v, 0, $max * 2); }
    return $v;
}

$interest = field('interest', 100);
$name     = field('name', 100);
$contact  = field('contact', 150);
$author   = field('author', 150);
$budget   = field('budget', 100);
$message  = field('message', 3000);
$page     = field('page', 300);

if ($contact === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'contact']);
    exit;
}

// Простая защита от частых повторных отправок с одного адреса (1 раз в 20 секунд)
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$lock = sys_get_temp_dir() . '/artel_form_' . md5($ip);
if ($ip && file_exists($lock) && (time() - filemtime($lock)) < 20) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate']);
    exit;
}
@touch($lock);

$dash = '—';
$text = "Новая заявка с сайта artelglubina.ru\n\n"
      . "Что интересует: " . ($interest !== '' ? $interest : $dash) . "\n"
      . "Имя: "            . ($name     !== '' ? $name     : $dash) . "\n"
      . "Телефон / Telegram: " . $contact . "\n"
      . "Автор: "          . ($author   !== '' ? $author   : $dash) . "\n"
      . "Бюджет: "         . ($budget   !== '' ? $budget   : $dash) . "\n\n"
      . "Задача:\n"        . ($message  !== '' ? $message  : $dash) . "\n\n"
      . "Страница: "       . ($page     !== '' ? $page     : $dash) . "\n"
      . "Время: " . date('d.m.Y H:i') . "\n";

$subjectText = 'Заявка с сайта Глубина' . ($interest !== '' ? ': ' . $interest : '');
$subject = '=?UTF-8?B?' . base64_encode($subjectText) . '?=';

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "From: =?UTF-8?B?" . base64_encode('Сайт Артель Глубина') . "?= <" . $FROM_EMAIL . ">\r\n";
if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
    $headers .= "Reply-To: " . $contact . "\r\n";
}

$mailOk = @mail($TO_EMAIL, $subject, $text, $headers, '-f' . $FROM_EMAIL);

// Письмо ушло — сразу отвечаем посетителю, Telegram пробуем уже после ответа
$answered = false;
if ($mailOk) {
    ignore_user_abort(true);
    @set_time_limit(30);
    $body = json_encode(['ok' => true]);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    header('Content-Encoding: none');
    echo $body;
    @flush();
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    $answered = true;
}

// Если Telegram недавно был недоступен — не ждём его снова 30 минут
$tgDownFile = dirname(__DIR__) . '/tmp/tg_down';
$tgDown = is_file($tgDownFile) && (time() - filemtime($tgDownFile)) < 1800;

$tgOk = false;
$tgErr = '';
$TG_TOKEN = trim($TG_TOKEN);
$TG_CHAT_ID = trim($TG_CHAT_ID);
if (!$tgDown && strpos($TG_TOKEN, ':') !== false && preg_match('/^-?\d+$/', $TG_CHAT_ID)) {
    $url = 'https://api.telegram.org/bot' . $TG_TOKEN . '/sendMessage';
    $payload = http_build_query(['chat_id' => $TG_CHAT_ID, 'text' => $text]);
    $res = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
        ]);
        $res = curl_exec($ch);
        if ($res === false) { $tgErr = 'curl: ' . curl_error($ch); if (is_dir(dirname($tgDownFile))) { @touch($tgDownFile); } }
        curl_close($ch);
    }
    if ($res === false && !function_exists('curl_init') && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) { $e = error_get_last(); $tgErr .= ' | fopen: ' . ($e ? $e['message'] : 'fail'); }
    }
    $tgOk = $res !== false && strpos($res, '"ok":true') !== false;
    if ($tgOk && is_file($tgDownFile)) { @unlink($tgDownFile); }
    if (!$tgOk && $res !== false) { $tgErr .= ' | telegram: ' . substr($res, 0, 200); }
} elseif ($tgDown) {
    $tgErr = 'skipped (telegram недоступен, повтор через 30 мин)';
} else {
    $tgErr = 'tg-config not filled';
}

if ($TG_TOKEN !== '') { $tgErr = str_replace($TG_TOKEN, '***', $tgErr); }

// Короткий журнал отправок (вне папки www, посетителям недоступен)
$logDir = dirname(__DIR__) . '/tmp';
if (is_dir($logDir) && is_writable($logDir)) {
    @file_put_contents($logDir . '/form.log',
        date('d.m.Y H:i:s') . ' mail=' . ($mailOk ? 'ok' : 'FAIL') . ' tg=' . ($tgOk ? 'ok' : 'FAIL ' . $tgErr) . "\n",
        FILE_APPEND);
}

if ($answered) {
    exit;
}
if ($mailOk || $tgOk) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'send']);
}
