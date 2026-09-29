<?php

function nr_date(string $value, string $fallback): string {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function nr_money($value): string { return 'Rp ' . number_format((float)$value, 0, ',', '.'); }
function nr_number($value): string { return number_format((float)$value, 0, ',', '.'); }

function nr_report(PDO $pdo, string $type, string $from, string $to): array {
    $definitions = [
        'finance' => [
            'title'=>'Laporan Keuangan', 'description'=>'Arus kas masuk dan keluar seluruh modul.',
            'headers'=>['Tanggal','Kode Referensi','Kode Sistem','Nama Transaksi','Jenis','Kategori','Akun','Proyek','Keterangan','Nominal'],
            'sql'=>"SELECT t.transaction_date,t.reference_code,t.transaction_code,t.transaction_name,t.type,COALESCE(c.category_name,'-') category_name,COALESCE(a.account_name,'-') account_name,COALESCE(p.project_name,'-') project_name,t.description,t.amount FROM transactions t LEFT JOIN transaction_categories c ON c.id=t.category_id LEFT JOIN finance_accounts a ON a.id=t.account_id LEFT JOIN projects p ON p.id=t.project_id WHERE t.transaction_date BETWEEN :from AND :to ORDER BY t.transaction_date DESC,t.id DESC",
            'row'=>fn($r)=>[$r['transaction_date'],$r['reference_code']?:'-',$r['transaction_code'],$r['transaction_name']?:$r['description'],ucfirst($r['type']),$r['category_name'],$r['account_name'],$r['project_name'],$r['description'],nr_money($r['amount'])],
            'summary'=>fn($rows)=>[
                ['label'=>'Total Pemasukan','value'=>nr_money(array_sum(array_map(fn($r)=>$r['type']==='income'?(float)$r['amount']:0,$rows))),'tone'=>'success'],
                ['label'=>'Total Pengeluaran','value'=>nr_money(array_sum(array_map(fn($r)=>$r['type']==='expense'?(float)$r['amount']:0,$rows))),'tone'=>'danger'],
                ['label'=>'Saldo Periode','value'=>nr_money(array_sum(array_map(fn($r)=>$r['type']==='income'?(float)$r['amount']:-(float)$r['amount'],$rows))),'tone'=>'primary'],
                ['label'=>'Jumlah Transaksi','value'=>nr_number(count($rows)),'tone'=>'secondary'],
            ],
        ],
        'projects' => [
            'title'=>'Laporan Proyek', 'description'=>'Nilai kontrak, status, termin dan biaya proyek.',
            'headers'=>['Kode','Proyek','Client','Mulai','Selesai','Status','Nilai Kontrak','Termin Dibayar','Pengeluaran'],
            'sql'=>"SELECT p.project_code,p.project_name,COALESCE(c.client_name,'-') client_name,p.start_date,p.end_date,p.status,p.contract_value,COALESCE(pt.paid_amount,0) paid_amount,COALESCE(tx.expense,0) expense FROM projects p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN (SELECT project_id,SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) paid_amount FROM project_payment_terms GROUP BY project_id) pt ON pt.project_id=p.id LEFT JOIN (SELECT project_id,SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END) expense FROM project_transactions GROUP BY project_id) tx ON tx.project_id=p.id WHERE (p.start_date IS NULL OR p.start_date<=:to) AND (p.end_date IS NULL OR p.end_date>=:from) ORDER BY p.id DESC",
            'row'=>fn($r)=>[$r['project_code'],$r['project_name'],$r['client_name'],$r['start_date'],$r['end_date'],ucfirst($r['status']),nr_money($r['contract_value']),nr_money($r['paid_amount']),nr_money($r['expense'])],
            'summary'=>fn($rows)=>[
                ['label'=>'Jumlah Proyek','value'=>nr_number(count($rows)),'tone'=>'primary'],
                ['label'=>'Nilai Kontrak','value'=>nr_money(array_sum(array_column($rows,'contract_value'))),'tone'=>'success'],
                ['label'=>'Termin Dibayar','value'=>nr_money(array_sum(array_column($rows,'paid_amount'))),'tone'=>'info'],
                ['label'=>'Pengeluaran','value'=>nr_money(array_sum(array_column($rows,'expense'))),'tone'=>'danger'],
            ],
        ],
        'payroll' => [
            'title'=>'Laporan Payroll', 'description'=>'Gaji bersih dan status payroll karyawan.',
            'headers'=>['Periode','Kode','Karyawan','Departemen','Gaji Pokok','Tunjangan','Bonus','Lembur','Potongan','Gaji Bersih','Status'],
            'sql'=>"SELECT p.payroll_month,e.employee_code,e.full_name,e.department,p.base_salary,p.total_allowance,p.total_bonus,p.overtime_amount,(COALESCE(p.total_deduction,0)+COALESCE(p.loan_deduction,0)+COALESCE(p.cash_advance_deduction,0)) deductions,p.net_salary,p.status FROM payrolls p JOIN employees e ON e.id=p.employee_id WHERE CONCAT(p.payroll_month,'-01') BETWEEN DATE_FORMAT(:from,'%Y-%m-01') AND DATE_FORMAT(:to,'%Y-%m-01') ORDER BY p.payroll_month DESC,e.full_name",
            'row'=>fn($r)=>[$r['payroll_month'],$r['employee_code'],$r['full_name'],$r['department'],nr_money($r['base_salary']),nr_money($r['total_allowance']),nr_money($r['total_bonus']),nr_money($r['overtime_amount']),nr_money($r['deductions']),nr_money($r['net_salary']),ucfirst($r['status'])],
            'summary'=>fn($rows)=>[
                ['label'=>'Data Payroll','value'=>nr_number(count($rows)),'tone'=>'primary'],
                ['label'=>'Gaji Pokok','value'=>nr_money(array_sum(array_column($rows,'base_salary'))),'tone'=>'info'],
                ['label'=>'Total Potongan','value'=>nr_money(array_sum(array_column($rows,'deductions'))),'tone'=>'danger'],
                ['label'=>'Gaji Bersih','value'=>nr_money(array_sum(array_column($rows,'net_salary'))),'tone'=>'success'],
            ],
        ],
        'assets' => [
            'title'=>'Laporan Aset', 'description'=>'Daftar nilai, lokasi, penanggung jawab dan kondisi aset.',
            'headers'=>['Kode','Nama Aset','Kategori','Lokasi','Penanggung Jawab','Kondisi','Status','Harga Perolehan','Nilai Saat Ini'],
            'sql'=>"SELECT a.asset_code,a.asset_name,a.category,a.location,COALESCE(e.full_name,'-') responsible_name,a.condition_status,a.asset_status,a.purchase_price,COALESCE(a.current_value,a.purchase_price) current_value FROM assets a LEFT JOIN employees e ON e.id=a.responsible_employee_id ORDER BY a.asset_name",
            'row'=>fn($r)=>[$r['asset_code'],$r['asset_name'],$r['category'],$r['location'],$r['responsible_name'],ucfirst($r['condition_status']),ucfirst($r['asset_status']),nr_money($r['purchase_price']),nr_money($r['current_value'])],
            'summary'=>fn($rows)=>[
                ['label'=>'Jumlah Aset','value'=>nr_number(count($rows)),'tone'=>'primary'],
                ['label'=>'Harga Perolehan','value'=>nr_money(array_sum(array_column($rows,'purchase_price'))),'tone'=>'info'],
                ['label'=>'Nilai Saat Ini','value'=>nr_money(array_sum(array_column($rows,'current_value'))),'tone'=>'success'],
                ['label'=>'Perlu Perhatian','value'=>nr_number(count(array_filter($rows,fn($r)=>in_array($r['condition_status'],['maintenance','damaged','lost'],true)))),'tone'=>'danger'],
            ],
        ],
        'products' => [
            'title'=>'Laporan Penjualan Produk', 'description'=>'Penjualan, omzet, HPP dan laba produk.',
            'headers'=>['Tanggal','Kode','Produk','Channel','Customer','Qty','Omzet','HPP','Laba','Pembayaran'],
            'sql'=>"SELECT s.sale_date,s.sale_code,p.product_name,s.channel,s.customer_name,s.qty,s.net_amount,s.hpp_total,s.profit_amount,s.payment_method FROM product_sales s JOIN products p ON p.id=s.product_id WHERE s.sale_date BETWEEN :from AND :to ORDER BY s.sale_date DESC,s.id DESC",
            'row'=>fn($r)=>[$r['sale_date'],$r['sale_code'],$r['product_name'],ucfirst($r['channel']),$r['customer_name'],$r['qty'],nr_money($r['net_amount']),nr_money($r['hpp_total']),nr_money($r['profit_amount']),ucwords(str_replace('_',' ',$r['payment_method']))],
            'summary'=>fn($rows)=>[
                ['label'=>'Jumlah Penjualan','value'=>nr_number(count($rows)),'tone'=>'primary'],
                ['label'=>'Produk Terjual','value'=>nr_number(array_sum(array_column($rows,'qty'))),'tone'=>'info'],
                ['label'=>'Omzet Bersih','value'=>nr_money(array_sum(array_column($rows,'net_amount'))),'tone'=>'success'],
                ['label'=>'Laba','value'=>nr_money(array_sum(array_column($rows,'profit_amount'))),'tone'=>'warning'],
            ],
        ],
        'employees' => [
            'title'=>'Laporan Karyawan', 'description'=>'Daftar karyawan, departemen dan status kepegawaian.',
            'headers'=>['Kode','Nama','Fingerprint','Posisi','Departemen','Tipe Gaji','Gaji','Status'],
            'sql'=>"SELECT employee_code,full_name,fingerprint_id,position,department,salary_type,salary_amount,employment_status FROM employees ORDER BY full_name",
            'row'=>fn($r)=>[$r['employee_code'],$r['full_name'],$r['fingerprint_id'],$r['position'],$r['department'],ucwords(str_replace('_',' ',$r['salary_type'])),nr_money($r['salary_amount']),ucfirst($r['employment_status'])],
            'summary'=>fn($rows)=>[
                ['label'=>'Jumlah Karyawan','value'=>nr_number(count($rows)),'tone'=>'primary'],
                ['label'=>'Karyawan Aktif','value'=>nr_number(count(array_filter($rows,fn($r)=>$r['employment_status']==='active'))),'tone'=>'success'],
                ['label'=>'Departemen','value'=>nr_number(count(array_unique(array_filter(array_column($rows,'department'))))),'tone'=>'info'],
                ['label'=>'Total Gaji Dasar','value'=>nr_money(array_sum(array_column($rows,'salary_amount'))),'tone'=>'warning'],
            ],
        ],
    ];

    if (!isset($definitions[$type])) $type='finance';
    $definition=$definitions[$type];
    try {
        $stmt=$pdo->prepare($definition['sql']);
        $params=[];
        if (strpos($definition['sql'], ':from') !== false) $params[':from']=$from;
        if (strpos($definition['sql'], ':to') !== false) $params[':to']=$to;
        $stmt->execute($params); $raw=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $error='';
    } catch (Throwable $e) { $raw=[]; $error='Laporan belum dapat dibaca. Periksa struktur database modul ini.'; error_log('Report '.$type.': '.$e->getMessage()); }
    return ['type'=>$type,'definition'=>$definition,'raw'=>$raw,'rows'=>array_map($definition['row'],$raw),'summary'=>($definition['summary'])($raw),'error'=>$error];
}
