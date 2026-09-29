<?php
require_once __DIR__ . '/../../includes/auth.php';

redirect('pages/products/simple.php?info=' . urlencode('Mutasi Bahan sudah dinonaktifkan pada Produk Sederhana.'));
