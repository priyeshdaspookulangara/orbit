<?php

namespace App\Controllers;

use App\Database\Database;
use App\Services\UserService;
use App\Services\MatrixService;
use App\Services\CommissionService;
use App\Services\OrderFulfillmentService;
use Exception;
use PDO;

class ApiController
{
    private PDO $pdo;
    private UserService $userService;
    private MatrixService $matrixService;
    private CommissionService $commissionService;
    private OrderFulfillmentService $fulfillmentService;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        Database::runMigrations($this->pdo);

        $this->userService = new UserService($this->pdo);
        $this->userService->ensureRootUserExists();

        $this->matrixService = new MatrixService($this->pdo);
        $this->commissionService = new CommissionService($this->pdo, $this->matrixService);
        $this->fulfillmentService = new OrderFulfillmentService(
            $this->pdo,
            $this->userService,
            $this->matrixService,
            $this->commissionService
        );
    }

    public function handleRequest(string $uri, string $method, array $data = []): array
    {
        $uri = parse_url($uri, PHP_URL_PATH);
        $uri = rtrim($uri, '/');

        try {
            switch ($uri) {
                case '/api/register':
                    if ($method !== 'POST') {
                        return $this->response(false, "Method Not Allowed", null, 405);
                    }
                    return $this->register($data);

                case '/api/login':
                    if ($method !== 'POST') {
                        return $this->response(false, "Method Not Allowed", null, 405);
                    }
                    return $this->login($data);

                case '/api/checkout':
                    if ($method !== 'POST') {
                        return $this->response(false, "Method Not Allowed", null, 405);
                    }
                    return $this->checkout($data);

                case '/api/user/dashboard':
                    if ($method !== 'GET') {
                        return $this->response(false, "Method Not Allowed", null, 405);
                    }
                    return $this->dashboard($data['user_id'] ?? null, $data['member_id'] ?? null);

                case '/api/matrix/tree':
                    if ($method !== 'GET') {
                        return $this->response(false, "Method Not Allowed", null, 405);
                    }
                    return $this->matrixTree($data['user_id'] ?? null, $data['member_id'] ?? null);

                case '/api/admin/stats':
                    if ($method !== 'GET') {
                        return $this->response(false, "Method Not Allowed", null, 405);
                    }
                    return $this->adminStats();

                default:
                    return $this->response(false, "Endpoint not found", null, 404);
            }
        } catch (Exception $e) {
            return $this->response(false, $e->getMessage(), null, 400);
        }
    }

    private function register(array $data): array
    {
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = trim($data['password'] ?? '');
        $referrerCode = trim($data['ref'] ?? $data['referrer_code'] ?? '');

        if (empty($name) || empty($email) || empty($password)) {
            return $this->response(false, "Name, email, and password are required.", null, 422);
        }

        $user = $this->userService->registerUser($name, $email, $password, $referrerCode ?: null);

        return $this->response(true, "Registration successful.", [
            'user' => $user,
            'referral_link' => "/register?ref=" . $user['member_id']
        ]);
    }

    private function login(array $data): array
    {
        $email = trim($data['email'] ?? '');
        $password = trim($data['password'] ?? '');

        if (empty($email) || empty($password)) {
            return $this->response(false, "Email and password are required.", null, 422);
        }

        $user = $this->userService->authenticate($email, $password);
        if (!$user) {
            return $this->response(false, "Invalid email or password.", null, 401);
        }

        $wallet = $this->commissionService->getWallet($user['id']);
        $matrixNode = $this->matrixService->getNodeByUserId($user['id']);

        return $this->response(true, "Login successful.", [
            'user' => $user,
            'wallet' => $wallet,
            'matrix_node' => $matrixNode,
            'referral_link' => "/register?ref=" . $user['member_id']
        ]);
    }

    private function checkout(array $data): array
    {
        $userId = (int) ($data['user_id'] ?? 0);
        $flavor = trim($data['flavor'] ?? 'Double Rich Chocolate');
        $address = trim($data['shipping_address'] ?? '');

        if ($userId <= 0 || empty($address)) {
            return $this->response(false, "User ID and shipping address are required.", null, 422);
        }

        $result = $this->fulfillmentService->processPackageOrder($userId, $flavor, $address);

        return $this->response(true, "Order processed successfully and user placed in 10-matrix.", $result);
    }

    private function dashboard(?int $userId, ?string $memberId): array
    {
        $user = null;
        if ($userId) {
            $user = $this->userService->getUserById($userId);
        } elseif ($memberId) {
            $user = $this->userService->getUserByMemberId($memberId);
        }

        if (!$user) {
            return $this->response(false, "User not found.", null, 404);
        }

        $wallet = $this->commissionService->getWallet($user['id']);
        $ledgers = $this->commissionService->getLedgerHistory($user['id']);
        $matrixNode = $this->matrixService->getNodeByUserId($user['id']);
        $uplines = $matrixNode ? $this->matrixService->getMatrixUpline($user['id'], 5) : [];

        // Referrals list
        $stmtRef = $this->pdo->prepare("SELECT id, member_id, name, email, is_active, created_at FROM users WHERE referrer_id = :id");
        $stmtRef->execute([':id' => $user['id']]);
        $referrals = $stmtRef->fetchAll() ?: [];

        return $this->response(true, "Dashboard retrieved.", [
            'user' => $user,
            'wallet' => $wallet,
            'matrix_node' => $matrixNode,
            'uplines' => $uplines,
            'referrals_count' => count($referrals),
            'referrals' => $referrals,
            'ledgers' => $ledgers,
            'referral_link' => "/register?ref=" . $user['member_id']
        ]);
    }

    private function matrixTree(?int $userId, ?string $memberId): array
    {
        $user = null;
        if ($userId) {
            $user = $this->userService->getUserById($userId);
        } elseif ($memberId) {
            $user = $this->userService->getUserByMemberId($memberId);
        } else {
            // Default to Root
            $user = $this->userService->getUserByMemberId('ORB00001');
        }

        if (!$user) {
            return $this->response(false, "User not found.", null, 404);
        }

        $node = $this->matrixService->getNodeByUserId($user['id']);
        if (!$node) {
            return $this->response(false, "User is not yet activated in the matrix.", null, 400);
        }

        $children = $this->matrixService->getDirectChildren($node['id']);

        return $this->response(true, "Matrix tree retrieved.", [
            'node' => $node,
            'children' => $children,
            'children_count' => count($children),
            'max_children' => MatrixService::MAX_CHILDREN_PER_PARENT
        ]);
    }

    private function adminStats(): array
    {
        $stmtUsers = $this->pdo->query("SELECT COUNT(*) as total_users, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users FROM users");
        $userStats = $stmtUsers->fetch();

        $treeStats = $this->matrixService->getTreeStats();

        $stmtOrders = $this->pdo->query("SELECT COUNT(*) as total_orders, SUM(amount) as total_revenue FROM orders");
        $orderStats = $stmtOrders->fetch();

        $stmtCommissions = $this->pdo->query("SELECT type, SUM(amount) as total_amount FROM wallet_ledgers GROUP BY type");
        $commissionStats = $stmtCommissions->fetchAll() ?: [];

        return $this->response(true, "Admin statistics retrieved.", [
            'users' => $userStats,
            'matrix_by_level' => $treeStats,
            'orders' => $orderStats,
            'commissions' => $commissionStats,
        ]);
    }

    private function response(bool $success, string $message, mixed $data = null, int $statusCode = 200): array
    {
        http_response_code($statusCode);
        return [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'status_code' => $statusCode
        ];
    }
}
