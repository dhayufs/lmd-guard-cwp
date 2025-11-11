#!/bin/bash
set -euo pipefail

# =========================
# Konfigurasi dasar
# =========================
REPO_RAW_URL="https://raw.githubusercontent.com/dhayufs/lmd-guard-cwp/main"
BRAND_NAME="LMD Guard CWP"
MOD_NAME="lmd_manager"

# =========================
# Auto-detect lokasi CWP (baru vs lama)
# =========================
if [ -d "/usr/local/cwpsrv/htdocs/admin" ]; then
    CWP_ADMIN_DIR="/usr/local/cwpsrv/htdocs/admin"
elif [ -d "/usr/local/cwpsrv/htdocs/resources/admin" ]; then
    CWP_ADMIN_DIR="/usr/local/cwpsrv/htdocs/resources/admin"
else
    echo "❌ ERROR: Folder admin CWP tidak ditemukan."
    exit 1
fi

MOD_DIR_FINAL="${CWP_ADMIN_DIR}/modules"
MENU_CONFIG_FILE="${CWP_ADMIN_DIR}/include/3rdparty.php"

# =========================
# Path & file terkait LMD
# =========================
CONFIG_FILE="/etc/cwp/lmd_config.json"
LMD_CONF="/usr/local/maldetect/conf.maldet"
HOOK_SCRIPT="/usr/local/maldetect/hook/post_quarantine.sh"

echo "--- Instalasi ${BRAND_NAME} dimulai ---"

# =========================
# Cek dependensi LMD
# =========================
if ! command -v maldet >/dev/null 2>&1; then
    echo "❌ GAGAL: 'maldet' tidak ditemukan. Instal Linux Malware Detect dulu."
    exit 1
fi
echo "✅ maldet terdeteksi."

# =========================
# Siapkan direktori
# =========================
mkdir -p "/etc/cwp" "${MOD_DIR_FINAL}" "$(dirname "$HOOK_SCRIPT")" "$(dirname "$MENU_CONFIG_FILE")"

# =========================
# Download module ke lokasi yang benar
# =========================
echo "--- Mengunduh module ${MOD_NAME}.php ---"
curl -fSL -o "${MOD_DIR_FINAL}/${MOD_NAME}.php" "${REPO_RAW_URL}/${MOD_NAME}.php"
chmod 0644 "${MOD_DIR_FINAL}/${MOD_NAME}.php"
echo "✅ Module terpasang: ${MOD_DIR_FINAL}/${MOD_NAME}.php"

# =========================
# Buat konfigurasi Telegram jika belum ada
# =========================
if [ ! -f "$CONFIG_FILE" ]; then
    echo '{"token": "", "chat_id": ""}' > "$CONFIG_FILE"
    chmod 600 "$CONFIG_FILE"
    echo "✅ Konfigurasi Telegram dibuat: $CONFIG_FILE"
else
    echo "ℹ️  Konfigurasi Telegram sudah ada: $CONFIG_FILE"
fi

# =========================
# Tambahkan menu di sidebar CWP (non-destruktif)
# =========================
echo "--- Menambahkan link menu sidebar ---"
touch "$MENU_CONFIG_FILE"
# Hapus entri lama agar tidak dobel
sed -i '/index.php?module=lmd_manager/d' "$MENU_CONFIG_FILE" || true
# Tambah entri
cat >> "$MENU_CONFIG_FILE" <<EOF
<li>
  <a href="index.php?module=${MOD_NAME}">
    <span class="icon16 icomoon-icon-shield"></span>${BRAND_NAME}
  </a>
</li>
EOF
echo "✅ Menu ditambahkan: $MENU_CONFIG_FILE"

# =========================
# Hook Telegram saat karantina
# =========================
echo "--- Membuat hook Telegram karantina ---"
cat > "$HOOK_SCRIPT" <<'EOF'
#!/bin/bash
set -eu

FILE_PATH="${1:-}"
SIGNATURE="${2:-}"
CONFIG_FILE="/etc/cwp/lmd_config.json"

# Ambil token & chat id dari JSON
TOKEN=$(grep -o '"token": *"[^"]*"' "$CONFIG_FILE" 2>/dev/null | cut -d '"' -f4 || true)
CHAT_ID=$(grep -o '"chat_id": *"[^"]*"' "$CONFIG_FILE" 2>/dev/null | cut -d '"' -f4 || true)

# Jika kosong, keluar tanpa error
if [ -z "${TOKEN:-}" ] || [ -z "${CHAT_ID:-}" ]; then
    exit 0
fi

HOST_NAME=$(hostname)
DT=$(date '+%Y-%m-%d %H:%M:%S')
MSG="🚨 MALWARE DIKARANTINA
Server: ${HOST_NAME}
Waktu: ${DT}
File: ${FILE_PATH}
Signature: ${SIGNATURE}
Aksi: Karantina instan oleh LMD"

curl -s -X POST "https://api.telegram.org/bot${TOKEN}/sendMessage" \
     -d "chat_id=${CHAT_ID}" \
     -d "text=${MSG}" >/dev/null 2>&1 || true

exit 0
EOF
chmod +x "$HOOK_SCRIPT"
echo "✅ Hook dibuat: $HOOK_SCRIPT"

# =========================
# Set hook di conf.maldet
# =========================
if [ -w "$LMD_CONF" ]; then
    if grep -q '^quarantine_exec_file=' "$LMD_CONF"; then
        sed -i "s|^quarantine_exec_file=.*|quarantine_exec_file=${HOOK_SCRIPT}|g" "$LMD_CONF"
    else
        echo "quarantine_exec_file=${HOOK_SCRIPT}" >> "$LMD_CONF"
    fi
    echo "✅ conf.maldet diupdate: quarantine_exec_file=${HOOK_SCRIPT}"
else
    echo "⚠️  Peringatan: $LMD_CONF tidak dapat ditulis. Setel manual 'quarantine_exec_file=${HOOK_SCRIPT}' bila perlu."
fi

echo "🎉 Instalasi ${BRAND_NAME} selesai."
echo "➡  Restart service panel: service cwpsrv restart"
echo "➡  Akses: /index.php?module=${MOD_NAME}"
