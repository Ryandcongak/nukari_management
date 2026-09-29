<?php $productPath = $_SERVER['REQUEST_URI'] ?? ''; ?>
<div class="product-card p-2 mb-3 no-print product-nav-wrap">
  <div class="nav nav-pills gap-1">
    <a class="nav-link <?= strpos($productPath,'simple.php')!==false||strpos($productPath,'products/index.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/simple.php'); ?>"><i class="bi bi-box-seam me-1"></i> Produk</a>
    <a class="nav-link <?= strpos($productPath,'sales.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/sales.php'); ?>"><i class="bi bi-cart-check me-1"></i> Penjualan</a>
    <a class="nav-link <?= strpos($productPath,'custom_orders.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/custom_orders.php'); ?>"><i class="bi bi-brush me-1"></i> Custom Order</a>
    <a class="nav-link <?= strpos($productPath,'report.php')!==false?'active':'' ?>" href="<?= base_url('pages/products/report.php'); ?>"><i class="bi bi-file-earmark-bar-graph me-1"></i> Laporan</a>
  </div>
</div>
