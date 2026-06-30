<?php
/**
 * clido - single-file PHP web interface for running scripts via button clicks
 * https://github.com/sysmatt/clido
 */

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

define('CLIDO_VERSION', '1.0.0');

// SIGTERM/SIGKILL are defined by the pcntl extension, which is often absent on
// web-server PHP. Define them from their POSIX-standard values if not already set.
defined('SIGTERM') || define('SIGTERM', 15);
defined('SIGKILL') || define('SIGKILL',  9);

// The "working directory" for this deployment is wherever the symlink lives,
// not where this file actually is. SCRIPT_FILENAME is set by the web server
// to the path it's serving (pre-symlink-resolution on most configs).
define('CLIDO_DIR',     dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
define('CLIDO_INI',     CLIDO_DIR . '/clido.ini');

$DEFAULTS = [
    'title'           => 'CliDO',
    'favicon'         => '',
    'logo'            => '',
    'execute_text'    => 'EXECUTE',
    'simplewebauth'   => '',
    'logout_url'      => '',
    'history_qty'     => 5,
    'show_command'    => false,
    'max_runtime'     => 0,
    'sigkill_wait'    => 5,
    'color_bg'        => '#0d1117',
    'color_sidebar'   => '#161b22',
    'color_accent'    => '#21262d',
    'color_border'    => '#30363d',
    'color_text'      => '#c9d1d9',
    'color_text_muted'=> '#8b949e',
    'color_btn'       => '#238636',
    'color_btn_text'  => '#ffffff',
    'color_cancel'    => '#da3633',
    'color_output_bg' => '#010409',
    'color_output_text'=> '#39d353',
    'ttyd_port_min'    => 7681,
    'ttyd_port_max'    => 7699,
    'ttyd_bin'         => 'ttyd',
];

// ---------------------------------------------------------------------------
// Parse INI
// ---------------------------------------------------------------------------

function clido_parse_ini(): array {
    if (!file_exists(CLIDO_INI)) {
        clido_fatal('clido.ini not found in ' . CLIDO_DIR);
    }
    $raw = parse_ini_file(CLIDO_INI, true, INI_SCANNER_RAW);
    if ($raw === false) {
        clido_fatal('Failed to parse ' . CLIDO_INI);
    }

    global $DEFAULTS;
    $global  = array_merge($DEFAULTS, array_change_key_case($raw['global'] ?? [], CASE_LOWER));
    $groups  = [];  // ordered list: ['type'=>'group'|'item', 'name'=>..., 'cfg'=>...]
    $items   = [];  // name => cfg

    foreach ($raw as $section => $cfg) {
        if ($section === 'global') continue;
        $cfg = array_change_key_case($cfg, CASE_LOWER);

        if (preg_match('/^group:\s*(.+)$/i', $section, $m)) {
            $name = trim($m[1]);
            $groups[] = ['type' => 'group', 'name' => $name, 'cfg' => $cfg];
        } elseif (preg_match('/^item:\s*(.+)$/i', $section, $m)) {
            $name = trim($m[1]);
            $item = build_item($name, $cfg, $global);
            $groups[] = ['type' => 'item', 'name' => $name, 'cfg' => $item];
            $items[$name] = $item;
        }
    }

    return ['global' => $global, 'groups' => $groups, 'items' => $items];
}

function build_item(string $name, array $cfg, array $global): array {
    $item = [
        'name'         => $name,
        'title'        => $cfg['title']        ?? $name,
        'description'  => $cfg['description']  ?? '',
        'command'      => $cfg['command']       ?? '',
        'url'          => $cfg['url']           ?? '',
        'execute_text' => $cfg['execute_text']  ?? $global['execute_text'],
        'showfiles'    => filter_var($cfg['showfiles']    ?? false,                    FILTER_VALIDATE_BOOLEAN),
        'ttyd'         => filter_var($cfg['ttyd']         ?? false,                    FILTER_VALIDATE_BOOLEAN),
        'show_command' => filter_var($cfg['show_command'] ?? $global['show_command'],  FILTER_VALIDATE_BOOLEAN),
        'max_runtime'  => (int)($cfg['max_runtime'] ?? $global['max_runtime']),
        'args'         => [],   // fixed-prefix args for text slots only
        'inputs'       => [],
    ];

    for ($i = 1; $i <= 32; $i++) {
        $hasArg   = array_key_exists("arg$i",      $cfg);
        $hasArgOn = array_key_exists("arg{$i}_on", $cfg);
        $hasInput = array_key_exists("input$i",    $cfg);
        if (!$hasArg && !$hasArgOn && !$hasInput) continue;

        $inputType = strtolower(trim($cfg["input$i"] ?? 'text'));

        if ($inputType === 'checkbox') {
            $item['inputs'][$i] = [
                'type'    => 'checkbox',
                'desc'    => $cfg["desc$i"] ?? "Option $i",
                'arg_on'  => $cfg["arg{$i}_on"] ?? ($hasArg ? $cfg["arg$i"] : null),
                'arg_off' => $cfg["arg{$i}_off"] ?? null,
                'default' => strtolower(trim($cfg["default$i"] ?? 'unchecked')),
            ];
        } elseif ($inputType === 'radio' || $inputType === 'select') {
            $options = [];
            for ($j = 1; $j <= 32; $j++) {
                if (!array_key_exists("opt{$i}_{$j}", $cfg)) break;
                $options[] = [
                    'value' => $cfg["opt{$i}_{$j}"],
                    'desc'  => $cfg["opt{$i}_{$j}_desc"] ?? $cfg["opt{$i}_{$j}"],
                ];
            }
            $item['inputs'][$i] = [
                'type'    => $inputType,
                'desc'    => $cfg["desc$i"] ?? "Option $i",
                'arg_pre' => $hasArg ? $cfg["arg$i"] : null,
                'default' => $cfg["default$i"] ?? ($options[0]['value'] ?? ''),
                'options' => $options,
            ];
        } elseif (in_array($inputType, ['single_file', 'file_list', 'file_each'])) {
            $item['inputs'][$i] = [
                'type'    => $inputType,
                'desc'    => $cfg["desc$i"] ?? "File $i",
                'arg_pre' => $hasArg ? $cfg["arg$i"] : null,
            ];
        } else {
            // text (default) — fixed prefix and/or user-supplied value
            if ($hasArg)   $item['args'][$i]   = $cfg["arg$i"];
            if ($hasInput) $item['inputs'][$i]  = [
                'type' => 'text',
                'desc' => $cfg["desc$i"] ?? "Input $i",
            ];
        }
    }

    return $item;
}

function assemble_args(array $item, array $userInputs, string $tmpDir = ''): array {
    $slots = array_unique(array_merge(
        array_keys($item['inputs']),
        array_keys($item['args'])
    ));
    sort($slots);
    $args = [];

    foreach ($slots as $n) {
        $input = $item['inputs'][$n] ?? null;
        $type  = $input['type'] ?? null;

        if ($type === 'checkbox') {
            $checked = ($userInputs["input$n"] ?? '') === 'on';
            $emit    = $checked ? ($input['arg_on'] ?? null) : ($input['arg_off'] ?? null);
            if ($emit !== null && $emit !== '') $args[] = escapeshellarg($emit);
        } elseif ($type === 'radio' || $type === 'select') {
            if (!empty($input['arg_pre'])) $args[] = escapeshellarg($input['arg_pre']);
            $val = $userInputs["input$n"] ?? $input['default'] ?? '';
            if ($val !== '') $args[] = escapeshellarg($val);
        } elseif (in_array($type, ['single_file', 'file_list', 'file_each'])) {
            $subdir = $tmpDir ? $tmpDir . '/uploaded_files' . $n : '';
            $files  = ($subdir && is_dir($subdir))
                ? array_values(array_filter(glob($subdir . '/*') ?: [], 'is_file'))
                : [];
            if (empty($files)) continue;
            if ($type === 'single_file') {
                if (!empty($input['arg_pre'])) $args[] = escapeshellarg($input['arg_pre']);
                $args[] = escapeshellarg($files[0]);
            } elseif ($type === 'file_list') {
                if (!empty($input['arg_pre'])) $args[] = escapeshellarg($input['arg_pre']);
                foreach ($files as $f) $args[] = escapeshellarg($f);
            } elseif ($type === 'file_each') {
                foreach ($files as $f) {
                    if (!empty($input['arg_pre'])) $args[] = escapeshellarg($input['arg_pre']);
                    $args[] = escapeshellarg($f);
                }
            }
        } else {
            // text or fixed-arg-only slot
            $fixedArg = $item['args'][$n] ?? null;
            if ($fixedArg !== null) $args[] = escapeshellarg($fixedArg);
            if ($input !== null) {
                $args[] = escapeshellarg($userInputs["input$n"] ?? '');
            }
        }
    }

    return $args;
}

function clido_fatal(string $msg): void {
    http_response_code(500);
    echo "<!DOCTYPE html><html><body style='font-family:monospace;color:red;background:#111;padding:2em'>";
    echo "<b>clido error:</b> " . htmlspecialchars($msg);
    echo "</body></html>";
    exit(1);
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

function clido_auth(array $global): void {
    $path = trim($global['simplewebauth'] ?? '');
    if ($path === '') return;  // no auth configured

    // Resolve relative to docroot if not absolute
    if ($path[0] !== '/') {
        $path = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/' . $path;
    }
    if (!file_exists($path)) {
        clido_fatal("simplewebauth not found at: $path");
    }
    require $path;
}

// ---------------------------------------------------------------------------
// Token / job tracking helpers
// ---------------------------------------------------------------------------

function job_token(): string {
    return bin2hex(random_bytes(16));
}

function job_pid_file(string $token): string {
    return sys_get_temp_dir() . "/clido_pid_$token";
}

function job_dir_file(string $token): string {
    return sys_get_temp_dir() . "/clido_dir_$token";
}

// ---------------------------------------------------------------------------
// Action: exec (POST — starts command, returns token)
// ---------------------------------------------------------------------------

function action_exec(array $cfg): void {
    $itemName = $_POST['item'] ?? '';
    $item     = $cfg['items'][$itemName] ?? null;
    if (!$item) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown item']);
        exit;
    }

    $command = $item['command'];
    if (!$command) {
        http_response_code(400);
        echo json_encode(['error' => 'No command defined for this item']);
        exit;
    }

    $token  = job_token();
    $tmpDir = sys_get_temp_dir() . '/clido_' . $token;
    mkdir($tmpDir, 0700);
    file_put_contents(job_dir_file($token), $tmpDir);

    // Process file inputs — save uploads or copy from previous run (history re-run)
    $fileRefs = [];
    $tmpBase  = rtrim(sys_get_temp_dir(), '/') . '/clido_';
    $fileTypes = ['single_file', 'file_list', 'file_each'];

    foreach ($item['inputs'] as $n => $input) {
        if (!in_array($input['type'], $fileTypes)) continue;
        $subdir = $tmpDir . '/uploaded_files' . $n;

        // History re-run: file paths supplied as file_ref_N[] POST values
        $refKey = "file_ref_$n";
        if (!empty($_POST[$refKey])) {
            $saved = [];
            foreach ((array)$_POST[$refKey] as $refPath) {
                $real = realpath($refPath);
                if (!$real || strpos($real, $tmpBase) !== 0 || !is_file($real)) continue;
                if (!is_dir($subdir)) mkdir($subdir, 0700);
                $dest = $subdir . '/' . basename($real);
                if (copy($real, $dest)) $saved[] = $dest;
            }
            if ($saved) $fileRefs["input$n"] = $saved;
            continue;
        }

        // New upload via multipart form
        $fieldKey = "file_input_$n";
        if (empty($_FILES[$fieldKey]['name'][0])) continue;

        $names    = (array)$_FILES[$fieldKey]['name'];
        $tmpNames = (array)$_FILES[$fieldKey]['tmp_name'];
        $errors   = (array)$_FILES[$fieldKey]['error'];
        $count    = ($input['type'] === 'single_file') ? 1 : count($names);
        $saved    = [];

        for ($j = 0; $j < $count; $j++) {
            if (($errors[$j] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $safeName = basename($names[$j] ?? '');
            if ($safeName === '' || $safeName === '.' || $safeName === '..') continue;
            if (!is_dir($subdir)) mkdir($subdir, 0700);
            $dest = $subdir . '/' . $safeName;
            if (move_uploaded_file($tmpNames[$j], $dest)) $saved[] = $dest;
        }
        if ($saved) $fileRefs["input$n"] = $saved;
    }

    // Assemble args now that files are in place (for accurate syslog)
    $userInputs = array_filter($_POST, fn($k) => (bool)preg_match('/^input\d+$/', $k), ARRAY_FILTER_USE_KEY);
    $args       = assemble_args($item, $userInputs, $tmpDir);

    header('Content-Type: application/json');
    echo json_encode(['token' => $token, 'file_refs' => $fileRefs]);

    $authUser = function_exists('auth_user') ? auth_user() : 'anonymous';
    $fullCmd  = $command . ($args ? ' ' . implode(' ', $args) : '');
    openlog('clido', LOG_PID, LOG_USER);
    syslog(LOG_INFO, "USER=$authUser ITEM=$itemName CMD=$fullCmd TMPDIR=$tmpDir");
    closelog();

    exit;
}

// ---------------------------------------------------------------------------
// Action: stream (GET — SSE, runs command and streams output)
// ---------------------------------------------------------------------------

function action_stream(array $cfg): void {
    $token    = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
    $itemName = $_GET['item'] ?? '';
    $item     = $cfg['items'][$itemName] ?? null;

    if (!$item || !$token) {
        http_response_code(400);
        exit;
    }

    $tmpDir = trim(file_get_contents(job_dir_file($token)) ?: '');
    if (!$tmpDir || !is_dir($tmpDir)) {
        http_response_code(400);
        exit;
    }

    $userInputs = array_filter($_GET, fn($k) => (bool)preg_match('/^input\d+$/', $k), ARRAY_FILTER_USE_KEY);
    $args       = assemble_args($item, $userInputs, $tmpDir);
    $command    = $item['command'];
    $fullCmd    = $command . ($args ? ' ' . implode(' ', $args) : '');

    $maxRuntime  = $item['max_runtime'];
    $sigkillWait = (int)($cfg['global']['sigkill_wait'] ?? 5);

    // SSE headers
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');  // nginx: disable proxy buffering
    header('Connection: keep-alive');

    // Disable PHP output buffering
    while (ob_get_level() > 0) ob_end_flush();
    flush();

    function sse(string $event, string $data): void {
        echo "event: $event\n";
        // Split data on newlines so SSE framing works correctly
        foreach (explode("\n", $data) as $line) {
            echo "data: $line\n";
        }
        echo "\n";
        flush();
    }

    $startTime = microtime(true);

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    if ($item['show_command']) {
        sse('command', $fullCmd);
    }

    $proc = proc_open($fullCmd, $descriptors, $pipes, $tmpDir, null, ['bypass_shell' => false]);

    if (!is_resource($proc)) {
        sse('error', 'Failed to start process');
        sse('done', json_encode(['exit' => -1, 'elapsed' => 0]));
        exit;
    }

    // Write PID file so cancel can kill the process
    $status = proc_get_status($proc);
    $pid    = $status['pid'];
    file_put_contents(job_pid_file($token), $pid);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $timedOut = false;

    while (true) {
        if (connection_aborted()) {
            // Client disconnected — kill and clean up
            posix_kill(-$pid, SIGTERM);
            if ($sigkillWait > 0) sleep($sigkillWait);
            posix_kill(-$pid, SIGKILL);
            proc_close($proc);
            @unlink(job_pid_file($token));
            exit;
        }

        $status = proc_get_status($proc);

        // Check max runtime
        if ($maxRuntime > 0 && (microtime(true) - $startTime) > $maxRuntime) {
            $timedOut = true;
            posix_kill(-$pid, SIGTERM);
            if ($sigkillWait > 0) sleep($sigkillWait);
            posix_kill(-$pid, SIGKILL);
        }

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);

        if ($out !== false && $out !== '') {
            sse('output', $out);
        }
        if ($err !== false && $err !== '') {
            sse('stderr', $err);
        }

        if (!$status['running']) {
            // Drain any remaining output
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            if ($out) sse('output', $out);
            if ($err) sse('stderr', $err);
            break;
        }

        if ($timedOut) break;

        usleep(50000);  // 50ms poll interval
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    @unlink(job_pid_file($token));

    $elapsed = round(microtime(true) - $startTime, 2);

    // Build file list if SHOWFILES
    $files = [];
    if ($item['showfiles']) {
        foreach (scandir($tmpDir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $fp = $tmpDir . '/' . $f;
            if (!is_file($fp)) continue;
            $files[] = [
                'name'  => $f,
                'size'  => filesize($fp),
                'mtime' => filemtime($fp),
            ];
        }
    }

    // Remove empty temp dir; silently fails if files remain (non-empty)
    @rmdir($tmpDir);

    // Dir tracking file is only needed if there are downloadable files waiting
    if (empty($files)) {
        @unlink(job_dir_file($token));
    }

    $donePayload = [
        'exit'      => $exitCode,
        'elapsed'   => $elapsed,
        'timed_out' => $timedOut,
        'files'     => $files,
        'token'     => $token,
        'showfiles' => $item['showfiles'],
    ];

    // Log completion
    $authUser = function_exists('auth_user') ? auth_user() : 'anonymous';
    openlog('clido', LOG_PID, LOG_USER);
    syslog(LOG_INFO, "USER=$authUser ITEM=$itemName EXIT=$exitCode ELAPSED={$elapsed}s TMPDIR=$tmpDir");
    closelog();

    sse('done', json_encode($donePayload));
    exit;
}

// ---------------------------------------------------------------------------
// Action: cancel (POST)
// ---------------------------------------------------------------------------

function action_cancel(array $cfg): void {
    $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
    if (!$token) {
        http_response_code(400);
        echo json_encode(['error' => 'No token']);
        exit;
    }

    $pidFile     = job_pid_file($token);
    $sigkillWait = (int)($cfg['global']['sigkill_wait'] ?? 5);

    header('Content-Type: application/json');

    if (!file_exists($pidFile)) {
        echo json_encode(['status' => 'not_running']);
        exit;
    }

    $pid = (int)file_get_contents($pidFile);
    if ($pid > 0) {
        posix_kill(-$pid, SIGTERM);
        if ($sigkillWait > 0) {
            sleep($sigkillWait);
            posix_kill(-$pid, SIGKILL);
        } else {
            posix_kill(-$pid, SIGKILL);
        }
    }

    @unlink($pidFile);

    $authUser = function_exists('auth_user') ? auth_user() : 'anonymous';
    openlog('clido', LOG_PID, LOG_USER);
    syslog(LOG_INFO, "USER=$authUser ACTION=cancel TOKEN=$token PID=$pid");
    closelog();

    echo json_encode(['status' => 'killed', 'pid' => $pid]);
    exit;
}

// ---------------------------------------------------------------------------
// Action: download (GET)
// ---------------------------------------------------------------------------

function action_download(array $cfg): void {
    $token    = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
    $filename = $_GET['file'] ?? '';

    if (!$token || !$filename) {
        http_response_code(400);
        exit;
    }

    $dirFile = job_dir_file($token);
    if (!file_exists($dirFile)) {
        http_response_code(404);
        exit;
    }

    $tmpDir   = trim(file_get_contents($dirFile));
    // Path traversal prevention: resolve and verify it's inside the tmpDir
    $realDir  = realpath($tmpDir);
    $realFile = realpath($tmpDir . '/' . $filename);

    if (!$realDir || !$realFile || strpos($realFile, $realDir . '/') !== 0) {
        http_response_code(403);
        exit;
    }

    if (!is_file($realFile)) {
        http_response_code(404);
        exit;
    }

    $mime = mime_content_type($realFile) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . addslashes(basename($realFile)) . '"');
    header('Content-Length: ' . filesize($realFile));
    readfile($realFile);
    exit;
}

// ---------------------------------------------------------------------------
// Action: clean (POST — delete temp dir)
// ---------------------------------------------------------------------------

function action_clean(array $cfg): void {
    $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
    if (!$token) {
        http_response_code(400);
        echo json_encode(['error' => 'No token']);
        exit;
    }

    $dirFile = job_dir_file($token);
    header('Content-Type: application/json');

    if (!file_exists($dirFile)) {
        echo json_encode(['status' => 'already_gone']);
        exit;
    }

    $tmpDir = trim(file_get_contents($dirFile));
    if ($tmpDir && is_dir($tmpDir)) {
        clido_rmdir($tmpDir);
    }
    @unlink($dirFile);

    echo json_encode(['status' => 'cleaned']);
    exit;
}

function clido_rmdir(string $dir): void {
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . '/' . $f;
        is_dir($path) ? clido_rmdir($path) : unlink($path);
    }
    rmdir($dir);
}

// ---------------------------------------------------------------------------
// ttyd: terminal item helpers
// ---------------------------------------------------------------------------

function ttyd_pid_file(string $token): string {
    return sys_get_temp_dir() . "/clido_ttyd_pid_$token";
}

function ttyd_find_free_port(int $min, int $max): int|false {
    for ($port = $min; $port <= $max; $port++) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($sock) { fclose($sock); continue; }  // something already listening
        return $port;
    }
    return false;
}

// ---------------------------------------------------------------------------
// Action: ttyd_start (POST — spawn ttyd, return proxy URL)
// ---------------------------------------------------------------------------

function action_ttyd_start(array $cfg): void {
    $itemName = $_POST['item'] ?? '';
    $item     = $cfg['items'][$itemName] ?? null;

    if (!$item || !$item['ttyd'] || !$item['command']) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid item']);
        exit;
    }

    $portMin = (int)($cfg['global']['ttyd_port_min'] ?? 7681);
    $portMax = (int)($cfg['global']['ttyd_port_max'] ?? 7699);

    $port = ttyd_find_free_port($portMin, $portMax);
    if ($port === false) {
        http_response_code(503);
        echo json_encode(['error' => 'No ttyd ports available — all slots in use']);
        exit;
    }

    // 32-char random hex token embedded in the URL path — this is the auth secret.
    // proxyUrl and basePath are the same: ttyd's --base-path must equal the full
    // public path so its JS client constructs WebSocket URLs nginx can route.
    $token    = bin2hex(random_bytes(16));
    $proxyUrl = '/ttyd/' . $port . '/' . $token . '/';
    $basePath = $proxyUrl;

    $userInputs = array_filter($_POST, fn($k) => (bool)preg_match('/^input\d+$/', $k), ARRAY_FILTER_USE_KEY);
    $args       = assemble_args($item, $userInputs, '');  // empty tmpDir: file inputs emit nothing

    // Split command field into separate tokens so ttyd receives each word as its own
    // argv element — escapeshellarg-ing the whole string as one arg causes ttyd to exec
    // a binary whose filename contains spaces, which fails or adds an unwanted shell layer.
    $cmdTokens   = preg_split('/\s+/', trim($item['command']));
    $cmdForShell = implode(' ', array_map('escapeshellarg', $cmdTokens));

    $ttydBin = escapeshellarg($cfg['global']['ttyd_bin'] ?? 'ttyd');
    $ttydLog = sys_get_temp_dir() . '/clido_ttyd_' . $token . '.log';
    // setsid: new session → no inherited controlling terminal from PHP-FPM, and SIGHUP
    // stays at default so ttyd can actually signal children when the client disconnects.
    // nohup would set SIG_IGN which propagates through fork+exec and orphans processes.
    // </dev/null: clean stdin, not the FPM worker's FastCGI socket.
    $ttydCmd = 'setsid ' . $ttydBin
        . ' --port ' . (int)$port
        . ' --base-path ' . escapeshellarg($basePath)
        . ' --writable'
        . ' ' . $cmdForShell
        . ($args ? ' ' . implode(' ', $args) : '')
        . ' </dev/null > ' . escapeshellarg($ttydLog) . ' 2>&1 & echo $!';

    $pid = (int)shell_exec($ttydCmd);

    if (!$pid) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to start ttyd — is it installed and on PATH?']);
        exit;
    }

    file_put_contents(ttyd_pid_file($token), $pid);

    // Poll until ttyd is listening (up to 5 s)
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($sock) { fclose($sock); $ready = true; break; }
    }

    if (!$ready) {
        $ttydOutput = trim(@file_get_contents($ttydLog) ?: '(no output captured)');
        @unlink($ttydLog);
        posix_kill($pid, SIGKILL);
        @unlink(ttyd_pid_file($token));
        http_response_code(500);
        echo json_encode(['error' => 'ttyd did not start in time. Output: ' . $ttydOutput]);
        exit;
    }

    @unlink($ttydLog);

    $authUser = function_exists('auth_user') ? auth_user() : 'anonymous';
    $fullCmd  = $item['command'] . ($args ? ' ' . implode(' ', $args) : '');
    openlog('clido', LOG_PID, LOG_USER);
    syslog(LOG_INFO, "USER=$authUser ITEM=$itemName TTYD_PORT=$port CMD=$fullCmd");
    closelog();

    echo json_encode(['proxy_url' => $proxyUrl, 'pid_token' => $token]);
    exit;
}

// ---------------------------------------------------------------------------
// Action: ttyd_kill (POST — terminate a ttyd process by token)
// ---------------------------------------------------------------------------

function action_ttyd_kill(array $cfg): void {
    $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
    if (!$token) {
        http_response_code(400);
        echo json_encode(['error' => 'No token']);
        exit;
    }

    $pidFile = ttyd_pid_file($token);

    if (!file_exists($pidFile)) {
        echo json_encode(['status' => 'not_running']);
        exit;
    }

    $pid = (int)file_get_contents($pidFile);
    if ($pid > 0) posix_kill($pid, SIGTERM);
    @unlink($pidFile);

    $authUser = function_exists('auth_user') ? auth_user() : 'anonymous';
    openlog('clido', LOG_PID, LOG_USER);
    syslog(LOG_INFO, "USER=$authUser ACTION=ttyd_kill TOKEN=$token PID=$pid");
    closelog();

    echo json_encode(['status' => 'killed', 'pid' => $pid]);
    exit;
}

// ---------------------------------------------------------------------------
// Action: check_refs (POST — reports which uploaded file dirs still exist)
// ---------------------------------------------------------------------------

function action_check_refs(): void {
    $dirs    = (array)($_POST['dirs'] ?? []);
    $tmpBase = rtrim(sys_get_temp_dir(), '/') . '/clido_';
    $result  = [];

    foreach ($dirs as $dir) {
        $real = realpath($dir);
        if (!$real || strpos($real, $tmpBase) !== 0) continue;
        $result[$dir] = is_dir($real) && !empty(glob($real . '/*'));
    }

    echo json_encode($result);
    exit;
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

$cfg    = clido_parse_ini();
$global = $cfg['global'];

// Auth must happen before any output
clido_auth($global);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'exec') {
    header('Content-Type: application/json');
    action_exec($cfg);
} elseif ($action === 'stream') {
    action_stream($cfg);
} elseif ($action === 'cancel') {
    header('Content-Type: application/json');
    action_cancel($cfg);
} elseif ($action === 'download') {
    action_download($cfg);
} elseif ($action === 'clean') {
    header('Content-Type: application/json');
    action_clean($cfg);
} elseif ($action === 'check_refs') {
    header('Content-Type: application/json');
    action_check_refs();
} elseif ($action === 'ttyd_start') {
    header('Content-Type: application/json');
    action_ttyd_start($cfg);
} elseif ($action === 'ttyd_kill') {
    header('Content-Type: application/json');
    action_ttyd_kill($cfg);
}

// ---------------------------------------------------------------------------
// UI helpers
// ---------------------------------------------------------------------------

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function js(mixed $v): string {
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Render UI
// ---------------------------------------------------------------------------

$authUser = function_exists('auth_user') ? auth_user() : '';
$g        = $global;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($g['title']) ?></title>
<?php if ($g['favicon']): ?>
<link rel="icon" href="<?= h($g['favicon']) ?>">
<?php endif ?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:           <?= h($g['color_bg'])          ?>;
    --sidebar:      <?= h($g['color_sidebar'])      ?>;
    --accent:       <?= h($g['color_accent'])       ?>;
    --border:       <?= h($g['color_border'])       ?>;
    --text:         <?= h($g['color_text'])         ?>;
    --text-muted:   <?= h($g['color_text_muted'])   ?>;
    --btn:          <?= h($g['color_btn'])          ?>;
    --btn-text:     <?= h($g['color_btn_text'])     ?>;
    --cancel:       <?= h($g['color_cancel'])       ?>;
    --out-bg:       <?= h($g['color_output_bg'])    ?>;
    --out-text:     <?= h($g['color_output_text'])  ?>;
}

html, body {
    height: 100%;
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 14px;
    line-height: 1.5;
}

/* ── Layout ── */
#layout {
    display: flex;
    height: 100vh;
    overflow: hidden;
}

/* ── Sidebar ── */
#sidebar {
    width: 240px;
    min-width: 200px;
    max-width: 320px;
    background: var(--sidebar);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex-shrink: 0;
}

#sidebar-header {
    padding: 16px 14px 12px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}

#sidebar-logo {
    display: block;
    max-width: 100%;
    max-height: 64px;
    margin-bottom: 10px;
    object-fit: contain;
}

#sidebar-title {
    font-size: 1.1em;
    font-weight: 600;
    color: var(--text);
    letter-spacing: 0.02em;
}

#sidebar-user {
    font-size: 0.78em;
    color: var(--text-muted);
    margin-top: 2px;
}

#sidebar-user a {
    color: var(--text-muted);
    text-decoration: none;
}
#sidebar-user a:hover { text-decoration: underline; }

#menu {
    overflow-y: auto;
    flex: 1;
    padding: 8px 0;
}

.menu-group-header {
    padding: 14px 14px 4px;
    font-size: 0.7em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    user-select: none;
}

.menu-item {
    display: block;
    width: 100%;
    text-align: left;
    background: none;
    border: none;
    padding: 8px 14px;
    color: var(--text);
    cursor: pointer;
    font-size: 0.92em;
    border-radius: 4px;
    margin: 1px 6px;
    width: calc(100% - 12px);
    transition: background 0.1s;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.menu-item:hover:not(:disabled) {
    background: var(--accent);
}

.menu-item.active {
    background: var(--accent);
    color: var(--btn);
    font-weight: 600;
}

.menu-item:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.menu-item-row {
    display: flex;
    align-items: center;
}
.menu-item-row .menu-item {
    flex: 1;
    min-width: 0;
    width: auto;
}
.menu-popout {
    display: none;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--text-muted);
    padding: 4px 5px;
    margin-right: 6px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 0.85em;
    line-height: 1;
}
.menu-item-row:hover .menu-popout {
    display: flex;
}
.menu-popout:hover {
    color: var(--text);
    background: var(--accent);
}

#sidebar-footer {
    padding: 10px 14px;
    border-top: 1px solid var(--border);
    flex-shrink: 0;
}

/* ── Main pane ── */
#main {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-width: 0;
}

/* ── Toolbar ── */
#toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--border);
    background: var(--bg);
    flex-shrink: 0;
    flex-wrap: wrap;
}

#toolbar-title {
    font-size: 1em;
    font-weight: 600;
    color: var(--text);
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

#toolbar-desc {
    font-size: 0.82em;
    color: var(--text-muted);
}

#btn-cancel, #btn-ttyd-kill {
    color: #fff;
    border: none;
    padding: 6px 18px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9em;
    font-weight: 600;
    display: none;
}
#btn-cancel    { background: var(--cancel); }
#btn-ttyd-kill { background: #6e40c9; }
#btn-cancel:hover, #btn-ttyd-kill:hover { filter: brightness(1.15); }

/* ── Output ── */
#output-wrap {
    flex: 1;
    overflow: hidden;
    position: relative;
    display: flex;
    flex-direction: column;
}

#embed {
    flex: 1;
    overflow: hidden;
    padding-left: 3px;
}
#embed iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
}
#ttyd-pool {
    flex: 1;
    overflow: hidden;
    padding-left: 3px;
    position: relative;
}
.ttyd-frame {
    display: none;
    position: absolute;
    inset: 0;
}
.ttyd-frame.active { display: block; }
.ttyd-frame iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
}

#output {
    flex: 1;
    overflow-y: auto;
    background: var(--out-bg);
    color: var(--out-text);
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 0.85em;
    line-height: 1.55;
    padding: 14px 16px;
    white-space: pre-wrap;
    word-break: break-all;
}

#output .line-stderr        { color: #ff7b72; }
#output .line-command-label { color: #bf5af2; }
#output .line-command-text  { color: #ffffff; font-weight: 600; }

/* ── Stats bar ── */
#stats {
    background: var(--sidebar);
    border-top: 1px solid var(--border);
    padding: 6px 16px;
    font-size: 0.78em;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    flex-shrink: 0;
}

#stats .stat-ok  { color: #3fb950; }
#stats .stat-err { color: #ff7b72; }
#stats .stat-timeout { color: #e3b341; }

/* ── File listing ── */
#files-section {
    background: var(--sidebar);
    border-top: 1px solid var(--border);
    padding: 10px 16px;
    flex-shrink: 0;
    display: none;
}

#files-section h3 {
    font-size: 0.82em;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 8px;
}

#file-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-height: 150px;
    overflow-y: auto;
}

#file-list li {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.84em;
}

#file-list a {
    color: #58a6ff;
    text-decoration: none;
    font-family: monospace;
}
#file-list a:hover { text-decoration: underline; }

.file-meta {
    color: var(--text-muted);
    font-size: 0.9em;
    white-space: nowrap;
}

#btn-clean {
    background: none;
    border: 1px solid var(--border);
    color: var(--text-muted);
    padding: 4px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.8em;
    margin-top: 8px;
}
#btn-clean:hover {
    background: var(--accent);
    color: var(--text);
}

/* ── Overlay / modal ── */
#overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.65);
    z-index: 100;
    align-items: center;
    justify-content: center;
}
#overlay.open { display: flex; }

#modal {
    background: var(--sidebar);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 28px 28px 24px;
    min-width: 360px;
    max-width: 560px;
    width: 90vw;
    box-shadow: 0 16px 64px rgba(0,0,0,0.6);
}

#modal h2 {
    font-size: 1.05em;
    font-weight: 600;
    margin-bottom: 4px;
}

#modal p.modal-desc {
    font-size: 0.83em;
    color: var(--text-muted);
    margin-bottom: 20px;
}

.form-row {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-bottom: 14px;
}

.form-row label {
    font-size: 0.83em;
    color: var(--text-muted);
    font-weight: 500;
}

.form-row input[type="text"] {
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 7px 10px;
    border-radius: 4px;
    font-size: 0.92em;
    width: 100%;
    font-family: inherit;
    transition: border-color 0.15s;
}

.form-row input[type="text"]:focus {
    outline: none;
    border-color: var(--btn);
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

.btn-primary {
    background: var(--btn);
    color: var(--btn-text);
    border: none;
    padding: 8px 24px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.92em;
    font-weight: 600;
    transition: filter 0.1s;
}
.btn-primary:hover { filter: brightness(1.15); }

.btn-secondary {
    background: none;
    border: 1px solid var(--border);
    color: var(--text);
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.92em;
}
.btn-secondary:hover { background: var(--accent); }

/* ── Welcome / empty state ── */
#welcome {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    gap: 8px;
    text-align: center;
    padding: 24px;
}
#welcome .welcome-title {
    font-size: 1.2em;
    font-weight: 500;
    color: var(--text);
}
#welcome .welcome-sub {
    font-size: 0.85em;
}

/* ── Scrollbar styling ── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

/* ── History section inside modal ── */
#modal {
    max-height: 90vh;
    overflow-y: auto;
}

#modal-history {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}

.history-label {
    font-size: 0.7em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    margin-bottom: 8px;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82em;
}

.history-table th {
    text-align: left;
    color: var(--text-muted);
    font-weight: 500;
    padding: 3px 8px 5px 0;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}

.history-table td {
    padding: 5px 8px 5px 0;
    border-bottom: 1px solid var(--accent);
    color: var(--text);
    max-width: 140px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
}

.history-table tr:last-child td { border-bottom: none; }

.history-actions {
    text-align: right;
    white-space: nowrap;
    width: 1%;
    max-width: none !important;
}

.hist-btn {
    background: none;
    border: 1px solid var(--border);
    color: var(--text-muted);
    padding: 2px 6px;
    border-radius: 3px;
    cursor: pointer;
    font-size: 0.8em;
    margin-left: 3px;
    line-height: 1.4;
    transition: background 0.1s, color 0.1s, border-color 0.1s;
}
.hist-btn:hover { background: var(--accent); color: var(--text); }

.hist-btn.hist-run {
    color: var(--btn);
    border-color: var(--btn);
    font-weight: 600;
    font-size: 0.72em;
    letter-spacing: 0.02em;
}
.hist-btn.hist-run:hover { background: var(--btn); color: var(--btn-text); border-color: var(--btn); }

.hist-btn.hist-del:hover { background: var(--cancel); color: #fff; border-color: var(--cancel); }

/* ── Checkbox input ── */
.form-row-checkbox { margin-bottom: 10px; }

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 0.92em;
    color: var(--text);
    user-select: none;
}

.checkbox-label input[type="checkbox"] {
    width: 15px;
    height: 15px;
    accent-color: var(--btn);
    cursor: pointer;
    flex-shrink: 0;
}

/* ── Radio group ── */
.field-label {
    display: block;
    font-size: 0.83em;
    color: var(--text-muted);
    font-weight: 500;
    margin-bottom: 6px;
}

.radio-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.radio-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 0.92em;
    color: var(--text);
    user-select: none;
}

.radio-label input[type="radio"] {
    accent-color: var(--btn);
    cursor: pointer;
    flex-shrink: 0;
}

/* ── Select dropdown ── */
.form-row select {
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 7px 10px;
    border-radius: 4px;
    font-size: 0.92em;
    width: 100%;
    font-family: inherit;
    cursor: pointer;
    transition: border-color 0.15s;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%238b949e' d='M6 8L0 0h12z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    padding-right: 28px;
}

.form-row select:focus {
    outline: none;
    border-color: var(--btn);
}

.form-row select option { background: var(--sidebar); }

/* ── File drop zone ── */
.file-drop-zone {
    border: 2px dashed var(--border);
    border-radius: 6px;
    padding: 18px 14px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
    position: relative;
}
.file-drop-zone:hover,
.file-drop-zone.drag-over {
    border-color: var(--btn);
    background: var(--accent);
}
.file-drop-icon {
    font-size: 1.4em;
    display: block;
    margin-bottom: 4px;
    color: var(--text-muted);
}
.file-drop-text {
    font-size: 0.85em;
    color: var(--text-muted);
}
.file-browse-link {
    color: var(--btn);
    text-decoration: underline;
    cursor: pointer;
}
.file-names-preview {
    list-style: none;
    margin-top: 8px;
    text-align: left;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.file-names-preview li {
    font-size: 0.8em;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 6px;
}
.file-names-preview .file-size {
    color: var(--text-muted);
    white-space: nowrap;
}
</style>
</head>
<body>

<div id="layout">

  <!-- ── Sidebar ── -->
  <nav id="sidebar">
    <div id="sidebar-header">
      <?php if ($g['logo']): ?>
      <img id="sidebar-logo" src="<?= h($g['logo']) ?>" alt="<?= h($g['title']) ?>">
      <?php endif ?>
      <div id="sidebar-title"><?= h($g['title']) ?></div>
      <?php if ($authUser && $g['logout_url']): ?>
      <div id="sidebar-user">
        <?= h($authUser) ?> &mdash;
        <a href="<?= h($g['logout_url']) ?>">sign out</a>
      </div>
      <?php elseif ($authUser): ?>
      <div id="sidebar-user"><?= h($authUser) ?></div>
      <?php endif ?>
    </div>

    <div id="menu">
<?php
$inGroup = false;
foreach ($cfg['groups'] as $entry) {
    if ($entry['type'] === 'group') {
        $inGroup = true;
        echo '<div class="menu-group-header">' . h($entry['cfg']['title'] ?? $entry['name']) . '</div>';
    } elseif ($entry['type'] === 'item') {
        $item   = $entry['cfg'];
        $title  = h($item['title']);
        $desc   = h($item['description']);
        $itemJs = js($item['name']);
        $btn    = "<button class=\"menu-item\" data-item={$itemJs} title=\"{$desc}\">{$title}</button>";
        if ($item['url']) {
            $url  = h($item['url']);
            $icon = "<a class=\"menu-popout\" href=\"{$url}\" target=\"_blank\" rel=\"noopener\" title=\"Open in new tab\">&#8599;</a>";
            echo "<div class=\"menu-item-row\">{$btn}{$icon}</div>\n";
        } else {
            echo $btn . "\n";
        }
    }
}
?>
    </div>

    <div id="sidebar-footer">
      <span style="font-size:0.72em;color:var(--text-muted)">clido v<?= CLIDO_VERSION ?></span>
    </div>
  </nav>

  <!-- ── Main ── -->
  <div id="main">

    <!-- Toolbar -->
    <div id="toolbar">
      <span id="toolbar-title" style="color:var(--text-muted)">Select an item from the menu</span>
      <span id="toolbar-desc"></span>
      <button id="btn-cancel">&#9632; CANCEL</button>
      <button id="btn-ttyd-kill">&#9632; KILL SESSION</button>
    </div>

    <!-- Output area -->
    <div id="output-wrap">
      <div id="welcome">
        <div class="welcome-title"><?= h($g['title']) ?></div>
        <div class="welcome-sub">Select a function from the left menu to get started.</div>
      </div>
      <div id="output" style="display:none"></div>
    </div>

    <!-- Embedded URL pane -->
    <div id="embed" style="display:none"></div>
    <div id="ttyd-pool" style="display:none"></div>

    <!-- Stats bar -->
    <div id="stats" style="display:none"></div>

    <!-- File listing -->
    <div id="files-section">
      <h3>Output Files</h3>
      <ul id="file-list"></ul>
      <button id="btn-clean">&#128465; CLEAN temp directory</button>
    </div>

  </div><!-- /#main -->
</div><!-- /#layout -->

<!-- ── Input overlay ── -->
<div id="overlay">
  <div id="modal">
    <h2 id="modal-title"></h2>
    <p class="modal-desc" id="modal-desc"></p>
    <form id="modal-form">
      <div id="modal-fields"></div>
      <div id="modal-history" style="display:none">
        <div class="history-label">Previous runs</div>
        <div id="modal-history-body"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary" id="btn-modal-cancel">Cancel</button>
        <button type="submit" class="btn-primary" id="btn-execute">EXECUTE</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Item definitions (server-side rendered) ──
const ITEMS = <?= js(array_map(function($i) {
    return [
        'name'         => $i['name'],
        'title'        => $i['title'],
        'description'  => $i['description'],
        'url'          => $i['url'],
        'inputs'       => $i['inputs'],
        'execute_text' => $i['execute_text'],
        'showfiles'    => $i['showfiles'],
        'ttyd'         => $i['ttyd'],
    ];
}, $cfg['items'])) ?>;

const HISTORY_QTY = <?= (int)($g['history_qty']) ?>;

// ── State ──
let running      = false;
let activeToken  = null;
let activeItem   = null;
let eventSource  = null;
const ttydSessions = {};  // item.name → { proxyUrl, pidToken }

// ── Element refs ──
const $menu          = document.getElementById('menu');
const $toolbar       = document.getElementById('toolbar-title');
const $toolDesc      = document.getElementById('toolbar-desc');
const $output        = document.getElementById('output');
const $outputWrap    = document.getElementById('output-wrap');
const $embed         = document.getElementById('embed');
const $welcome       = document.getElementById('welcome');
const $stats         = document.getElementById('stats');
const $filesSection  = document.getElementById('files-section');
const $fileList      = document.getElementById('file-list');
const $ttydPool      = document.getElementById('ttyd-pool');
const $btnCancel     = document.getElementById('btn-cancel');
const $btnTtydKill   = document.getElementById('btn-ttyd-kill');
const $btnClean      = document.getElementById('btn-clean');
const $overlay       = document.getElementById('overlay');
const $modal         = document.getElementById('modal');
const $modalTitle    = document.getElementById('modal-title');
const $modalDesc     = document.getElementById('modal-desc');
const $modalFields   = document.getElementById('modal-fields');
const $modalHistory  = document.getElementById('modal-history');
const $modalHistBody = document.getElementById('modal-history-body');
const $btnExec       = document.getElementById('btn-execute');
const $modalForm     = document.getElementById('modal-form');

// ── History helpers ──
function histKey(itemName) { return 'clido:history:' + itemName; }

function loadHistory(itemName) {
    try { return JSON.parse(localStorage.getItem(histKey(itemName)) || '[]'); }
    catch { return []; }
}

function saveHistory(itemName, inputs, fileRefs = {}) {
    const hasText  = Object.values(inputs).some(v => String(v).trim());
    const hasFiles = Object.keys(fileRefs).length > 0;
    if (!hasText && !hasFiles) return;

    let hist = loadHistory(itemName);
    const entry = { inputs, ts: Math.floor(Date.now() / 1000) };
    if (hasFiles) entry.file_refs = fileRefs;

    // Only deduplicate text-only entries; file runs are always unique per tmpDir
    if (!hasFiles) {
        const sig = JSON.stringify(inputs);
        hist = hist.filter(e => !e.file_refs && JSON.stringify(e.inputs) !== sig);
    }

    hist.unshift(entry);
    hist = hist.slice(0, HISTORY_QTY);
    localStorage.setItem(histKey(itemName), JSON.stringify(hist));
}

function deleteHistoryEntry(itemName, idx) {
    const hist = loadHistory(itemName);
    hist.splice(idx, 1);
    localStorage.setItem(histKey(itemName), JSON.stringify(hist));
}

function histDisplayVal(inp, val) {
    if (inp.type === 'checkbox') return val === 'on' ? '✓' : '✗';
    if (inp.type === 'radio' || inp.type === 'select') {
        const opt = (inp.options || []).find(o => o.value === val);
        return opt ? (opt.desc || opt.value) : val;
    }
    return val;
}

function renderHistory(item, dirStatus = {}) {
    const hist = loadHistory(item.name);
    if (!hist.length) {
        $modalHistory.style.display = 'none';
        return;
    }

    const slots     = Object.entries(item.inputs || {}).sort((a,b) => Number(a[0]) - Number(b[0]));
    const execText  = escHtml(item.execute_text || 'EXECUTE');
    const fileTypes = ['single_file', 'file_list', 'file_each'];

    let html = '<table class="history-table"><thead><tr>';
    slots.forEach(([n, inp]) => {
        const label = fileTypes.includes(inp.type)
            ? (inp.desc || ('File ' + n))
            : (inp.desc || ('Input ' + n));
        html += `<th>${escHtml(label)}</th>`;
    });
    html += '<th></th></tr></thead><tbody>';

    hist.forEach((entry, idx) => {
        let anyMissing = false;
        let rowCells   = '';

        slots.forEach(([n, inp]) => {
            if (fileTypes.includes(inp.type)) {
                const paths = (entry.file_refs || {})['input' + n] || [];
                if (!paths.length) {
                    rowCells += `<td class="muted">—</td>`;
                } else {
                    const dir   = paths[0].substring(0, paths[0].lastIndexOf('/'));
                    const avail = dirStatus[dir];
                    const icon  = avail === false ? '⚠' : '📁';
                    const names = paths.map(p => p.substring(p.lastIndexOf('/') + 1)).join(', ');
                    const trunc = names.length > 32 ? names.slice(0, 32) + '…' : names;
                    const style = avail === false ? ' style="color:var(--cancel)"' : '';
                    if (avail === false) anyMissing = true;
                    rowCells += `<td${style} title="${escHtml(names)}">${icon} ${escHtml(trunc)}</td>`;
                }
            } else {
                const val     = entry.inputs['input' + n] ?? '';
                const display = histDisplayVal(inp, val);
                rowCells += `<td title="${escHtml(String(val))}">${escHtml(String(display))}</td>`;
            }
        });

        const disRun = anyMissing ? ' disabled title="Uploaded files are no longer available"' : '';
        rowCells += `<td class="history-actions">
            <button type="button" class="hist-btn hist-run" data-idx="${idx}"${disRun}>${execText}</button>
            <button type="button" class="hist-btn hist-edit" data-idx="${idx}" title="Pre-fill fields">&#9998;</button>
            <button type="button" class="hist-btn hist-del"  data-idx="${idx}" title="Remove from history">&times;</button>
        </td>`;
        html += `<tr>${rowCells}</tr>`;
    });

    html += '</tbody></table>';
    $modalHistBody.innerHTML = html;
    $modalHistory.style.display = 'block';

    // Run button: close modal and execute with saved file refs
    $modalHistBody.querySelectorAll('.hist-run').forEach(btn => {
        btn.addEventListener('click', () => {
            const entry = loadHistory(item.name)[Number(btn.dataset.idx)];
            if (!entry) return;
            $overlay.classList.remove('open');
            startExec(item, entry.inputs, entry.file_refs || {}, null);
        });
    });

    // Edit button: pre-fill non-file form fields, keep modal open
    $modalHistBody.querySelectorAll('.hist-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            const entry = loadHistory(item.name)[Number(btn.dataset.idx)];
            if (!entry) return;
            Object.entries(entry.inputs).forEach(([k, v]) => {
                const fields = $modalFields.querySelectorAll(`[name="${k}"]`);
                if (!fields.length) return;
                const first = fields[0];
                if (first.type === 'checkbox') {
                    first.checked = (v === 'on');
                } else if (first.type === 'radio') {
                    fields.forEach(r => { r.checked = (r.value === v); });
                } else {
                    first.value = v;
                }
            });
            const firstInput = $modalFields.querySelector('input[type="text"], select');
            if (firstInput) firstInput.focus();
        });
    });

    // Delete button: remove entry and re-render with fresh status check
    $modalHistBody.querySelectorAll('.hist-del').forEach(btn => {
        btn.addEventListener('click', () => {
            deleteHistoryEntry(item.name, Number(btn.dataset.idx));
            renderHistory(item, {});
            checkFileRefsAndUpdate(item);
        });
    });
}

async function checkFileRefsAndUpdate(item) {
    const hist = loadHistory(item.name);
    const dirs = new Set();
    hist.forEach(e => {
        if (!e.file_refs) return;
        Object.values(e.file_refs).forEach(paths =>
            paths.forEach(p => dirs.add(p.substring(0, p.lastIndexOf('/'))))
        );
    });
    if (!dirs.size) return;

    const fd = new FormData();
    [...dirs].forEach(d => fd.append('dirs[]', d));
    try {
        const res = await fetch(`${location.pathname}?action=check_refs`, { method: 'POST', body: fd });
        if (!res.ok) return;
        const dirStatus = await res.json();
        renderHistory(item, dirStatus);
    } catch { /* ignore */ }
}

function initDropZones() {
    $modalFields.querySelectorAll('.file-drop-zone').forEach(zone => {
        const input   = zone.querySelector('.file-input-hidden');
        const preview = zone.querySelector('.file-names-preview');

        zone.addEventListener('click', e => {
            if (!e.target.closest('.file-names-preview')) input.click();
        });
        zone.addEventListener('dragenter', e => { e.preventDefault(); zone.classList.add('drag-over'); });
        zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
        zone.addEventListener('dragleave', e => {
            if (!zone.contains(e.relatedTarget)) zone.classList.remove('drag-over');
        });
        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('drag-over');
            const dt    = new DataTransfer();
            const files = input.multiple ? [...e.dataTransfer.files] : [e.dataTransfer.files[0]];
            files.filter(Boolean).forEach(f => dt.items.add(f));
            input.files = dt.files;
            updateFilePreview(preview, input.files);
        });
        input.addEventListener('change', () => updateFilePreview(preview, input.files));
    });
}

function updateFilePreview(preview, files) {
    preview.innerHTML = '';
    [...files].forEach(f => {
        const li = document.createElement('li');
        li.innerHTML = `<span>&#128196; ${escHtml(f.name)}</span><span class="file-size">${formatBytes(f.size)}</span>`;
        preview.appendChild(li);
    });
}

// ── Menu click handler ──
$menu.addEventListener('click', e => {
    const btn = e.target.closest('.menu-item');
    if (!btn || running) return;
    const name = btn.dataset.item;
    const item = ITEMS[name];
    if (!item) return;
    openItem(item);
});

function showEmbed(url) {
    $outputWrap.style.display   = 'none';
    $stats.style.display        = 'none';
    $filesSection.style.display = 'none';
    hideTtydPool();
    $embed.innerHTML = '';
    const iframe = document.createElement('iframe');
    iframe.src = url;
    $embed.appendChild(iframe);
    $embed.style.display = 'flex';
}

function hideTtydPool() {
    $ttydPool.querySelectorAll('.ttyd-frame').forEach(f => f.classList.remove('active'));
    $ttydPool.style.display = 'none';
}

function activateTtydFrame(itemName) {
    $outputWrap.style.display   = 'none';
    $stats.style.display        = 'none';
    $filesSection.style.display = 'none';
    $embed.style.display        = 'none';
    $ttydPool.querySelectorAll('.ttyd-frame').forEach(f => f.classList.remove('active'));
    $ttydPool.querySelector(`.ttyd-frame[data-item="${CSS.escape(itemName)}"]`)?.classList.add('active');
    $ttydPool.style.display = 'flex';
}

function openItem(item) {
    activeItem = item;
    document.querySelectorAll('.menu-item').forEach(b => b.classList.remove('active'));
    document.querySelector(`.menu-item[data-item="${CSS.escape(item.name)}"]`)?.classList.add('active');

    $toolbar.textContent  = item.title;
    $toolbar.style.color  = 'var(--text)';
    $toolDesc.textContent = item.description || '';
    $btnTtydKill.style.display = 'none';

    if (item.url) {
        showEmbed(item.url);
        return;
    }

    if (item.ttyd) {
        $stats.style.display        = 'none';
        $filesSection.style.display = 'none';
        if (ttydSessions[item.name]) {
            // Resume — show the persistent iframe (WebSocket and child process stay alive)
            activateTtydFrame(item.name);
            $btnTtydKill.style.display = 'inline-block';
        } else {
            hideTtydPool();
            $embed.style.display      = 'none';
            $embed.innerHTML          = '';
            $outputWrap.style.display = 'none';
            if (Object.keys(item.inputs || {}).length === 0) {
                launchTtyd(item, {});
            } else {
                openModalFor(item);
            }
        }
        return;
    }

    // Command / URL item — hide the ttyd pool and restore the output pane
    hideTtydPool();
    $embed.style.display      = 'none';
    $embed.innerHTML          = '';
    $outputWrap.style.display = '';

    if (Object.keys(item.inputs || {}).length === 0) {
        startExec(item, {});
    } else {
        openModalFor(item);
    }
}

function openModalFor(item) {
    $modalTitle.textContent = item.title;
    $modalDesc.textContent  = item.description || '';
    $btnExec.textContent    = item.execute_text || 'EXECUTE';
    $modalFields.innerHTML  = '';

    Object.entries(item.inputs || {}).sort((a,b) => Number(a[0]) - Number(b[0])).forEach(([n, inp]) => {
        const row = document.createElement('div');
        const id  = `input_field_${n}`;

        if (inp.type === 'checkbox') {
            row.className = 'form-row form-row-checkbox';
            const chk = inp.default === 'checked' ? 'checked' : '';
            row.innerHTML = `<label class="checkbox-label">
                    <input type="checkbox" id="${id}" name="input${n}" value="on" ${chk}>
                    <span>${escHtml(inp.desc || ('Option ' + n))}</span>
                </label>`;

        } else if (inp.type === 'radio') {
            row.className = 'form-row';
            let html = `<span class="field-label">${escHtml(inp.desc || ('Option ' + n))}</span>
                    <div class="radio-group">`;
            (inp.options || []).forEach(opt => {
                const chk = opt.value === inp.default ? 'checked' : '';
                html += `<label class="radio-label">
                        <input type="radio" name="input${n}" value="${escHtml(opt.value)}" ${chk}>
                        <span>${escHtml(opt.desc || opt.value)}</span>
                    </label>`;
            });
            html += `</div>`;
            row.innerHTML = html;

        } else if (inp.type === 'select') {
            row.className = 'form-row';
            let html = `<label for="${id}">${escHtml(inp.desc || ('Option ' + n))}</label>
                    <select id="${id}" name="input${n}">`;
            (inp.options || []).forEach(opt => {
                const sel = opt.value === inp.default ? 'selected' : '';
                html += `<option value="${escHtml(opt.value)}" ${sel}>${escHtml(opt.desc || opt.value)}</option>`;
            });
            html += `</select>`;
            row.innerHTML = html;

        } else if (['single_file', 'file_list', 'file_each'].includes(inp.type)) {
            row.className = 'form-row';
            const multi = inp.type !== 'single_file' ? 'multiple' : '';
            row.innerHTML = `
                    <label>${escHtml(inp.desc || ('File ' + n))}</label>
                    <div class="file-drop-zone" data-slot="${n}">
                        <input type="file" class="file-input-hidden" name="file_input_${n}" ${multi} style="display:none" tabindex="-1">
                        <span class="file-drop-icon">&#8679;</span>
                        <span class="file-drop-text">Drop ${inp.type !== 'single_file' ? 'files' : 'a file'} here or <span class="file-browse-link">browse</span></span>
                        <ul class="file-names-preview"></ul>
                    </div>
                `;

        } else {
            row.className = 'form-row';
            row.innerHTML = `
                    <label for="${id}">${escHtml(inp.desc || ('Input ' + n))}</label>
                    <input type="text" id="${id}" name="input${n}" autocomplete="off">
                `;
        }

        $modalFields.appendChild(row);
    });

    initDropZones();

    if (item.ttyd) {
        $modalHistory.style.display = 'none';
    } else {
        renderHistory(item, {});
        checkFileRefsAndUpdate(item);
    }

    $overlay.classList.add('open');
    const first = $modalFields.querySelector('input[type="text"], input[type="checkbox"], input[type="radio"], select');
    if (first) setTimeout(() => first.focus(), 50);
}

// ── Modal form submit ──
$modalForm.addEventListener('submit', e => {
    e.preventDefault();
    if (running) return;
    const data = new FormData($modalForm);
    const inputs = {};
    for (const [k, v] of data.entries()) {
        if (k.startsWith('file_input_') || v instanceof File) continue;
        inputs[k] = v;
    }
    // FormData omits unchecked checkboxes — capture them explicitly as 'off'
    $modalForm.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        if (!(cb.name in inputs)) inputs[cb.name] = 'off';
    });
    const fileInputs = [...$modalForm.querySelectorAll('input[type="file"]')].filter(fi => fi.files.length > 0);
    $overlay.classList.remove('open');
    if (activeItem && activeItem.ttyd) {
        launchTtyd(activeItem, inputs);
    } else {
        startExec(activeItem, inputs, {}, fileInputs.length ? fileInputs : null);
    }
});

document.getElementById('btn-modal-cancel').addEventListener('click', () => {
    $overlay.classList.remove('open');
});

$overlay.addEventListener('click', e => {
    if (e.target === $overlay) $overlay.classList.remove('open');
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') $overlay.classList.remove('open');
});

// ── Start execution ──
async function startExec(item, inputs, fileRefs = {}, formFileInputs = null) {
    $welcome.style.display = 'none';
    $output.style.display  = 'block';
    $output.innerHTML      = '';
    $stats.style.display   = 'none';
    $stats.innerHTML       = '';
    $filesSection.style.display = 'none';
    $fileList.innerHTML    = '';

    setRunning(true);

    const hasUploads = formFileInputs && formFileInputs.length > 0;
    const hasRefs    = Object.keys(fileRefs).length > 0;
    if (hasUploads) appendOutput('[clido] Uploading files…', 'stderr');
    else if (hasRefs) appendOutput('[clido] Preparing files from previous run…', 'stderr');

    const formData = new FormData();
    formData.append('action', 'exec');
    formData.append('item', item.name);
    for (const [k, v] of Object.entries(inputs)) formData.append(k, v);

    // File paths from a history re-run — PHP copies them into the new tmpDir
    for (const [inputKey, paths] of Object.entries(fileRefs)) {
        const n = inputKey.replace('input', '');
        paths.forEach(p => formData.append(`file_ref_${n}[]`, p));
    }

    // Actual file uploads from the modal — use fi.name+'[]' so PHP builds the array in $_FILES
    if (formFileInputs) {
        formFileInputs.forEach(fi => {
            for (const file of fi.files) formData.append(fi.name + '[]', file);
        });
    }

    let token;
    try {
        const res = await fetch(location.pathname, { method: 'POST', body: formData });
        if (res.redirected || !res.headers.get('content-type')?.includes('json')) {
            appendOutput('[clido] Session expired — please reload the page to log in again.', 'stderr');
            setRunning(false);
            return;
        }
        const json = await res.json();
        if (json.error) throw new Error(json.error);
        token = json.token;
        // Save history after exec so file_refs paths (assigned by server) are captured
        const savedRefs = json.file_refs || {};
        if (Object.keys(inputs).length > 0 || Object.keys(savedRefs).length > 0) {
            saveHistory(item.name, inputs, savedRefs);
        }
    } catch (err) {
        appendOutput('[clido error] Failed to start: ' + err.message, 'stderr');
        setRunning(false);
        return;
    }

    activeToken = token;

    const params = new URLSearchParams({ action: 'stream', token, item: item.name });
    for (const [k, v] of Object.entries(inputs)) params.set(k, v);

    eventSource = new EventSource(location.pathname + '?' + params.toString());

    eventSource.addEventListener('command', e => { appendCommand(e.data); });
    eventSource.addEventListener('output',  e => { appendOutput(e.data, 'stdout'); });
    eventSource.addEventListener('stderr',  e => { appendOutput(e.data, 'stderr'); });

    eventSource.addEventListener('done', e => {
        eventSource.close();
        eventSource = null;
        const d = JSON.parse(e.data);
        showStats(d);
        if (d.showfiles && d.files && d.files.length > 0) showFiles(d.files, d.token);
        setRunning(false);
        activeToken = null;
    });

    eventSource.addEventListener('error', e => {
        if (eventSource.readyState === EventSource.CLOSED) return;
        appendOutput('[clido] Connection lost.', 'stderr');
        eventSource.close();
        eventSource = null;
        setRunning(false);
        activeToken = null;
    });
}

// ── ttyd: launch terminal item ──
async function launchTtyd(item, inputs) {
    $outputWrap.style.display = 'none';
    $embed.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);font-family:monospace">Launching terminal…</div>';
    $embed.style.display = 'flex';

    const formData = new FormData();
    formData.append('action', 'ttyd_start');
    formData.append('item', item.name);
    for (const [k, v] of Object.entries(inputs)) formData.append(k, v);

    try {
        const res  = await fetch(location.pathname, { method: 'POST', body: formData });
        if (res.redirected) {
            showTtydError('Session expired — please reload the page to log in again.');
            return;
        }
        const raw = await res.text();
        if (!raw.trim()) {
            showTtydError('Server returned an empty response (HTTP ' + res.status + '). Check the PHP / web server error log.');
            console.error('[clido] ttyd_start: empty response body, HTTP', res.status);
            return;
        }
        let json;
        try { json = JSON.parse(raw); }
        catch {
            showTtydError('Server returned a non-JSON response (HTTP ' + res.status + '). Check the PHP error log.');
            console.error('[clido] ttyd_start raw response:', raw.slice(0, 500));
            return;
        }
        if (json.error) throw new Error(json.error);
        ttydSessions[item.name] = { proxyUrl: json.proxy_url, pidToken: json.pid_token };
        // Create a persistent frame in the pool — the iframe stays alive when switching items
        const frame = document.createElement('div');
        frame.className  = 'ttyd-frame';
        frame.dataset.item = item.name;
        const ifrm = document.createElement('iframe');
        ifrm.src = json.proxy_url;
        frame.appendChild(ifrm);
        $ttydPool.appendChild(frame);
        activateTtydFrame(item.name);
        $embed.style.display = 'none';
        $embed.innerHTML     = '';
        $btnTtydKill.style.display = 'inline-block';
        ifrm.addEventListener('load', () => {
            try { ifrm.contentWindow.dispatchEvent(new Event('resize')); } catch (_) {}
        }, { once: true });
    } catch (err) {
        showTtydError('Failed to start terminal: ' + err.message);
    }
}

function showTtydError(msg) {
    $embed.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#ff7b72;font-family:monospace;padding:1em;text-align:center">${escHtml(msg)}</div>`;
    $embed.style.display = 'flex';
}

// ── ttyd: kill session button ──
$btnTtydKill.addEventListener('click', () => {
    if (!activeItem) return;
    const session = ttydSessions[activeItem.name];
    if (!session) return;

    const { pidToken } = session;
    delete ttydSessions[activeItem.name];

    const deadFrame = $ttydPool.querySelector(`.ttyd-frame[data-item="${CSS.escape(activeItem.name)}"]`);
    if (deadFrame) deadFrame.remove();
    hideTtydPool();
    $outputWrap.style.display   = '';
    $welcome.style.display      = '';
    $output.style.display       = 'none';
    $output.innerHTML           = '';
    $stats.style.display        = 'none';
    $btnTtydKill.style.display  = 'none';

    const fd = new FormData();
    fd.append('action', 'ttyd_kill');
    fd.append('token',  pidToken);
    fetch(location.pathname, { method: 'POST', body: fd }).catch(() => {});
});

// ── ttyd: clean up all sessions on page unload ──
window.addEventListener('beforeunload', () => {
    for (const session of Object.values(ttydSessions)) {
        const fd = new FormData();
        fd.append('action', 'ttyd_kill');
        fd.append('token',  session.pidToken);
        navigator.sendBeacon(location.pathname, fd);
    }
});

// ── Cancel ──
$btnCancel.addEventListener('click', async () => {
    if (!activeToken) return;
    const tok = activeToken;
    const fd  = new FormData();
    fd.append('action', 'cancel');
    fd.append('token',  tok);

    if (eventSource) { eventSource.close(); eventSource = null; }
    activeToken = null;
    setRunning(false);

    appendOutput('\n[clido] Cancelling…', 'stderr');
    await fetch(location.pathname, { method: 'POST', body: fd });
    appendOutput('[clido] Cancelled.', 'stderr');
});

// ── Clean temp dir ──
$btnClean.addEventListener('click', async () => {
    const tok = $btnClean.dataset.token;
    if (!tok) return;
    const fd = new FormData();
    fd.append('action', 'clean');
    fd.append('token',  tok);
    await fetch(location.pathname, { method: 'POST', body: fd });
    $filesSection.style.display = 'none';
    $fileList.innerHTML = '';
});

// ── UI helpers ──
function setRunning(yes) {
    running = yes;
    document.querySelectorAll('.menu-item').forEach(b => b.disabled = yes);
    $btnCancel.style.display = yes ? 'inline-block' : 'none';
}

function appendCommand(cmd) {
    const wrap  = document.createElement('span');
    const label = document.createElement('span');
    const text  = document.createElement('span');
    label.className   = 'line-command-label';
    text.className    = 'line-command-text';
    label.textContent = 'Executing: ';
    text.textContent  = cmd + '\n';
    wrap.appendChild(label);
    wrap.appendChild(text);
    $output.appendChild(wrap);
    $output.scrollTop = $output.scrollHeight;
}

function appendOutput(text, type) {
    const span = document.createElement('span');
    span.textContent = text;
    if (type === 'stderr') span.className = 'line-stderr';
    $output.appendChild(span);
    $output.scrollTop = $output.scrollHeight;
}

function showStats(d) {
    $stats.style.display = 'flex';
    let html = '';
    if (d.timed_out) {
        html += `<span class="stat-timeout">&#9888; TIMED OUT</span>`;
    } else {
        const cls  = d.exit === 0 ? 'stat-ok' : 'stat-err';
        const icon = d.exit === 0 ? '&#10003;' : '&#10007;';
        html += `<span class="${cls}">${icon} Exit: ${d.exit}</span>`;
    }
    html += `<span>&#128336; ${d.elapsed}s</span>`;
    $stats.innerHTML = html;
}

function showFiles(files, token) {
    $filesSection.style.display = 'block';
    $fileList.innerHTML = '';
    $btnClean.dataset.token = token;

    files.forEach(f => {
        const li    = document.createElement('li');
        const url   = `${location.pathname}?action=download&token=${encodeURIComponent(token)}&file=${encodeURIComponent(f.name)}`;
        const size  = formatBytes(f.size);
        const mtime = new Date(f.mtime * 1000).toLocaleString();
        li.innerHTML = `<a href="${escHtml(url)}" download="${escHtml(f.name)}">${escHtml(f.name)}</a>
            <span class="file-meta">${escHtml(size)} &mdash; ${escHtml(mtime)}</span>`;
        $fileList.appendChild(li);
    });
}

function formatBytes(n) {
    if (n < 1024)       return n + ' B';
    if (n < 1048576)    return (n/1024).toFixed(1) + ' KB';
    if (n < 1073741824) return (n/1048576).toFixed(1) + ' MB';
    return (n/1073741824).toFixed(1) + ' GB';
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
