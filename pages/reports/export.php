<?php
require_once __DIR__ . '/../../includes/auth.php';
require_permission('reports.export');
require_once __DIR__ . '/report_data.php';
$type=$_GET['type'] ?? 'finance'; $from=nr_date($_GET['from'] ?? '',date('Y-m-01')); $to=nr_date($_GET['to'] ?? '',date('Y-m-d')); if($from>$to)[$from,$to]=[$to,$from];
$report=nr_report($pdo,$type,$from,$to);
$filename='laporan-'.$report['type'].'-'.$from.'-'.$to.'.csv';
header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="'.$filename.'"'); header('X-Content-Type-Options: nosniff');
$out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,[$report['definition']['title']],';'); fputcsv($out,['Periode',$from.' s/d '.$to],';');
if ($report['type']==='finance' && !empty($report['finance'])) {
    $finance=$report['finance'];
    fputcsv($out,[], ';');
    fputcsv($out,['POSISI UANG PER '.$to],';');
    fputcsv($out,['Total Uang Perusahaan',nr_money($finance['ending']['total_cash'])],';');
    fputcsv($out,['Saldo Bank & Kas Pusat',nr_money($finance['ending']['central_cash'])],';');
    fputcsv($out,['Sisa Uang di PIC',nr_money($finance['ending']['pic_cash'])],';');
    fputcsv($out,['Piutang Belum Diterima',nr_money($finance['receivable'])],';');
    fputcsv($out,['Hutang Belum Dibayar',nr_money($finance['debt'])],';');
    fputcsv($out,['Posisi Bersih',nr_money($finance['net_position'])],';');
    fputcsv($out,[], ';');
    fputcsv($out,['ARUS KAS PERIODE'], ';');
    foreach($report['summary'] as $item) fputcsv($out,[$item['label'],$item['value']],';');
    fputcsv($out,['Dana Diberikan ke PIC (transfer internal)',nr_money($finance['internal_transferred'])],';');
    fputcsv($out,['Dana Dikembalikan PIC (transfer internal)',nr_money($finance['internal_returned'])],';');
    fputcsv($out,[], ';');
    fputcsv($out,['RINCIAN SALDO BANK & KAS'], ';');
    fputcsv($out,['Akun','Jenis','Saldo'], ';');
    foreach($finance['ending']['accounts'] as $account) fputcsv($out,[$account['account_name'],$account['account_type'],nr_money($account['balance'])],';');
    fputcsv($out,[], ';');
    fputcsv($out,['RINCIAN DANA PIC'], ';');
    fputcsv($out,['PIC','Kode','Saldo'], ';');
    foreach($finance['ending']['funds'] as $fund) {
        if(abs((float)$fund['balance'])<0.01) continue;
        fputcsv($out,[$fund['holder_name'],$fund['fund_code'],nr_money($fund['balance'])],';');
    }
}
fputcsv($out,[], ';'); fputcsv($out,['RINCIAN LAPORAN'], ';'); fputcsv($out,$report['definition']['headers'],';');
foreach($report['rows'] as $row) fputcsv($out,$row,';'); fclose($out); exit;
