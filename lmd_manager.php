<?php
// ==============================================================================
// CWP MODULE WRAPPER (FINAL FIX: Menggunakan logic $include_path dari example.php)
// ==============================================================================
if ( !isset( $include_path ) ) { 
    echo "invalid access"; 
    exit(); 
}

// >>> KOREKSI KRITIS: MENGHAPUS SEMUA include_once PATH YANG GAGAL <<<
// Kita hanya mengandalkan CWP telah memuat COMMON dan CONFIG global.
// Include header/footer yang gagal harus diganti dengan markup statis atau dihapus.

// *Komentari/Hapus path yang gagal*
// include_once("/usr/local/cwpsrv/htdocs/resources/admin/include/config.php"); 
// include_once("/usr/local/cwpsrv/htdocs/resources/admin/common.php"); 
// [CWP_User::isAdmin() seharusnya sudah tersedia di sini jika CWP bekerja normal]

// ==============================================================================
// BLOK 1: LOGIKA SERVER PHP & HELPER 
// ==============================================================================

// Lokasi file config JSON (untuk Telegram/Inotify Status)
define('LMD_CONFIG_FILE', '/etc/cwp/lmd_config.json');
// Lokasi file log sementara untuk polling scan
define('LMD_TEMP_LOG', '/tmp/lmd_scan_output'); 

// 1. FUNGSI HELPER KEAMANAN: Sanitasi Shell Input
function sanitize_shell_input($input) {
    $input = str_replace(array(';', '&&', '||', '`', '$', '(', ')', '#', '!', "\n", "\r", '\\'), '', $input);
    $input = str_replace("'", "\'", $input);
    return trim($input);
}

// 2. FUNGSI HELPER TELEGRAM
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

// 3. FUNGSI HELPER PARSING KARANTINA
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

// Baca konfigurasi di sini agar bisa diakses oleh fungsi helper
$lmd_config = file_exists(LMD_CONFIG_FILE) ? json_decode(file_get_contents(LMD_CONFIG_FILE), true) : ['token' => '', 'chat_id' => ''];

// --- BLOK LOGIKA SERVER AJAX ---
if (isset($_REQUEST['action_type'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid action.'];
    $action = $_REQUEST['action_type'];
    
    // Asumsi CWP_User::isAdmin() sudah tersedia
    if (CWP_User::isAdmin()) { 
        switch ($action) {
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

// ==============================================================================
// BLOK 2: TAMPILAN HTML DASHBOARD (JIKA BUKAN AJAX REQUEST)
// ==============================================================================

<div class="container-fluid" id="lmd_module_container">

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
// ==============================================================================
// SCRIPT JAVASCRIPT
// ==============================================================================
// Penundaan 500ms untuk memberi waktu CWP menyelesaikan inisialisasi UI
setTimeout(function() {
    var $moduleContainer = $('#lmd_module_container');
    
    $moduleContainer.ready(function() {
        
        var scanInterval = null; 

        // Navigasi Tab
        $moduleContainer.find('#lmdTabs a').click(function (e) {
            e.preventDefault();
            $(this).tab('show');
            $moduleContainer.find('.tab-pane').removeClass('active');
            
            var targetTab = $(this).data('tab');
            $moduleContainer.find('#tab-' + targetTab).addClass('active');

            if (targetTab === 'quarantine') {
                loadQuarantineList(); 
            }
        });

        // Toggle Full/Recent Scan
        $moduleContainer.find('input[name="scan_type_radio"]').change(function() {
            if ($(this).val() === 'full') {
                $moduleContainer.find('#scan_path_group').show();
                $moduleContainer.find('#scan_recent_group').hide();
            } else {
                $moduleContainer.find('#scan_path_group').hide();
                $moduleContainer.find('#scan_recent_group').show();
            }
        }).trigger('change'); 

        // Select All Checkbox
        $moduleContainer.find('#select_all_quarantine').click(function() {
            $moduleContainer.find(':checkbox[name="qid[]"]').prop('checked', this.checked);
        });
        
        // =================================================================
        // FUNGSI JAVASCRIPT: LOGIKA INTI
        // =================================================================
        
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

        function startPolling() {
            if (scanInterval) { clearInterval(scanInterval); }
            $moduleContainer.find('#start_scan_button').prop('disabled', true).text('Memproses...');

            scanInterval = setInterval(function() {
                $.post('index.php?module=lmd_manager', { action_type: 'get_scan_log' },
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
        
        // =================================================================
        // D. EVENT LISTENERS
        // =================================================================

        // D1. Toggle Inotify
        $('#toggle_inotify').click(function() {
            var button = $(this);
            var currentState = button.data('state');
            button.prop('disabled', true).text('Memproses...');
            $.post('index.php?module=lmd_manager', { action_type: 'toggle_inotify', state: currentState },
                function(data) { alert(data.message); loadSummary(); }, 'json'
            ).always(function() { button.prop('disabled', false); });
        });
        
        // D2. Update Signature
        $('#update_signature').click(function() {
            var button = $(this);
            button.prop('disabled', true).text('Memproses Pembaruan...');
            $.post('index.php?module=lmd_manager', { action_type: 'update_signature' },
                function(data) { alert(data.message); setTimeout(loadSummary, 5000); }, 'json'
            ).always(function() { button.prop('disabled', false).text('Perbarui Signature Sekarang'); });
        });

        // D3. Submit Form Scan
        $('#scan_form').submit(function(e) {
            e.preventDefault();
            var button = $('#start_scan_button');
            var type = $('input[name="scan_type_radio"]:checked').val();

            $('#scan_log').text('Memulai pemindaian...\n');
            button.prop('disabled', true).text('Memproses Permintaan...');
            
            $.post('index.php?module=lmd_manager', $(this).serialize() + '&action_type=start_scan',
                function(data) {
                    if (data.status === 'success') { alert(data.message); startPolling(); } 
                    else { alert('Gagal memicu: ' + data.message); button.prop('disabled', false).text('Mulai Pemindaian'); }
                }, 'json'
            ).fail(function() { alert('Kesalahan jaringan.'); button.prop('disabled', false).text('Mulai Pemindaian'); });
        });
        
        // D4. Submit Form Pengaturan & Test Telegram
        $('#settings_form').submit(function(e) {
            e.preventDefault();
            $.post('index.php?module=lmd_manager', $(this).serialize() + '&action_type=save_settings',
                function(data) { alert(data.message); }, 'json'
            );
        });
        $('#test_telegram').click(function() {
            $.post('index.php?module=lmd_manager', { action_type: 'test_telegram' },
                function(data) { alert(data.status === 'success' ? 'Sukses: ' + data.message : 'Gagal: ' + data.message); }, 'json'
            );
        });

        // D5. Aksi Batch Karantina
        function handleQuarantineAction(actionType, button) {
            var selectedQids = $moduleContainer.find('input[name="qid[]"]:checked').map(function(){ return $(this).val(); }).get();

            if (selectedQids.length === 0 || !confirm('Yakin ' + actionType.toUpperCase() + ' ' + selectedQids.length + ' file?')) { return; }

            button.prop('disabled', true).text('Memproses...');
            $.post('index.php?module=lmd_manager',
                { action_type: 'quarantine_action', action_q: actionType, file_ids: selectedQids },
                function(data) { alert(data.message); loadQuarantineList(); }, 'json'
            ).always(function() { 
                 var initialText = actionType.charAt(0).toUpperCase() + actionType.slice(1) + 'kan yang Dipilih';
                 if (actionType === 'restore') initialText = 'Pulihkan yang Dipilih';
                 if (actionType === 'delete') initialText = 'Hapus Permanen yang Dipilih';
                 if (actionType === 'clean') initialText = 'Coba Bersihkan yang Dipilih';

                 button.prop('disabled', false).text(initialText); 
            }).fail(function() {
                alert('Gagal terhubung ke server. Periksa koneksi.');
            });
        }
        $moduleContainer.find('#restore_button').click(function() { handleQuarantineAction('restore', $(this)); });
        $moduleContainer.find('#delete_button').click(function() { handleQuarantineAction('delete', $(this)); });
        $moduleContainer.find('#clean_button').click(function() { handleQuarantineAction('clean', $(this)); });

    });
}, 500); // Tutup setTimeout
</script>
</div> <?php
// Wajib: Memanggil footer CWP
include_once("/usr/local/cwpsrv/htdocs/resources/admin/footer.php");
?>
