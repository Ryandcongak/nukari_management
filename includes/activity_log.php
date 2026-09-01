<?php
/**
 * Activity Log Helper
 * PT Nukari Kriya Raya Management System
 *
 * Simpan file ini di:
 * includes/activity_log.php
 *
 * Cara pakai di file lain:
 * require_once __DIR__ . '/../../includes/activity_log.php';
 *
 * Contoh:
 * log_activity($pdo, 'Payroll', 'UPDATE', 'Mengubah adjustment payroll Sarni periode 2026-05', $payrollId, 'payrolls');
 */

if (!function_exists('get_client_ip')) {
    function get_client_ip(): string
    {
        $keys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ipList = explode(',', $_SERVER[$key]);
                return trim($ipList[0]);
            }
        }

        return 'UNKNOWN';
    }
}

if (!function_exists('current_user_id')) {
    function current_user_id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}

if (!function_exists('log_activity')) {
    /**
     * Menyimpan aktivitas penting user ke tabel activity_logs.
     *
     * @param PDO         $pdo
     * @param string      $module          Nama modul, contoh: Payroll, HR Leave, Loan, Kasbon
     * @param string      $action          Aksi, contoh: CREATE, UPDATE, DELETE, APPROVE, LOCK, UNLOCK, EXPORT
     * @param string|null $description     Deskripsi aktivitas yang mudah dipahami manusia
     * @param int|null    $referenceId     ID data terkait
     * @param string|null $referenceTable  Nama tabel terkait
     * @param int|null    $userId          Optional, default membaca dari session
     *
     * @return bool
     */
    function log_activity(
        PDO $pdo,
        string $module,
        string $action,
        ?string $description = null,
        ?int $referenceId = null,
        ?string $referenceTable = null,
        ?int $userId = null
    ): bool {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $userId = $userId ?? current_user_id();
            $ipAddress = get_client_ip();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            static $modernSchema = null;
            if ($modernSchema === null) {
                $columns = $pdo->query('SHOW COLUMNS FROM activity_logs')->fetchAll(PDO::FETCH_COLUMN);
                $modernSchema = in_array('module', $columns, true) && in_array('action', $columns, true);
            }

            if ($modernSchema) {
                $stmt = $pdo->prepare("
                    INSERT INTO activity_logs
                        (user_id, module, action, description, reference_id, reference_table, ip_address, user_agent, created_at)
                    VALUES
                        (:user_id, :module, :action, :description, :reference_id, :reference_table, :ip_address, :user_agent, NOW())
                ");
                return $stmt->execute([
                    ':user_id' => $userId,
                    ':module' => mb_substr($module, 0, 100),
                    ':action' => mb_substr($action, 0, 100),
                    ':description' => $description,
                    ':reference_id' => $referenceId,
                    ':reference_table' => $referenceTable ? mb_substr($referenceTable, 0, 100) : null,
                    ':ip_address' => mb_substr($ipAddress, 0, 100),
                    ':user_agent' => $userAgent,
                ]);
            }

            $activity = trim($module . ' · ' . $action);
            if ($referenceTable || $referenceId) {
                $activity .= ' [' . ($referenceTable ?: 'data') . '#' . ($referenceId ?: '-') . ']';
            }
            $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, activity, description, ip_address, created_at) VALUES (:user_id,:activity,:description,:ip_address,NOW())");
            return $stmt->execute([
                ':user_id' => $userId,
                ':activity' => mb_substr($activity, 0, 255),
                ':description' => $description,
                ':ip_address' => mb_substr($ipAddress, 0, 100),
            ]);
        } catch (Throwable $e) {
            // Jangan sampai audit log membuat proses utama gagal.
            // Untuk development, Anda bisa aktifkan error_log berikut.
            error_log('Activity log failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('log_activity_changes')) {
    /**
     * Membuat deskripsi perubahan field secara otomatis.
     *
     * @param array $before Data lama
     * @param array $after  Data baru
     * @param array $labels Label field, contoh: ['net_salary' => 'Gaji Bersih']
     *
     * @return string
     */
    function log_activity_changes(array $before, array $after, array $labels = []): string
    {
        $changes = [];

        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;

            if ((string)$oldValue !== (string)$newValue) {
                $label = $labels[$key] ?? $key;
                $changes[] = $label . ': "' . (string)$oldValue . '" menjadi "' . (string)$newValue . '"';
            }
        }

        return empty($changes) ? 'Tidak ada perubahan data.' : implode('; ', $changes);
    }
}

if (!function_exists('activity_action_badge_class')) {
    function activity_action_badge_class(string $action): string
    {
        $action = strtoupper($action);

        return match ($action) {
            'CREATE', 'ADD', 'INSERT' => 'success',
            'UPDATE', 'EDIT', 'ADJUSTMENT' => 'primary',
            'DELETE', 'REMOVE', 'CANCEL' => 'danger',
            'APPROVE', 'PAID', 'LOCK' => 'success',
            'REJECT', 'UNLOCK' => 'warning',
            'EXPORT', 'PRINT' => 'info',
            default => 'secondary',
        };
    }
}
