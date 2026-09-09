<?php
/**
 * Простое общее хранилище для «Журнала пивовара».
 * Кладите этот файл рядом с zhurnal-pivovara.html в той же папке на Synology
 * (Web Station). Данные хранятся в journal-data.json в этой же папке.
 *
 * GET  api.php?action=get  — вернуть текущие данные (JSON)
 * POST api.php             — записать данные (тело запроса — JSON)
 *
 * ВАЖНО: этот скрипт ВСЕГДА отвечает HTTP 200, а успех/ошибку показывает
 * полем "ok" в теле ответа. Это сделано специально: на этой Synology
 * страница ошибок Web Station подменяет тело ответа для любых кодов,
 * отличных от 2xx, своей стандартной HTML-страницей — из-за этого
 * настоящее сообщение об ошибке было не увидеть. Раз код всегда 200,
 * подмена не происходит, и мы видим реальную причину в поле "error".
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

function respond($payload) {
    http_response_code(200); // всегда 200 — см. пояснение в шапке файла
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail($msg, $extra = []) {
    respond(array_merge(['ok' => false, 'error' => $msg], $extra));
}

// Ловим фатальные ошибки, чтобы вместо голого 500 всегда возвращать понятный JSON.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
        }
        echo json_encode([
            'ok' => false,
            'error' => 'php fatal: ' . $e['message'],
            'file' => $e['file'],
            'line' => $e['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        respond(['ok' => true]);
    }

    $dataDir = __DIR__ . '/data';
    $dataFile = $dataDir . '/journal-data.json';

    function emptyState() {
        return ['userRecipes' => [], 'userRecipeLibrary' => []];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (file_exists($dataFile)) {
            $content = file_get_contents($dataFile);
            $decoded = json_decode($content, true);
            if ($decoded === null) {
                respond(emptyState());
            }
            respond($decoded);
        } else {
            respond(emptyState());
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if ($data === null || !array_key_exists('userRecipes', $data) || !array_key_exists('userRecipeLibrary', $data)) {
            fail('invalid payload', ['raw_len' => strlen($raw), 'json_error' => json_last_error_msg()]);
        }

        if (!is_dir($dataDir)) {
            fail('папка data не найдена рядом с api.php', ['expected' => $dataDir]);
        }

        if (!is_writable($dataDir)) {
            fail('папка data не доступна для записи пользователю веб-сервера', [
                'dir' => $dataDir,
                'dir_perms' => substr(sprintf('%o', @fileperms($dataDir)), -4),
                'dir_owner' => @fileowner($dataDir),
                'whoami_uid' => function_exists('posix_getuid') ? @posix_getuid() : null,
            ]);
        }

        $tmp = $dataFile . '.tmp';
        $written = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($written === false) {
            $err = error_get_last();
            fail('write failed', ['php_warning' => $err ? $err['message'] : null, 'tmp' => $tmp]);
        }
        if (!@rename($tmp, $dataFile)) {
            fail('rename failed', ['tmp' => $tmp, 'target' => $dataFile]);
        }

        respond(['ok' => true]);
    }

    fail('method not allowed', ['method' => $_SERVER['REQUEST_METHOD']]);
} catch (Throwable $e) {
    fail('exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
}
