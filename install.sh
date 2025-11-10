#!/bin/bash
set -euo pipefail # Safety flags

# --- 1. Konfigurasi dan Variabel ---
REPO_RAW_URL="https://raw.githubusercontent.com/YourUser/lmd-cwp-manager/main" # GANTI INI
CWP_ADMIN_DIR="/usr/local/cwpsrv/htdocs/resources/admin"
MOD_DIR="${CWP_ADMIN_DIR}/modules/developer"
CONFIG_FILE="/etc/cwp/lmd_config.json"
LMD_CONF="/usr/local/maldetect/conf.maldet"
HOOK_SCRIPT="/usr/local/maldetect/hook/post_quarantine.sh"

echo "--- Memulai Instalasi LMD Guard CWP dari GitHub ---"

# --- 2. Cek Prasyarat ---
if ! command -v maldet &> /dev/null; then
    echo "🚨 GAGAL: LMD tidak ditemukan. Instal LMD terlebih dahulu!"
    exit 1
fi
echo "✅ LMD ditemukan."

# --- 3. Penyiapan Direktori dan Download File ---
echo "--- Menyiapkan direktori dan unduh file ---"
mkdir -p /etc/cwp/
mkdir -p "${MOD_DIR}"
mkdir -p "$(dirname "$HOOK_SCRIPT")"

# MENGUNDUH FILE PHP/HTML/JS FINAL (LMD Manager)
curl -o "${MOD_DIR}/lmd_manager.php" -L "${REPO_RAW_URL}/lmd_manager.php"
echo "✅ lmd_manager.php berhasil diunduh dan disalin."

# Membuat file konfigurasi LMD Manager jika belum ada
if [[ ! -f "$CONFIG_FILE" ]]; then
    echo '{"token": "", "chat_id": ""}' > "${CONFIG_FILE}"
    chmod 600 "${CONFIG_FILE}"
    echo "✅ File konfigurasi ${CONFIG_FILE} dibuat."
fi

# --- 4. Implementasi Skrip Hook Real-Time Telegram ---
echo "--- Membuat skrip hook real-time Telegram ---"
cat << 'EOF_HOOK' > "${HOOK_SCRIPT}"
#!/bin/bash
set -euo pipefail 

FILE_PATH="$1"
SIGNATURE="$2"
HOST_NAME=$(hostname)
CONFIG_FILE="/etc/cwp/lmd_config.json"

TOKEN=$(grep -o '"token": *"[^"]*"' "$CONFIG_FILE" | grep -o '"[^"]*"$' | tr -d '"')
CHAT_ID=$(grep -o '"chat_id": *"[^"]*"' "$CONFIG_FILE" | grep -o '"[^"]*"$' | tr -d '"')

if [ -z "$TOKEN" ] || [ -z "$CHAT_ID" ]; then
    exit 0
fi

MESSAGE="🚨 *MALWARE REAL-TIME DIKARANTINA* 🚨\n\n*Server:* $HOST_NAME\n*Waktu:* $(date '+%Y-%m-%d %H:%M:%S')\n*File:* \`$FILE_PATH\`\n*Ancaman:* $SIGNATURE\n*Aksi:* **Karantina Instan** oleh LMD Guard CWP."

curl -s -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" \
-d chat_id="$CHAT_ID" \
-d parse_mode="Markdown" \
-d text="$MESSAGE" > /dev/null 2>&1

exit 0
EOF_HOOK
chmod +x "${HOOK_SCRIPT}"
echo "✅ Skrip hook post_quarantine.sh dibuat dan siap."

# --- 5. Konfigurasi Sistem (Izin Tulis, LMD, CWP Controller) ---
echo "--- Modifikasi konfigurasi sistem dan CWP ---"

# A. Cek Izin Tulis LMD
if [[ ! -w "$LMD_CONF" ]]; then
    echo "🚨 GAGAL KRITIS: File konfigurasi LMD ($LMD_CONF) tidak dapat ditulisi. Harap periksa izin."
    exit 1
fi

# B. Aktifkan Hook di Konfigurasi LMD
if grep -q "quarantine_exec_file" "$LMD_CONF"; then
    sed -i "s|^quarantine_exec_file=.*|quarantine_exec_file=${HOOK_SCRIPT}|g" "$LMD_CONF"
else
    echo "quarantine_exec_file=${HOOK_SCRIPT}" >> "$LMD_CONF"
fi
echo "✅ Konfigurasi LMD untuk hook berhasil."

# C. Modifikasi CWP Controller (3rdparty.php)
MOD_CONTROLLER="${CWP_ADMIN_DIR}/include/3rdparty.php"
# Menyisipkan logic loading modul lmd_manager di awal controller logic
MOD_LINE="\t\$mod = isset(\$_REQUEST\['mod'\]) ? \$_REQUEST\['mod'\] : null; if (\$action == 'developer' && \$mod == 'lmd_manager') { require_once('${MOD_DIR}/lmd_manager.php'); exit; }"

if ! grep -q "lmd_manager" "$MOD_CONTROLLER"; then
    # Mencari baris if ($action != 'login') { dan menyisipkan logic kita
    sed -i "/if (\$action != 'login') {/a ${MOD_LINE}" "$MOD_CONTROLLER"
    echo "✅ Logic loading modul ditambahkan ke 3rdparty.php."
fi

# D. Tambahkan Menu CWP (thirdparty.tpl)
MENU_TPL="${CWP_ADMIN_DIR}/include/thirdparty.tpl"
# NAMA BRAND DI SINI!
MENU_LINE='<li><a href="index.php?module=3rdparty&action=developer&mod=lmd_manager">LMD Guard CWP</a></li>' 
if ! grep -q "lmd_manager" "$MENU_TPL"; then
    # Menyisipkan menu sebelum penutup </ul> di template CWP
    sed -i "/<\/ul>/i ${MENU_LINE}" "$MENU_TPL"
    echo "✅ Menu 'LMD Guard CWP' ditambahkan ke dashboard."
fi

echo "--- INSTALASI LMD GUARD CWP SELESAI TOTAL ---"

3. 🧩 lmd_manager.php (Kode Final Gabungan Branded)
Nama brand ditambahkan ke Judul Module HTML.
<?php
// ==============================================================================
// BLOK 1: LOGIKA SERVER PHP & HELPER (TETAP SAMA)
// ==============================================================================
// CWP Access Check
if (empty($CWP_APP_NAME)) { die('Unauthorized access'); }
define('LMD_CONFIG_FILE', '/etc/cwp/lmd_config.json');
define('LMD_TEMP_LOG', '/tmp/lmd_scan_output'); 

function sanitize_shell_input($input) {
    $input = str_replace(array(';', '&&', '||', '`', '$', '(', ')', '#', '!', "\n", "\r", '\\'), '', $input);
    $input = str_replace("'", "\'", $input);
    return trim($input);
}
// [ ... FUNGSI send_telegram_notification, parse_quarantine_list DI SINI ... ]
function send_telegram_notification($message) {
    global $lmd_config; 
    $token = $lmd_config['token'] ?? '';
    $chat_id = $lmd_config['chat_id'] ?? '';
    if (empty($token) || empty($chat_id)) {
        return ['status' => 'error', 'message' => 'Token atau Chat ID Telegram kosong.'];
    }
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
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
    $data_lines = array_slice($lines, 5, -2); 
    foreach ($data_lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '=======') !== false) continue;
        $parts = preg_split('/\s{2,}/', $line, 7, PREG_SPLIT_NO_EMPTY);
        if (count($parts) >= 7) {
            $quarantine_list[] = [
                'qid' => sanitize_shell_input($parts[1]), 
                'status' => sanitize_shell_input($parts[2]),
                'path' => sanitize_shell_input($parts[3]),
                'signature' => sanitize_shell_input($parts[4]),
                'time' => sanitize_shell_input($parts[5]),
                'user' => sanitize_shell_input($parts[6]),
            ];
        }
    }
    return $quarantine_list;
}

$lmd_config = file_exists(LMD_CONFIG_FILE) ? json_decode(file_get_contents(LMD_CONFIG_FILE), true) : ['token' => '', 'chat_id' => ''];

// --- BLOK LOGIKA SERVER AJAX (TETAP SAMA) ---
if (isset($_REQUEST['action_type'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid action.'];
    $action = $_REQUEST['action_type'];
    
    if (CWP_User::isAdmin()) {
        switch ($action) {
            // [ ... SEMUA CASE AJAX DI SINI ... ]
            case 'get_summary':
                $is_monitoring = strpos(shell_exec('ps aux | grep "maldet --monitor" | grep -v grep'), 'maldet --monitor') !== false;
                $version = trim(str_replace('Version:', '', shell_exec('maldet --version | grep Version')));
                $quarantine_count = (int)shell_exec('find /usr/local/maldetect/quarantine/ -type f | wc -l');
            
                $response = ['status' => 'success', 'data' => ['version' => $version, 'quarantine_count' => $quarantine_count, 'is_monitoring' => $is_monitoring]];
                break;

            case 'toggle_inotify':
                $state = $_POST['state'] ?? 'stop';
                $clean_state = sanitize_shell_input($state);

                $command = ($clean_state == 'start') ? 'maldet --monitor users' : 'maldet --monitor stop';
                shell_exec($command);
                $response = ['status' => 'success', 'message' => "Pemantauan real-time diubah ke: {$clean_state}"];
                break;
                
            case 'update_signature':
                shell_exec('nohup maldet -u && maldet -d &');
                $response = ['status' => 'success', 'message' => 'Pembaruan signature LMD dimulai.'];
                break;

            case 'start_scan':
                $path = $_POST['scan_path'] ?? '/home/';
                $type = $_POST['scan_type'] ?? 'full'; 
                $days = (int)($_POST['scan_days'] ?? 7);
                
                $clean_path = sanitize_shell_input($path);
                if (empty($clean_path)) { $clean_path = '/home/'; }

                if ($type === 'recent') {
                    $command = "maldet --scan-recent {$days} > ".LMD_TEMP_LOG." &";
                    $message = "Pemindaian file yang dimodifikasi dalam {$days} hari terakhir dimulai.";
                } else {
                    $command = "maldet --scan-all {$clean_path} > ".LMD_TEMP_LOG." &";
                    $message = "Pemindaian jalur {$clean_path} dimulai.";
                }
                
                shell_exec("nohup {$command} 2>&1"); 
                $response = ['status' => 'success', 'message' => $message];
                break;

            case 'get_scan_log':
                $log_content = '';
                $is_finished = true; 
                
                if (file_exists(LMD_TEMP_LOG)) {
                    $log_content = file_get_contents(LMD_TEMP_LOG);
                    $is_finished = (strpos($log_content, 'maldet scan and quarantine completed') !== false);
                    if ($is_finished) { unlink(LMD_TEMP_LOG); }
                }

                $response = ['status' => 'success', 'log' => htmlspecialchars($log_content), 'finished' => $is_finished];
                break;

            case 'quarantine_list':
                $list_output = shell_exec('maldet --quarantine list'); 
                $parsed_data = parse_quarantine_list($list_output);
                $response = ['status' => 'success', 'data' => $parsed_data];
                break;

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
                    $command = "maldet --{$clean_action} {$id_list}";
                    
                    shell_exec($command);
                    $response = ['status' => 'success', 'message' => count($clean_file_ids) . " file telah dikenakan aksi '{$clean_action}'."];
                } else {
                    $response['message'] = 'Aksi karantina tidak valid atau file ID kosong/berbahaya.';
                }
                break;
            
            case 'save_settings':
                $token = sanitize_shell_input($_POST['token']);
                $chat_id = sanitize_shell_input($_POST['chat_id']);
                file_put_contents(LMD_CONFIG_FILE, json_encode(['token' => $token, 'chat_id' => $chat_id]));
                $lmd_config = json_decode(file_get_contents(LMD_CONFIG_FILE), true); 
                $response = ['status' => 'success', 'message' => 'Pengaturan Telegram berhasil disimpan.'];
                break;

            case 'test_telegram':
                $test_message = "*Pesan Uji Coba LMD Guard CWP*\n\nSelamat! Integrasi Telegram berhasil. Anda akan menerima notifikasi real-time di channel ini.";
                $response = send_telegram_notification($test_message);
                break;

            default:
                break;
        }
    } else {
        $response['message'] = 'Akses ditolak.';
    }
    
    echo json_encode($response);
    exit;
}
?>

<div class="cwp_module_header">
    <div class="cwp_module_name">LMD Guard CWP</div>
    <div class="cwp_module_info">Integrasi LMD Real-Time dengan CWP & Notifikasi Telegram</div>
</div>

<ul class="nav nav-tabs" id="lmdTabs">
    <li class="active"><a data-tab="summary">Ringkasan & Status LMD Guard 🟢</a></li>
    <li><a data-tab="scan">Pemindaian 🔎</a></li>
    <li><a data-tab="quarantine">Karantina & Laporan LMD Guard 🗑️</a></li>
    <li><a data-tab="settings">Pengaturan Telegram ⚙️</a></li>
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
                <label for="scan_path_input">Jalur Pemindaian Kustom:</label>
                <input type="text" class="form-control" id="scan_path_input" name="scan_path" value="/home/">
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
        <h3>Konfigurasi Telegram</h3>
        <form id="settings_form">
            <div class="form-group"><label>Telegram Bot Token:</label>
                <input type="text" class="form-control" name="token" value="<?= htmlspecialchars($lmd_config['token'] ?? '') ?>">
            </div>
            <div class="form-group"><label>Telegram Chat ID:</label>
                <input type="text" class="form-control" name="chat_id" value="<?= htmlspecialchars($lmd_config['chat_id'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
            <button type="button" id="test_telegram" class="btn btn-info">Uji Coba Kirim</button>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    
    var scanInterval = null; 

    $('#lmdTabs a').click(function (e) {
        e.preventDefault();
        $(this).tab('show');
        $('.tab-pane').removeClass('active');
        
        var targetTab = $(this).data('tab');
        $('#tab-' + targetTab).addClass('active');

        if (targetTab === 'quarantine') {
            loadQuarantineList(); 
        }
    });

    $('input[name="scan_type_radio"]').change(function() {
        if ($(this).val() === 'full') {
            $('#scan_path_group').show();
            $('#scan_recent_group').hide();
        } else {
            $('#scan_path_group').hide();
            $('#scan_recent_group').show();
        }
    }).trigger('change'); 

    $('#select_all_quarantine').click(function() {
        $(':checkbox[name="qid[]"]').prop('checked', this.checked);
    });
    
    // =================================================================
    // FUNGSI JAVASCRIPT: LOGIKA INTI
    // =================================================================
    
    function loadSummary() {
        $.post('index.php?module=3rdparty&action=developer&mod=lmd_manager', { action_type: 'get_summary' },
            function(data) {
                if (data.status === 'success') {
                    $('#lmd_version').text(data.data.version);
                    $('#quarantine_count').text(data.data.quarantine_count);

                    var isMonitoring = data.data.is_monitoring;
                    var statusElement = $('#inotify_status');
                    var buttonElement = $('#toggle_inotify');
                    
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

    function startPolling() {
        if (scanInterval) { clearInterval(scanInterval); }
        $('#start_scan_button').prop('disabled', true).text('Memindai (Sedang Berjalan)...');

        scanInterval = setInterval(function() {
            $.post('index.php?module=3rdparty&action=developer&mod=lmd_manager', { action_type: 'get_scan_log' },
                function(data) {
                    $('#scan_log').html(data.log);
                    var logArea = $('#scan_log');
                    logArea.scrollTop(logArea.prop("scrollHeight"));
                    
                    if (data.finished) {
                        clearInterval(scanInterval);
                        $('#scan_log').append('\n--- PEMINDAIAN SELESAI ---\n');
                        $('#start_scan_button').prop('disabled', false).text('Mulai Pemindaian');
                        scanInterval = null;
                        loadQuarantineList(); 
                    }
                }, 'json'
            ).fail(function() {
                clearInterval(scanInterval);
                $('#scan_log').append('\n--- KESALAHAN JARINGAN/SERVER ---');
                $('#start_scan_button').prop('disabled', false).text('Mulai Pemindaian');
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
        $('#quarantine_table_body').html(tableHtml);
    }
    function loadQuarantineList() {
        $('#quarantine_table_body').html('<tr><td colspan="5">Memuat data karantina...</td></tr>');
        $.post('index.php?module=3rdparty&action=developer&mod=lmd_manager', { action_type: 'quarantine_list' },
            function(data) {
                if (data.status === 'success' && data.data && data.data.length > 0) {
                    renderQuarantineTable(data.data);
                } else {
                    $('#quarantine_table_body').html('<tr><td colspan="5">Tidak ada file dalam karantina.</td></tr>');
                }
            }, 'json'
        );
    }
    
    // =================================================================
    // D. EVENT LISTENERS
    // =================================================================

    $('#toggle_inotify').click(function() {
        var button = $(this);
        var currentState = button.data('state');
        button.prop('disabled', true).text('Memproses...');
        $.post('index.php?module=3rdparty&action=developer&mod=lmd_manager', { action_type: 'toggle_inotify', state: currentState },
            function(data) { alert(data.message); loadSummary(); }, 'json'
        ).always(function() { button.prop('disabled', false); });
    });
    
    $('#update_signature').click(function() {
        var button = $(this);
        button.prop('disabled', true).text('Memproses Pembaruan...');
        $.post('index.php?module=3rdparty&action=developer&mod=lmd_manager', { action_type: 'update_signature' },
            function(data) { alert(data.message); setTimeout(loadSummary, 5000); }, 'json'
        ).always(function() { button.prop('disabled', false).text('Perbarui Signature Sekarang'); });
    });

    $('#scan_form').submit(function(e) {
        e.preventDefault();
        var button = $('#start_scan_button');
        var type = $('input[name="scan_type_radio"]:checked').val();

        $('#scan_log').text('Memulai pemindaian...\n');
        button.prop('disabled', true).text('Memproses Permintaan...');
        
        $.post('index.php?module=3rdparty&action=developer&mod=lmd_manager', $(this).serialize() + '&action_type=start_scan',
            function(data) {
                if (data.status === 'success') { alert(data.message); startPolling(); } 
                else { alert('Gagal memicu: ' + data.message); button.prop('disabled', false).text('Mulai Pemindaian'); }
            }, 'json'
        ).fail(function() { alert('Kesalahan jaringan.'); button.prop('disabled', false).text('Mulai Pemindaian'); });
    });
    
    $('#settings_form').submit(function(e) {
        e.preventDefault();
        $.post('index.php?module=3rdparty&action=developer&mod=lmd_manager', $(this).serialize() + '&action_type=save_settings',
            function(data) { alert(data.message); }, 'json'
        );
    });
    $('#test_telegram').click(function() {
        $.post('index.php?module=3rdparty&action=developer&mod=lmd_manager', { action_type: 'test_telegram' },
            function(data) { alert(data.status === 'success' ? 'Sukses: ' + data.message : 'Gagal: ' + data.message); }, 'json'
        );
    });

    function handleQuarantineAction(actionType, button) {
        var selectedQids = $('input[name="qid[]"]:checked').map(function(){ return $(this).val(); }).get();

        if (selectedQids.length === 0 || !confirm('Yakin ' + actionType.toUpperCase() + ' ' + selectedQids.length + ' file?')) { return; }

        button.prop('disabled', true).text('Memproses...');
        $.post('index.php?module=3rdparty&action=developer&mod=lmd_manager',
            { action_type: 'quarantine_action', action_q: actionType, file_ids: selectedQids },
            function(data) { alert(data.message); loadQuarantineList(); }, 'json'
        ).always(function() { 
             // Reset teks tombol berdasarkan actionType
             var initialText = actionType.charAt(0).toUpperCase() + actionType.slice(1) + 'kan yang Dipilih';
             if (actionType === 'restore') initialText = 'Pulihkan yang Dipilih';
             if (actionType === 'delete') initialText = 'Hapus Permanen yang Dipilih';
             if (actionType === 'clean') initialText = 'Coba Bersihkan yang Dipilih';

             button.prop('disabled', false).text(initialText); 
        }).fail(function() {
            alert('Gagal terhubung ke server. Periksa koneksi.');
        });
    }
    $('#restore_button').click(function() { handleQuarantineAction('restore', $(this)); });
    $('#delete_button').click(function() { handleQuarantineAction('delete', $(this)); });
    $('#clean_button').click(function() { handleQuarantineAction('clean', $(this)); });

});
</script>
