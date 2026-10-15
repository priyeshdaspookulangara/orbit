<?php

namespace App\Database;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;
    private static string $driver = 'sqlite';
    private static string $sqlitePath = __DIR__ . '/../../storage/database.sqlite';
    private static array $dbConfig = [];

    public static function setConfig(array $config): void
    {
        self::$dbConfig = $config;
        self::$driver = $config['driver'] ?? 'sqlite';
        self::$sqlitePath = $config['sqlite_path'] ?? (__DIR__ . '/../../storage/database.sqlite');
        self::$instance = null; // reset instance on config update
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            if (self::$driver === 'mysql') {
                $host = self::$dbConfig['host'] ?? '127.0.0.1';
                $port = self::$dbConfig['port'] ?? '3306';
                $dbname = self::$dbConfig['dbname'] ?? 'protein_mlm';
                $username = self::$dbConfig['username'] ?? 'root';
                $password = self::$dbConfig['password'] ?? '';

                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                self::$instance = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } else {
                // SQLite (Default)
                if (self::$sqlitePath !== ':memory:') {
                    $dir = dirname(self::$sqlitePath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                }

                $dsn = "sqlite:" . self::$sqlitePath;
                self::$instance = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                self::$instance->exec("PRAGMA foreign_keys = ON;");
            }
        }
        return self::$instance;
    }

    public static function runMigrations(PDO $pdo, string $schemaPath = __DIR__ . '/../../database/schema.sql'): void
    {
        $sql = file_get_contents($schemaPath);
        if ($sql === false) {
            throw new \RuntimeException("Could not read schema file: {$schemaPath}");
        }

        // Handle MySQL vs SQLite differences if necessary
        $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driverName === 'mysql') {
            $sql = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT AUTO_INCREMENT PRIMARY KEY', $sql);
            $sql = str_replace('DATETIME DEFAULT CURRENT_TIMESTAMP', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sql);
        }

        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                $pdo->exec($stmt);
            }
        }
    }
}
