<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\ApiController;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

$data = [];
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $json = json_decode($rawInput, true);
        if (is_array($json)) {
            $data = $json;
        }
    }
    $data = array_merge($data, $_POST);
} else {
    $data = $_GET;
}

$controller = new ApiController();
$response = $controller->handleRequest($uri, $method, $data);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
