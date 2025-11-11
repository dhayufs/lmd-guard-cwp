#!/bin/bash
set -euo pipefail

BRAND_NAME="LMD Guard CWP"
MOD_NAME="lmd_manager"

CWP_ADMIN_DIR="/usr/local/cwpsrv/htdocs/resources/admin"
MOD_DIR="${CWP_ADMIN_DIR}/modules"
MENU_FILE="${CWP_ADMIN_DIR}/include/3rdparty.php"

CONFIG_JSON="/etc/cwp/lmd_config.json"
RESTORE_DIR="/root/lmd_restored"
JOB_LOG_DIR="/var/log/maldet_ui"
HOOK_SCRIPT="/usr/local/maldetect/hook/post_quarantine.sh"
LMD_CONF="/usr/local/maldetect/conf.maldet"

echo ""
echo "=== 🔥 UNINSTALL $BRAND_NAME DIMULAI ==="
echo ""

# ----------------------------------------------------------------------
# 1. HAPUS FILE MODUL
# ----------------------------------------------------------------------
echo "🗑️ Menghapus modul..."
rm -f "${MOD_DIR}/${MOD_NAME}.php" || true
echo "✅ Modul dihapus."

# ----------------------------------------------------------------------
# 2. HAPUS KONFIGURASI JSON
# ----------------------------------------------------------------------
echo "🗑️ Menghapus konfigurasi JSON..."
rm -f "$CONFIG_JSON" || true
echo "✅ Konfigurasi JSON dihapus."

# ----------------------------------------------------------------------
# 3. HAPUS RESTORE DIR & JOB LOG DIR
# ----------------------------------------------------------------------
echo "🗑️ Membersihkan direktori restore dan log job..."
rm -rf "$RESTORE_DIR" || true
rm -rf "$JOB_LOG_DIR" || true
echo "✅ Direktori restore & log job dibersihkan."

# ----------------------------------------------------------------------
# 4. HAPUS MENU ENTRY (tidak overwrite seluruh file!)
# ----------------------------------------------------------------------
echo "🧩 Menghapus entri menu..."
if [ -f "$MENU_FILE" ]; then
    sed -i "/index.php?module=${MOD_NAME}/d" "$MENU_FILE"
fi
echo "✅ Menu dibersihkan dari sidebar."

# ----------------------------------------------------------------------
# 5. NONAKTIFKAN HOOK LMD
# ----------------------------------------------------------------------
echo "🔗 Menonaktifkan hook realtime LMD..."
rm -f "$HOOK_SCRIPT" || true

# hapus baris konfigurasi hook dari maldet
if [ -f "$LMD_CONF" ]; then
    sed -i '/quarantine_exec_file/d' "$LMD_CONF" || true
fi
echo "✅ Hook realtime dinonaktifkan."

# ----------------------------------------------------------------------
# 6. **TIDAK** mematikan monitoring otomatis.
# (biar nggak ganggu server production)
# ----------------------------------------------------------------------

# ----------------------------------------------------------------------
# 7. RESTART CWP
# ----------------------------------------------------------------------
echo "♻️ Restart cwpsrv untuk refresh UI..."
systemctl restart cwpsrv >/dev/null 2>&1 || service cwpsrv restart >/dev/null 2>&1
echo "✅ CWP restart selesai."

echo ""
echo "🎉 UNINSTALL $BRAND_NAME SELESAI!"
echo "📌 Jika kamu pernah mengaktifkan pemantauan real-time, kamu bisa matikan manual:"
echo "    maldet -k"
echo ""
