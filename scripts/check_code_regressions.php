<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file): bool {
            if ($file->isDir()) {
                return !in_array($file->getFilename(), ['vendor', '.git', 'backups', 'uploads'], true);
            }
            return $file->getExtension() === 'php';
        }
    )
);

$errors = [];
foreach ($iterator as $file) {
    $path = $file->getPathname();
    $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
    $source = (string)file_get_contents($path);

    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }
        $text = $token[1];
        if (!preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|WHERE|VALUES)\b/i', $text)) {
            continue;
        }
        preg_match_all('/(?<!:):[A-Za-z_][A-Za-z0-9_]*/', $text, $matches);
        foreach (array_count_values($matches[0]) as $placeholder => $count) {
            if ($count > 1) {
                $errors[] = "{$relative}:{$token[2]} duplicate PDO placeholder {$placeholder} ({$count}x)";
            }
        }
        if (preg_match('/SHOW\s+(?:TABLES|COLUMNS|INDEX|KEYS)\b[^;]*(?:\?|:[A-Za-z_])/i', $text)) {
            $errors[] = "{$relative}:{$token[2]} placeholder tidak boleh dipakai di SHOW TABLES/COLUMNS";
        }
    }

    if (str_starts_with($relative, 'pages' . DIRECTORY_SEPARATOR)
        && preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+[A-Za-z_`]|DELETE\s+FROM)\b/i', $source)
        && str_contains($source, '$_GET')
        && !str_contains($source, 'require_post(')
        && !str_contains($source, 'REQUEST_METHOD')) {
        $errors[] = "{$relative} dapat memutasi data dari GET tanpa require_post/REQUEST_METHOD";
    }
}

if ($errors) {
    fwrite(STDERR, "Code regression checks failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Code regression checks passed.\n";
