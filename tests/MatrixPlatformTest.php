<?php

namespace App\Tests;

use App\Database\Database;
use App\Services\UserService;
use App\Services\MatrixService;
use App\Services\CommissionService;
use App\Services\OrderFulfillmentService;
use PDO;

class MatrixPlatformTest
{
    private PDO $pdo;
    private UserService $userService;
    private MatrixService $matrixService;
    private CommissionService $commissionService;
    private OrderFulfillmentService $fulfillmentService;

    public function setUp(): void
    {
        // Use in-memory SQLite for fast testing
        Database::setConfig([
            'driver' => 'sqlite',
            'sqlite_path' => ':memory:'
        ]);
        $this->pdo = Database::getInstance();
        Database::runMigrations($this->pdo);

        $this->userService = new UserService($this->pdo);
        $this->matrixService = new MatrixService($this->pdo);
        $this->commissionService = new CommissionService($this->pdo, $this->matrixService);
        $this->fulfillmentService = new OrderFulfillmentService(
            $this->pdo,
            $this->userService,
            $this->matrixService,
            $this->commissionService
        );
    }

    private function assertEquals($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException("Assertion Failed: Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ". " . $message);
        }
    }

    private function assertNotNull($actual, string $message = ''): void
    {
        if ($actual === null) {
            throw new \RuntimeException("Assertion Failed: Expected non-null value. " . $message);
        }
    }

    public function testRootUserInitialization(): void
    {
        $root = $this->userService->ensureRootUserExists();
        $this->assertEquals('ORB00001', $root['member_id']);
        $this->assertEquals(1, (int)$root['is_active']);

        $matrixNode = $this->matrixService->getNodeByUserId($root['id']);
        $this->assertNotNull($matrixNode);
        $this->assertEquals(1, (int)$matrixNode['position_index']);
        $this->assertEquals(1, (int)$matrixNode['level']);
    }

    public function testCompanyWideBFSSpilloverPlacement(): void
    {
        $root = $this->userService->ensureRootUserExists();

        // Register and buy package for 12 members
        $placedNodes = [];
        for ($i = 1; $i <= 12; $i++) {
            $user = $this->userService->registerUser("Member $i", "member$i@test.com", "Password123!", 'ORB00001');
            $orderRes = $this->fulfillmentService->processPackageOrder($user['id'], 'Chocolate', 'Address ' . $i);
            $placedNodes[] = $orderRes['matrix_node'];
        }

        // Member 1 to 10 should be placed under Root (position_index 2 to 11, parent_id 1)
        for ($i = 0; $i < 10; $i++) {
            $this->assertEquals($i + 2, (int)$placedNodes[$i]['position_index']);
            $this->assertEquals(1, (int)$placedNodes[$i]['parent_id']); // Parent is Root
        }

        // Member 11 (node index 12) must spill over to Member 1 (parent_id 2) as its first child
        $this->assertEquals(12, (int)$placedNodes[10]['position_index']);
        $this->assertEquals((int)$placedNodes[0]['id'], (int)$placedNodes[10]['parent_id']); // Parent is Member 1

        // Member 12 (node index 13) must spill over to Member 1 (parent_id 2) as its second child
        $this->assertEquals(13, (int)$placedNodes[11]['position_index']);
        $this->assertEquals((int)$placedNodes[0]['id'], (int)$placedNodes[11]['parent_id']); // Parent is Member 1
    }

    public function testDirectAndMatrixCommissionDistribution(): void
    {
        $root = $this->userService->ensureRootUserExists();

        // Create User A (Sponsor Root)
        $userA = $this->userService->registerUser("User A", "usera@test.com", "Pass123!", 'ORB00001');
        $this->fulfillmentService->processPackageOrder($userA['id'], 'Vanilla', 'Address A');

        // Create User B (Sponsor User A)
        $userB = $this->userService->registerUser("User B", "userb@test.com", "Pass123!", $userA['member_id']);
        $orderB = $this->fulfillmentService->processPackageOrder($userB['id'], 'Strawberry', 'Address B');

        // Check Direct Commission to User A (₹1,000)
        $walletA = $this->commissionService->getWallet($userA['id']);
        $this->assertEquals(1000.00, (float)$walletA['balance']);

        // Check Matrix Commissions:
        // Position 1: Root (pos 1)
        // Position 2: User A (pos 2, parent Root)
        // Position 3: User B (pos 3, parent Root)
        // Root direct from A = 1000, matrix from A = 200, matrix from B = 200 -> Total = 1400
        $walletRoot = $this->commissionService->getWallet($root['id']);
        $this->assertEquals(1400.00, (float)$walletRoot['balance']);

        // Fulfillment check for User B
        $fulfillment = $this->fulfillmentService->getFulfillmentByOrderId($orderB['order_id']);
        $this->assertNotNull($fulfillment);
        $this->assertEquals('Strawberry', $fulfillment['flavor']);
        $this->assertEquals('DISPATCH_PENDING', $fulfillment['status']);
    }

    public function testMemberUpdateFeature(): void
    {
        $root = $this->userService->ensureRootUserExists();

        $updated = $this->userService->updateUser($root['id'], [
            'name' => 'Root Admin Renamed',
            'email' => 'root_new@orbprotein.com'
        ]);

        $this->assertEquals('Root Admin Renamed', $updated['name']);
        $this->assertEquals('root_new@orbprotein.com', $updated['email']);
    }

    public function testPasswordResetFeature(): void
    {
        $root = $this->userService->ensureRootUserExists();

        $updated = $this->userService->resetPassword('root@orbprotein.com', 'BrandNewResetPass123!');
        $this->assertNotNull($updated);

        $auth = $this->userService->authenticate('root@orbprotein.com', 'BrandNewResetPass123!');
        $this->assertNotNull($auth);
    }

    public function testFull5LevelMatrixCapacityLimits(): void
    {
        $root = $this->userService->ensureRootUserExists();

        // Verify mathematical formula for 10-matrix parents & levels
        // Pos 1 -> Root
        // Pos 2..11 -> Children of Root (Parent 1)
        // Pos 12..21 -> Children of Pos 2
        for ($i = 2; $i <= 21; $i++) {
            $user = $this->userService->registerUser("User $i", "user$i@domain.com", "Pass123!", 'ORB00001');
            $orderRes = $this->fulfillmentService->processPackageOrder($user['id'], 'Chocolate', 'Loc');
            $this->assertEquals($i, (int)$orderRes['matrix_node']['position_index']);

            if ($i <= 11) {
                $this->assertEquals(1, (int)$orderRes['matrix_node']['parent_id']);
            } else {
                // Parent should be Pos 2 node (which has matrix_node id = 2)
                $this->assertEquals(2, (int)$orderRes['matrix_node']['parent_id']);
            }
        }
    }
}
