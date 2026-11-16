<?php
// Einrichtung der Telegram-Benachrichtigungen (siehe telegram-lib.php) - nur Admin.
// Aktionen: get | find_chat | save | test | delete. Alles ausser get braucht das CSRF-Token.
// Der Bot-Token wird nie zurueckgegeben; get meldet nur, ob einer hinterlegt ist.
require_once __DIR__ . '/session-init.php';
require_once __DIR__ . '/json_store.php';
require_once __DIR__ . '/telegram-lib.php';
header('Content-Type: application/json; charset=UTF-8');

$dataDir = __DIR__ . '/data';
function tg_respond($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['user'])) tg_respond(['error' => 'not_authenticated'], 401);
$users = jsonStoreRead($dataDir . '/users.json');
if (!capolioIsVerwalter($dataDir, $_SESSION['user'])) tg_respond(['error' => 'forbidden'], 403);

$action = $_REQUEST['action'] ?? 'get';
// Kanal: 'optionen', 'swing', 'ausbruch', 'top3' oder 'kursalarm' - je ein eigener Bot (oder derselbe, wenn gewuenscht).
$channel = $_REQUEST['channel'] ?? '';
if (!isset(TELEGRAM_CHANNELS[$channel])) tg_respond(['error' => 'invalid_channel'], 400);
if ($action !== 'get') {
    $csrf = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrfToken']) || !hash_equals($_SESSION['csrfToken'], (string) $csrf)) tg_respond(['error' => 'invalid_csrf'], 403);
}

$maskiert = fn($id) => strlen((string) $id) > 4 ? str_repeat('•', strlen((string) $id) - 3) . substr((string) $id, -3) : '•••';

switch ($action) {
    case 'get':
        $c = telegram_config($dataDir, $channel);
        $w = $c ? null : telegram_wirksamer_kanal($dataDir, $channel);
        $ec = $w ? telegram_config($dataDir, $w) : null;
        tg_respond(['channel' => $channel, 'label' => TELEGRAM_CHANNELS[$channel], 'configured' => (bool) $c, 'chatId' => $c ? $maskiert($c['chatId']) : null, 'savedAt' => $c['savedAt'] ?? null,
            'ersatz' => $ec ? ['channel' => $w, 'label' => TELEGRAM_CHANNELS[$w], 'chatId' => $maskiert($ec['chatId'])] : null]);

    case 'find_chat':
        // Token aus dem Formular (noch nicht gespeichert) oder der hinterlegte.
        $token = trim($_POST['botToken'] ?? '') ?: (telegram_config($dataDir, $channel)['botToken'] ?? '');
        if (!telegram_valid_token($token)) tg_respond(['error' => 'invalid_token']);
        $r = telegram_find_chat_id($token);
        tg_respond($r['ok'] ? ['chatId' => $r['chatId'], 'name' => $r['name']] : ['error' => 'telegram', 'message' => $r['error']]);

    case 'save':
        $token = trim($_POST['botToken'] ?? '');
        $chatId = trim($_POST['chatId'] ?? '');
        // Leerer Token beim Speichern = bisherigen behalten (nur Chat-ID geaendert).
        if ($token === '') $token = telegram_config($dataDir, $channel)['botToken'] ?? '';
        if (!telegram_valid_token($token)) tg_respond(['error' => 'invalid_token']);
        if (!telegram_valid_chat_id($chatId)) tg_respond(['error' => 'invalid_chat_id']);
        if (!telegram_save_config($dataDir, $token, $chatId, $channel)) tg_respond(['error' => 'write_failed'], 500);
        $r = telegram_send($dataDir, "✅ <b>ValueLens</b> ist verbunden.\nHier kommen ab jetzt die Signale " . telegram_escape(TELEGRAM_CHANNELS[$channel]) . " an.", null, $channel);
        tg_respond(['saved' => true, 'testSent' => $r['ok'], 'message' => $r['ok'] ? null : $r['error']]);

    case 'test':
        // Ohne eigenen Bot ueber den Ersatz-Bot - genau der Weg, den die echten Meldungen nehmen (16.11.2026)
        $w = telegram_wirksamer_kanal($dataDir, $channel) ?? $channel;
        $r = telegram_send($dataDir, "🔔 Testnachricht von <b>ValueLens</b> (" . telegram_escape(TELEGRAM_CHANNELS[$channel]) . ($w !== $channel ? ', über ' . telegram_escape(TELEGRAM_CHANNELS[$w]) : '') . ") - " . date('d.m.Y H:i'), null, $w);
        tg_respond($r['ok'] ? ['sent' => true] : ['error' => 'telegram', 'message' => $r['error']]);

    case 'delete':
        @unlink(telegram_config_file($dataDir, $channel));
        tg_respond(['deleted' => true]);
}
tg_respond(['error' => 'unknown_action'], 400);
