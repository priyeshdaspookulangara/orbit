<?php

namespace App\Services;

use PDO;
use RuntimeException;

class MatrixService
{
    public const MAX_CHILDREN_PER_PARENT = 10;
    public const MAX_LEVELS = 5;
    public const MAX_TOTAL_NODES = 11111; // 1 + 10 + 100 + 1000 + 10000

    private PDO $pdo;
    private AuditLogger $logger;

    public function __construct(PDO $pdo, ?AuditLogger $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger ?? new AuditLogger($pdo);
    }

    /**
     * Places a user in the 10-matrix tree using Company-Wide Breadth-First Search (BFS) Spillover logic.
     *
     * In a full 10-ary tree with level order indexed 1..11111:
     * - Node position_index = N (1-based)
     * - Level = floor(log10(9*N + 1))
     * - Parent position_index = floor((N - 2) / 10) + 1  (for N > 1)
     * - Children of parent P are position_index: 10 * P - 8 to 10 * P + 1.
     *
     * This guarantees absolute left-to-right, level-by-level placement across the entire company.
     *
     * @param int $userId
     * @return array The created matrix_node record
     */
    public function placeUserInMatrix(int $userId): array
    {
        // 1. Check if user is already placed in matrix
        $stmt = $this->pdo->prepare("SELECT * FROM matrix_nodes WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $userId]);
        $existingNode = $stmt->fetch();
        if ($existingNode) {
            return $existingNode;
        }

        // 2. Lock the matrix table/nodes to prevent race conditions during BFS calculation
        $driverName = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driverName === 'mysql') {
            // For MySQL, acquire an exclusive advisory lock or SELECT FOR UPDATE
            $this->pdo->query("SELECT GET_LOCK('matrix_placement_lock', 10)");
        } elseif ($driverName === 'sqlite') {
            // SQLite automatically handles file locks in EXCLUSIVE transaction mode
            // We ensure transaction is active or started in caller
        }

        try {
            // 3. Determine total existing nodes count
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM matrix_nodes");
            $totalCount = (int) $stmt->fetchColumn();

            if ($totalCount >= self::MAX_TOTAL_NODES) {
                throw new RuntimeException("Matrix maximum capacity reached (11,111 nodes across 5 levels).");
            }

            // Next node position index N
            $newPositionIndex = $totalCount + 1;

            if ($newPositionIndex === 1) {
                // Root node placement
                $stmt = $this->pdo->prepare("
                    INSERT INTO matrix_nodes (user_id, parent_id, level, position_index, children_count, created_at)
                    VALUES (:user_id, NULL, 1, 1, 0, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([':user_id' => $userId]);
                $nodeId = (int) $this->pdo->lastInsertId();

                $this->logger->log('MATRIX_NODE_PLACED', $userId, [
                    'position_index' => 1,
                    'level' => 1,
                    'parent_id' => null
                ]);

                return $this->getNodeById($nodeId);
            }

            // Compute level for position_index (1-based level structure)
            // N=1 => Level 1, N=2..11 => Level 2, N=12..111 => Level 3, etc.
            $level = (int) ceil(log10(9 * $newPositionIndex + 1));
            if ($level > self::MAX_LEVELS) {
                throw new RuntimeException("Matrix level limit exceeded. Maximum supported level is 5.");
            }

            // Compute Parent Position Index
            // In a 10-ary tree starting at index 1: parent_pos = floor((N - 2) / 10) + 1
            $parentPosIndex = (int) (floor(($newPositionIndex - 2) / self::MAX_CHILDREN_PER_PARENT) + 1);

            // Fetch Parent Node
            $stmt = $this->pdo->prepare("SELECT * FROM matrix_nodes WHERE position_index = :pos_index");
            $stmt->execute([':pos_index' => $parentPosIndex]);
            $parentNode = $stmt->fetch();

            if (!$parentNode) {
                throw new RuntimeException("Parent node at position index {$parentPosIndex} not found.");
            }

            if ($parentNode['children_count'] >= self::MAX_CHILDREN_PER_PARENT) {
                throw new RuntimeException("Parent node position {$parentPosIndex} already has maximum children.");
            }

            $parentId = (int) $parentNode['id'];

            // Insert New Node
            $stmt = $this->pdo->prepare("
                INSERT INTO matrix_nodes (user_id, parent_id, level, position_index, children_count, created_at)
                VALUES (:user_id, :parent_id, :level, :position_index, 0, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':parent_id' => $parentId,
                ':level' => $level,
                ':position_index' => $newPositionIndex,
            ]);
            $nodeId = (int) $this->pdo->lastInsertId();

            // Increment parent's children_count
            $stmtUpdateParent = $this->pdo->prepare("
                UPDATE matrix_nodes SET children_count = children_count + 1 WHERE id = :id
            ");
            $stmtUpdateParent->execute([':id' => $parentId]);

            $this->logger->log('MATRIX_NODE_PLACED', $userId, [
                'position_index' => $newPositionIndex,
                'level' => $level,
                'parent_id' => $parentId,
                'parent_position_index' => $parentPosIndex
            ]);

            return $this->getNodeById($nodeId);
        } finally {
            if ($driverName === 'mysql') {
                $this->pdo->query("SELECT RELEASE_LOCK('matrix_placement_lock')");
            }
        }
    }

    /**
     * Get 5-level uplines in the matrix tree starting from a user's node up to 5 levels above.
     * Returns array of parent nodes ordered from immediate parent (Level 1 above) up to 5 levels above.
     */
    public function getMatrixUpline(int $userId, int $maxDepth = 5): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM matrix_nodes WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $userId]);
        $currentNode = $stmt->fetch();

        if (!$currentNode) {
            return [];
        }

        $uplines = [];
        $currentParentId = $currentNode['parent_id'];
        $depth = 0;

        while ($currentParentId !== null && $depth < $maxDepth) {
            $stmtParent = $this->pdo->prepare("
                SELECT m.*, u.member_id, u.name, u.email, u.is_active
                FROM matrix_nodes m
                JOIN users u ON m.user_id = u.id
                WHERE m.id = :id
            ");
            $stmtParent->execute([':id' => $currentParentId]);
            $parent = $stmtParent->fetch();

            if (!$parent) {
                break;
            }

            $uplines[] = $parent;
            $currentParentId = $parent['parent_id'];
            $depth++;
        }

        return $uplines;
    }

    public function getNodeById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT m.*, u.member_id, u.name, u.email
            FROM matrix_nodes m
            JOIN users u ON m.user_id = u.id
            WHERE m.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $node = $stmt->fetch();
        return $node ?: null;
    }

    public function getNodeByUserId(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT m.*, u.member_id, u.name, u.email
            FROM matrix_nodes m
            JOIN users u ON m.user_id = u.id
            WHERE m.user_id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $node = $stmt->fetch();
        return $node ?: null;
    }

    /**
     * Fetch children of a given node
     */
    public function getDirectChildren(int $nodeId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT m.*, u.member_id, u.name, u.email
            FROM matrix_nodes m
            JOIN users u ON m.user_id = u.id
            WHERE m.parent_id = :parent_id
            ORDER BY m.position_index ASC
        ");
        $stmt->execute([':parent_id' => $nodeId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Fetch entire sub-tree or total company tree summary for visualization
     */
    public function getTreeStats(): array
    {
        $stmt = $this->pdo->query("
            SELECT level, COUNT(*) as total_nodes
            FROM matrix_nodes
            GROUP BY level
            ORDER BY level ASC
        ");
        return $stmt->fetchAll() ?: [];
    }
}
