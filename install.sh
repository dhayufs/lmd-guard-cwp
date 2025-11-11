#!/bin/bash
set -euo pipefail # Safety flags untuk stabilitas dan deteksi unbound variable

# =================================================================
# 1. DEFINISI VARIABEL (HARUS DI AWAL)
# =================================================================
# GANTI INI DENGAN DETAIL REPO ANDA
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

LMD_DEPENDENCY="maldet"
LMD_RESTORE_DIR="/root/lmd_restored"
LOCAL_CONFIG_DIR="/usr/local/cwp/.conf"
LOCAL_CONFIG_FILE="${LOCAL_CONFIG_DIR}/lmd_guard.ini"


echo "--- Memulai Instalasi ${BRAND_NAME} (FINAL OVERWRITE) ---"

# =================================================================
# 2. PROSES INSTALASI DAN DEPENDENSI
# =================================================================

# Cek LMD
if ! command -v "${LMD_DEPENDENCY}" >/dev/null 2>&1; then
    echo "🚨 GAGAL: maldet tidak ditemukan! Instal LMD terlebih dahulu!"
    exit 1
fi
echo "✅ LMD terdeteksi."

# Penyiapan Direktori
echo "--- Menyiapkan direktori dan unduh file ---"
mkdir -p /etc/cwp/ "${MOD_DIR_FINAL}" "$(dirname "$HOOK_SCRIPT")" \
         "${LMD_RESTORE_DIR}" "${LOCAL_CONFIG_DIR}"

# Membuat file konfigurasi LMD Guard INI (Fix 2)
if [ ! -f "$LOCAL_CONFIG_FILE" ]; then
cat > "$LOCAL_CONFIG_FILE" <<EOF
TELEGRAM_BOT=
TELEGRAM_CHAT=
CRON_SCHEDULE=OFF
CSF_AUTOBAN=0
EOF
fi

# MENGUNDUH FILE PHP/HTML/JS FINAL ke lokasi yang BENAR
curl -s -o "${MOD_DIR_FINAL}/${MOD_NAME}.php" -L "${REPO_RAW_URL}/${MOD_NAME}.php"
chmod 644 "${MOD_DIR_FINAL}/${MOD_NAME}.php"
echo "✅ ${MOD_NAME}.php berhasil diunduh dan disalin."

# Membuat file konfigurasi PHP/JSON jika belum ada
if [ ! -f "$CONFIG_FILE" ]; then
    echo '{"token": "", "chat_id": ""}' > "$CONFIG_FILE"
    chmod 600 "$CONFIG_FILE"
fi
echo "✅ Konfigurasi Telegram siap."

# =================================================================
# 3. OVERWRITE MENU SIDEBAR (3rdparty.php)
# =================================================================
echo "--- OVERWRITE & Menyiapkan Menu Sidebar CWP ---"

# File ini hanya berisi link HTML, dan akan ditimpa total.
MENU_LINK_HTML="<li><a href=\"index.php?module=${MOD_NAME}\"><span class=\"icon16 icomoon-icon-arrow-right-3\"></span>${BRAND_NAME}</a></li>"

# Catatan: Kita hapus dulu baris yang mengandung 'lmd_manager' untuk jaga-jaga
sed -i '/lmd_manager/d' "${MENU_CONFIG_FILE}" || true 

# Timpa total file dengan link LMD Guard CWP kita
cat > "$MENU_CONFIG_FILE" <<EOF_MENU
${MENU_LINK_HTML}
EOF_MENU

echo "✅ File menu ${MENU_CONFIG_FILE} di-OVERWRITE dengan link ${BRAND_NAME}."


# =================================================================
# 4. HOOK TELEGRAM & LMD CONFIG
# =================================================================
echo "--- Membuat skrip hook real-time Telegram ---"
cat << 'EOF_HOOK' > "${HOOK_SCRIPT}"
#!/bin/bash
set -euo pipefail 

FILE_PATH="$1"
SIGNATURE="$2"
HOST_NAME=$(hostname)
CONFIG_FILE="/etc/cwp/lmd_config.json"

TOKEN=$(grep -o '"token": *"[^"]*"' "$CONFIG_FILE" | grep -o '"[^"]*"' | tr -d '"')
CHAT_ID=$(grep -o '"chat_id": *"[^"]*"' "$CONFIG_FILE" | grep -o '"[^"]*"' | tr -d '"')

if [ -z "$TOKEN" ] || [ -z "$CHAT_ID" ]; then
    exit 0
fi

MESSAGE="🚨 *MALWARE REAL-TIME DIKARANTINA* 🚨\n\n*Server:* $HOST_NAME\n*Waktu:* $(date '+%Y-%m-%d %H:%M:%S')\n*File:* \`$FILE_PATH\`\n*Ancaman:* $SIGNATURE\n*Aksi:* **Karantina Instan** oleh ${BRAND_NAME}."

curl -s -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" \
-d chat_id="$CHAT_ID" -d parse_mode="Markdown" -d text="$MESSAGE" > /dev/null 2>&1

exit 0
EOF_HOOK
chmod +x "${HOOK_SCRIPT}"
echo "✅ Hook Telegram berhasil dipasang."

# Konfigurasi LMD
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

# --- 6. FINALISASI ---
echo "--- INSTALASI ${BRAND_NAME} SELESAI TOTAL ---"
echo "JANGAN LUPA RESTART CWP SERVICE: service cwpsrv restart"
echo "URL Akses Langsung: /index.php?module=${MOD_NAME}"
