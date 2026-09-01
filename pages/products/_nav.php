<?php $productPath = $_SERVER['REQUEST_URI'] ?? ''; ?>
<div class="product-card p-2 mb-3 no-print product-nav-wrap">
  <div class="nav nav-pills gap-1">
    <a class="nav-link <?= strpos($productPath,'products/index.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/index.php'); ?>"><i class="bi bi-grid me-1"></i> Dashboard</a>
    <a class="nav-link <?= strpos($productPath,'materials.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/materials.php'); ?>"><i class="bi bi-boxes me-1"></i> Gudang Bahan</a>
    <a class="nav-link <?= strpos($productPath,'movements.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/movements.php'); ?>"><i class="bi bi-arrow-left-right me-1"></i> Mutasi Stok</a>
    <a class="nav-link <?= strpos($productPath,'production.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/production.php'); ?>"><i class="bi bi-gear-wide-connected me-1"></i> Produksi</a>
    <a class="nav-link <?= strpos($productPath,'calculator.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/calculator.php'); ?>"><i class="bi bi-calculator me-1"></i> Kalkulator HPP</a>
    <a class="nav-link <?= strpos($productPath,'sales.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/sales.php'); ?>"><i class="bi bi-cart-check me-1"></i> Penjualan</a>
    <a class="nav-link <?= strpos($productPath,'custom_orders.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/custom_orders.php'); ?>"><i class="bi bi-brush me-1"></i> Custom Order</a>
    <a class="nav-link <?= strpos($productPath,'report.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/report.php'); ?>"><i class="bi bi-file-earmark-bar-graph me-1"></i> Laporan</a>
  </div>
</div>
