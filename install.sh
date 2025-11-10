#!/bin/bash
set -euo pipefail

# =================================================================
# 1. DEFINISI VARIABEL
# =================================================================
REPO_RAW_URL="https://raw.githubusercontent.com/dhayufs/lmd-guard-cwp/main"
BRAND_NAME="LMD Guard CWP"
MOD_NAME="lmd_manager"

CWP_ADMIN_DIR="/usr/local/cwpsrv/htdocs/resources/admin"

CONFIG_FILE="/etc/cwp/lmd_config.json"
LMD_CONF="/usr/local/maldetect/conf.maldet"
HOOK_SCRIPT="/usr/local/maldetect/hook/post_quarantine.sh"
MOD_DIR_FINAL="${CWP_ADMIN_DIR}/modules"

# AUTODETECT MENU FILE (FIX MENU BLANK)
if [ -f "${CWP_ADMIN_DIR}/include/3rdparty.php" ]; then
    MENU_CONFIG_FILE="${CWP_ADMIN_DIR}/include/3rdparty.php"
elif [ -f "${CWP_ADMIN_DIR}/menu/3rdparty.php" ]; then
    MENU_CONFIG_FILE="${CWP_ADMIN_DIR}/menu/3rdparty.php"
else
    echo "🚨 ERROR: 3rdparty.php tidak ditemukan! Menu tidak bisa ditambahkan."
    exit 1
fi

LMD_DEPENDENCY="maldet"
LMD_RESTORE_DIR="/root/lmd_restored"
LOCAL_CONFIG_DIR="/usr/local/cwp/.conf"
LOCAL_CONFIG_FILE="${LOCAL_CONFIG_DIR}/lmd_guard.ini"

echo "--- Memulai Instalasi ${BRAND_NAME} ---"

# =================================================================
# 2. CEK DEPENDENSI
# =================================================================
if ! command -v "${LMD_DEPENDENCY}" >/dev/null 2>&1; then
    echo "🚨 GAGAL: maldet tidak ditemukan! Instal dulu:"
    echo "yum install maldetect -y"
    exit 1
fi
echo "✅ LMD terdeteksi."

# =================================================================
# 3. PERSIAPAN
# =================================================================
mkdir -p /etc/cwp/ "${MOD_DIR_FINAL}" "$(dirname "$HOOK_SCRIPT")" \
         "${LMD_RESTORE_DIR}" "${LOCAL_CONFIG_DIR}"

if [ ! -f "$LOCAL_CONFIG_FILE" ]; then
cat > "$LOCAL_CONFIG_FILE" <<EOF
TELEGRAM_BOT=
TELEGRAM_CHAT=
CRON_SCHEDULE=OFF
CSF_AUTOBAN=0
EOF
fi

# =================================================================
# 4. DOWNLOAD MODULE (FIX: gunakan curl -s supaya installer rapi)
# =================================================================
curl -s -o "${MOD_DIR_FINAL}/${MOD_NAME}.php" -L "${REPO_RAW_URL}/${MOD_NAME}.php"
chmod 644 "${MOD_DIR_FINAL}/${MOD_NAME}.php"
echo "✅ Modul berhasil diunduh."

# =================================================================
# 5. CONFIG FILE
# =================================================================
if [ ! -f "$CONFIG_FILE" ]; then
    echo '{"token": "", "chat_id": ""}' > "$CONFIG_FILE"
    chmod 600 "$CONFIG_FILE"
fi
echo "✅ Konfigurasi Telegram siap."

# =================================================================
# 6. PERBAIKI MENU (FIX: hapus menu lama + anti duplikasi)
# =================================================================
sed -i '/lmd_manager/d;/LMD Guard CWP/d' "$MENU_CONFIG_FILE" || true

MENU_LINK_HTML="<li><a href=\"index.php?module=${MOD_NAME}\"><span class=\"icon16 icomoon-icon-arrow-right-3\"></span>${BRAND_NAME}</a></li>"
if ! grep -q "${MOD_NAME}" "$MENU_CONFIG_FILE"; then
    echo "$MENU_LINK_HTML" >> "$MENU_CONFIG_FILE"
fi
echo "✅ Menu berhasil diperbarui."

# =================================================================
# 7. HOOK TELEGRAM
# =================================================================
cat << 'EOF' > "$HOOK_SCRIPT"
#!/bin/bash
FILE_PATH="$1"
SIGNATURE="$2"
HOST_NAME=$(hostname)
CONFIG_FILE="/etc/cwp/lmd_config.json"

TOKEN=$(grep -o '"token": *"[^"]*"' "$CONFIG_FILE" | grep -o '"[^"]*"' | tr -d '"')
CHAT_ID=$(grep -o '"chat_id": *"[^"]*"' "$CONFIG_FILE" | grep -o '"[^"]*"' | tr -d '"')

[ -z "$TOKEN" ] && exit 0
[ -z "$CHAT_ID" ] && exit 0

MESSAGE="🚨 *MALWARE TERDETEKSI & DIKARANTINA* 🚨
*Server:* $HOST_NAME
*File:* \`$FILE_PATH\`
*Signature:* $SIGNATURE
*Waktu:* $(date '+%Y-%m-%d %H:%M:%S')"

curl -s -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" \
-d chat_id="$CHAT_ID" -d parse_mode="Markdown" -d text="$MESSAGE" >/dev/null 2>&1
EOF

chmod +x "$HOOK_SCRIPT"

if grep -q "quarantine_exec_file" "$LMD_CONF"; then
    sed -i "s|^quarantine_exec_file=.*|quarantine_exec_file=$HOOK_SCRIPT|g" "$LMD_CONF"
else
    echo "quarantine_exec_file=$HOOK_SCRIPT" >> "$LMD_CONF"
fi

echo "✅ Hook Telegram berhasil dipasang."

# =================================================================
# 8. RESTART PANEL (FIX: supaya menu langsung muncul)
# =================================================================
systemctl restart cwpsrv >/dev/null 2>&1 || service cwpsrv restart

echo ""
echo "🎉 Instalasi ${BRAND_NAME} Selesai!"
echo "Login ke CWP → Developer Menu → ${BRAND_NAME}"
echo "Atau buka langsung:"
echo "https://SERVER:2031/index.php?module=${MOD_NAME}"
