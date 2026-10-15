<?php

namespace App\Services;

use PDO;
use RuntimeException;

class CommissionService
{
    public const DIRECT_REFERRAL_COMMISSION = 1000.00; // ₹1,000
    public const MATRIX_LEVEL_COMMISSION = 200.00;     // ₹200 per head up to 5 levels

    private PDO $pdo;
    private MatrixService $matrixService;
    private AuditLogger $logger;

    public function __construct(PDO $pdo, ?MatrixService $matrixService = null, ?AuditLogger $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger ?? new AuditLogger($pdo);
        $this->matrixService = $matrixService ?? new MatrixService($pdo, $this->logger);
    }

    /**
     * Process all commissions triggered by a new active order/package purchase of ₹6,000.
     *
     * 1. Direct Referral Commission: ₹1,000 to user's referrer (sponsor).
     * 2. Matrix Level Commission: ₹200 per head distributed to 5 levels of matrix upline.
     *
     * @param int $purchasingUserId The ID of the member who bought the distributor package.
     * @param string $orderReference Order ID or Order Number reference.
     * @return array Summary of processed commissions.
     */
    public function processCommissions(int $purchasingUserId, string $orderReference): array
    {
        // Fetch purchasing user details
        $stmtUser = $this->pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmtUser->execute([':id' => $purchasingUserId]);
        $buyer = $stmtUser->fetch();

        if (!$buyer) {
            throw new RuntimeException("Purchasing user with ID {$purchasingUserId} not found.");
        }

        $summary = [
            'direct_commission' => null,
            'matrix_commissions' => []
        ];

        // --- 1. Direct Referral Commission (₹1,000) ---
        if ($buyer['referrer_id']) {
            $referrerId = (int) $buyer['referrer_id'];

            $summary['direct_commission'] = $this->creditWallet(
                $referrerId,
                'DIRECT_REFERRAL_COMMISSION',
                self::DIRECT_REFERRAL_COMMISSION,
                "Direct Referral Bonus from member {$buyer['member_id']} (Order: {$orderReference})",
                $orderReference
            );

            $this->logger->log('DIRECT_COMMISSION_CREDITED', $referrerId, [
                'amount' => self::DIRECT_REFERRAL_COMMISSION,
                'from_user' => $purchasingUserId,
                'from_member_id' => $buyer['member_id'],
                'order_reference' => $orderReference
            ]);
        }

        // --- 2. Matrix Level Commissions (₹200 per level up to 5 levels) ---
        $uplines = $this->matrixService->getMatrixUpline($purchasingUserId, 5);

        $levelNumber = 1;
        foreach ($uplines as $uplineNode) {
            $uplineUserId = (int) $uplineNode['user_id'];

            $matrixEntry = $this->creditWallet(
                $uplineUserId,
                'MATRIX_LEVEL_COMMISSION',
                self::MATRIX_LEVEL_COMMISSION,
                "Matrix Level {$levelNumber} Bonus from member {$buyer['member_id']} (Order: {$orderReference})",
                $orderReference
            );

            $summary['matrix_commissions'][] = [
                'level' => $levelNumber,
                'user_id' => $uplineUserId,
                'member_id' => $uplineNode['member_id'],
                'wallet_ledger' => $matrixEntry
            ];

            $this->logger->log('MATRIX_LEVEL_COMMISSION_CREDITED', $uplineUserId, [
                'level' => $levelNumber,
                'amount' => self::MATRIX_LEVEL_COMMISSION,
                'from_user' => $purchasingUserId,
                'from_member_id' => $buyer['member_id'],
                'order_reference' => $orderReference
            ]);

            $levelNumber++;
        }

        return $summary;
    }

    /**
     * Credits a wallet with the specified amount and creates an immutable ledger entry.
     */
    public function creditWallet(
        int $userId,
        string $type,
        float $amount,
        string $description,
        ?string $referenceId = null
    ): array {
        // Fetch or initialize wallet
        $driverName = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $forUpdate = ($driverName === 'mysql') ? ' FOR UPDATE' : '';
        $stmt = $this->pdo->prepare("SELECT * FROM wallets WHERE user_id = :user_id{$forUpdate}");
        $stmt->execute([':user_id' => $userId]);
        $wallet = $stmt->fetch();

        if (!$wallet) {
            $this->pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (:user_id, 0.00)")
                ->execute([':user_id' => $userId]);
            $currentBalance = 0.00;
        } else {
            $currentBalance = (float) $wallet['balance'];
        }

        $newBalance = $currentBalance + $amount;

        // Update Wallet Balance
        $stmtUpdate = $this->pdo->prepare("
            UPDATE wallets SET balance = :balance, updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id
        ");
        $stmtUpdate->execute([
            ':balance' => $newBalance,
            ':user_id' => $userId,
        ]);

        // Create Wallet Ledger Entry
        $stmtLedger = $this->pdo->prepare("
            INSERT INTO wallet_ledgers (user_id, type, amount, balance_after, description, reference_id, created_at)
            VALUES (:user_id, :type, :amount, :balance_after, :description, :reference_id, CURRENT_TIMESTAMP)
        ");
        $stmtLedger->execute([
            ':user_id' => $userId,
            ':type' => $type,
            ':amount' => $amount,
            ':balance_after' => $newBalance,
            ':description' => $description,
            ':reference_id' => $referenceId,
        ]);

        $ledgerId = (int) $this->pdo->lastInsertId();

        return [
            'id' => $ledgerId,
            'user_id' => $userId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $newBalance,
            'description' => $description,
            'reference_id' => $referenceId
        ];
    }

    /**
     * Get wallet details and balance for a user
     */
    public function getWallet(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM wallets WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $userId]);
        $wallet = $stmt->fetch();

        if (!$wallet) {
            return [
                'user_id' => $userId,
                'balance' => 0.00,
                'updated_at' => null
            ];
        }

        return $wallet;
    }

    /**
     * Get immutable ledger transactions history for a user
     */
    public function getLedgerHistory(int $userId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM wallet_ledgers
            WHERE user_id = :user_id
            ORDER BY id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}
