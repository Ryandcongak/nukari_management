<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/report_data.php';
$type=$_GET['type'] ?? 'finance'; $from=nr_date($_GET['from'] ?? '',date('Y-m-01')); $to=nr_date($_GET['to'] ?? '',date('Y-m-d')); if($from>$to)[$from,$to]=[$to,$from];
$report=nr_report($pdo,$type,$from,$to);
$filename='laporan-'.$report['type'].'-'.$from.'-'.$to.'.csv';
header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="'.$filename.'"'); header('X-Content-Type-Options: nosniff');
$out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,[$report['definition']['title']],';'); fputcsv($out,['Periode',$from.' s/d '.$to],';'); fputcsv($out,[], ';'); fputcsv($out,$report['definition']['headers'],';');
foreach($report['rows'] as $row) fputcsv($out,$row,';'); fclose($out); exit;
