<?php
/**
 * SQL install helpers for install.php.
 */

function alcrosExecuteSqlBatch(PDO $pdo, string $sql): void
{
    $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

function alcrosInstallPdo(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET;

    return new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
