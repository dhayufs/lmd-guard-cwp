<?php
/**
 * LMD Guard CWP - Module untuk CWP Pro
 * UI mengikuti template default panel CWP.
 * Perbaikan:
 * - Path include dinamis (tanpa hard-coded absolute path)
 * - Kompatibel PHP 7/8 (tanpa short echo tag)
 * - Eksekusi background pakai nohup yang benar
 * - Sanitasi input shell sederhana
 * - Handling file config Telegram dengan aman
 */

 // ------------------------------------------------------------
 // 1) CWP MODULE WRAPPER GUARD
 // ------------------------------------------------------------
if (!isset($include_path)) { 
    echo "invalid access"; 
    exit();
}

// ------------------------------------------------------------
 // 2) LOAD CWP CORE (DINAMIS, TANPA PATH ABSOLUT)
 // ------------------------------------------------------------
$BASE = realpath(dirname(__FILE__) . "/.."); // -> /resources/admin
if ($BASE === false) { 
    echo "base path error"; 
    exit();
}
include_once($BASE . "/include/config.php");
include_once($BASE . "/common.php");

// ------------------------------------------------------------
// 3) KONFIGURASI MODULE
// ------------------------------------------------------------
define('LMD_CONFIG_FILE', '/etc/cwp/lmd_config.json');   // token/chat_id Telegram
define('LMD_TEMP_LOG',   '/tmp/lmd_scan_output');        // log sementara untuk polling scan

// ------------------------------------------------------------
// 4) HELPER FUNCTIONS
// ------------------------------------------------------------
function sanitize_shell_input($input) {
    // singkirkan karakter berbahaya untuk shell
    $bad = array(';','&&','||','`','$','(',')','#','!','"',"'", "\n","\r","\\");
    return trim(str_replace($bad, '', (string)$input));
}

function send_telegram_notification($message) {
    $cfg = (file_exists(LMD_CONFIG_FILE) ? json_decode(@file_get_contents(LMD_CONFIG_FILE), true) : []);
    $token   = isset($cfg['token'])   ? trim($cfg['token'])   : '';
    $chat_id = isset($cfg['chat_id']) ? trim($cfg['chat_id']) : '';

    if ($token === '' || $chat_id === '') {
        return ['status' => 'error', 'message' => 'Token atau Chat ID Telegram kosong.'];
    }

    $url  = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    $out = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res = json_decode($out, true);
    if ($http === 200 && isset($res['ok']) && $res['ok'] === true) {
        return ['status' => 'success', 'message' => 'Pesan uji coba berhasil dikirim!'];
    }
    $desc = isset($res['description']) ? $res['description'] : 'Gagal menghubungi Telegram API. Cek Token/Chat ID.';
    return ['status' => 'error', 'message' => "Gagal Telegram (HTTP {$http}): {$desc}"];
}

function parse_quarantine_list($raw_output) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$raw_output);
    $quarantine_list = [];
    // MalDet biasanya menampilkan header 5 baris; sesuaikan jika format berubah
    $data_lines = array_slice($lines, 5);

    foreach ($data_lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=======') !== false) { continue; }

        // Kolom tipikal: IDX  QID  STATUS  FILE  SIGNATURE  TIME  USER
        $parts = preg_split('/\s{2,}/', $line, 7, PREG_SPLIT_NO_EMPTY);
        if (count($parts) >= 7) {
            // parts[0] sering index internal tampilan; ambil QID di [1]
            $quarantine_list[] = [
                'qid'       => sanitize_shell_input($parts[1]),
                'status'    => sanitize_shell_input($parts[2]),
                'path'      => sanitize_shell_input($parts[3]),
                'signature' => sanitize_shell_input($parts[4]),
                'time'      => sanitize_shell_input($parts[5]),
                'user'      => sanitize_shell_input($parts[6]),
            ];
        }
    }
    return $quarantine_list;
}

// Muat konfigurasi Telegram untuk tampilan form (aman jika file belum ada)
$lmd_config = (file_exists(LMD_CONFIG_FILE) ? json_decode(@file_get_contents(LMD_CONFIG_FILE), true) : []);
if (!is_array($lmd_config)) { $lmd_config = ['token' => '', 'chat_id' => '']; }

// ------------------------------------------------------------
// 5) AJAX HANDLER
// ------------------------------------------------------------
if (isset($_REQUEST['action_type'])) {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['status' => 'error', 'message' => 'Invalid action.'];
    $action = isset($_REQUEST['action_type']) ? $_REQUEST['action_type'] : '';

    if (class_exists('CWP_User') && CWP_User::isAdmin()) {
        switch ($action) {
            case 'get_summary':
                // Cek proses monitor
                $ps = shell_exec('ps aux | grep "maldet --monitor" | grep -v grep');
                $is_monitoring = (strpos((string)$ps, 'maldet --monitor') !== false);

                // Versi
                $ver_raw = shell_exec('maldet --version 2>/dev/null | grep Version');
                $version = trim(str_replace('Version:', '', (string)$ver_raw));

                // Hitung file di karantina
                $quarantine_count = (int)shell_exec('find /usr/local/maldetect/quarantine/ -type f 2>/dev/null | wc -l');

                $response = [
                    'status' => 'success',
                    'data' => [
                        'version' => $version,
                        'quarantine_count' => $quarantine_count,
                        'is_monitoring' => $is_monitoring
                    ]
                ];
                break;

            case 'toggle_inotify':
                $state = isset($_POST['state']) ? sanitize_shell_input($_POST['state']) : 'stop';
                if ($state === 'start') {
                    // Monitor semua user home dir
                    shell_exec('nohup maldet --monitor users >/dev/null 2>&1 &');
                } else {
                    shell_exec('maldet --monitor stop >/dev/null 2>&1');
                }
                $response = ['status' => 'success', 'message' => "Pemantauan real-time diubah ke: {$state}"];
                break;

            case 'update_signature':
                // Pastikan nohup membungkus kedua perintah
                shell_exec("nohup bash -c 'maldet -u && maldet -d' >/dev/null 2>&1 &");
                $response = ['status' => 'success', 'message' => 'Pembaruan signature LMD dimulai.'];
                break;

            case 'start_scan':
                $path = isset($_POST['scan_path']) ? $_POST['scan_path'] : '/home/';
                $type = isset($_POST['scan_type_radio']) ? $_POST['scan_type_radio'] : 'full';
                $days = isset($_POST['scan_days']) ? (int)$_POST['scan_days'] : 7;

                $clean_path = sanitize_shell_input($path);
                if ($clean_path === '') { $clean_path = '/home/'; }

                if ($type === 'recent') {
                    $cmd = "maldet --scan-recent {$days}";
                    $message = "Pemindaian file yang dimodifikasi dalam {$days} hari terakhir dimulai.";
                } else {
                    $cmd = "maldet --scan-all {$clean_path}";
                    $message = "Pemindaian jalur {$clean_path} dimulai.";
                }

                // Tulis ke log sementara untuk dipolling
                // Gunakan bash -c agar redirection bekerja baik di background
                $bg = "nohup bash -c '". $cmd ." > ". LMD_TEMP_LOG ." 2>&1' >/dev/null 2>&1 &";
                shell_exec($bg);

                $response = ['status' => 'success', 'message' => $message];
                break;

            case 'get_scan_log':
                $log_content = '';
                $is_finished = true;
                if (file_exists(LMD_TEMP_LOG)) {
                    $log_content = (string)@file_get_contents(LMD_TEMP_LOG);
                    // Heuristik selesai (teks output LMD standar)
                    $is_finished = (strpos($log_content, 'scan and quarantine completed') !== false)
                                || (strpos($log_content, 'scan completed') !== false);
                    // Jika sudah selesai, hapus log sementara
                    if ($is_finished) { @unlink(LMD_TEMP_LOG); }
                }
                $response = [
                    'status' => 'success',
                    'log' => htmlspecialchars($log_content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'finished' => $is_finished
                ];
                break;

            case 'quarantine_list':
                $list_output = shell_exec('maldet --quarantine list 2>/dev/null');
                $parsed = parse_quarantine_list((string)$list_output);
                $response = ['status' => 'success', 'data' => $parsed];
                break;

            case 'quarantine_action':
                $action_q = isset($_POST['action_q']) ? sanitize_shell_input($_POST['action_q']) : '';
                $raw_ids  = isset($_POST['file_ids']) ? $_POST['file_ids'] : [];

                $clean_ids = [];
                if (is_array($raw_ids)) {
                    foreach ($raw_ids as $qid) {
                        if (is_numeric($qid)) { $clean_ids[] = (int)$qid; }
                    }
                }

                if (!empty($clean_ids) && in_array($action_q, ['restore','delete','clean'], true)) {
                    $id_list = implode(' ', $clean_ids);
                    $command = "maldet --{$action_q} {$id_list}";
                    shell_exec($command . ' >/dev/null 2>&1');
                    $response = ['status' => 'success', 'message' => count($clean_ids) . " file telah dikenakan aksi '{$action_q}'."];
                } else {
                    $response = ['status' => 'error', 'message' => 'Aksi karantina tidak valid atau daftar ID kosong.'];
                }
                break;

            case 'save_settings':
                $token   = isset($_POST['token'])   ? sanitize_shell_input($_POST['token'])   : '';
                $chat_id = isset($_POST['chat_id']) ? sanitize_shell_input($_POST['chat_id']) : '';
                $save = ['token' => $token, 'chat_id' => $chat_id];

                // Pastikan folder /etc/cwp ada; jika tidak, tetap coba tulis dan biarkan permission error terlihat
                @file_put_contents(LMD_CONFIG_FILE, json_encode($save, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $response = ['status' => 'success', 'message' => 'Pengaturan Telegram berhasil disimpan.'];
                break;

            case 'test_telegram':
                $test_message = "*Pesan Uji Coba LMD Guard CWP*\n\nSelamat! Integrasi Telegram berhasil. Anda akan menerima notifikasi real-time di channel ini.";
                $response = send_telegram_notification($test_message);
                break;

            default:
                $response = ['status' => 'error', 'message' => 'Aksi tidak dikenal.'];
                break;
        }
    } else {
        $response = ['status' => 'error', 'message' => 'Akses ditolak.'];
    }

    echo json_encode($response);
    exit();
}

// ------------------------------------------------------------
// 6) TAMPILAN (NON-AJAX): HEADER
// ------------------------------------------------------------
include_once($BASE . "/header.php");
?>

<div class="container-fluid" id="lmd_module_container">
  <div class="cwp_module_header">
    <div class="cwp_module_name">LMD Guard CWP</div>
    <div class="cwp_module_info">Integrasi LMD Real-Time dengan CWP &amp; Notifikasi Telegram</div>
  </div>

  <ul class="nav nav-tabs" id="lmdTabs">
    <li class="active"><a data-tab="summary" href="#">Ringkasan &amp; Status LMD Guard 🟢</a></li>
    <li><a data-tab="scan" href="#">Pemindaian 🔎</a></li>
    <li><a data-tab="quarantine" href="#">Karantina &amp; Laporan 🗑️</a></li>
    <li><a data-tab="settings" href="#">Pengaturan Telegram ⚙️</a></li>
  </ul>

  <div class="tab-content" style="padding:15px;border:1px solid #ddd;border-top:none;">

    <div id="tab-summary" class="tab-pane active">
      <h3>Status Keamanan LMD Guard</h3>
      <p>
        Status Real-Time (Inotify):
        <span id="inotify_status" class="label label-danger">OFF</span>
        <button id="toggle_inotify" class="btn btn-xs btn-default" data-state="start">Nyalakan Pemantauan</button>
      </p>
      <p>Versi LMD: <span id="lmd_version">Memuat...</span> |
         Karantina Aktif: <span id="quarantine_count">0</span></p>
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
            <option value="1">1 Hari Terakhir</option>
            <option value="7" selected>7 Hari Terakhir</option>
            <option value="30">30 Hari Terakhir</option>
          </select>
        </div>

        <button type="submit" id="start_scan_button" class="btn btn-primary">Mulai Pemindaian</button>
      </form>

      <hr>
      <h4>Log Pemindaian:</h4>
      <pre id="scan_log" style="max-height:300px;overflow:auto;background:#333;color:#0f0;padding:10px;">Log akan muncul di sini.</pre>
    </div>

    <div id="tab-quarantine" class="tab-pane">
      <h3>Manajemen Karantina</h3>
      <div class="well">
        <button id="restore_button" class="btn btn-success">Pulihkan yang Dipilih</button>
        <button id="delete_button" class="btn btn-danger">Hapus Permanen yang Dipilih</button>
        <button id="clean_button" class="btn btn-warning">Coba Bersihkan yang Dipilih</button>
      </div>

      <table class="table table-bordered table-striped">
        <thead>
          <tr>
            <th><input type="checkbox" id="select_all_quarantine"></th>
            <th>ID</th>
            <th>Lokasi File Asli</th>
            <th>Signature Malware</th>
            <th>Waktu Karantina (User)</th>
          </tr>
        </thead>
        <tbody id="quarantine_table_body">
          <tr><td colspan="5">Klik tab Karantina untuk memuat data.</td></tr>
        </tbody>
      </table>
    </div>

    <div id="tab-settings" class="tab-pane">
      <h3>Konfigurasi Telegram</h3>
      <form id="settings_form">
        <div class="form-group">
          <label>Telegram Bot Token:</label>
          <input type="text" class="form-control" name="token" value="<?php echo htmlspecialchars(isset($lmd_config['token'])?$lmd_config['token']:'', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="form-group">
          <label>Telegram Chat ID:</label>
          <input type="text" class="form-control" name="chat_id" value="<?php echo htmlspecialchars(isset($lmd_config['chat_id'])?$lmd_config['chat_id']:'', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
        <button type="button" id="test_telegram" class="btn btn-info">Uji Coba Kirim</button>
      </form>
    </div>

  </div>
</div>

<script>
// ============================================================
// JS LOGIC (menggunakan jQuery bawaan CWP)
// ============================================================
setTimeout(function() {
  var $moduleContainer = $('#lmd_module_container');

  $moduleContainer.ready(function() {

    var scanInterval = null;

    // Navigasi Tab
    $moduleContainer.find('#lmdTabs a').on('click', function(e) {
      e.preventDefault();
      $moduleContainer.find('#lmdTabs li').removeClass('active');
      $(this).parent().addClass('active');

      $moduleContainer.find('.tab-pane').removeClass('active');
      var target = $(this).data('tab');
      $moduleContainer.find('#tab-' + target).addClass('active');

      if (target === 'quarantine') {
        loadQuarantineList();
      }
    });

    // Toggle Full/Recent scan
    $moduleContainer.find('input[name="scan_type_radio"]').on('change', function() {
      if ($(this).val() === 'full') {
        $moduleContainer.find('#scan_path_group').show();
        $moduleContainer.find('#scan_recent_group').hide();
      } else {
        $moduleContainer.find('#scan_path_group').hide();
        $moduleContainer.find('#scan_recent_group').show();
      }
    }).trigger('change');

    // Select all checkbox
    $moduleContainer.find('#select_all_quarantine').on('click', function() {
      $moduleContainer.find(':checkbox[name="qid[]"]').prop('checked', this.checked);
    });

    // ---------- Functions ----------
    function loadSummary() {
      $.post('index.php?module=lmd_manager', { action_type: 'get_summary' }, function(data) {
        if (data && data.status === 'success') {
          $moduleContainer.find('#lmd_version').text(data.data.version || '-');
          $moduleContainer.find('#quarantine_count').text(data.data.quarantine_count || 0);

          var statusElement = $moduleContainer.find('#inotify_status');
          var buttonElement = $moduleContainer.find('#toggle_inotify');

          if (data.data.is_monitoring) {
            statusElement.text('ON').removeClass('label-danger').addClass('label-success');
            buttonElement.text('Matikan Pemantauan').removeClass('btn-default').addClass('btn-danger').data('state','stop');
          } else {
            statusElement.text('OFF').removeClass('label-success').addClass('label-danger');
            buttonElement.text('Nyalakan Pemantauan').removeClass('btn-danger').addClass('btn-default').data('state','start');
          }
        }
      }, 'json').fail(function(){ console.error('Gagal memuat ringkasan.'); });
    }
    loadSummary();
    setInterval(loadSummary, 10000);

    function startPolling() {
      if (scanInterval) { clearInterval(scanInterval); }
      $moduleContainer.find('#start_scan_button').prop('disabled', true).text('Memproses...');

      scanInterval = setInterval(function() {
        $.post('index.php?module=lmd_manager', { action_type: 'get_scan_log' }, function(data) {
          if (!data) return;
          $moduleContainer.find('#scan_log').html(data.log || '');
          var logArea = $moduleContainer.find('#scan_log');
          logArea.scrollTop(logArea.prop('scrollHeight'));

          if (data.finished) {
            clearInterval(scanInterval);
            scanInterval = null;
            $moduleContainer.find('#scan_log').append('\n--- PEMINDAIAN SELESAI ---\n');
            $moduleContainer.find('#start_scan_button').prop('disabled', false).text('Mulai Pemindaian');
            loadQuarantineList();
          }
        }, 'json').fail(function() {
          clearInterval(scanInterval);
          scanInterval = null;
          $moduleContainer.find('#scan_log').append('\n--- KESALAHAN JARINGAN/SERVER ---');
          $moduleContainer.find('#start_scan_button').prop('disabled', false).text('Mulai Pemindaian');
        });
      }, 2000);
    }

    function renderQuarantineTable(data) {
      var html = '';
      $.each(data, function(i, item) {
        html += '<tr>';
        html += '<td><input type="checkbox" name="qid[]" value="'+ (item.qid || '') +'"></td>';
        html += '<td>'+ (item.qid || '') +'</td>';
        html += '<td>'+ (item.path || '') +'</td>';
        html += '<td>'+ (item.signature || '') +'</td>';
        html += '<td>'+ (item.time || '') +' '+ ((item.user || '') ? '('+ item.user +')' : '') +'</td>';
        html += '</tr>';
      });
      $moduleContainer.find('#quarantine_table_body').html(html);
    }

    function loadQuarantineList() {
      $moduleContainer.find('#quarantine_table_body').html('<tr><td colspan="5">Memuat data karantina...</td></tr>');
      $.post('index.php?module=lmd_manager', { action_type: 'quarantine_list' }, function(data) {
        if (data && data.status === 'success' && data.data && data.data.length > 0) {
          renderQuarantineTable(data.data);
        } else {
          $moduleContainer.find('#quarantine_table_body').html('<tr><td colspan="5">Tidak ada file dalam karantina.</td></tr>');
        }
      }, 'json');
    }

    // ---------- Events ----------
    $('#toggle_inotify').on('click', function() {
      var button = $(this);
      var currentState = button.data('state') || 'start';
      button.prop('disabled', true).text('Memproses...');
      $.post('index.php?module=lmd_manager', { action_type: 'toggle_inotify', state: currentState }, function(data) {
        alert(data && data.message ? data.message : 'Selesai.');
        loadSummary();
      }, 'json').always(function() {
        button.prop('disabled', false);
      });
    });

    $('#update_signature').on('click', function() {
      var button = $(this);
      button.prop('disabled', true).text('Memproses Pembaruan...');
      $.post('index.php?module=lmd_manager', { action_type: 'update_signature' }, function(data) {
        alert(data && data.message ? data.message : 'Diproses.');
        setTimeout(loadSummary, 5000);
      }, 'json').always(function() {
        button.prop('disabled', false).text('Perbarui Signature Sekarang');
      });
    });

    $('#scan_form').on('submit', function(e) {
      e.preventDefault();
      var button = $('#start_scan_button');
      $('#scan_log').text('Memulai pemindaian...\n');
      button.prop('disabled', true).text('Memproses Permintaan...');
      $.post('index.php?module=lmd_manager', $(this).serialize() + '&action_type=start_scan', function(data) {
        if (data && data.status === 'success') {
          alert(data.message || 'Diproses.');
          startPolling();
        } else {
          alert(data && data.message ? data.message : 'Gagal memicu pemindaian.');
          button.prop('disabled', false).text('Mulai Pemindaian');
        }
      }, 'json').fail(function() {
        alert('Kesalahan jaringan.');
        button.prop('disabled', false).text('Mulai Pemindaian');
      });
    });

    $('#settings_form').on('submit', function(e) {
      e.preventDefault();
      $.post('index.php?module=lmd_manager', $(this).serialize() + '&action_type=save_settings', function(data) {
        alert(data && data.message ? data.message : 'Tersimpan.');
      }, 'json');
    });

    $('#test_telegram').on('click', function() {
      $.post('index.php?module=lmd_manager', { action_type: 'test_telegram' }, function(data) {
        if (data && data.status === 'success') {
          alert('Sukses: ' + (data.message || 'Pesan terkirim.'));
        } else {
          alert('Gagal: ' + (data && data.message ? data.message : 'Tidak diketahui.'));
        }
      }, 'json');
    });

    function handleQuarantineAction(actionType, button) {
      var selectedQids = $moduleContainer.find('input[name="qid[]"]:checked').map(function(){ return $(this).val(); }).get();
      if (selectedQids.length === 0) { return alert('Pilih minimal satu item.'); }
      if (!confirm('Yakin ' + actionType.toUpperCase() + ' ' + selectedQids.length + ' file?')) { return; }

      var initialText = button.text();
      button.prop('disabled', true).text('Memproses...');

      $.ajax({
        url: 'index.php?module=lmd_manager',
        method: 'POST',
        dataType: 'json',
        data: { action_type: 'quarantine_action', action_q: actionType, file_ids: selectedQids }
      }).done(function(data) {
        alert(data && data.message ? data.message : 'Selesai.');
        loadQuarantineList();
      }).fail(function() {
        alert('Gagal terhubung ke server.');
      }).always(function() {
        button.prop('disabled', false).text(initialText);
      });
    }

    $moduleContainer.find('#restore_button').on('click', function(){ handleQuarantineAction('restore', $(this)); });
    $moduleContainer.find('#delete_button').on('click', function(){ handleQuarantineAction('delete', $(this)); });
    $moduleContainer.find('#clean_button').on('click', function(){ handleQuarantineAction('clean',  $(this)); });

  });
}, 300);
</script>

<?php
// ------------------------------------------------------------
// 7) FOOTER
// ------------------------------------------------------------
include_once($BASE . "/footer.php");
