<?php
function master_groups(): array {
    return [
        'department'=>['Departemen','bi-diagram-3','Organisasi'], 'position'=>['Jabatan','bi-person-workspace','Organisasi'],
        'project_type'=>['Jenis Project','bi-kanban','Project'], 'work_stage'=>['Tahapan Pekerjaan','bi-signpost-split','Project'],
        'asset_category'=>['Kategori Aset','bi-box-seam','Aset'], 'asset_condition'=>['Kondisi Aset','bi-clipboard-check','Aset'],
        'location'=>['Lokasi','bi-geo-alt','Operasional'], 'unit'=>['Satuan','bi-rulers','Produk & Material'],
        'product_type'=>['Jenis Produk','bi-recycle','Produk & Material'],
        'payment_method'=>['Metode Pembayaran','bi-credit-card','Keuangan'],
    ];
}
function master_group(PDO $pdo,string $group,bool $activeOnly=true): array {
    if(!isset(master_groups()[$group]))return [];
    $sql="SELECT * FROM master_data_items WHERE group_code=:group".($activeOnly?" AND status='active'":"")." ORDER BY sort_order,item_name";
    $stmt=$pdo->prepare($sql);$stmt->execute([':group'=>$group]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function master_options(PDO $pdo,string $group,?string $selected=null): string {
    $html=''; $found=false; foreach(master_group($pdo,$group) as $item){$value=(string)$item['item_code'];$label=(string)$item['item_name'];$isSelected=$selected===$value;$found=$found||$isSelected;$html.='<option value="'.htmlspecialchars($value,ENT_QUOTES,'UTF-8').'"'.($isSelected?' selected':'').'>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</option>';}
    if($selected!==null&&$selected!==''&&!$found)$html='<option value="'.htmlspecialchars($selected,ENT_QUOTES,'UTF-8').'" selected>'.htmlspecialchars(ucwords(str_replace('_',' ',$selected)),ENT_QUOTES,'UTF-8').' (Legacy)</option>'.$html;
    return $html;
}
function master_datalist(PDO $pdo,string $group,string $id): string {
    $html='<datalist id="'.htmlspecialchars($id,ENT_QUOTES,'UTF-8').'">'; foreach(master_group($pdo,$group) as $item){$html.='<option value="'.htmlspecialchars((string)$item['item_name'],ENT_QUOTES,'UTF-8').'">';} return $html.'</datalist>';
}
