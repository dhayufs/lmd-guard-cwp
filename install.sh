#!/bin/bash
set -euo pipefail # Safety flags untuk stabilitas dan deteksi unbound variable

# =================================================================
# 1. DEFINISI VARIABEL (HARUS ADA DI AWAL)
# =================================================================
REPO_RAW_URL="https://raw.githubusercontent.com/dhayufs/lmd-guard-cwp/main" 
BRAND_NAME="LMD Guard CWP"
MOD_NAME="lmd_manager" 

CWP_ADMIN_DIR="/usr/local/cwpsrv/htdocs/resources/admin"

# Variabel Path KRITIS
CONFIG_FILE="/etc/cwp/lmd_config.json"
LMD_CONF="/usr/local/maldetect/conf.maldet"
HOOK_SCRIPT="/usr/local/maldetect/hook/post_quarantine.sh"
MOD_DIR_FINAL="${CWP_ADMIN_DIR}/modules" 
MENU_CONFIG_FILE="${CWP_ADMIN_DIR}/include/3rdparty.php" 

# --- Variabel Tambahan untuk Kestabilan Modul ---
LMD_DEPENDENCY="maldet"
LMD_RESTORE_DIR="/root/lmd_restored" # Fix 3: Folder Restore
LOCAL_CONFIG_DIR="/usr/local/cwp/.conf"
LOCAL_CONFIG_FILE="${LOCAL_CONFIG_DIR}/lmd_guard.ini" # Fix 2: Config File Lokal

echo "--- Memulai Instalasi ${BRAND_NAME} dari GitHub (Production Ready) ---"

# =================================================================
# 2. PROSES INSTALASI
# =================================================================

# --- A. CEK DEPENDENSI (Fix Tambahan: Wajib Ada) ---
echo "--- Memeriksa Dependensi ---"
if ! command -v "${LMD_DEPENDENCY}" &> /dev/null; then
    echo "🚨 GAGAL: ${LMD_DEPENDENCY} tidak ditemukan. Instal LMD terlebih dahulu!"
    exit 1
fi
echo "✅ LMD ditemukan."

# --- B. PENYIAPAN DIRECTORY MODUL ---
echo "--- Menyiapkan direktori dan unduh file ---"
mkdir -p /etc/cwp/
mkdir -p "${MOD_DIR_FINAL}" 
mkdir -p "$(dirname "$HOOK_SCRIPT")"

# Fix 3: Buat folder restore untuk menghindari kegagalan tombol restore
mkdir -p "${LMD_RESTORE_DIR}" 
echo "✅ Direktori Restore ${LMD_RESTORE_DIR} dibuat."

# Fix 2: Buat file config LMD Guard INI (opsional, tapi bagus untuk masa depan)
mkdir -p "${LOCAL_CONFIG_DIR}"
if [ ! -f "$LOCAL_CONFIG_FILE" ]; then
    cat > "$LOCAL_CONFIG_FILE" <<EOF
TELEGRAM_BOT=
TELEGRAM_CHAT=
CRON_SCHEDULE=OFF
CSF_AUTOBAN=0
EOF
    echo "✅ File konfigurasi lokal INI dibuat: ${LOCAL_CONFIG_FILE}"
fi

# Mengunduh file modul PHP/HTML/JS FINAL
curl -o "${MOD_DIR_FINAL}/${MOD_NAME}.php" -L "${REPO_RAW_URL}/${MOD_NAME}.php"
echo "✅ ${MOD_NAME}.php berhasil diunduh ke lokasi modul yang benar."

# Membuat file konfigurasi LMD Manager jika belum ada (untuk PHP)
if [[ ! -f "$CONFIG_FILE" ]]; then
    echo '{"token": "", "chat_id": ""}' > "${CONFIG_FILE}"
    chmod 600 "${CONFIG_FILE}"
    echo "✅ File konfigurasi ${CONFIG_FILE} dibuat."
fi

# --- 3. IMPLEMENTASI LOGIKA MENU DAN KOREKSI CONTROLLER LAMA ---
echo "--- Membersihkan dan Menyiapkan Menu CWP ---"

# A. CLEANUP LOGIC PHP YANG SALAH (PENTING!)
sed -i '/lmd_manager/d' "${CWP_ADMIN_DIR}/include/3rdparty.php" || true 
echo "✅ Logic lama dari 3rdparty.php telah dihapus (Cleanup)."


# B. MEMBUAT/MENULIS ULANG FILE MENU (3rdparty.php)
MENU_LINK_HTML="<li><a href=\"index.php?module=${MOD_NAME}\"><span class=\"icon16 icomoon-icon-arrow-right-3\"></span>${BRAND_NAME}</a></li>"

# Fix 1: Mencegah Duplikasi Menu dengan grep -q (Cek Baris)
if ! grep -q "${MOD_NAME}" "$MENU_CONFIG_FILE"; then
    echo "${MENU_LINK_HTML}" >> "${MENU_CONFIG_FILE}"
    echo "✅ Link menu ${BRAND_NAME} ditambahkan ke ${MENU_CONFIG_FILE} (Cegah duplikasi)."
else
    echo "✅ Link menu sudah ada, dilewati."
fi


# --- (Sisa Skrip Hook dan Konfigurasi LMD Tetap Sama) ---
# ... (Blok code untuk membuat HOOK_SCRIPT) ...
echo "--- Membuat skrip hook real-time Telegram ---"
cat << EOF_HOOK > "${HOOK_SCRIPT}"
#!/bin/bash
set -euo pipefail 

FILE_PATH="\$1"
SIGNATURE="\$2"
HOST_NAME=\$(hostname)
CONFIG_FILE="/etc/cwp/lmd_config.json"

TOKEN=\$(grep -o '"token": *"[^"]*"' "\$CONFIG_FILE" | grep -o '"[^"]*"' | tr -d '"')
CHAT_ID=\$(grep -o '"chat_id": *"[^"]*"' "\$CONFIG_FILE" | grep -o '"[^"]*"' | tr -d '"')

if [ -z "\$TOKEN" ] || [ -z "\$CHAT_ID" ]; then
    exit 0
fi

MESSAGE="🚨 *MALWARE REAL-TIME DIKARANTINA* 🚨\n\n*Server:* \$HOST_NAME\n*Waktu:* \$(date '+%Y-%m-%d %H:%M:%S')\n*File:* \`\$FILE_PATH\`\n*Ancaman:* \$SIGNATURE\n*Aksi:* **Karantina Instan** oleh ${BRAND_NAME}."

curl -s -X POST "https://api.telegram.org/bot\$TOKEN/sendMessage" \
-d chat_id="\$CHAT_ID" \
-d parse_mode="Markdown" \
-d text="\$MESSAGE" > /dev/null 2>&1

exit 0
EOF_HOOK
chmod +x "${HOOK_SCRIPT}"
echo "✅ Skrip hook post_quarantine.sh dibuat dan siap."


# --- Konfigurasi Sistem (LMD) ---
echo "--- Modifikasi konfigurasi sistem LMD ---"

if [[ ! -w "$LMD_CONF" ]]; then
    echo "🚨 GAGAL KRITIS: File konfigurasi LMD (\$LMD_CONF) tidak dapat ditulisi."
    exit 1
fi

if grep -q "quarantine_exec_file" "$LMD_CONF"; then
    sed -i "s|^quarantine_exec_file=.*|quarantine_exec_file=${HOOK_SCRIPT}|g" "$LMD_CONF"
else
    echo "quarantine_exec_file=${HOOK_SCRIPT}" >> "$LMD_CONF"
fi
echo "✅ Konfigurasi LMD untuk hook berhasil."

# --- FINALISASI ---
echo "--- INSTALASI ${BRAND_NAME} SELESAI TOTAL ---"
echo "JANGAN LUPA RESTART CWP SERVICE: service cwpsrv restart"
echo "URL Akses Langsung: /index.php?module=${MOD_NAME}"
