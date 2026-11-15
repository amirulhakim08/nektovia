<?php
/*
 * NEKTOVIA — fail tetapan.
 * 1. Salin fail ini kepada "config.php" (dalam folder yang sama).
 * 2. Isi maklumat di bawah. JANGAN commit config.php ke GitHub.
 */
return [
    // Alamat laman (tanpa garis miring di hujung)
    'site_url' => 'https://nektovia.com',

    // Pangkalan data MySQL (hPanel → Databases → MySQL Databases)
    'db_dsn'  => 'mysql:host=localhost;dbname=u000000000_nektovia;charset=utf8mb4',
    'db_user' => 'u000000000_nektovia',
    'db_pass' => 'KATA_LALUAN_DATABASE',

    // CHIP (dashboard CHIP → Developers)
    'chip_brand_id'   => 'BRAND_ID_ANDA',
    'chip_secret_key' => 'SECRET_KEY_ANDA',
    'chip_endpoint'   => 'https://gate.chip-in.asia/api/v1/',

    // Kata laluan halaman admin (/admin/). Guna kata laluan yang kuat.
    'admin_password' => 'TUKAR_KATA_LALUAN_INI',

    // Nombor WhatsApp untuk pertanyaan (format antarabangsa)
    'whatsapp' => '601152804888',
];
