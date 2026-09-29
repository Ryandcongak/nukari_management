<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$from=preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['from']??'')?$_GET['from']:date('Y-m-01');
$to=preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['to']??'')?$_GET['to']:date('Y-m-d');
if($from>$to){[$from,$to]=[$to,$from];}
$products=$pdo->query("SELECT product_code,product_name,category,description,base_price,selling_price,stock,unit,track_stock,status FROM products ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$stmt=$pdo->prepare("SELECT s.receipt_code,s.sale_date,s.customer_name,s.channel,s.payment_method,i.product_code,i.product_name,i.qty,i.unit_price,i.base_price,i.net_amount,i.profit_amount FROM product_cashier_sales s JOIN product_cashier_sale_items i ON i.sale_id=s.id WHERE DATE(s.sale_date) BETWEEN :from AND :to AND s.payment_status='paid' ORDER BY s.sale_date DESC,s.id DESC,i.id");
$stmt->execute([':from'=>$from,':to'=>$to]);$sales=$stmt->fetchAll(PDO::FETCH_ASSOC);

function product_export_header($sheet,string $range):void{$sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');$sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('166534');$sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);}
function product_export_table($sheet,string $range):void{$sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D8E2DC');$sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);}
function product_export_money($sheet,string $range):void{$sheet->getStyle($range)->getNumberFormat()->setFormatCode('"Rp" #,##0');}
function product_export_title($sheet,string $title,string $period):void{$sheet->setCellValue('A1',$title);$sheet->setCellValue('A2',$period);$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('166534');$sheet->freezePane('A5');}

$book=new Spreadsheet();$productSheet=$book->getActiveSheet();$productSheet->setTitle('Produk Jadi');product_export_title($productSheet,'Laporan Produk Jadi','Diekspor '.date('d M Y H:i'));
$productHeaders=['Kode','Nama Produk','Kategori','Deskripsi','Harga Dasar','Harga Jual','Stok','Satuan','Kontrol Stok','Status'];$productSheet->fromArray($productHeaders,null,'A4');product_export_header($productSheet,'A4:J4');$row=5;
foreach($products as $p){$productSheet->fromArray([$p['product_code'],$p['product_name'],$p['category'],$p['description'],(float)$p['base_price'],(float)$p['selling_price'],(float)$p['stock'],$p['unit'],(int)$p['track_stock']===1?'Aktif':'Tidak',$p['status']],null,'A'.$row);$row++;}
if($row>5){product_export_table($productSheet,'A4:J'.($row-1));product_export_money($productSheet,'E5:F'.($row-1));$productSheet->getStyle('G5:G'.($row-1))->getNumberFormat()->setFormatCode('#,##0.###');}

$saleSheet=$book->createSheet();$saleSheet->setTitle('Penjualan POS');product_export_title($saleSheet,'Laporan Penjualan POS','Periode '.$from.' sampai '.$to);
$saleHeaders=['Tanggal','Kode Struk','Kode Produk','Produk','Customer','Channel','Metode','Qty','Harga Jual','Harga Dasar','Net','Keuntungan'];$saleSheet->fromArray($saleHeaders,null,'A4');product_export_header($saleSheet,'A4:L4');$row=5;
foreach($sales as $s){$saleSheet->fromArray([$s['sale_date'],$s['receipt_code'],$s['product_code'],$s['product_name'],$s['customer_name']?:'Umum',$s['channel'],$s['payment_method'],(float)$s['qty'],(float)$s['unit_price'],(float)$s['base_price'],(float)$s['net_amount'],(float)$s['profit_amount']],null,'A'.$row);$row++;}
if($row>5){product_export_table($saleSheet,'A4:L'.($row-1));product_export_money($saleSheet,'I5:L'.($row-1));$saleSheet->getStyle('H5:H'.($row-1))->getNumberFormat()->setFormatCode('#,##0.###');}
foreach([$productSheet,$saleSheet] as $sheet){foreach(range('A',$sheet->getHighestColumn()) as $column)$sheet->getColumnDimension($column)->setAutoSize(true);$sheet->setAutoFilter('A4:'.$sheet->getHighestColumn().'4');}
$book->setActiveSheetIndex(0);while(ob_get_level()>0)ob_end_clean();$filename='laporan-produk-pos-'.date('Ymd-His').'.xlsx';header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: max-age=0');(new Xlsx($book))->save('php://output');exit;
