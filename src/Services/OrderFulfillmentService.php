<?php

namespace App\Services;

use PDO;
use Exception;
use RuntimeException;

class OrderFulfillmentService
{
    public const PACKAGE_PRICE = 6000.00;

    private PDO $pdo;
    private UserService $userService;
    private MatrixService $matrixService;
    private CommissionService $commissionService;
    private AuditLogger $logger;

    public function __construct(
        PDO $pdo,
        ?UserService $userService = null,
        ?MatrixService $matrixService = null,
        ?CommissionService $commissionService = null,
        ?AuditLogger $logger = null
    ) {
        $this->pdo = $pdo;
        $this->logger = $logger ?? new AuditLogger($pdo);
        $this->userService = $userService ?? new UserService($pdo, $this->logger);
        $this->matrixService = $matrixService ?? new MatrixService($pdo, $this->logger);
        $this->commissionService = $commissionService ?? new CommissionService($pdo, $this->matrixService, $this->logger);
    }

    /**
     * Seeds or fetches default protein powder product
     */
    public function ensureProductExists(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM products WHERE sku = 'PROTEIN-DIST-KIT'");
        $product = $stmt->fetch();

        if ($product) {
            return $product;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO products (sku, name, price, stock_quantity)
            VALUES ('PROTEIN-DIST-KIT', 'Protein Powder Distributor Package (2kg Tub)', :price, 10000)
        ");
        $stmt->execute([':price' => self::PACKAGE_PRICE]);
        $productId = (int) $this->pdo->lastInsertId();

        return [
            'id' => $productId,
            'sku' => 'PROTEIN-DIST-KIT',
            'name' => 'Protein Powder Distributor Package (2kg Tub)',
            'price' => self::PACKAGE_PRICE,
            'stock_quantity' => 10000
        ];
    }

    /**
     * Processes ₹6,000 package checkout order inside an atomic database transaction.
     *
     * 1. Deduct Product Stock Inventory.
     * 2. Activate User (`is_active = 1`).
     * 3. Place User into the 10-Matrix Tree using company-wide BFS Spillover logic.
     * 4. Trigger direct referral commission (₹1,000) and matrix level commissions (₹200 x 5 levels).
     * 5. Generate Warehouse Fulfillment Dispatch Order with flavor, shipping address, courier tracking.
     * 6. Record immutable Audit Logs.
     *
     * @param int $userId Member placing order.
     * @param string $flavor Selected protein powder flavor (e.g., Chocolate, Vanilla, Strawberry).
     * @param string $shippingAddress Full delivery address.
     * @return array Order summary results.
     */
    public function processPackageOrder(int $userId, string $flavor, string $shippingAddress): array
    {
        $product = $this->ensureProductExists();

        // Check user
        $user = $this->userService->getUserById($userId);
        if (!$user) {
            throw new RuntimeException("User with ID {$userId} not found.");
        }

        // Begin strict ACID Transaction
        $this->pdo->beginTransaction();

        try {
            // 1. Deduct inventory stock
            $driverName = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $forUpdate = ($driverName === 'mysql') ? ' FOR UPDATE' : '';
            $stmtStock = $this->pdo->prepare("SELECT stock_quantity FROM products WHERE id = :id{$forUpdate}");
            $stmtStock->execute([':id' => $product['id']]);
            $currentStock = (int) $stmtStock->fetchColumn();

            if ($currentStock < 1) {
                throw new RuntimeException("Product is currently out of stock.");
            }

            $stmtUpdateStock = $this->pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - 1 WHERE id = :id");
            $stmtUpdateStock->execute([':id' => $product['id']]);

            // 2. Activate User if inactive
            if (!$user['is_active']) {
                $stmtActivate = $this->pdo->prepare("UPDATE users SET is_active = 1 WHERE id = :id");
                $stmtActivate->execute([':id' => $userId]);
            }

            // 3. Create Order Record
            $orderNumber = "ORD-" . strtoupper(uniqid());
            $stmtOrder = $this->pdo->prepare("
                INSERT INTO orders (order_number, user_id, product_id, amount, flavor, status, created_at)
                VALUES (:order_number, :user_id, :product_id, :amount, :flavor, 'COMPLETED', CURRENT_TIMESTAMP)
            ");
            $stmtOrder->execute([
                ':order_number' => $orderNumber,
                ':user_id' => $userId,
                ':product_id' => $product['id'],
                ':amount' => self::PACKAGE_PRICE,
                ':flavor' => $flavor,
            ]);
            $orderId = (int) $this->pdo->lastInsertId();

            // 4. Place User in Matrix (Company-Wide BFS Spillover)
            $matrixNode = $this->matrixService->placeUserInMatrix($userId);

            // 5. Distribute Direct Referral & Matrix Level Commissions
            $commissionSummary = $this->commissionService->processCommissions($userId, $orderNumber);

            // 6. Generate Warehouse Fulfillment Order
            $trackingNumber = "TRK-" . strtoupper(bin2hex(random_bytes(4)));
            $courierName = "Express Cargo Logistics";

            $stmtFulfillment = $this->pdo->prepare("
                INSERT INTO fulfillment_orders (order_id, user_id, shipping_address, flavor, status, courier_name, tracking_number, created_at, updated_at)
                VALUES (:order_id, :user_id, :shipping_address, :flavor, 'DISPATCH_PENDING', :courier_name, :tracking_number, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmtFulfillment->execute([
                ':order_id' => $orderId,
                ':user_id' => $userId,
                ':shipping_address' => $shippingAddress,
                ':flavor' => $flavor,
                ':courier_name' => $courierName,
                ':tracking_number' => $trackingNumber,
            ]);
            $fulfillmentId = (int) $this->pdo->lastInsertId();

            // Audit log order completion
            $this->logger->log('ORDER_PROCESSED', $userId, [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'amount' => self::PACKAGE_PRICE,
                'flavor' => $flavor,
                'matrix_position' => $matrixNode['position_index'],
                'fulfillment_id' => $fulfillmentId,
            ]);

            // Commit Transaction
            $this->pdo->commit();

            return [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'amount' => self::PACKAGE_PRICE,
                'user' => $this->userService->getUserById($userId),
                'matrix_node' => $matrixNode,
                'fulfillment' => [
                    'id' => $fulfillmentId,
                    'shipping_address' => $shippingAddress,
                    'flavor' => $flavor,
                    'status' => 'DISPATCH_PENDING',
                    'courier_name' => $courierName,
                    'tracking_number' => $trackingNumber,
                ],
                'commissions' => $commissionSummary,
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->logger->log('ORDER_FAILED', $userId, [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get fulfillment status for an order
     */
    public function getFulfillmentByOrderId(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT f.*, o.order_number, u.member_id, u.name, u.email
            FROM fulfillment_orders f
            JOIN orders o ON f.order_id = o.id
            JOIN users u ON f.user_id = u.id
            WHERE f.order_id = :order_id
        ");
        $stmt->execute([':order_id' => $orderId]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    /**
     * Update Courier Dispatch status
     */
    public function updateFulfillmentStatus(int $fulfillmentId, string $status, ?string $trackingNumber = null): bool
    {
        $params = [
            ':id' => $fulfillmentId,
            ':status' => $status
        ];
        $sql = "UPDATE fulfillment_orders SET status = :status, updated_at = CURRENT_TIMESTAMP";

        if ($trackingNumber !== null) {
            $sql .= ", tracking_number = :tracking_number";
            $params[':tracking_number'] = $trackingNumber;
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($params);

        if ($result) {
            $this->logger->log('FULFILLMENT_STATUS_UPDATED', null, [
                'fulfillment_id' => $fulfillmentId,
                'status' => $status,
                'tracking_number' => $trackingNumber
            ]);
        }

        return $result;
    }
}
