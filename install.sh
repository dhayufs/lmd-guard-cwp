#!/bin/bash
set -euo pipefail # Safety flags untuk stabilitas dan deteksi unbound variable

# =================================================================
# 1. DEFINISI VARIABEL (HARUS ADA DI AWAL)
# =================================================================

# GANTI INI DENGAN DETAIL REPO ANDA
REPO_RAW_URL="https://raw.githubusercontent.com/dhayufs/lmd-guard-cwp/main" 

BRAND_NAME="LMD Guard CWP"
MOD_NAME="lmd_manager" 

CWP_ADMIN_DIR="/usr/local/cwpsrv/htdocs/resources/admin"

# Variabel Path KRITIS (Penting untuk mengatasi unbound variable)
CONFIG_FILE="/etc/cwp/lmd_config.json"
LMD_CONF="/usr/local/maldetect/conf.maldet"
HOOK_SCRIPT="/usr/local/maldetect/hook/post_quarantine.sh"

MOD_DIR_FINAL="${CWP_ADMIN_DIR}/modules" 
MENU_CONFIG_FILE="${CWP_ADMIN_DIR}/include/3rdparty.php" 
MOD_CONTROLLER="${CWP_ADMIN_DIR}/include/3rdparty.php"

echo "--- Memulai Instalasi ${BRAND_NAME} dari GitHub (Final Fix V2) ---"

# =================================================================
# 2. PROSES INSTALASI
# =================================================================

# Cek LMD
if ! command -v maldet &> /dev/null; then
    echo "🚨 GAGAL: LMD tidak ditemukan. Instal LMD terlebih dahulu!"
    exit 1
fi
echo "✅ LMD ditemukan."

# Penyiapan Direktori dan Download File
echo "--- Menyiapkan direktori dan unduh file ---"
mkdir -p /etc/cwp/
mkdir -p "${MOD_DIR_FINAL}" 
mkdir -p "$(dirname "$HOOK_SCRIPT")"

# MENGUNDUH FILE PHP/HTML/JS FINAL ke lokasi yang BENAR.
# Menggunakan variabel $REPO_RAW_URL yang sudah didefinisikan di awal.
curl -o "${MOD_DIR_FINAL}/${MOD_NAME}.php" -L "${REPO_RAW_URL}/${MOD_NAME}.php"
echo "✅ ${MOD_NAME}.php berhasil diunduh ke lokasi modul yang benar."

# Membuat file konfigurasi LMD Manager jika belum ada
if [[ ! -f "$CONFIG_FILE" ]]; then
    echo '{"token": "", "chat_id": ""}' > "${CONFIG_FILE}"
    chmod 600 "${CONFIG_FILE}"
    echo "✅ File konfigurasi ${CONFIG_FILE} dibuat."
fi

# --- 3. IMPLEMENTASI LOGIKA MENU DAN KOREKSI CONTROLLER LAMA ---
echo "--- Membersihkan dan Menyiapkan Menu CWP ---"

# A. CLEANUP (MENGHAPUS LOGIC YANG SALAH DARI PERCOBAAN SEBELUMNYA)
# Menggunakan '|| true' untuk memastikan skrip tidak gagal jika file belum ada.
sed -i '/lmd_manager/d' "${CWP_ADMIN_DIR}/include/3rdparty.php" || true 
echo "✅ Logic lama dari 3rdparty.php telah dihapus (Cleanup)."


# B. MEMBUAT/MENULIS ULANG FILE MENU (3rdparty.php)
# Sesuai dokumen CWP, file ini hanya berisi link HTML
MENU_LINK_HTML="<li><a href=\"index.php?module=${MOD_NAME}\"><span class=\"icon16 icomoon-icon-arrow-right-3\"></span>${BRAND_NAME}</a></li>"

if [[ -f "$MENU_CONFIG_FILE" ]]; then
    # Jika file sudah ada, kita hanya append/tambah link kita di akhir
    echo "${MENU_LINK_HTML}" >> "${MENU_CONFIG_FILE}"
    echo "✅ Link menu ${BRAND_NAME} ditambahkan ke ${MENU_CONFIG_FILE}."
else
    # Jika file belum ada (instalasi pertama), kita buat dan isi
    echo "${MENU_LINK_HTML}" > "${MENU_CONFIG_FILE}"
    echo "✅ File menu ${MENU_CONFIG_FILE} dibuat dengan link ${BRAND_NAME}."
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

MESSAGE="🚨 *MALWARE REAL-TIME DIKARANTINA* 🚨\n\n*Server:* $HOST_NAME\n*Waktu:* $(date '+%Y-%m-%d %H:%M:%S')\n*File:* \`$FILE_PATH\`\n*Ancaman:* $SIGNATURE\n*Aksi:* **Karantina Instan** oleh ${BRAND_NAME}."

curl -s -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" \
-d chat_id="$CHAT_ID" \
-d parse_mode="Markdown" \
-d text="$MESSAGE" > /dev/null 2>&1

exit 0
EOF_HOOK
chmod +x "${HOOK_SCRIPT}"
echo "✅ Skrip hook post_quarantine.sh dibuat dan siap."


# --- 5. Konfigurasi Sistem (LMD) ---
echo "--- Modifikasi konfigurasi sistem LMD ---"

if [[ ! -w "$LMD_CONF" ]]; then
    echo "🚨 GAGAL KRITIS: File konfigurasi LMD ($LMD_CONF) tidak dapat ditulisi."
    exit 1
fi

if grep -q "quarantine_exec_file" "$LMD_CONF"; then
    sed -i "s|^quarantine_exec_file=.*|quarantine_exec_file=${HOOK_SCRIPT}|g" "$LMD_CONF"
else
    echo "quarantine_exec_file=${HOOK_SCRIPT}" >> "$LMD_CONF"
fi
echo "✅ Konfigurasi LMD untuk hook berhasil."

# --- 6. FINALISASI ---
echo "--- INSTALASI ${BRAND_NAME} SELESAI TOTAL ---"
echo "URL Akses Langsung: /index.php?module=${MOD_NAME}"
