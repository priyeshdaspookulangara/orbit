<?php

namespace App\Services;

use PDO;
use RuntimeException;

class UserService
{
    private PDO $pdo;
    private AuditLogger $logger;

    public function __construct(PDO $pdo, ?AuditLogger $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger ?? new AuditLogger($pdo);
    }

    /**
     * Initializes the root node user ORB00001 if it does not exist.
     */
    public function ensureRootUserExists(
        string $name = "Root Sponsor",
        string $email = "root@orbprotein.com",
        string $password = "RootSecurePassword123!"
    ): array {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE member_id = 'ORB00001'");
        $stmt->execute();
        $rootUser = $stmt->fetch();

        if ($rootUser) {
            return $rootUser;
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare("
            INSERT INTO users (member_id, name, email, password_hash, referrer_id, is_active, created_at)
            VALUES ('ORB00001', :name, :email, :password_hash, NULL, 1, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':password_hash' => $passwordHash,
        ]);

        $userId = (int) $this->pdo->lastInsertId();

        // Initialize Root Wallet
        $stmtWallet = $this->pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (:user_id, 0.00)");
        $stmtWallet->execute([':user_id' => $userId]);

        // Place Root Node in Matrix as Position 1, Level 1 (or Depth 1)
        $stmtMatrix = $this->pdo->prepare("
            INSERT INTO matrix_nodes (user_id, parent_id, level, position_index, children_count, created_at)
            VALUES (:user_id, NULL, 1, 1, 0, CURRENT_TIMESTAMP)
        ");
        $stmtMatrix->execute([':user_id' => $userId]);

        $this->logger->log('ROOT_USER_CREATED', $userId, [
            'member_id' => 'ORB00001',
            'email' => $email
        ]);

        return $this->getUserById($userId);
    }

    /**
     * Register a new user with an optional referrer code (e.g., ORB00001).
     */
    public function registerUser(string $name, string $email, string $password, ?string $referrerCode = null): array
    {
        // Check email uniqueness
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            throw new RuntimeException("Email address is already registered.");
        }

        $referrerId = null;
        if (!empty($referrerCode)) {
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE member_id = :member_id");
            $stmt->execute([':member_id' => trim($referrerCode)]);
            $referrer = $stmt->fetch();
            if (!$referrer) {
                throw new RuntimeException("Invalid referral code: {$referrerCode}");
            }
            $referrerId = (int) $referrer['id'];
        } else {
            // Default referrer to Root User if none specified
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE member_id = 'ORB00001'");
            $stmt->execute();
            $root = $stmt->fetch();
            if ($root) {
                $referrerId = (int) $root['id'];
            }
        }

        // Generate next member ID format ORBXXXXX
        $memberId = $this->generateNextMemberId();
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $this->pdo->prepare("
            INSERT INTO users (member_id, name, email, password_hash, referrer_id, is_active, created_at)
            VALUES (:member_id, :name, :email, :password_hash, :referrer_id, 0, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':member_id' => $memberId,
            ':name' => $name,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':referrer_id' => $referrerId,
        ]);

        $userId = (int) $this->pdo->lastInsertId();

        // Initialize wallet
        $stmtWallet = $this->pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (:user_id, 0.00)");
        $stmtWallet->execute([':user_id' => $userId]);

        $this->logger->log('USER_REGISTERED', $userId, [
            'member_id' => $memberId,
            'referrer_id' => $referrerId
        ]);

        return $this->getUserById($userId);
    }

    public function getUserById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT u.*, r.member_id as referrer_member_id, r.name as referrer_name
            FROM users u
            LEFT JOIN users r ON u.referrer_id = r.id
            WHERE u.id = :id
        ");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function getUserByMemberId(string $memberId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT u.*, r.member_id as referrer_member_id, r.name as referrer_name
            FROM users u
            LEFT JOIN users r ON u.referrer_id = r.id
            WHERE u.member_id = :member_id
        ");
        $stmt->execute([':member_id' => $memberId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function authenticate(string $email, string $password): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            unset($user['password_hash']);
            return $user;
        }

        return null;
    }

    /**
     * Reset user password by email
     */
    public function resetPassword(string $email, string $newPassword): array
    {
        $cleanEmail = trim($email);
        $cleanPassword = trim($newPassword);

        if (empty($cleanEmail) || empty($cleanPassword)) {
            throw new RuntimeException("Email and new password are required for password reset.");
        }

        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute([':email' => $cleanEmail]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException("No account found with email address: {$cleanEmail}");
        }

        $userId = (int) $user['id'];
        $passwordHash = password_hash($cleanPassword, PASSWORD_BCRYPT);

        $stmtUpdate = $this->pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $stmtUpdate->execute([':hash' => $passwordHash, ':id' => $userId]);

        $this->logger->log('PASSWORD_RESET', $userId, [
            'email' => $cleanEmail
        ]);

        return $this->getUserById($userId);
    }

    /**
     * Update user details (name, email, optional password)
     */
    public function updateUser(int $userId, array $data): array
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException("User with ID {$userId} not found.");
        }

        $newName = trim($data['name'] ?? $user['name']);
        $newEmail = trim($data['email'] ?? $user['email']);

        if (empty($newName) || empty($newEmail)) {
            throw new RuntimeException("Name and Email cannot be empty.");
        }

        // Check email uniqueness if email changed
        if ($newEmail !== $user['email']) {
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
            $stmt->execute([':email' => $newEmail, ':id' => $userId]);
            if ($stmt->fetch()) {
                throw new RuntimeException("Email address {$newEmail} is already in use by another account.");
            }
        }

        $params = [
            ':id' => $userId,
            ':name' => $newName,
            ':email' => $newEmail,
        ];

        $sql = "UPDATE users SET name = :name, email = :email";

        if (!empty($data['password'])) {
            $sql .= ", password_hash = :password_hash";
            $params[':password_hash'] = password_hash(trim($data['password']), PASSWORD_BCRYPT);
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $this->logger->log('USER_PROFILE_UPDATED', $userId, [
            'name' => $newName,
            'email' => $newEmail,
            'password_changed' => !empty($data['password'])
        ]);

        return $this->getUserById($userId);
    }

    private function generateNextMemberId(): string
    {
        // Find max ID
        $stmt = $this->pdo->query("SELECT MAX(id) as max_id FROM users");
        $row = $stmt->fetch();
        $nextId = ((int) ($row['max_id'] ?? 0)) + 1;
        return sprintf("ORB%05d", $nextId);
    }
}
