#!/bin/bash
set -euo pipefail # Safety flags

# --- 1. Definisi Variabel Uninstall ---
BRAND_NAME="LMD Guard CWP"
MOD_NAME="lmd_manager" 

CWP_ADMIN_DIR="/usr/local/cwpsrv/htdocs/resources/admin"
MENU_CONFIG_FILE="${CWP_ADMIN_DIR}/include/3rdparty.php"

# Variabel Uninstall System
LMD_CONF="/usr/local/maldetect/conf.maldet"
HOOK_SCRIPT="/usr/local/maldetect/hook/post_quarantine.sh"
MOD_DIR_FINAL="${CWP_ADMIN_DIR}/modules"

echo "--- Memulai UNINSTALL ${BRAND_NAME} ---"

# --- 2. Hapus File Modul dan Konfigurasi ---
echo "--- Menghapus file modul dan konfigurasi lokal ---"

# Hapus modul utama
rm -f "${MOD_DIR_FINAL}/${MOD_NAME}.php" || true 

# Hapus file konfigurasi Telegram (JSON)
rm -f /etc/cwp/lmd_config.json || true 

# Hapus folder restore LMD dan konfigurasi lokal CWP
rm -rf /root/lmd_restored || true
rm -rf /usr/local/cwp/.conf/lmd_guard.ini || true

echo "✅ File modul dan konfigurasi lokal dihapus."


# --- 3. Hapus Menu Link (Cleanup Sidebar) ---
echo "--- Menghapus link menu dari CWP Sidebar ---"

if [ -f "$MENU_CONFIG_FILE" ]; then
    # Menghapus baris yang mengandung LMD Guard CWP dari file menu
    sed -i '/LMD Guard CWP/d' "$MENU_CONFIG_FILE" || true
    # Menghapus baris yang mungkin mengandung module=lmd_manager (pencegahan)
    sed -i '/module=lmd_manager/d' "$MENU_CONFIG_FILE" || true
fi
echo "✅ Link menu dihapus dari 3rdparty.php."


# --- 4. Hapus Hook Telegram LMD (Sistem) ---
echo "--- Menghapus Hook Real-Time LMD ---"

# Hapus skrip hook bash
rm -f "$HOOK_SCRIPT" || true

# Hapus konfigurasi hook dari file maldet.conf
if [ -f "$LMD_CONF" ]; then
    sed -i '/quarantine_exec_file/d' "$LMD_CONF" || true
fi
echo "✅ Hook LMD dinonaktifkan dan skrip dihapus."


# --- 5. Restart Layanan CWP (Wajib) ---
echo "--- Restart layanan CWP untuk memuat ulang UI ---"

# Gunakan systemctl atau service (tergantung AlmaLinux)
systemctl restart cwpsrv >/dev/null 2>&1 || service cwpsrv restart
echo "✅ Restart CWP selesai."

echo ""
echo "🎉 UNINSTALL ${BRAND_NAME} Selesai Total!"
