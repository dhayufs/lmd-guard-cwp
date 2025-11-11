#!/bin/bash
set -euo pipefail

BRAND_NAME="LMD Guard CWP"
MOD_NAME="lmd_manager"
REPO_RAW_URL="https://raw.githubusercontent.com/dhayufs/lmd-guard-cwp/main"

CWP_ADMIN_DIR="/usr/local/cwpsrv/htdocs/resources/admin"
MOD_DIR="${CWP_ADMIN_DIR}/modules"
MENU_FILE="${CWP_ADMIN_DIR}/include/3rdparty.php"

CONFIG_JSON="/etc/cwp/lmd_config.json"
RESTORE_DIR="/root/lmd_restored"
JOB_LOG_DIR="/var/log/maldet_ui"
HOOK_SCRIPT="/usr/local/maldetect/hook/post_quarantine.sh"
LMD_CONF="/usr/local/maldetect/conf.maldet"

echo "=== 🚀 Instalasi $BRAND_NAME Dimulai ==="

# Pastikan maldet tersedia
if ! command -v maldet >/dev/null 2>&1; then
    echo "❌ Maldet belum terinstal. Install dulu: yum install maldetect"
    exit 1
fi
echo "✅ Maldet terdeteksi."

mkdir -p "$MOD_DIR" "$RESTORE_DIR" "$JOB_LOG_DIR" "$(dirname "$HOOK_SCRIPT")"
chmod 750 "$JOB_LOG_DIR"

echo "📥 Mengunduh modul terbaru..."
curl -s -L -o "${MOD_DIR}/${MOD_NAME}.php" "${REPO_RAW_URL}/${MOD_NAME}.php"
chmod 644 "${MOD_DIR}/${MOD_NAME}.php"
echo "✅ Modul terpasang."

if [ ! -f "$CONFIG_JSON" ]; then
cat > "$CONFIG_JSON" <<EOF
{
  "token": "",
  "chat_id": "",
  "mode": "quarantine"
}
EOF
chmod 600 "$CONFIG_JSON"
fi
echo "✅ Konfigurasi initial JSON tersedia."

echo "🧩 Menambahkan menu jika belum ada..."
MENU_LINE="<li><a href=\"index.php?module=${MOD_NAME}\"><span class=\"icon16 icomoon-icon-shield\"></span>${BRAND_NAME}</a></li>"
grep -q "$MOD_NAME" "$MENU_FILE" || echo "$MENU_LINE" >> "$MENU_FILE"
echo "✅ Menu siap."

echo "🔗 Memasang hook real-time...
(Hook mengikuti mode delete/clean/karantina)"
cat > "$HOOK_SCRIPT" << 'EOF'
#!/bin/bash
FILE="$1"
SIGNATURE="$2"
HOST=$(hostname)
CONFIG="/etc/cwp/lmd_config.json"

TOKEN=$(grep -o '"token": *"[^"]*"' "$CONFIG" | cut -d '"' -f4)
CHAT_ID=$(grep -o '"chat_id": *"[^"]*"' "$CONFIG" | cut -d '"' -f4)
MODE=$(grep -o '"mode": *"[^"]*"' "$CONFIG" | cut -d '"' -f4)

[ -z "$TOKEN" ] && exit 0
[ -z "$CHAT_ID" ] && exit 0

ACTION="Karantina"

if [ "$MODE" == "clean" ]; then
    ACTION="Clean + Karantina"
fi

if [ "$MODE" == "delete" ]; then
    ACTION="HAPUS PERMANEN"
    rm -f "$FILE"
fi

MESSAGE="⚠️ *Malware Terdeteksi*
*File:* \`$FILE\`
*Signature:* $SIGNATURE
*Aksi:* *$ACTION*
*Server:* $HOST"

curl -s -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" \
     -d chat_id="$CHAT_ID" -d parse_mode=Markdown -d text="$MESSAGE" >/dev/null 2>&1
EOF

chmod +x "$HOOK_SCRIPT"

if grep -q "^quarantine_exec_file=" "$LMD_CONF"; then
    sed -i "s|^quarantine_exec_file=.*|quarantine_exec_file=${HOOK_SCRIPT}|" "$LMD_CONF"
else
    echo "quarantine_exec_file=${HOOK_SCRIPT}" >> "$LMD_CONF"
fi

echo "✅ Hook LMD aktif."
echo "♻️ Restart cwpsrv..."
systemctl restart cwpsrv >/dev/null 2>&1 || service cwpsrv restart >/dev/null 2>&1

echo ""
echo "=== ✅ Instalasi Selesai ==="
echo "🔗 Akses: https://SERVER-IP:2031/index.php?module=${MOD_NAME}"
