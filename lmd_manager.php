<?php
// ==============================================================================
// LMD Manager Modul (Refactor untuk job-based log & multi-mode handling)
// ==============================================================================

if ( !isset( $include_path ) ) { 
    echo "invalid access"; 
    exit(); 
}

// ------------------------------------------------------------------------------
// Konstanta & Setup
// ------------------------------------------------------------------------------
define('LMD_CONFIG_FILE', '/etc/cwp/lmd_config.json');
define('LMD_JOB_LOG_DIR', '/var/log/maldet_ui');
define('LMD_BIN', '/usr/local/maldetect/maldet');

if (!is_executable(LMD_BIN)) {
    // fallback to 'maldet' in PATH
    define('LMD_BIN_FALLBACK', 'maldet');
} else {
    define('LMD_BIN_FALLBACK', LMD_BIN);
}

// pastikan direktori log ada
if (!is_dir(LMD_JOB_LOG_DIR)) {
    @mkdir(LMD_JOB_LOG_DIR, 0750, true);
}

// ------------------------------------------------------------------------------
// Helper functions
// ------------------------------------------------------------------------------
function sanitize_shell_input($input) {
    $input = str_replace(array(';', '&&', '||', '`', '$', '(', ')', '#', '!', "\n", "\r", '\\'), '', $input);
    $input = str_replace("'", "\'", $input);
    return trim($input);
}

function send_telegram_notification($message) {
    global $lmd_config;
    $token = $lmd_config['token'] ?? '';
    $chat_id = $lmd_config['chat_id'] ?? '';

    if (empty($token) || empty($chat_id)) {
        return ['status' => 'error', 'message' => 'Token atau Chat ID Telegram kosong.'];
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'Markdown'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $server_output = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($server_output, true);

    if ($http_code == 200 && (isset($result['ok']) && $result['ok'] === true)) {
        return ['status' => 'success', 'message' => 'Pesan uji coba berhasil dikirim!'];
    } else {
        $error_message = $result['description'] ?? 'Gagal menghubungi Telegram API. Cek Token dan Chat ID.';
        return ['status' => 'error', 'message' => "Gagal Telegram (HTTP {$http_code}): {$error_message}"];
    }
}

function parse_quarantine_list($raw_output) {
    $lines = preg_split('/\r\n|\r|\n/', $raw_output);
    $quarantine_list = [];
    // attempt to skip header/footer; keep robust fallback
    $data_lines = array_filter($lines, function($l){
        $l = trim($l);
        return ($l !== '' && strpos($l, '==') === false && strpos($l, 'Quarantine') === false);
    });

    foreach ($data_lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '=======') !== false) continue;

        // split by multiple spaces/tabs
        $parts = preg_split('/\s{2,}/', $line, 7, PREG_SPLIT_NO_EMPTY);
        if (count($parts) >= 6) {
            // try to map last columns to path and user
            $quarantine_list[] = [
                'qid' => sanitize_shell_input($parts[0] ?? ''),
                'status' => sanitize_shell_input($parts[1] ?? ''),
                'path' => sanitize_shell_input($parts[2] ?? ''),
                'signature' => sanitize_shell_input($parts[3] ?? ''),
                'time' => sanitize_shell_input($parts[4] ?? ''),
                'user' => sanitize_shell_input($parts[5] ?? ''),
            ];
        }
    }
    return $quarantine_list;
}

// load config if ada
$lmd_config = file_exists(LMD_CONFIG_FILE) ? json_decode(file_get_contents(LMD_CONFIG_FILE), true) : ['token'=>'','chat_id'=>'','mode'=>'quarantine'];

// ------------------------------------------------------------------------------
// AJAX Handler
// ------------------------------------------------------------------------------
if (isset($_REQUEST['action_type'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid action.'];
    $action = $_REQUEST['action_type'];

    switch ($action) {

        // -------------------------
        // get summary
        // -------------------------
        case 'get_summary':
            $bin = LMD_BIN_FALLBACK;
            $version = trim(shell_exec(escapeshellcmd($bin).' --version 2>/dev/null | grep -i Version | head -n1 | awk -F: \'{print $2}\''));
            if ($version === '') { $version = 'unknown'; }

            // Cek monitoring aktif
            $ps = shell_exec('ps -eo pid,cmd | grep -E "[m]aldet (--monitor|-m)"');
            $is_monitoring = !empty(trim($ps));

            $quarantine_count = (int)trim(shell_exec('find /usr/local/maldetect/quarantine -type f 2>/dev/null | wc -l'));
            $response = ['status'=>'success','data'=>[
                'version'=>$version,
                'quarantine_count'=>$quarantine_count,
                'is_monitoring'=>$is_monitoring
            ]];
            break;

        // -------------------------
        // toggle inotify / monitor
        // -------------------------
        case 'toggle_inotify':
            $state = $_POST['state'] ?? 'stop';
            $state = $state === 'start' ? 'start' : 'stop';
            $bin = LMD_BIN_FALLBACK;

            if ($state === 'start') {
                // -m users => monitor all users
                shell_exec(escapeshellcmd($bin).' -m users >/dev/null 2>&1 &');
            } else {
                // -k => kill monitor
                shell_exec(escapeshellcmd($bin).' -k >/dev/null 2>&1');
            }
            $response = ['status'=>'success','message'=>"Pemantauan real-time diubah ke: {$state}"];
            break;

        // -------------------------
        // update signature
        // -------------------------
        case 'update_signature':
            $bin = LMD_BIN_FALLBACK;
            shell_exec('( '.escapeshellcmd($bin).' -u && '.escapeshellcmd($bin).' -d ) >/dev/null 2>&1 &');
            $response = ['status' => 'success', 'message' => 'Pembaruan signature LMD dimulai.'];
            break;

        // -------------------------
        // start_scan (job-based)
        // -------------------------
        case 'start_scan':
            // default path = /home/
            $path = $_POST['scan_path'] ?? '/home/';
            $type = $_POST['scan_type_radio'] ?? ($_POST['scan_type'] ?? 'full');
            $days = (int)($_POST['scan_days'] ?? 7);

            // normalize path: ensure it's within /home/
            $clean_path = trim($path);
            if ($clean_path === '' || $clean_path === '/' ) { $clean_path = '/home/'; }

            // if user provided a specific path, ensure it's inside /home
            if (strpos($clean_path, '/home/') !== 0) {
                // force to /home/
                $clean_path = '/home/';
            }

            // job id & logfile
            try {
                $jobid = date('Ymd_His').'_'.bin2hex(random_bytes(3));
            } catch (Exception $e) {
                $jobid = date('Ymd_His').'_'.mt_rand(100000,999999);
            }
            $logfile = LMD_JOB_LOG_DIR."/job_{$jobid}.log";

            $bin = LMD_BIN_FALLBACK;

            if ($type === 'recent') {
                // maldet -r PATH DAYS
                $cmd = escapeshellcmd($bin).' -r '.escapeshellarg($clean_path).' '.((int)$days);
                $msg = "Pemindaian file yang dimodifikasi dalam {$days} hari terakhir dimulai untuk {$clean_path}.";
            } else {
                // --scan-all PATH
                $cmd = escapeshellcmd($bin).' --scan-all '.escapeshellarg($clean_path);
                $msg = "Pemindaian jalur {$clean_path} dimulai.";
            }

            // write header & run background, append start/end markers
            shell_exec("( echo '[LMD] START '.date '+%Y-%m-%d %H:%M:%S'; {$cmd}; echo '[LMD] END '.date '+%Y-%m-%d %H:%M:%S' ) >> ".escapeshellarg($logfile)." 2>&1 &");

            $response = ['status'=>'success','message'=>$msg,'jobid'=>$jobid];
            break;

        // -------------------------
        // get_scan_log (per job)
        // -------------------------
        case 'get_scan_log':
            $jobid = $_POST['jobid'] ?? '';
            $is_finished = false;
            $log_content = 'Log belum tersedia.';

            if (preg_match('/^[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $jobid)) {
                $logfile = LMD_JOB_LOG_DIR."/job_{$jobid}.log";
                if (file_exists($logfile)) {
                    $log_content = file_get_contents($logfile);

                    // heuristik selesai: cari END marker atau kata-kata maldet
                    $is_finished = (bool)preg_match('/(\[LMD\]\s*END|Scan\s*completed|Scan\s*Complete)/i', $log_content);

                    // Jika selesai dan mode 'delete', perform delete actions safely for files under /home/
                    if ($is_finished) {
                        $mode = $lmd_config['mode'] ?? 'quarantine';
                        if ($mode === 'delete') {
                            // cari pattern file paths yang biasa muncul di output maldet
                            // mencoba beberapa pola: "Infected: <path>" or lines containing "-> /path" etc.
                            $deleted = 0;
                            // pattern 1: lines with "Infect" or "{INFECTED}" not guaranteed; fallback to scanning for /home/ paths
                            if (preg_match_all('/(\/home[^\s\'"]+)/i', $log_content, $matches)) {
                                foreach ($matches[1] as $badfile) {
                                    $badfile = trim($badfile);
                                    // safety: only delete if file exists, is under /home and not a directory
                                    if (strpos($badfile, '/home/') === 0 && is_file($badfile)) {
                                        @unlink($badfile);
                                        $deleted++;
                                    }
                                }
                                if ($deleted > 0) {
                                    // notify via telegram if configured
                                    if (!empty($lmd_config['token'] ?? '') && !empty($lmd_config['chat_id'] ?? '')) {
                                        $msg = "Auto-Delete Mode: {$deleted} file dihapus setelah pemindaian (Job {$jobid})";
                                        send_telegram_notification($msg);
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $log_content = 'Menunggu proses mulai menulis log...';
                }
            } else {
                $log_content = 'Job ID tidak valid.';
                $is_finished = true;
            }

            $response = ['status'=>'success','log'=>htmlspecialchars($log_content), 'finished'=>$is_finished];
            break;

        // -------------------------
        // quarantine list
        // -------------------------
        case 'quarantine_list':
            $bin = LMD_BIN_FALLBACK;
            $list_output = shell_exec(escapeshellcmd($bin).' --quarantine list 2>/dev/null');
            $parsed_data = parse_quarantine_list($list_output);
            $response = ['status' => 'success', 'data' => $parsed_data];
            break;

        // -------------------------
        // quarantine action
        // -------------------------
        case 'quarantine_action':
            $action_q = $_POST['action_q'] ?? '';
            $raw_file_ids = $_POST['file_ids'] ?? [];
            $clean_action = sanitize_shell_input($action_q);
            $clean_file_ids = [];

            foreach ($raw_file_ids as $qid) {
                if (is_numeric($qid)) {
                    $clean_file_ids[] = (int)$qid;
                }
            }

            if (!empty($clean_file_ids) && in_array($clean_action, ['restore', 'delete', 'clean'])) {
                $id_list = implode(' ', $clean_file_ids);
                $bin = LMD_BIN_FALLBACK;

                if ($clean_action == 'restore') {
                    $command = escapeshellcmd($bin).' --restore '.escapeshellarg($id_list);
                } else if ($clean_action == 'clean') {
                    $command = escapeshellcmd($bin).' --clean '.escapeshellarg($id_list);
                } else {
                    $command = escapeshellcmd($bin).' --purge '.escapeshellarg($id_list);
                }

                shell_exec($command);
                $response = ['status' => 'success', 'message' => count($clean_file_ids) . " file telah dikenakan aksi '{$clean_action}'."];
            } else {
                $response['message'] = 'Aksi karantina tidak valid atau file ID kosong/berbahaya.';
            }
            break;

        // -------------------------
        // save settings (token/chatid/mode)
        // -------------------------
        case 'save_settings':
            $token = sanitize_shell_input($_POST['token'] ?? '');
            $chat_id = sanitize_shell_input($_POST['chat_id'] ?? '');
            $mode = $_POST['mode'] ?? 'quarantine';
            $mode = in_array($mode, ['quarantine','clean','delete']) ? $mode : 'quarantine';

            file_put_contents(LMD_CONFIG_FILE, json_encode([
                'token' => $token,
                'chat_id' => $chat_id,
                'mode' => $mode
            ], JSON_PRETTY_PRINT));

            $lmd_config = json_decode(file_get_contents(LMD_CONFIG_FILE), true);
            $response = ['status' => 'success', 'message' => 'Pengaturan berhasil disimpan.'];
            break;

        // -------------------------
        // test telegram
        // -------------------------
        case 'test_telegram':
            $test_message = "*Pesan Uji Coba LMD Guard CWP*\n\nIntegrasi Telegram berhasil.";
            $response = send_telegram_notification($test_message);
            break;

        default:
            break;
    }

    echo json_encode($response);
    exit;
}
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
  <link href="../design/css/custom.css" rel="stylesheet" />
  <link id="customcss" href="../design/css/custom.css" rel="stylesheet" />
  <style>
    .cwp_module_header {border-bottom: 1px solid #ccc; margin-bottom: 15px;}
    .tab-pane{padding-top:12px}
    .table{background:#fff}
    .label-danger { background-color: #d9534f; }
    .label-success { background-color: #5cb85c; }
  </style>
  <script src="../design/js/libs/jquery-2.1.1.min.js"></script>
  <script src="../design/js/bootstrap/bootstrap.js"></script>
  <script src="design/js/main.js"></script>
  <script src="design/js/pages/blank.js"></script>
</head>
<body>
<div class="container-fluid" id="lmd_module_container">
<div class="cwp_module_header">
    <div class="cwp_module_name">LMD Guard CWP</div>
    <div class="cwp_module_info">Integrasi LMD Real-Time dengan CWP & Notifikasi Telegram</div>
</div>

<ul class="nav nav-tabs" id="lmdTabs">
    <li class="active"><a data-tab="summary">Ringkasan & Status LMD Guard 🟢</a></li>
    <li><a data-tab="scan">Pemindaian 🔎</a></li>
    <li><a data-tab="quarantine">Karantina & Laporan LMD Guard 🗑️</a></li>
    <li><a data-tab="settings">Pengaturan Telegram & Mode ⚙️</a></li>
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
                    <option value="quarantine" <?= ($lmd_config['mode'] ?? '')=='quarantine'?'selected':'' ?>>Mode 1 - Karantina</option>
                    <option value="clean" <?= ($lmd_config['mode'] ?? '')=='clean'?'selected':'' ?>>Mode 2 - Clean + Karantina</option>
                    <option value="delete" <?= ($lmd_config['mode'] ?? '')=='delete'?'selected':'' ?>>Mode 3 - Hapus Permanen</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
            <button type="button" id="test_telegram" class="btn btn-info">Uji Coba Kirim</button>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    var $moduleContainer = $('#lmd_module_container');
    var scanInterval = null;

    // Tab nav
    $moduleContainer.find('#lmdTabs a').click(function (e) {
        e.preventDefault();
        $(this).tab('show');
        $moduleContainer.find('.tab-pane').removeClass('active');
        var targetTab = $(this).data('tab');
        $('#tab-' + targetTab).addClass('active');
        if (targetTab === 'quarantine') { loadQuarantineList(); }
    });

    // Toggle scan type
    $moduleContainer.find('input[name="scan_type_radio"]').change(function() {
        if ($(this).val() === 'full') {
            $moduleContainer.find('#scan_path_group').show();
            $moduleContainer.find('#scan_recent_group').hide();
        } else {
            $moduleContainer.find('#scan_path_group').hide();
            $moduleContainer.find('#scan_recent_group').show();
        }
    }).trigger('change');

    function loadSummary() {
        $.post('index.php?module=lmd_manager', { action_type: 'get_summary' },
            function(data) {
                if (data.status === 'success') {
                    $moduleContainer.find('#lmd_version').text(data.data.version);
                    $moduleContainer.find('#quarantine_count').text(data.data.quarantine_count);
                    var isMonitoring = data.data.is_monitoring;
                    var statusElement = $moduleContainer.find('#inotify_status');
                    var buttonElement = $moduleContainer.find('#toggle_inotify');
                    if (isMonitoring) {
                        statusElement.text('ON').removeClass('label-danger').addClass('label-success');
                        buttonElement.text('Matikan Pemantauan').removeClass('btn-default').addClass('btn-danger').data('state', 'stop');
                    } else {
                        statusElement.text('OFF').removeClass('label-success').addClass('label-danger');
                        buttonElement.text('Nyalakan Pemantauan').removeClass('btn-danger').addClass('btn-default').data('state', 'start');
                    }
                }
            }, 'json'
        ).fail(function() { console.error("Gagal memuat ringkasan."); });
    }
    loadSummary();
    setInterval(loadSummary, 10000);

    function startPolling(jobid) {
        if (scanInterval) { clearInterval(scanInterval); }
        $moduleContainer.find('#start_scan_button').prop('disabled', true).text('Memproses...');
        $moduleContainer.data('jobid', jobid);

        scanInterval = setInterval(function() {
            $.post('index.php?module=lmd_manager', { action_type: 'get_scan_log', jobid: jobid },
                function(data) {
                    $moduleContainer.find('#scan_log').html(data.log);
                    var logArea = $moduleContainer.find('#scan_log');
                    logArea.scrollTop(logArea.prop("scrollHeight"));
                    if (data.finished) {
                        clearInterval(scanInterval);
                        $moduleContainer.find('#scan_log').append('\n--- PEMINDAIAN SELESAI ---\n');
                        $moduleContainer.find('#start_scan_button').prop('disabled', false).text('Mulai Pemindaian');
                        scanInterval = null;
                        loadQuarantineList();
                    }
                }, 'json'
            ).fail(function() {
                clearInterval(scanInterval);
                $moduleContainer.find('#scan_log').append('\n--- KESALAHAN JARINGAN/SERVER ---');
                $moduleContainer.find('#start_scan_button').prop('disabled', false).text('Mulai Pemindaian');
                scanInterval = null;
            });
        }, 2000);
    }

    function renderQuarantineTable(data) {
        var tableHtml = '';
        $.each(data, function(index, item) {
            tableHtml += '<tr>';
            tableHtml += '<td><input type="checkbox" name="qid[]" value="' + item.qid + '"></td>';
            tableHtml += '<td>' + item.qid + '</td>';
            tableHtml += '<td>' + item.path + '</td>';
            tableHtml += '<td>' + item.signature + '</td>';
            tableHtml += '<td>' + item.time + ' (' + item.user + ')</td>';
            tableHtml += '</tr>';
        });
        $moduleContainer.find('#quarantine_table_body').html(tableHtml);
    }

    function loadQuarantineList() {
        $moduleContainer.find('#quarantine_table_body').html('<tr><td colspan="5">Memuat data karantina...</td></tr>');
        $.post('index.php?module=lmd_manager', { action_type: 'quarantine_list' },
            function(data) {
                if (data.status === 'success' && data.data && data.data.length > 0) {
                    renderQuarantineTable(data.data);
                } else {
                    $moduleContainer.find('#quarantine_table_body').html('<tr><td colspan="5">Tidak ada file dalam karantina.</td></tr>');
                }
            }, 'json'
        );
    }

    // EVENTS
    $moduleContainer.find('#toggle_inotify').click(function() {
        var button = $(this);
        var currentState = button.data('state');
        button.prop('disabled', true).text('Memproses...');
        $.post('index.php?module=lmd_manager', { action_type: 'toggle_inotify', state: currentState },
            function(data) { alert(data.message); loadSummary(); }, 'json'
        ).always(function() { button.prop('disabled', false); });
    });

    $moduleContainer.find('#update_signature').click(function() {
        var button = $(this);
        button.prop('disabled', true).text('Memproses Pembaruan...');
        $.post('index.php?module=lmd_manager', { action_type: 'update_signature' },
            function(data) { alert(data.message); setTimeout(loadSummary, 5000); }, 'json'
        ).always(function() { button.prop('disabled', false).text('Perbarui Signature Sekarang'); });
    });

    $moduleContainer.find('#scan_form').submit(function(e) {
        e.preventDefault();
        var button = $('#start_scan_button');
        $('#scan_log').text('Memulai pemindaian...\n');
        button.prop('disabled', true).text('Memproses Permintaan...');
        $.post('index.php?module=lmd_manager', $(this).serialize() + '&action_type=start_scan',
            function(data) {
                if (data.status === 'success' && data.jobid) {
                    alert(data.message);
                    startPolling(data.jobid);
                } else {
                    alert('Gagal memicu: ' + (data.message || 'Unknown'));
                    button.prop('disabled', false).text('Mulai Pemindaian');
                }
            }, 'json'
        ).fail(function() { alert('Kesalahan jaringan.'); button.prop('disabled', false).text('Mulai Pemindaian'); });
    });

    $('#settings_form').submit(function(e) {
        e.preventDefault();
        $.post('index.php?module=lmd_manager', $(this).serialize() + '&action_type=save_settings',
            function(data) { alert(data.message); }, 'json'
        );
    });

    $moduleContainer.find('#test_telegram').click(function() {
        $.post('index.php?module=lmd_manager', { action_type: 'test_telegram' },
            function(data) { alert(data.status === 'success' ? 'Sukses: ' + data.message : 'Gagal: ' + data.message); }, 'json'
        );
    });

    function handleQuarantineAction(actionType, button) {
        var selectedQids = $moduleContainer.find('input[name="qid[]"]:checked').map(function(){ return $(this).val(); }).get();
        if (selectedQids.length === 0 || !confirm('Yakin ' + actionType.toUpperCase() + ' ' + selectedQids.length + ' file?')) { return; }
        button.prop('disabled', true).text('Memproses...');
        $.post('index.php?module=lmd_manager', { action_type: 'quarantine_action', action_q: actionType, file_ids: selectedQids },
            function(data) { alert(data.message); loadQuarantineList(); }, 'json'
        ).always(function() {
             var initialText = actionType.charAt(0).toUpperCase() + actionType.slice(1) + 'kan yang Dipilih';
             if (actionType === 'restore') initialText = 'Pulihkan yang Dipilih';
             if (actionType === 'delete') initialText = 'Hapus Permanen yang Dipilih';
             if (actionType === 'clean') initialText = 'Coba Bersihkan yang Dipilih';
             button.prop('disabled', false).text(initialText);
        }).fail(function() { alert('Gagal terhubung ke server. Periksa koneksi.'); });
    }
    $moduleContainer.find('#restore_button').click(function() { handleQuarantineAction('restore', $(this)); });
    $moduleContainer.find('#delete_button').click(function() { handleQuarantineAction('delete', $(this)); });
    $moduleContainer.find('#clean_button').click(function() { handleQuarantineAction('clean', $(this)); });

});
</script>
</div> </body>
</html>
