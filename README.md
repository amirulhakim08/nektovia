# nektovia.com

Laman web NEKTOVIA: laman korporat, landing page produk, troli, checkout CHIP, dan halaman admin.

| Alamat | Fungsi |
|---|---|
| `/` | Laman korporat (pengenalan, produk, koperasi, hubungi) |
| `/goldentrio/` | Landing page The Golden Trio |
| `/madulebahasli/` | Landing page Madu Lebah Asli (cadangan, belum dibuka) |
| `/checkout/` | Troli, kupon, alamat, bayar melalui CHIP |
| `/checkout/selesai/` | Status pesanan selepas bayaran |
| `/admin/` | Pesanan, status penghantaran, kupon, eksport CSV |

Perlu hosting dengan **PHP 8.1+ dan MySQL** (contoh: Hostinger). GitHub Pages tidak boleh menjalankan checkout.

## Pasang di Hostinger

1. **Pangkalan data** — hPanel → *Databases → MySQL Databases*. Cipta database dan pengguna. Simpan nama database, nama pengguna dan kata laluan.
2. **Kod** — hPanel → *Advanced → Git*. Repository: `https://github.com/amirulhakim08/nektovia.git`, Branch: `main`, Directory: kosongkan (public_html). *Folder public_html mesti kosong sebelum deploy pertama.* Hidupkan **Auto Deployment** dan tambah webhook yang diberi ke GitHub (repo → Settings → Webhooks).
3. **Tetapan** — hPanel → *File Manager* → `public_html/api/`. Salin `config.sample.php` kepada `config.php` dan isikan:
   - maklumat database (langkah 1)
   - `chip_brand_id` dan `chip_secret_key` (dashboard CHIP → Developers)
   - `admin_password` (kata laluan kuat untuk `/admin/`)
   `config.php` tidak disimpan dalam GitHub, jadi deploy tidak akan memadamnya.
4. **Domain** — di GoDaddy, tukar rekod **A `@`** kepada IP Hostinger (hPanel → *Websites → nektovia.com → DNS / Domain*), padam rekod A GitHub (185.199.x.x), dan tukar **CNAME `www`** kepada `nektovia.com`. Kemudian matikan GitHub Pages (repo → Settings → Pages → Unpublish).
5. **SSL** — hPanel → *Security → SSL* → pasang SSL percuma untuk nektovia.com.
6. **Uji** — buka `/admin/`, log masuk, semak kupon. Buat satu pesanan ujian dengan kunci ujian CHIP (test key) sebelum guna kunci sebenar.

## Ubah suai

- **Harga / produk baharu** — `api/products.php` (harga dalam sen; `active => true` untuk buka jualan). Kemas kini juga harga yang dipaparkan dalam landing page.
- **Caj penghantaran** — `SHIPPING` dalam `api/lib.php` (Semenanjung RM8, Sabah/Sarawak/Labuan RM18 setiap pesanan).
- **Kupon** — di `/admin/?p=coupons` (kod, nilai diskaun, belian minimum, had penggunaan, tarikh). Belian minimum dikira sebelum penghantaran.
- **Teks promosi** di landing page (RM90 → RM20, RM150 → RM50) ialah teks biasa — kemas kini jika kupon berubah.

## Keselamatan

- Harga, kupon dan jumlah dikira di pelayan, bukan di pelayar.
- Status "Dibayar" disahkan terus dengan API CHIP (bukan berdasarkan data yang dihantar ke laman).
- Jumlah yang dicaj CHIP disemak sama dengan jumlah pesanan sebelum pelanggan dihantar ke halaman bayaran.
