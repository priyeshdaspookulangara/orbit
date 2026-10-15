<?php

namespace App\Services;

use PDO;

class AuditLogger
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function log(string $eventType, ?int $userId, array $details): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO audit_logs (event_type, user_id, details, created_at)
            VALUES (:event_type, :user_id, :details, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':event_type' => $eventType,
            ':user_id' => $userId,
            ':details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
