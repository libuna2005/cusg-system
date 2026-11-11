<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['cusg_admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

echo json_encode(['success' => true, 'admin' => $_SESSION['cusg_admin']]);
