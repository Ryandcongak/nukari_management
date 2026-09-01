<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$id = (int)($_POST['asset_id'] ?? 0);
if ($id <= 0) redirect('pages/assets/index.php');
try {
    $file = asset_upload_file($_FILES['file_path'] ?? [], 'assets', 'asset_doc', ['jpg','jpeg','png','webp','pdf']);
    if (!$file) throw new Exception('File dokumen wajib diupload.');
    $stmt = $pdo->prepare("INSERT INTO asset_documents (asset_id, document_type, document_name, file_path, expiry_date, notes, created_by) VALUES (:asset_id, :document_type, :document_name, :file_path, :expiry_date, :notes, :created_by)");
    $stmt->execute([
        ':asset_id'=>$id,
        ':document_type'=>$_POST['document_type'] ?? 'other',
        ':document_name'=>clean_input($_POST['document_name'] ?? 'Dokumen Aset'),
        ':file_path'=>$file,
        ':expiry_date'=>($_POST['expiry_date'] ?? '') ?: null,
        ':notes'=>clean_input($_POST['notes'] ?? ''),
        ':created_by'=>$_SESSION['user_id'] ?? null,
    ]);
    $docId = (int)$pdo->lastInsertId();
    asset_add_event($pdo, $id, 'document', 'Dokumen aset ditambahkan', clean_input($_POST['document_name'] ?? 'Dokumen aset'), 'asset_documents', $docId);
    if (!empty($_POST['expiry_date'])) {
        asset_create_notification($pdo, 'asset_document_expiry', 'Dokumen Aset Akan Berakhir', clean_input($_POST['document_name'] ?? 'Dokumen aset') . ' untuk aset ID #' . $id, $_POST['expiry_date'], 'warning', 'bi-file-earmark-text', 'pages/assets/detail.php?id=' . $id);
    }
    $_SESSION['success'] = 'Dokumen aset berhasil ditambahkan.';
} catch (Exception $e) { $_SESSION['error'] = $e->getMessage(); }
redirect('pages/assets/detail.php?id=' . $id . '#documents');
