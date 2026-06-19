<?php
// src/Database.php — PDO factory (sqlite for testing, mysql for production).

class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        try {
            if (($config['db_driver'] ?? 'sqlite') === 'mysql') {
                $m = $config['mysql'];
                $pdo = new PDO(
                    "mysql:host={$m['host']};dbname={$m['name']};charset=utf8mb4",
                    $m['user'],
                    $m['pass']
                );
            } else {
                $pdo = new PDO('sqlite:' . $config['sqlite_path']);
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Service temporarily unavailable.');
        }
        return self::$pdo = $pdo;
    }
}
