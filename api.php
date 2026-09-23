<?php
/**
 * Простое общее хранилище для «Журнала пивовара» + необязательный прокси
 * к облачному API RAPT (для цифрового ареометра RAPT Pill).
 * Кладите этот файл рядом с zhurnal-pivovara.html в той же папке на Synology
 * (Web Station). Данные хранятся в journal-data.json в этой же папке.
 *
 * GET  api.php?action=get            — вернуть текущие данные журнала (JSON)
 * POST api.php                       — записать данные журнала (тело запроса — JSON)
 * GET  api.php?action=rapt_status    — настроен ли доступ к RAPT на этом сервере
 * GET  api.php?action=rapt_devices   — список ваших ареометров RAPT Pill
 * GET  api.php?action=rapt_latest    — текущие показания одного ареометра (&deviceId=)
 * GET  api.php?action=rapt_telemetry — история показаний за N часов (&deviceId=&hours=)
 *
 * RAPT-функции работают только если рядом лежит config.local.php с вашими
 * учётными данными RAPT (см. config.local.example.php) — этот файл никогда
 * не должен попадать в публичный репозиторий, он в .gitignore.
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

    // ---------- RAPT (цифровой ареометр RAPT Pill) ----------

    function raptConfig() {
        $file = __DIR__ . '/config.local.php';
        if (!file_exists($file)) return null;
        $cfg = include $file;
        if (!is_array($cfg) || empty($cfg['rapt_username']) || empty($cfg['rapt_secret'])) return null;
        return $cfg;
    }

    // Получает валидный bearer-токен RAPT, кэшируя его в data/rapt-token-cache.json,
    // чтобы не логиниться в RAPT на каждый запрос страницы.
    function raptToken($cfg, $dataDir) {
        $cacheFile = $dataDir . '/rapt-token-cache.json';
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && !empty($cached['access_token']) && !empty($cached['expires_at']) && $cached['expires_at'] > time() + 60) {
                return $cached['access_token'];
            }
        }

        $ch = curl_init('https://id.rapt.io/connect/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => 'rapt-user',
                'grant_type' => 'password',
                'username' => $cfg['rapt_username'],
                'password' => $cfg['rapt_secret'],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($body, true);
        if ($httpCode !== 200 || !$decoded || empty($decoded['access_token'])) {
            throw new Exception('не удалось авторизоваться в RAPT (код ' . $httpCode . ')');
        }

        $expiresIn = isset($decoded['expires_in']) ? (int)$decoded['expires_in'] : 3600;
        if (is_dir($dataDir) && is_writable($dataDir)) {
            @file_put_contents($cacheFile, json_encode([
                'access_token' => $decoded['access_token'],
                'expires_at' => time() + $expiresIn,
            ]));
        }
        return $decoded['access_token'];
    }

    function raptApiGet($path, $query, $cfg, $dataDir) {
        $token = raptToken($cfg, $dataDir);
        $url = 'https://api.rapt.io/api' . $path;
        if ($query) $url .= '?' . http_build_query($query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            throw new Exception('RAPT API вернул код ' . $httpCode);
        }
        return json_decode($body, true);
    }

    $raptAction = isset($_GET['action']) ? $_GET['action'] : '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $raptAction === 'rapt_status') {
        respond(['ok' => true, 'configured' => raptConfig() !== null]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $raptAction === 'rapt_devices') {
        $cfg = raptConfig();
        if (!$cfg) fail('RAPT не настроен на сервере (нет config.local.php)');
        try {
            $devices = raptApiGet('/Hydrometers/GetHydrometers', [], $cfg, $dataDir);
            $list = array_map(function ($d) {
                return ['id' => $d['id'], 'name' => $d['name']];
            }, is_array($devices) ? $devices : []);
            respond(['ok' => true, 'devices' => $list]);
        } catch (Throwable $e) {
            fail('RAPT: ' . $e->getMessage());
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $raptAction === 'rapt_latest') {
        $cfg = raptConfig();
        if (!$cfg) fail('RAPT не настроен на сервере (нет config.local.php)');
        $deviceId = isset($_GET['deviceId']) ? $_GET['deviceId'] : '';
        if (!$deviceId) fail('не указан deviceId');
        try {
            $devices = raptApiGet('/Hydrometers/GetHydrometers', [], $cfg, $dataDir);
            $device = null;
            foreach ((is_array($devices) ? $devices : []) as $d) {
                if ($d['id'] === $deviceId) { $device = $d; break; }
            }
            if (!$device) fail('ареометр с таким id не найден в вашем аккаунте RAPT');
            respond(['ok' => true, 'reading' => [
                'gravity' => $device['gravity'],
                'temperature' => $device['temperature'],
                'battery' => $device['battery'],
                'rssi' => $device['rssi'],
                'lastActivityTime' => $device['lastActivityTime'],
            ]]);
        } catch (Throwable $e) {
            fail('RAPT: ' . $e->getMessage());
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $raptAction === 'rapt_telemetry') {
        $cfg = raptConfig();
        if (!$cfg) fail('RAPT не настроен на сервере (нет config.local.php)');
        $deviceId = isset($_GET['deviceId']) ? $_GET['deviceId'] : '';
        if (!$deviceId) fail('не указан deviceId');
        $hours = isset($_GET['hours']) ? max(1, min(720, (int)$_GET['hours'])) : 168;
        try {
            $end = gmdate('Y-m-d\TH:i:s\Z');
            $start = gmdate('Y-m-d\TH:i:s\Z', time() - $hours * 3600);
            $telemetry = raptApiGet('/Hydrometers/GetTelemetry', [
                'hydrometerId' => $deviceId,
                'startDate' => $start,
                'endDate' => $end,
            ], $cfg, $dataDir);
            $points = array_map(function ($t) {
                return [
                    'createdOn' => $t['createdOn'],
                    'gravity' => $t['gravity'],
                    'temperature' => $t['temperature'],
                ];
            }, is_array($telemetry) ? $telemetry : []);
            respond(['ok' => true, 'points' => $points]);
        } catch (Throwable $e) {
            fail('RAPT: ' . $e->getMessage());
        }
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
