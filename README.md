🛡️ LMD Guard CWP: Malware Manager Otomatis untuk CentOS Web Panel
LMD Guard CWP adalah modul kustom open-source yang mengintegrasikan Linux Malware Detect (LMD) dan notifikasi real-time Telegram langsung ke dashboard admin CWP Anda.

Fitur Utama
 * Pemantauan Real-Time: Kontrol inotify LMD dari dashboard untuk deteksi seketika.
 * Notifikasi Seketika: Mengirim alert Telegram segera setelah malware dikarantina.
 * Manajemen Karantina: Lihat, pulihkan, atau hapus malware langsung dari dashboard.
 * Pemindaian Fleksibel: Opsi pemindaian penuh, jalur kustom, atau file yang dimodifikasi dalam X hari.
 * Keamanan CWP: Menggunakan logic sanitasi yang ketat untuk mencegah Shell Injection.

🛠️ Instalasi Otomatis (Satu Prompt)
Instalasi ini 100% otomatis. Skrip akan menangani pengunduhan file, konfigurasi LMD, dan penambahan menu ke sidebar CWP.

Prasyarat
 * Anda harus menjalankan command sebagai pengguna root.
 * Linux Malware Detect (LMD) harus sudah terinstal di server Anda.

Berikut adalah command final yang sudah diverifikasi dan paling efisien untuk instalasi dan uninstal, siap Anda jalankan di terminal server Anda:

🚀 1. Command Instalasi (Install Command)

*Command* ini akan mengunduh dan menjalankan `install.sh`, yang kemudian akan memasang modul **LMD Guard CWP** dan **merefresh layanan CWP** secara otomatis.

Pastikan Anda menjalankan ini sebagai `root`:

bash
`REPO_URL="https://raw.githubusercontent.com/dhayufs/lmd-guard-cwp/main"; \
cd /usr/local/src && rm -f install.sh && curl -o install.sh -L ${REPO_URL}/install.sh && bash install.sh`

🗑️ 2. Command Penghapusan (Uninstall Command)

Jika Anda perlu menghapus modul sepenuhnya, gunakan *command* ini. Ini akan mengunduh dan menjalankan `uninstall.sh`, yang akan membersihkan semua file, *hook*, dan *link* menu.

Pastikan Anda menjalankan ini sebagai `root`:

bash
`REPO_URL="https://raw.githubusercontent.com/dhayufs/lmd-guard-cwp/main"; cd /usr/local/src && rm -f uninstall.sh && curl -o uninstall.sh -L ${REPO_URL}/uninstall.sh && bash uninstall.sh`

⚙️ Panduan Konfigurasi Dashboard (Wajib!)
Setelah instalasi selesai (skrip tidak menampilkan error), ikuti langkah konfigurasi real-time ini:

1. Akses Modul LMD Guard CWP
 * Akses Menu: Buka CWP Admin Panel -> Developer Menu -> LMD Guard CWP.
 * Akses Langsung (Jika Menu Gagal Muncul): Gunakan deep link ini (ganti token sesi Anda):
   https://[IP_SERVER]:2031/cwp_xxxxxxxx/admin/index.php?module=lmd_manager

2. Atur Notifikasi Telegram
Pergi ke Tab Pengaturan Telegram (Tab terakhir).
 * Telegram Bot Token: Isi dengan Token dari @BotFather.
 * Telegram Chat ID: Isi dengan ID chat Anda (biasanya dimulai dengan -).
 * Langkah Setting:
   * Klik Simpan Pengaturan.
   * Klik Uji Coba Kirim untuk memverifikasi notifikasi masuk ke Telegram Anda.

3. Aktifkan Pemantauan Real-Time
Pergi ke Tab Ringkasan & Status (Tab pertama).
 * Lihat status Real-Time (Inotify).
 * Jika status OFF (Merah), klik Nyalakan Pemantauan.
 * Status akan berubah menjadi ON (Hijau), dan deteksi seketika kini aktif.

📝 Panduan Penggunaan Fitur Lain
 * Pemindaian Penuh / Kustom:
   * Lokasi: Tab Pemindaian 🔎
   * Cara Kerja: Masukkan jalur (e.g., /home/) atau pilih opsi hari terakhir, lalu klik Mulai Pemindaian. Log progress akan muncul di bawah.
 * Update Signature:
   * Lokasi: Tab Ringkasan 🟢
   * Cara Kerja: Klik Perbarui Signature Sekarang. Ini menjalankan maldet -u -d di background.
 * Manajemen Karantina:
   * Lokasi: Tab Karantina 🗑️
   * Cara Kerja: Muat daftar file yang dikarantina. Gunakan checkbox untuk memilih, lalu pilih aksi Pulihkan, Hapus Permanen, atau Coba Bersihkan (Clean).

🛑 Troubleshooting & Debugging CWP
 * Masalah Layar Putih (Blank Screen):
   * Penyebab: Biasanya syntax error PHP atau headers yang sudah terkirim.
   * Solusi: Lakukan cleanup manual pada file modul: Pastikan /usr/local/cwpsrv/htdocs/resources/admin/modules/lmd_manager.php diawali dengan <?php tanpa spasi/karakter lain di depannya. Lalu, jalankan service cwpsrv restart.
 * Modul Gagal Dimuat / Error Aneh:
   * Solusi: Bersihkan cache CWP dengan menjalankan: service cwpsrv restart.
