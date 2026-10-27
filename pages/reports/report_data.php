<?php

require_once __DIR__ . '/../../includes/finance_helper.php';

function nr_date(string $value, string $fallback): string {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function nr_money($value): string { return 'Rp ' . number_format((float)$value, 0, ',', '.'); }
function nr_number($value): string { return number_format((float)$value, 0, ',', '.'); }

/**
 * Posisi uang perusahaan pada suatu tanggal.
 *
 * Saldo akun pusat dibangun dari saldo awal + transaksi terposting - dana yang
 * diberikan ke PIC + pengembalian PIC. Saldo PIC dihitung terpisah agar
 * perpindahan internal tidak keliru dianggap sebagai biaya atau pendapatan.
 */
function nr_finance_cash_position(PDO $pdo, string $date, bool $inclusive = true): array {
    $operator = $inclusive ? '<=' : '<';
    $accounts = $pdo->query("SELECT id,account_code,account_name,account_type,opening_balance FROM finance_accounts WHERE status='active' ORDER BY is_default DESC,account_type,account_name")->fetchAll(PDO::FETCH_ASSOC);

    $transactionStmt = $pdo->prepare("SELECT account_id,COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) effect FROM transactions WHERE posting_mode='posted' AND account_id IS NOT NULL AND transaction_date {$operator} :position_date GROUP BY account_id");
    $transactionStmt->execute([':position_date'=>$date]);
    $transactionEffects = [];
    foreach ($transactionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $transactionEffects[(int)$row['account_id']] = (float)$row['effect'];

    $transferStmt = $pdo->prepare("SELECT account_id,COALESCE(SUM(CASE WHEN movement_type='receive' THEN -amount WHEN movement_type='return' THEN amount ELSE 0 END),0) effect FROM operational_fund_movements WHERE posting_mode='posted' AND account_id IS NOT NULL AND movement_date {$operator} :position_date GROUP BY account_id");
    $transferStmt->execute([':position_date'=>$date]);
    $transferEffects = [];
    foreach ($transferStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $transferEffects[(int)$row['account_id']] = (float)$row['effect'];

    $central = 0.0;
    foreach ($accounts as &$account) {
        $id = (int)$account['id'];
        $account['balance'] = (float)$account['opening_balance'] + ($transactionEffects[$id] ?? 0.0) + ($transferEffects[$id] ?? 0.0);
        $central += $account['balance'];
    }
    unset($account);

    $fundStmt = $pdo->prepare("SELECT f.id,f.fund_code,f.person_name,COALESCE(NULLIF(p.display_name,''),f.person_name) holder_name,f.status,
            COALESCE(SUM(CASE WHEN m.movement_type='receive' THEN m.amount WHEN m.movement_type='expense' THEN -m.amount WHEN m.movement_type='return' THEN -m.amount WHEN m.movement_type='adjustment' THEN m.amount ELSE 0 END),0) balance
        FROM operational_funds f
        LEFT JOIN company_parties p ON p.id=f.holder_party_id
        LEFT JOIN operational_fund_movements m ON m.fund_id=f.id AND m.posting_mode='posted' AND m.movement_date {$operator} :position_date
        WHERE f.status<>'cancelled'
        GROUP BY f.id,f.fund_code,f.person_name,p.display_name,f.status
        ORDER BY holder_name,f.fund_code");
    $fundStmt->execute([':position_date'=>$date]);
    $funds = $fundStmt->fetchAll(PDO::FETCH_ASSOC);
    $picCash = 0.0;
    $picAdvance = 0.0;
    foreach ($funds as &$fund) {
        $fund['balance'] = (float)$fund['balance'];
        $picCash += max(0.0, $fund['balance']);
        $picAdvance += max(0.0, -$fund['balance']);
    }
    unset($fund);

    return [
        'date'=>$date,
        'accounts'=>$accounts,
        'funds'=>$funds,
        'central_cash'=>$central,
        'pic_cash'=>$picCash,
        'pic_advance'=>$picAdvance,
        'total_cash'=>$central + $picCash,
        'signed_total'=>$central + $picCash - $picAdvance,
    ];
}

function nr_finance_debt_position(PDO $pdo, string $date, array $funds): array {
    $paymentStmt = $pdo->prepare("SELECT debt_id,COALESCE(SUM(amount),0) paid FROM debt_payments WHERE posting_mode='posted' AND payment_date<=:position_date GROUP BY debt_id");
    $paymentStmt->execute([':position_date'=>$date]);
    $payments = [];
    foreach ($paymentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $payments[(int)$row['debt_id']] = (float)$row['paid'];

    $fundBalances = [];
    foreach ($funds as $fund) $fundBalances[(int)$fund['id']] = (float)$fund['balance'];

    $stmt = $pdo->prepare("SELECT id,type,amount,paid_amount,paid_date,source_module,source_id FROM debts WHERE posting_mode<>'historical' AND issue_date<=:position_date");
    $stmt->execute([':position_date'=>$date]);
    $receivable = 0.0;
    $debt = array_sum(array_map(static fn($balance): float => max(0.0, -(float)$balance), $fundBalances));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Hutang otomatis Dana PIC diambil langsung dari saldo dana yang minus
        // agar tidak bergantung pada kapan baris hutang sinkron dibuat/ditutup.
        if ($row['source_module']==='operational_fund') continue;
        $paid = $payments[(int)$row['id']] ?? 0.0;
        if (!empty($row['paid_date']) && $row['paid_date'] <= $date) $paid = max($paid, (float)$row['paid_amount']);
        $outstanding = max(0.0, (float)$row['amount'] - $paid);
        if ($row['type']==='receivable') $receivable += $outstanding;
        else $debt += $outstanding;
    }
    return ['receivable'=>$receivable,'debt'=>$debt];
}

function nr_report(PDO $pdo, string $type, string $from, string $to): array {
    $executiveView = function_exists('is_report_only_role') && is_report_only_role();
    $definitions = [
        'finance' => [
            'title'=>'Laporan Keuangan', 'description'=>'Posisi uang perusahaan dan arus kas yang benar-benar memengaruhi kas/bank atau Dana PIC.',
            'headers'=>['Tanggal','Referensi','Kode Sistem','Keterangan','Arus','Kategori','Lokasi Uang','Proyek','Catatan','Nominal'],
            'sql'=>"SELECT t.transaction_date,t.reference_code,t.transaction_code,t.transaction_name,t.type,COALESCE(c.category_name,'-') category_name,COALESCE(a.account_name,'-') account_name,COALESCE(p.project_name,'-') project_name,t.description,t.amount,'account' source_kind FROM transactions t LEFT JOIN transaction_categories c ON c.id=t.category_id LEFT JOIN finance_accounts a ON a.id=t.account_id LEFT JOIN projects p ON p.id=t.project_id WHERE t.posting_mode='posted' AND t.account_id IS NOT NULL AND t.transaction_date BETWEEN :from AND :to ORDER BY t.transaction_date DESC,t.id DESC",
            'row'=>fn($r)=>[$r['transaction_date'],$r['reference_code']?:'-',$r['transaction_code'],$r['transaction_name']?:$r['description'],$r['type']==='income'?'Masuk':'Keluar',$r['category_name'],$r['account_name'],$r['project_name'],$r['description']?:'-',nr_money($r['amount'])],
            'summary'=>fn($rows)=>[
                ['label'=>'Total Pemasukan','value'=>nr_money(array_sum(array_map(fn($r)=>$r['type']==='income'?(float)$r['amount']:0,$rows))),'tone'=>'success'],
                ['label'=>'Total Pengeluaran','value'=>nr_money(array_sum(array_map(fn($r)=>$r['type']==='expense'?(float)$r['amount']:0,$rows))),'tone'=>'danger'],
                ['label'=>'Arus Kas Bersih Periode','value'=>nr_money(array_sum(array_map(fn($r)=>$r['type']==='income'?(float)$r['amount']:-(float)$r['amount'],$rows))),'tone'=>'primary'],
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
            'headers'=>['Periode','Kode','Karyawan','Departemen','Gaji Pokok','Tunjangan','Bonus','Lembur','Tambahan','Total Potongan','Gaji Bersih','Status'],
            'sql'=>"SELECT p.payroll_month,e.employee_code,e.full_name,e.department,p.base_salary,p.total_allowance,p.total_bonus,p.overtime_amount,COALESCE(p.additional_amount,0) additional_amount,(COALESCE(p.total_deduction,0)+COALESCE(p.total_tax,0)+COALESCE(p.bpjs_health,0)+COALESCE(p.bpjs_employment,0)) deductions,p.net_salary,p.status FROM payrolls p JOIN employees e ON e.id=p.employee_id WHERE CONCAT(p.payroll_month,'-01') BETWEEN DATE_FORMAT(:from,'%Y-%m-01') AND DATE_FORMAT(:to,'%Y-%m-01') ORDER BY p.payroll_month DESC,e.full_name",
            'row'=>fn($r)=>[$r['payroll_month'],$r['employee_code'],$r['full_name'],$r['department'],nr_money($r['base_salary']),nr_money($r['total_allowance']),nr_money($r['total_bonus']),nr_money($r['overtime_amount']),nr_money($r['additional_amount']),nr_money($r['deductions']),nr_money($r['net_salary']),ucfirst($r['status'])],
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

    // Direktur dan Komisaris memperoleh ringkasan yang berguna tanpa
    // fingerprint maupun rincian gaji setiap karyawan.
    if ($executiveView) {
        $definitions['payroll'] = [
            'title'=>'Ringkasan Payroll', 'description'=>'Ringkasan biaya payroll per periode tanpa rincian gaji per karyawan.',
            'headers'=>['Periode','Jumlah Karyawan','Gaji Pokok','Tunjangan','Bonus','Lembur','Tambahan','Potongan','Gaji Bersih'],
            'sql'=>"SELECT p.payroll_month,COUNT(*) employee_count,SUM(p.base_salary) base_salary,SUM(p.total_allowance) total_allowance,SUM(p.total_bonus) total_bonus,SUM(p.overtime_amount) overtime_amount,SUM(COALESCE(p.additional_amount,0)) additional_amount,SUM(COALESCE(p.total_deduction,0)+COALESCE(p.total_tax,0)+COALESCE(p.bpjs_health,0)+COALESCE(p.bpjs_employment,0)) deductions,SUM(p.net_salary) net_salary FROM payrolls p WHERE CONCAT(p.payroll_month,'-01') BETWEEN DATE_FORMAT(:from,'%Y-%m-01') AND DATE_FORMAT(:to,'%Y-%m-01') GROUP BY p.payroll_month ORDER BY p.payroll_month DESC",
            'row'=>fn($r)=>[$r['payroll_month'],nr_number($r['employee_count']),nr_money($r['base_salary']),nr_money($r['total_allowance']),nr_money($r['total_bonus']),nr_money($r['overtime_amount']),nr_money($r['additional_amount']),nr_money($r['deductions']),nr_money($r['net_salary'])],
            'summary'=>fn($rows)=>[
                ['label'=>'Periode Payroll','value'=>nr_number(count($rows)),'tone'=>'primary'],
                ['label'=>'Gaji Pokok','value'=>nr_money(array_sum(array_column($rows,'base_salary'))),'tone'=>'info'],
                ['label'=>'Total Potongan','value'=>nr_money(array_sum(array_column($rows,'deductions'))),'tone'=>'danger'],
                ['label'=>'Gaji Bersih','value'=>nr_money(array_sum(array_column($rows,'net_salary'))),'tone'=>'success'],
            ],
        ];
        $definitions['employees'] = [
            'title'=>'Ringkasan Karyawan', 'description'=>'Jumlah karyawan berdasarkan departemen dan status kepegawaian.',
            'headers'=>['Departemen','Jumlah Karyawan','Aktif','Tidak Aktif'],
            'sql'=>"SELECT COALESCE(NULLIF(department,''),'Belum ditentukan') department,COUNT(*) employee_count,SUM(employment_status='active') active_count,SUM(employment_status<>'active') inactive_count FROM employees GROUP BY COALESCE(NULLIF(department,''),'Belum ditentukan') ORDER BY department",
            'row'=>fn($r)=>[$r['department'],nr_number($r['employee_count']),nr_number($r['active_count']),nr_number($r['inactive_count'])],
            'summary'=>fn($rows)=>[
                ['label'=>'Jumlah Karyawan','value'=>nr_number(array_sum(array_column($rows,'employee_count'))),'tone'=>'primary'],
                ['label'=>'Karyawan Aktif','value'=>nr_number(array_sum(array_column($rows,'active_count'))),'tone'=>'success'],
                ['label'=>'Tidak Aktif','value'=>nr_number(array_sum(array_column($rows,'inactive_count'))),'tone'=>'warning'],
                ['label'=>'Departemen','value'=>nr_number(count($rows)),'tone'=>'info'],
            ],
        ];
    }

    if (!isset($definitions[$type])) $type='finance';
    $definition=$definitions[$type];
    try {
        if ($type==='finance') {
            $raw=finance_consolidated_cash_flow_rows($pdo,$from,$to);
        } else {
            $stmt=$pdo->prepare($definition['sql']);
            $params=[];
            if (strpos($definition['sql'], ':from') !== false) $params[':from']=$from;
            if (strpos($definition['sql'], ':to') !== false) $params[':to']=$to;
            $stmt->execute($params); $raw=$stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $error='';
    } catch (Throwable $e) { $raw=[]; $error='Laporan belum dapat dibaca. Periksa struktur database modul ini.'; error_log('Report '.$type.': '.$e->getMessage()); }
    $result=['type'=>$type,'definition'=>$definition,'raw'=>$raw,'rows'=>array_map($definition['row'],$raw),'summary'=>($definition['summary'])($raw),'error'=>$error];
    if ($type==='finance' && $error==='') {
        try {
            $opening=nr_finance_cash_position($pdo,$from,false);
            $ending=nr_finance_cash_position($pdo,$to,true);
            $debts=nr_finance_debt_position($pdo,$to,$ending['funds']);
            $income=array_sum(array_map(static fn($r)=>(($r['type']??'')==='income'?(float)$r['amount']:0.0),$raw));
            $expense=array_sum(array_map(static fn($r)=>(($r['type']??'')==='expense'?(float)$r['amount']:0.0),$raw));
            $net=$income-$expense;
            $periodDays=max(1,(int)floor((strtotime($to)-strtotime($from))/86400)+1);
            $previousTo=date('Y-m-d',strtotime($from.' -1 day'));
            $previousFrom=date('Y-m-d',strtotime($previousTo.' -'.($periodDays-1).' days'));
            $previousRows=finance_consolidated_cash_flow_rows($pdo,$previousFrom,$previousTo);
            $previousSummary=finance_cash_flow_summary($previousRows);
            $transferStmt=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type='receive' THEN amount ELSE 0 END),0) transferred,COALESCE(SUM(CASE WHEN movement_type='return' THEN amount ELSE 0 END),0) returned FROM operational_fund_movements WHERE posting_mode='posted' AND movement_date BETWEEN :from AND :to");
            $transferStmt->execute([':from'=>$from,':to'=>$to]);
            $transfers=$transferStmt->fetch(PDO::FETCH_ASSOC)?:[];
            $freshness=$pdo->query("SELECT MAX(changed_at) FROM (
                    SELECT MAX(updated_at) changed_at FROM transactions
                    UNION ALL SELECT MAX(created_at) changed_at FROM operational_fund_movements
                    UNION ALL SELECT MAX(updated_at) changed_at FROM debts
                    UNION ALL SELECT MAX(updated_at) changed_at FROM finance_accounts
                ) finance_changes")->fetchColumn();
            $result['finance']=[
                'opening'=>$opening,
                'ending'=>$ending,
                'income'=>$income,
                'expense'=>$expense,
                'net_cash_flow'=>$net,
                'cash_change'=>$ending['total_cash']-$opening['total_cash'],
                'reconciliation_difference'=>($ending['total_cash']-$opening['total_cash'])-$net,
                'internal_transferred'=>(float)($transfers['transferred']??0),
                'internal_returned'=>(float)($transfers['returned']??0),
                'receivable'=>$debts['receivable'],
                'debt'=>$debts['debt'],
                'net_position'=>$ending['total_cash']+$debts['receivable']-$debts['debt'],
                'previous_period'=>['from'=>$previousFrom,'to'=>$previousTo]+$previousSummary,
                'last_updated_at'=>$freshness?:null,
            ];
            $result['summary']=[
                ['label'=>'Saldo Awal Periode','value'=>nr_money($opening['total_cash']),'tone'=>'secondary'],
                ['label'=>'Total Pemasukan','value'=>nr_money($income),'tone'=>'success'],
                ['label'=>'Total Pengeluaran','value'=>nr_money($expense),'tone'=>'danger'],
                ['label'=>'Arus Kas Bersih','value'=>nr_money($net),'tone'=>$net>=0?'success':'danger'],
                ['label'=>'Saldo Akhir Periode','value'=>nr_money($ending['total_cash']),'tone'=>'primary'],
            ];
        } catch (Throwable $e) {
            $result['error']='Posisi uang belum dapat dihitung. Periksa struktur database keuangan.';
            error_log('Finance position report: '.$e->getMessage());
        }
    }
    return $result;
}
