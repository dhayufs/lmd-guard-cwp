<?php
// ======================================================================
// LMD Guard CWP - v3.4 PRO Edition
// Full file: place at /usr/local/cwpsrv/htdocs/resources/admin/modules/lmd_manager.php
// Features:
// - Robust AJAX handlers for CWP environment
// - Safe JSON responses (no accidental HTML)
// - Toggle inotify (kill old, start monitor /home/)
// - Start scans (full/recent) with background jobs and job log
// - Quarantine hybrid listing (maldet list fallback to folder read)
// - Telegram notifications on user actions (ON/OFF/scan/update/save/test)
// - Toast notifications in dashboard UI
// - Polling with backoff and HTML-detection safety
// ======================================================================

if (php_sapi_name() !== 'cli') {
    $script = basename($_SERVER['SCRIPT_FILENAME']);
    // allow AJAX direct calls or included through index.php
    if ($script !== 'index.php' && !isset($_REQUEST['action_type'])) {
        echo "invalid access";
        exit();
    }
}

// -------------------- CONFIG / CONSTANTS --------------------
define('LMD_BIN', (file_exists('/usr/local/sbin/maldet') ? '/usr/local/sbin/maldet' : 'maldet'));
define('LMD_CONF', '/usr/local/maldetect/conf.maldet');
define('LMD_QUARANTINE_DIR', '/usr/local/maldetect/quarantine');
define('LMD_TMP_DIR', '/usr/local/maldetect/tmp');
define('LMD_JOB_LOG_DIR', '/var/log/maldet_ui');
define('LMD_CONFIG_FILE', '/etc/cwp/lmd_config.json');

// Ensure job log dir exists
if (!file_exists(LMD_JOB_LOG_DIR)) {
    @mkdir(LMD_JOB_LOG_DIR, 0750, true);
}

// Load config (Telegram+mode)
$lmd_config = ['token'=>'','chat_id'=>'','mode'=>'quarantine'];
if (file_exists(LMD_CONFIG_FILE)) {
    $json = @file_get_contents(LMD_CONFIG_FILE);
    $tmp = @json_decode($json, true);
    if (is_array($tmp)) $lmd_config = array_merge($lmd_config, $tmp);
}

// -------------------- HELPERS --------------------
function respond_json($data) {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize_shell_input($s) {
    $s = str_replace(array(";","&&","||","`","$","(",")","#","!","\n","\r","\\"), '', (string)$s);
    $s = str_replace("'", "\\'", $s);
    return trim($s);
}

function send_telegram_notification($message) {
    global $lmd_config;
    $token = $lmd_config['token'] ?? '';
    $chat_id = $lmd_config['chat_id'] ?? '';
    if (empty($token) || empty($chat_id)) return ['status'=>'error','message'=>'Telegram token/chat_id not set'];
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = ['chat_id'=>$chat_id,'text'=>$message,'parse_mode'=>'Markdown'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $res = @json_decode($resp, true);
    if ($code == 200 && isset($res['ok']) && $res['ok']===true) return ['status'=>'success','message'=>'Telegram sent'];
    return ['status'=>'error','message'=>($res['description'] ?? 'Telegram API error')];
}

function parse_quarantine_list_output($raw_output) {
    // Try parse maldet --quarantine list output
    $lines = preg_split('/\r\n|\r|\n/', trim($raw_output));
    $items = [];
    if (count($lines) > 5 && strpos($raw_output, '=======') !== false) {
        $data_lines = array_slice($lines, 5, -2);
        foreach ($data_lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line,'=======')!==false) continue;
            $parts = preg_split('/\s{2,}/', $line, 7, PREG_SPLIT_NO_EMPTY);
            if (count($parts) >= 7) {
                $items[] = [
                    'qid' => sanitize_shell_input($parts[1]),
                    'status' => sanitize_shell_input($parts[2]),
                    'path' => sanitize_shell_input($parts[3]),
                    'signature' => sanitize_shell_input($parts[4]),
                    'time' => sanitize_shell_input($parts[5]),
                    'user' => sanitize_shell_input($parts[6])
                ];
            }
        }
    }
    return $items;
}

function hybrid_quarantine_list() {
    // first try maldet native
    $out = @shell_exec(LMD_BIN . ' --quarantine list 2>/dev/null');
    $parsed = parse_quarantine_list_output($out ?: '');
    if (!empty($parsed)) return $parsed;
    // fallback: scan quarantine directory
    $dir = LMD_QUARANTINE_DIR;
    $items = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') as $file) {
            if (preg_match('/\.info$/', $file)) continue;
            $infof = $file . '.info';
            $signature = 'unknown';
            $user = 'root';
            $time = date('Y-m-d H:i:s', filemtime($file));
            $orig = '';
            if (file_exists($infof)) {
                $meta = file_get_contents($infof);
                // attempt to read original file path or malware signature
                if (preg_match('/Original file:\s*(.*)/i', $meta, $m)) $orig = trim($m[1]);
                if (preg_match('/malware:\s*(.*)/i', $meta, $m2)) $signature = trim($m2[1]);
            }
            $items[] = [
                'qid' => md5($file),
                'status' => 'quarantined',
                'path' => $orig ?: $file,
                'signature' => $signature,
                'time' => $time,
                'user' => $user
            ];
        }
    }
    return $items;
}

// -------------------- AJAX HANDLER --------------------
if (isset($_REQUEST['action_type'])) {
    $action = $_REQUEST['action_type'];
    $response = ['status'=>'error','message'=>'Invalid action.'];

    switch ($action) {
        case 'get_summary':
            // Detect monitor via PID file or inotifywait
            $maldet_bin = LMD_BIN;
            $pid_file = LMD_TMP_DIR . '/.monitor.pid';
            $is_monitoring = false;
            if (file_exists($pid_file)) {
                $pid = trim(@file_get_contents($pid_file));
                if (!empty($pid) && is_numeric($pid) && posix_kill((int)$pid, 0)) $is_monitoring = true;
            }
            if (!$is_monitoring) {
                $check = @shell_exec("ps aux | grep '/usr/bin/inotifywait' | grep -v grep");
                $is_monitoring = (strpos($check, '/usr/bin/inotifywait') !== false);
            }
            // version
            $version = trim(@shell_exec($maldet_bin . ' --version 2>/dev/null | awk -F: \'/Version/ {print \\ $2}\''));
            // fallback
            if (empty($version)) {
                $version = trim(@shell_exec($maldet_bin . ' --version 2>/dev/null | head -n1'));
            }
            if (empty($version)) $version = 'unknown';
            $quarantine_count = (int)trim(@shell_exec('find ' . escapeshellarg(LMD_QUARANTINE_DIR) . ' -type f ! -name "*.info" 2>/dev/null | wc -l'));
            $response = ['status'=>'success','data'=>['version'=>preg_replace('/\s+/',' ', trim($version)),'quarantine_count'=>$quarantine_count,'is_monitoring'=>$is_monitoring]];
            respond_json($response);
            break;

        case 'toggle_inotify':
            $state = $_POST['state'] ?? 'stop';
            $clean_state = ($state === 'start') ? 'start' : 'stop';
            // kill existing monitor first
            @shell_exec(LMD_BIN . ' --kill-monitor 2>/dev/null');
            if ($clean_state === 'start') {
                // start monitor on /home/
                @shell_exec(LMD_BIN . ' --monitor /home/ >/dev/null 2>&1 &');
                $msg = "Pemantauan real-time dimulai untuk folder /home/";
                // notify telegram
                send_telegram_notification("[LMD Guard] Pemantauan real-time diaktifkan pada server " . gethostname());
                $response = ['status'=>'success','message'=>$msg];
            } else {
                @shell_exec(LMD_BIN . ' --kill-monitor >/dev/null 2>&1');
                $msg = "Pemantauan real-time dimatikan.";
                send_telegram_notification("[LMD Guard] Pemantauan real-time dimatikan pada server " . gethostname());
                $response = ['status'=>'success','message'=>$msg];
            }
            respond_json($response);
            break;

        case 'update_signature':
            // spawn update in background
            @shell_exec('nohup ' . LMD_BIN . ' -u >/dev/null 2>&1 &');
            $response = ['status'=>'success','message'=>'Pembaruan signature LMD dimulai.'];
            send_telegram_notification("[LMD Guard] Pembaruan signature dimulai pada server " . gethostname());
            respond_json($response);
            break;

        case 'start_scan':
            $path = $_POST['scan_path'] ?? '/home/';
            $type = $_POST['scan_type'] ?? 'full';
            $days = (int)($_POST['scan_days'] ?? 7);
            // enforce /home only
            if (strpos($path, '/home') !== 0) $path = '/home/';
            $jobid = time() . rand(1000,9999);
            $joblog = LMD_JOB_LOG_DIR . "/job_${jobid}.log";
            if ($type === 'recent') {
                $cmd = LMD_BIN . " --scan-recent ${days} > " . escapeshellarg($joblog) . " 2>&1 &";
                $message = "Pemindaian (recent {$days} hari) dimulai.";
            } else {
                $cmd = LMD_BIN . " --scan-all " . escapeshellarg($path) . " > " . escapeshellarg($joblog) . " 2>&1 &";
                $message = "Pemindaian jalur {$path} dimulai.";
            }
            @shell_exec('nohup ' . $cmd);
            send_telegram_notification("[LMD Guard] Scan dimulai pada server " . gethostname() . " (job: ${jobid})");
            $response = ['status'=>'success','message'=>$message,'jobid'=>$jobid];
            respond_json($response);
            break;

        case 'get_scan_log':
            $jobid = $_REQUEST['jobid'] ?? '';
            $logfile = LMD_JOB_LOG_DIR . "/job_${jobid}.log";
            $log_content = '';
            $is_finished = true;
            if ($jobid && file_exists($logfile)) {
                $log_content = file_get_contents($logfile);
                // check for completion keyword
                $is_finished = (strpos($log_content, 'maldet scan and quarantine completed') !== false || strpos($log_content, 'Scan completed') !== false);
                if ($is_finished) @unlink($logfile);
            }
            $response = ['status'=>'success','log'=>htmlspecialchars($log_content),'finished'=>$is_finished];
            respond_json($response);
            break;

        case 'quarantine_list':
            $list = hybrid_quarantine_list();
            $response = ['status'=>'success','data'=>$list];
            respond_json($response);
            break;

        case 'quarantine_action':
            $action_q = $_POST['action_q'] ?? '';
            $raw_ids = $_POST['file_ids'] ?? [];
            $clean_action = sanitize_shell_input($action_q);
            $clean_ids = [];
            foreach ($raw_ids as $id) {
                if (is_numeric($id)) $clean_ids[] = (int)$id;
            }
            if (empty($clean_ids)) {
                $response = ['status'=>'error','message'=>'No valid numeric quarantine IDs provided.'];
                respond_json($response);
            }
            if (!in_array($clean_action, ['restore','clean','delete'])) {
                $response = ['status'=>'error','message'=>'Invalid quarantine action.'];
                respond_json($response);
            }
            $id_list = implode(' ', $clean_ids);
            if ($clean_action == 'restore') {
                @shell_exec(LMD_BIN . ' --restore ' . escapeshellcmd($id_list));
                $msg = count($clean_ids) . ' file dipulihkan.';
            } elseif ($clean_action == 'clean') {
                @shell_exec(LMD_BIN . ' --clean ' . escapeshellcmd($id_list));
                $msg = count($clean_ids) . ' file dicoba dibersihkan.';
            } else {
                @shell_exec(LMD_BIN . ' --purge ' . escapeshellcmd($id_list));
                $msg = count($clean_ids) . ' file dihapus permanen.';
            }
            send_telegram_notification("[LMD Guard] Aksi karantina: {$clean_action} pada server " . gethostname());
            $response = ['status'=>'success','message'=>$msg];
            respond_json($response);
            break;

        case 'save_settings':
            $token = sanitize_shell_input($_POST['token'] ?? '');
            $chat_id = sanitize_shell_input($_POST['chat_id'] ?? '');
            $mode = $_POST['mode'] ?? 'quarantine';
            $mode = in_array($mode, ['quarantine','clean','delete']) ? $mode : 'quarantine';
            $data = ['token'=>$token,'chat_id'=>$chat_id,'mode'=>$mode];
            @file_put_contents(LMD_CONFIG_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $lmd_config = $data;
            // apply to conf.maldet if writable
            if (is_writable(LMD_CONF)) {
                // ensure keys exist
                $conf = file_get_contents(LMD_CONF);
                $conf = preg_replace('/^quarantine_hits=.*/m', "quarantine_hits=" . ($mode!=='delete' ? '1' : '0'), $conf);
                $conf = preg_replace('/^clean_hits=.*/m', "clean_hits=" . ($mode==='clean' ? '1' : '0'), $conf);
                if (strpos($conf, 'quarantine_hits=')===false) $conf .= "\nquarantine_hits=" . ($mode!=='delete' ? '1' : '0');
                if (strpos($conf, 'clean_hits=')===false) $conf .= "\nclean_hits=" . ($mode==='clean' ? '1' : '0');
                @file_put_contents(LMD_CONF, $conf);
            }
            send_telegram_notification("[LMD Guard] Pengaturan disimpan. Mode: {$mode}");
            $response = ['status'=>'success','message'=>'Pengaturan berhasil disimpan.'];
            respond_json($response);
            break;

        case 'test_telegram':
            $msg = "*Pesan Uji Coba LMD Guard CWP*\n\nSelamat! Integrasi Telegram berhasil. Server: " . gethostname();
            $res = send_telegram_notification($msg);
            respond_json($res);
            break;

        default:
            respond_json(['status'=>'error','message'=>'Unknown action']);
            break;
    }
}

// -------------------- HTML MODULE UI --------------------
?>
<!doctype html>
<html class="no-js">
<head>
  <meta charset="utf-8">
  <title>LMD Guard CWP</title>
  <link href="../design/css/icons.css" rel="stylesheet" />
  <link href="../design/css/bootstrap.css" rel="stylesheet" />
  <link href="../design/css/plugins.css" rel="stylesheet" />
  <link href="../design/css/main.css" rel="stylesheet" />
  <style>
    .cwp_module_header {border-bottom: 1px solid #ccc; margin-bottom: 15px;}
    .tab-pane{padding-top:12px}
    .table{background:#fff}
    .label-danger { background-color: #d9534f; }
    .label-success { background-color: #5cb85c; }
    /* toast */
    .lmd-toast { position: fixed; right: 20px; top: 80px; z-index: 99999; min-width: 260px; }
    .lmd-toast .item { background:#333; color:#fff; padding:10px 14px; margin-bottom:8px; border-radius:6px; box-shadow:0 4px 10px rgba(0,0,0,.2); }
    .lmd-toast .item.success { background:#2e8b57; }
    .lmd-toast .item.error { background:#c0392b; }
    .lmd-toast .item.warn { background:#f39c12; color:#000; }
  </style>
  <script src="../design/js/libs/jquery-2.1.1.min.js"></script>
  <script src="../design/js/bootstrap/bootstrap.js"></script>
</head>
<body>

<div class="container-fluid" id="lmd_module_container">

<div class="cwp_module_header">
    <div class="cwp_module_name">LMD Guard CWP</div>
    <div class="cwp_module_info">Integrasi LMD Real-Time dengan CWP & Notifikasi Telegram</div>
</div>

<ul class="nav nav-tabs" id="lmdTabs">
    <li class="active"><a data-tab="summary">Ringkasan & Status LMD Guard <span id="lmd_tab_dot">&nbsp;</span></a></li>
    <li><a data-tab="scan">Pemindaian</a></li>
    <li><a data-tab="quarantine">Karantina & Laporan LMD Guard</a></li>
    <li><a data-tab="settings">Pengaturan Telegram & Mode</a></li>
</ul>

<div class="tab-content" style="padding: 15px; border: 1px solid #ddd; border-top: none;">

    <div id="tab-summary" class="tab-pane active">
        <h3>Status Keamanan LMD Guard</h3>
        <p>Status Real-Time (Inotify): <span id="inotify_status" class="label label-danger">OFF</span>
            <button id="toggle_inotify" class="btn btn-xs btn-default">Nyalakan Pemantauan</button>
        </p>
        <p>Versi LMD: <span id="lmd_version">Memuat...</span> | Karantina Aktif: <span id="quarantine_count">0</span></p>
        <button id="update_signature" class="btn btn-warning">Perbarui Signature Sekarang</button>
    </div>

    <div id="tab-scan" class="tab-pane">
        <h3>Pengaturan Pemindaian</h3>
        <form id="scan_form">
            <div class="form-group">
                <label>Tipe Pemindaian:</label><br>
                <label class="radio-inline"><input type="radio" name="scan_type_radio" value="full" checked> Pemindaian Penuh (Jalur Kustom)</label>
                <label class="radio-inline"><input type="radio" name="scan_type_radio" value="recent"> Pemindaian Terbaru (Berdasarkan Hari)</label>
            </div>

            <div class="form-group" id="scan_path_group">
                <label for="scan_path_input">Jalur Pemindaian Kustom (hanya dalam /home/):</label>
                <input type="text" class="form-control" id="scan_path_input" name="scan_path" value="/home/">
                <small class="text-muted">Harus berada di dalam /home/ — path lain akan otomatis diubah ke /home/</small>
            </div>

            <div class="form-group" id="scan_recent_group" style="display:none;">
                <label for="scan_days_select">Pindai File yang Dimodifikasi Dalam:</label>
                <select class="form-control" id="scan_days_select" name="scan_days">
                    <option value="1">1 Hari Terakhir</option><option value="7" selected>7 Hari Terakhir</option><option value="30">30 Hari Terakhir</option>
                </select>
            </div>

            <button type="submit" id="start_scan_button" class="btn btn-primary">Mulai Pemindaian</button>
        </form>
        
        <hr>
        <h4>Log Pemindaian:</h4>
        <pre id="scan_log" style="max-height: 300px; overflow: auto; background: #333; color: #0f0; padding: 10px;">Log akan muncul di sini.</pre>
    </div>

    <div id="tab-quarantine" class="tab-pane">
        <h3>Manajemen Karantina</h3>
        <div class="well">
            <button id="restore_button" class="btn btn-success">Pulihkan yang Dipilih</button>
            <button id="delete_button" class="btn btn-danger">Hapus Permanen yang Dipilih</button>
            <button id="clean_button" class="btn btn-warning">Coba Bersihkan yang Dipilih</button>
        </div>

        <table class="table table-bordered table-striped">
            <thead><tr>
                <th><input type="checkbox" id="select_all_quarantine"></th><th>ID</th><th>Lokasi File Asli</th>
                <th>Signature Malware</th><th>Waktu Karantina (User)</th>
            </tr></thead>
            <tbody id="quarantine_table_body">
                <tr><td colspan="5">Klik tab Karantina untuk memuat data.</td></tr>
            </tbody>
        </table>
    </div>

    <div id="tab-settings" class="tab-pane">
        <h3>Konfigurasi Telegram & Mode</h3>
        <form id="settings_form">
            <div class="form-group"><label>Telegram Bot Token:</label>
                <input type="text" class="form-control" name="token" value="<?= htmlspecialchars($lmd_config['token'] ?? '') ?>">
            </div>
            <div class="form-group"><label>Telegram Chat ID:</label>
                <input type="text" class="form-control" name="chat_id" value="<?= htmlspecialchars($lmd_config['chat_id'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Mode Penanganan Malware:</label>
                <select class="form-control" name="mode">
                    <option value="quarantine" <?= ($lmd_config['mode'] ?? '')==='quarantine' ? 'selected' : '' ?>>Mode 1 - Karantina</option>
                    <option value="clean" <?= ($lmd_config['mode'] ?? '')==='clean' ? 'selected' : '' ?>>Mode 2 - Clean + Karantina</option>
                    <option value="delete" <?= ($lmd_config['mode'] ?? '')==='delete' ? 'selected' : '' ?>>Mode 3 - Hapus Permanen</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
            <button type="button" id="test_telegram" class="btn btn-info">Uji Coba Kirim</button>
        </form>
    </div>
</div>

<!-- Toast container -->
<div class="lmd-toast" id="lmd_toast_container"></div>

<script>
// =========================
// Tiny Toast helper
// =========================
function showToast(msg, type, timeout) {
    timeout = timeout || 5000;
    var $c = $('#lmd_toast_container');
    var $item = $('<div class="item"></div>');
    $item.addClass(type || '');
    $item.text(msg);
    $c.prepend($item);
    setTimeout(function(){ $item.fadeOut(300,function(){$item.remove();}); }, timeout);
}

// =========================
// Polling controller and request (from earlier robust version)
// =========================
var _lmd_poll = { timer: null, intervalMs: 10000, backoffStep: 2, maxIntervalMs: 120000, consecutiveErrors: 0, isRunning: false };

function _lmd_requestSummary(callback) {
    console.log("🔄 [LMD GUARD] Memuat ringkasan status...");
    $.ajax({
        url: 'index.php?module=lmd_manager',
        type: 'POST',
        data: { action_type: 'get_summary' },
        cache: false,
        timeout: 10000,
        success: function (raw) {
            if (typeof raw === 'string' && raw.trim().startsWith('<')) {
                console.error("🚨 [LMD GUARD] Server mengembalikan HTML, bukan JSON. Respon awal:", raw.trim().substring(0,200));
                callback({ ok: false, reason: 'html', raw: raw });
                return;
            }
            var data = null;
            try { data = (typeof raw === 'object') ? raw : JSON.parse(raw); }
            catch (e) { console.error("❌ [LMD GUARD] Gagal parse JSON:", e, raw); callback({ ok: false, reason: 'parse', error: e, raw: raw }); return; }
            if (!data || data.status !== 'success' || !data.data) { console.error("⚠️ [LMD GUARD] JSON struktur tidak valid:", data); callback({ ok: false, reason: 'invalid', raw: data }); return; }
            try {
                $('#lmd_version').text(data.data.version || 'unknown');
                $('#quarantine_count').text(data.data.quarantine_count || 0);
                var isMonitoring = data.data.is_monitoring === true || data.data.is_monitoring === "true" || data.data.is_monitoring === 1 || data.data.is_monitoring === "1";
                var statusElement = $('#inotify_status');
                var buttonElement = $('#toggle_inotify');
                if (isMonitoring) { statusElement.text('ON').removeClass('label-danger').addClass('label-success'); buttonElement.text('Matikan Pemantauan').removeClass('btn-default').addClass('btn-danger').data('state','stop'); $('#lmd_tab_dot').html('<span style="color:green">&bull;</span>'); }
                else { statusElement.text('OFF').removeClass('label-success').addClass('label-danger'); buttonElement.text('Nyalakan Pemantauan').removeClass('btn-danger').addClass('btn-default').data('state','start'); $('#lmd_tab_dot').html('&nbsp;'); }
            } catch (uiErr) { console.error("⚠️ [LMD GUARD] Error saat update UI:", uiErr); }
            callback({ ok: true, data: data });
        },
        error: function (xhr, status, error) { console.error("🚨 [LMD GUARD] AJAX gagal:", status, error); callback({ ok: false, reason: 'ajax', status: status, error: error, xhr: xhr }); }
    });
}

function startSummaryPolling() {
    if (_lmd_poll.isRunning) return;
    _lmd_poll.isRunning = true; _lmd_poll.intervalMs = 10000; _lmd_poll.consecutiveErrors = 0;
    function _tick(){ _lmd_requestSummary(function(res){ if (res.ok) { _lmd_poll.consecutiveErrors = 0; _lmd_poll.intervalMs = 10000; _lmd_poll.timer = setTimeout(_tick, _lmd_poll.intervalMs); } else { _lmd_poll.consecutiveErrors++; console.warn('⚠️ [LMD GUARD] Polling error count:', _lmd_poll.consecutiveErrors, 'reason:', res.reason); if (res.reason === 'html') { stopSummaryPolling(); $('#inotify_status').text('ERROR').removeClass('label-success').addClass('label-danger'); $('#lmd_version').text('Server returned HTML'); showToast('Server returned HTML for AJAX, polling stopped','error',8000); return; } var nextInterval = Math.min(_lmd_poll.intervalMs * _lmd_poll.backoffStep, _lmd_poll.maxIntervalMs); _lmd_poll.intervalMs = nextInterval; if (_lmd_poll.consecutiveErrors >= 6) { stopSummaryPolling(); $('#inotify_status').text('ERROR').removeClass('label-success').addClass('label-danger'); $('#lmd_version').text('Multiple AJAX failures — Polling stopped'); showToast('Multiple AJAX failures — Polling stopped','error',8000); return; } _lmd_poll.timer = setTimeout(_tick, _lmd_poll.intervalMs); } }); }
    _tick();
}
function stopSummaryPolling(){ if (!_lmd_poll.isRunning) return; _lmd_poll.isRunning = false; if (_lmd_poll.timer) { clearTimeout(_lmd_poll.timer); _lmd_poll.timer = null; } console.log('⏹️ [LMD GUARD] Polling dihentikan.'); }

// =========================
// DOM Ready - Bind events
// =========================
$(document).ready(function(){
    // tab navigation
    $('#lmdTabs a').click(function(e){ e.preventDefault(); $(this).tab('show'); $('.tab-pane').removeClass('active'); var t = $(this).data('tab'); $('#tab-'+t).addClass('active'); if (t==='quarantine') loadQuarantineList(); });

    // scan type toggle
    $('input[name="scan_type_radio"]').change(function(){ if ($(this).val()==='full'){ $('#scan_path_group').show(); $('#scan_recent_group').hide(); } else { $('#scan_path_group').hide(); $('#scan_recent_group').show(); } }).trigger('change');

    // select all
    $('#select_all_quarantine').click(function(){ $('input[name="qid[]"]').prop('checked', this.checked); });

    // initial load + polling
    _lmd_requestSummary(function(res){ if (res.ok) { startSummaryPolling(); } else { // try once then start if ok
            startSummaryPolling(); }
    });

    // Toggle inotify
    $('#toggle_inotify').click(function(){ var btn=$(this); var state = btn.data('state') || 'start'; btn.prop('disabled',true); $.post('index.php?module=lmd_manager',{ action_type:'toggle_inotify', state: state }, function(data){ if (data.status==='success'){ showToast(data.message,'success'); } else { showToast(data.message || 'Gagal','error'); } _lmd_requestSummary(function(){ btn.prop('disabled',false); }); }, 'json').fail(function(){ showToast('Gagal terhubung','error'); btn.prop('disabled',false); }); });

    // update signature
    $('#update_signature').click(function(){ var btn=$(this); btn.prop('disabled',true); $.post('index.php?module=lmd_manager',{ action_type:'update_signature' }, function(data){ if (data.status==='success') showToast(data.message,'success'); else showToast(data.message||'Gagal','error'); btn.prop('disabled',false); }, 'json').fail(function(){ showToast('Gagal jaringan','error'); btn.prop('disabled',false); }); });

    // start scan
    $('#scan_form').submit(function(e){ e.preventDefault(); var btn=$('#start_scan_button'); var payload = $(this).serialize() + '&action_type=start_scan'; btn.prop('disabled',true); $('#scan_log').text('Memulai pemindaian...\n'); $.post('index.php?module=lmd_manager', payload, function(data){ if (data.status==='success'){ showToast(data.message,'success'); startPollingScan(data.jobid); } else { showToast(data.message||'Gagal','error'); btn.prop('disabled',false); } }, 'json').fail(function(){ showToast('Gagal jaringan','error'); btn.prop('disabled',false); }); });

    // settings save
    $('#settings_form').submit(function(e){ e.preventDefault(); var payload = $(this).serialize() + '&action_type=save_settings'; $.post('index.php?module=lmd_manager', payload, function(data){ if (data.status==='success') showToast(data.message,'success'); else showToast(data.message||'Gagal','error'); }, 'json').fail(function(){ showToast('Gagal jaringan','error'); }); });

    // test telegram
    $('#test_telegram').click(function(){ var btn=$(this); btn.prop('disabled',true); $.post('index.php?module=lmd_manager',{ action_type:'test_telegram' }, function(data){ if (data.status==='success') showToast('Sukses: '+data.message,'success'); else showToast('Gagal: '+(data.message||'error'),'error'); btn.prop('disabled',false); }, 'json').fail(function(){ showToast('Gagal jaringan','error'); btn.prop('disabled',false); }); });

    // quarantine actions
    function handleQuarantineAction(actionType, $button){ var selected = $('input[name="qid[]"]:checked').map(function(){ return $(this).val(); }).get(); if (selected.length===0){ showToast('Pilih file dulu','warn'); return; } if (!confirm('Yakin '+actionType.toUpperCase()+' '+selected.length+' file?')) return; $button.prop('disabled',true); $.post('index.php?module=lmd_manager',{ action_type:'quarantine_action', action_q: actionType, file_ids: selected }, function(data){ if (data.status==='success') showToast(data.message,'success'); else showToast(data.message||'Gagal','error'); loadQuarantineList(); $button.prop('disabled',false); }, 'json').fail(function(){ showToast('Gagal jaringan','error'); $button.prop('disabled',false); }); }
    $('#restore_button').click(function(){ handleQuarantineAction('restore', $(this)); });
    $('#delete_button').click(function(){ handleQuarantineAction('delete', $(this)); });
    $('#clean_button').click(function(){ handleQuarantineAction('clean', $(this)); });

});

// =========================
// Scan polling
// =========================
var _lmd_scan_poll = null;
function startPollingScan(jobid){ if (_lmd_scan_poll) clearInterval(_lmd_scan_poll); $('#start_scan_button').prop('disabled',true).text('Memproses...'); _lmd_scan_poll = setInterval(function(){ $.post('index.php?module=lmd_manager',{ action_type:'get_scan_log', jobid: jobid }, function(data){ $('#scan_log').text(data.log); var logArea = $('#scan_log'); logArea.scrollTop(logArea.prop('scrollHeight')); if (data.finished){ clearInterval(_lmd_scan_poll); _lmd_scan_poll = null; $('#scan_log').append('\n--- PEMINDAIAN SELESAI ---\n'); $('#start_scan_button').prop('disabled',false).text('Mulai Pemindaian'); showToast('Pemindaian selesai','success'); startSummaryPolling(); loadQuarantineList(); } }, 'json'); }, 2000); }

// =========================
// Quarantine list
// =========================
function renderQuarantineTable(data){ var html=''; $.each(data, function(i,item){ html += '<tr>'; html += '<td><input type="checkbox" name="qid[]" value="'+item.qid+'"></td>'; html += '<td>'+item.qid+'</td>'; html += '<td>'+item.path+'</td>'; html += '<td>'+item.signature+'</td>'; html += '<td>'+item.time+' ('+item.user+')</td>'; html += '</tr>'; }); $('#quarantine_table_body').html(html); }
function loadQuarantineList(){ $('#quarantine_table_body').html('<tr><td colspan="5">Memuat data karantina...</td></tr>'); $.post('index.php?module=lmd_manager',{ action_type:'quarantine_list' }, function(data){ if (data.status==='success' && data.data && data.data.length>0){ renderQuarantineTable(data.data); } else { $('#quarantine_table_body').html('<tr><td colspan="5">Tidak ada file dalam karantina.</td></tr>'); } }, 'json').fail(function(){ $('#quarantine_table_body').html('<tr><td colspan="5">Gagal memuat karantina.</td></tr>'); }); }

</script>
</body>
</html>
