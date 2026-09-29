<?php

function party_generate_code(PDO $pdo): string
{
    $prefix = 'PIC-' . date('Ym') . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM company_parties WHERE party_code LIKE :code");
    $stmt->execute([':code'=>$prefix.'%']);
    return $prefix . str_pad((string)(((int)$stmt->fetchColumn()) + 1), 4, '0', STR_PAD_LEFT);
}
function party_active_list(PDO $pdo): array
{
    return $pdo->query("SELECT p.*, GROUP_CONCAT(r.role_type ORDER BY r.role_type SEPARATOR ', ') role_labels
        FROM company_parties p LEFT JOIN company_party_roles r ON r.party_id=p.id AND r.status='active'
        WHERE p.status='active' GROUP BY p.id ORDER BY p.display_name")->fetchAll(PDO::FETCH_ASSOC);
}

function party_role_label(string $role): string
{
    return [
        'shareholder'=>'Pemegang Saham','director'=>'Direktur','president_commissioner'=>'Komisaris Utama',
        'commissioner'=>'Komisaris','consultant'=>'Konsultan','employee'=>'Karyawan','partner'=>'Mitra',
        'supplier'=>'Supplier','other'=>'Lainnya'
    ][$role] ?? ucwords(str_replace('_',' ',$role));
}
