#!/bin/bash
set -euo pipefail

REPO_RAW_URL="https://raw.githubusercontent.com/dhayufs/lmd-guard-cwp/main"
BRAND_NAME="LMD Guard CWP"
MOD_NAME="lmd_manager"

# =========================
# AUTO-DETECT PATH CWP
# =========================
if [ -d "/usr/local/cwpsrv/htdocs/admin" ]; then
    CWP_ADMIN_DIR="/usr/local/cwpsrv/htdocs/admin"
elif [ -d "/usr/local/cwpsrv/htdocs/resources/admin" ]; then
    CWP_ADMIN_DIR="/usr/local/cwpsrv/htdocs/resources/admin"
else
    echo "❌ GAGAL: Tidak menemukan folder admin CWP!"
    exit 1
fi

MOD_DIR_FINAL="${CWP_ADMIN_DIR}/modules"
MENU_CONFIG_FILE="${CWP_ADMIN_DIR}/include/3rdparty.php"

CONFIG_FILE="/etc/cwp/lmd_config.json"
LMD_CONF="/usr/local/maldetect/conf.maldet"
HOOK_SCRIPT="/usr/local/maldetect/hook/post_quarantine.sh"

echo "--- Memulai Instalasi ${BRAND_NAME} ---"

# Cek LMD
if ! command -v maldet >/dev/null 2>&1; then
    echo "❌ GAGAL: maldet tidak ditemukan!"
    exit 1
fi

mkdir -p /etc/cwp/ "${MOD_DIR_FINAL}" "$(dirname "$HOOK_SCRIPT")"

# Konfigurasi Telegram
if [ ! -f "$CONFIG_FILE" ]; then
    echo '{"token": "", "chat_id": ""}' > "$CONFIG_FILE"
    chmod 600 "$CONFIG_FILE"
fi

# Download module
curl -s -o "${MOD_DIR_FINAL}/${MOD_NAME}.php" -L "${REPO_RAW_URL}/${MOD_NAME}.php"
chmod 644 "${MOD_DIR_FINAL}/${MOD_NAME}.php"

# Update menu sidebar (hapus entry lama → tambahkan ulang)
sed -i '/lmd_manager/d' "${MENU_CONFIG_FILE}" 2>/dev/null || true
echo "<li><a href=\"index.php?module=${MOD_NAME}\"><span class=\"icon16 icomoon-icon-shield\"></span>${BRAND_NAME}</a></li>" >> "$MENU_CONFIG_FILE"

# Hook Telegram Quarantine Alert
cat << 'EOF' > "$HOOK_SCRIPT"
#!/bin/bash
FILE_PATH="$1"
SIGNATURE="$2"
CONFIG="/etc/cwp/lmd_config.json"
TOKEN=$(grep -o '"token": *"[^"]*"' "$CONFIG" | cut -d '"' -f4)
CHAT=$(grep -o '"chat_id": *"[^"]*"' "$CONFIG" | cut -d '"' -f4)

[ -z "$TOKEN" ] || [ -z "$CHAT" ] && exit 0

MSG="🚨 MALWARE TERTANGKAP\nFile: $FILE_PATH\nSignature: $SIGNATURE\nServer: $(hostname)"
curl -s -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" -d chat_id="$CHAT" -d text="$MSG" >/dev/null 2>&1
EOF
chmod +x "$HOOK_SCRIPT"

# Set hook in maldet config
sed -i "s|^quarantine_exec_file=.*|quarantine_exec_file=${HOOK_SCRIPT}|g" "$LMD_CONF" || \
echo "quarantine_exec_file=${HOOK_SCRIPT}" >> "$LMD_CONF"

echo "✅ Instalasi selesai!"
echo "➡ Restart panel dengan: service cwpsrv restart"
