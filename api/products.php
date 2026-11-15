<?php
/*
 * Katalog produk. Harga dalam SEN (4900 = RM49.00).
 * 'active' => false : produk dipaparkan tetapi belum boleh dibeli.
 */
return [
    'goldentrio' => [
        'name'   => 'NEKTOVIA The Golden Trio (80 g)',
        'short'  => 'The Golden Trio',
        'price'  => 4900,
        'image'  => '/assets/img/golden-trio.webp',
        'url'    => '/goldentrio/',
        'active' => true,
    ],
    'madulebahasli' => [
        'name'   => 'NEKTOVIA Madu Lebah Asli (500 g)',
        'short'  => 'Madu Lebah Asli',
        'price'  => 3900,
        'image'  => '/assets/img/madu.webp',
        'url'    => '/madulebahasli/',
        'active' => false, // tukar kepada true bila produk sedia dijual
    ],
];
