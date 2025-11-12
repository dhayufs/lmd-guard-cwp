#!/bin/bash
# ============================================================
#  LMD Guard CWP - Health Checker CLI
#  Versi: 1.0 (Stable)
#  Lokasi: /usr/local/bin/lmd-guard-check
# ============================================================

MODULE_PATH="/usr/local/cwpsrv/htdocs/resources/admin/modules/lmd_manager.php"
CONFIG_FILE="/etc/cwp/lmd_config.json"
LMD_CONF="/usr/local/maldetect/conf.maldet"
HOOK_PATH="/usr/local/maldetect/hook/post_quarantine.sh"
Q_DIR="/usr/local/maldetect/quarantine"

divider() { echo "-----------------------------------------------------------"; }
check_status() {
  local msg="$1"
  local cmd="$2"
  echo -ne "[🔍] $msg ... "
  eval "$cmd" &>/dev/null && echo "✅ OK" || echo "❌ FAIL"
}

echo ""
echo "🧠  LMD GUARD CWP - HEALTH CHECKER"
divider

# 1️⃣ Periksa keberadaan file inti
check_status "File modul tersedia" "[ -f \"$MODULE_PATH\" ]"
check_status "Config JSON tersedia" "[ -f \"$CONFIG_FILE\" ]"
check_status "Hook Bash tersedia" "[ -f \"$HOOK_PATH\" ]"

# 2️⃣ Cek versi maldet
VERSION=$(maldet --version 2>/dev/null | awk -F: '/Version/ {print $2}' | xargs)
if [ -z "$VERSION" ]; then
  VERSION=$(maldet --version 2>/dev/null | head -n1)
fi
echo "⚙️  Maldet Version: ${VERSION:-Unknown}"

# 3️⃣ Cek mode & Telegram config
if [ -f "$CONFIG_FILE" ]; then
  MODE=$(grep -oP '"mode": *"\K[^"]+' "$CONFIG_FILE" 2>/dev/null)
  TOKEN=$(grep -oP '"token": *"\K[^"]+' "$CONFIG_FILE" 2>/dev/null)
  CHATID=$(grep -oP '"chat_id": *"\K[^"]+' "$CONFIG_FILE" 2>/dev/null)
  echo "🧩  Mode aktif         : ${MODE:-tidak ditemukan}"
  echo "💬  Telegram Bot Token : ${TOKEN:-kosong}"
  echo "💬  Telegram Chat ID   : ${CHATID:-kosong}"
else
  echo "❌ Config JSON tidak ditemukan."
fi

# 4️⃣ Cek apakah monitoring aktif
if ps aux | grep -E '[m]aldet (--monitor|-m)' >/dev/null; then
  echo "🟢  Real-Time Monitoring: Aktif"
else
  echo "🔴  Real-Time Monitoring: Tidak aktif"
fi

# 5️⃣ Cek hook di konfigurasi
if grep -q "quarantine_exec_file=${HOOK_PATH}" "$LMD_CONF" 2>/dev/null; then
  echo "🧩  Hook terdaftar di conf.maldet ✅"
else
  echo "⚠️  Hook belum tercantum di conf.maldet"
fi

# 6️⃣ Tes backend modul (action_type)
divider
echo "🧠  TESTING BACKEND MODULE PHP"
divider

php -r '$_REQUEST["action_type"]="get_summary"; include "'"$MODULE_PATH"'";' 2>/dev/null | jq . >/tmp/lmd_summary.json 2>/dev/null
if [ -s /tmp/lmd_summary.json ]; then
  echo "✅  get_summary berjalan"
else
  echo "❌  get_summary gagal"
fi

php -r '$_REQUEST["action_type"]="quarantine_list"; include "'"$MODULE_PATH"'";' 2>/dev/null | jq . >/tmp/lmd_quarantine.json 2>/dev/null
if [ -s /tmp/lmd_quarantine.json ]; then
  COUNT=$(jq '.data | length' /tmp/lmd_quarantine.json)
  echo "✅  quarantine_list berjalan ($COUNT item)"
else
  echo "❌  quarantine_list gagal"
fi

# 7️⃣ Cek jumlah file karantina langsung
if [ -d "$Q_DIR" ]; then
  QCOUNT=$(find "$Q_DIR" -type f ! -name "*.info" 2>/dev/null | wc -l)
  echo "📦  File karantina fisik: ${QCOUNT}"
else
  echo "⚠️  Folder karantina tidak ditemukan"
fi

# 8️⃣ Tes kirim notifikasi Telegram (opsional)
if [ -n "$TOKEN" ] && [ -n "$CHATID" ]; then
  echo "💬  Mengirim tes notifikasi Telegram..."
  curl -s -X POST "https://api.telegram.org/bot${TOKEN}/sendMessage" \
    -d chat_id="${CHATID}" -d parse_mode="Markdown" \
    -d text="🧠 *LMD GUARD CWP TEST*  
✅ Notifikasi Telegram bekerja dengan baik.  
🕓 $(date '+%Y-%m-%d %H:%M:%S')" >/dev/null \
    && echo "✅  Telegram OK" || echo "❌  Telegram GAGAL"
else
  echo "⚠️  Telegram belum dikonfigurasi, lewati tes."
fi

divider
echo "✅  Pemeriksaan selesai!"
echo "📄  Ringkasan:"
echo "    - Modul Path: $MODULE_PATH"
echo "    - Config JSON: $CONFIG_FILE"
echo "    - Hook Path: $HOOK_PATH"
echo "    - Quarantine Dir: $Q_DIR"
divider
echo "🔥  Semua siap jalan! Jalankan dashboard CWP → LMD Guard CWP untuk verifikasi GUI."
echo ""
