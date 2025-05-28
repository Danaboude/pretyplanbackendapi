<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include 'db.php';

// === Helpers ===
function getJsonInput() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        exit;
    }
    return $data;
}

// === User Functions ===
function createUser($data) {
    global $mysqli;
    if (!isset($data['full_name'], $data['email'], $data['password'])) {
        http_response_code(400);
        return ['error' => 'Missing required fields'];
    }

    $result = $mysqli->query("SELECT COUNT(*) as total FROM users");
    $row = $result->fetch_assoc();
    $isFirstUser = $row['total'] == 0;
    $role = $isFirstUser ? 'manager' : 'employee';

    $stmt = $mysqli->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)");
    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
    $stmt->bind_param("ssss", $data['full_name'], $data['email'], $hashedPassword, $role);

    if ($stmt->execute()) {
        return ['id' => $stmt->insert_id, 'role' => $role];
    } else {
        http_response_code(500);
        return ['error' => 'Email already exists or failed to register'];
    }
}

function loginUser($data) {
    global $mysqli;
    if (!isset($data['email'], $data['password'])) {
        http_response_code(400);
        return ['error' => 'Missing email or password'];
    }

    $stmt = $mysqli->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $data['email']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($data['password'], $user['password'])) {
        return [
            'id' => $user['id'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
            'email' => $user['email'],
            'account_balance' => $user['account_balance']
        ];
    } else {
        http_response_code(401);
        return ['error' => 'Invalid credentials'];
    }
}

function deleteUser($id) {
    global $mysqli;
    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return ['success' => $stmt->affected_rows > 0];
}

function getUserDetailsById($userId) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT id, full_name, email, role, account_balance FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}
function createTask($data) {
    global $mysqli;

    if (!isset($data['title'], $data['deadline_date'], $data['deadline_time'], $data['priority'], $data['status'], $data['cost'], $data['assigned_to'], $data['category'])) {
        http_response_code(400);
        return ['error' => 'Missing task fields'];
    }

    $note = $data['note'] ?? '';

    $stmt = $mysqli->prepare("INSERT INTO tasks (title, category, deadline_date, deadline_time, note, priority, status, cost, assigned_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param(
        "ssssssdis",
        $data['title'],
        $data['category'],
        $data['deadline_date'],
        $data['deadline_time'],
        $note,
        $data['priority'],
        $data['status'],
        $data['cost'],
        $data['assigned_to']
    );
    
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        return ['id' => $stmt->insert_id];
    } else {
        http_response_code(500);
        return ['error' => 'Failed to insert task'];
    }
}

function updateTaskStatus($data) {
    global $mysqli;

    $taskId = $data['task_id'] ?? null;
    $newStatus = $data['status'] ?? null;

    if (!$taskId || !$newStatus) {
        http_response_code(400);
        return ['error' => 'Missing task_id or status'];
    }

    $stmt = $mysqli->prepare("SELECT cost, assigned_to, status FROM tasks WHERE id = ?");
    $stmt->bind_param("i", $taskId);
    $stmt->execute();
    $result = $stmt->get_result();
    $task = $result->fetch_assoc();

    if (!$task) {
        http_response_code(404);
        return ['error' => 'Task not found'];
    }

    if ($task['status'] === 'completed' && $newStatus === 'completed') {
        return ['message' => 'Task already completed'];
    }

    $stmt = $mysqli->prepare("UPDATE tasks SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $newStatus, $taskId);
    $stmt->execute();

    if ($stmt->affected_rows > 0 && $newStatus === 'completed') {
        $stmt = $mysqli->prepare("UPDATE users SET account_balance = account_balance + ? WHERE id = ?");
        $stmt->bind_param("di", $task['cost'], $task['assigned_to']);
        $stmt->execute();
    }

    return ['success' => true];
}

function getTasksByUserId($userId) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT * FROM tasks WHERE assigned_to = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $tasks = [];
    while ($row = $result->fetch_assoc()) {
        $tasks[] = $row;
    }
    return $tasks;
}

// === Withdrawal / Checkout ===
function createCheckoutRequest($data) {
    global $mysqli;

    if (!isset($data['user_id'], $data['amount'])) {
        http_response_code(400);
        return ['error' => 'Missing user_id or amount'];
    }

    $userId = (int) $data['user_id'];
    $amount = (float) $data['amount'];
    $status = 'pending';

    $stmt = $mysqli->prepare("SELECT account_balance FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        http_response_code(404);
        return ['error' => 'User not found'];
    }

    if ($user['account_balance'] < $amount) {
        http_response_code(400);
        return ['error' => 'Insufficient balance'];
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("INSERT INTO checkout_requests (user_id, amount, status) VALUES (?, ?, ?)");
        $stmt->bind_param("ids", $userId, $amount, $status);
        if (!$stmt->execute()) {
            throw new Exception('Failed to insert withdrawal request');
        }

        $stmt = $mysqli->prepare("UPDATE users SET account_balance = account_balance - ? WHERE id = ?");
        $stmt->bind_param("di", $amount, $userId);
        if (!$stmt->execute()) {
            throw new Exception('Failed to deduct balance');
        }

        $mysqli->commit();
        return ['id' => $stmt->insert_id, 'success' => true];
    } catch (Exception $e) {
        $mysqli->rollback();
        http_response_code(500);
        return ['error' => $e->getMessage()];
    }
}

function getWithdrawalsByUserId($userId) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT * FROM checkout_requests WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $withdrawals = [];
    while ($row = $result->fetch_assoc()) {
        $withdrawals[] = $row;
    }
    return $withdrawals;
}

// === ROUTING ===
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
$requestUri = str_replace($scriptName, '', $_SERVER['REQUEST_URI']);
$requestUri = explode('/', trim($requestUri, '/'));
$requestMethod = $_SERVER['REQUEST_METHOD'];

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Routes
if ($requestUri[0] === 'users') {
    switch ($requestMethod) {
        case 'POST':
            $data = getJsonInput();
            echo json_encode(createUser($data));
            break;
        case 'DELETE':
            $id = isset($requestUri[1]) ? (int)$requestUri[1] : 0;
            echo json_encode(deleteUser($id));
            break;
        case 'GET':
            $result = $mysqli->query("SELECT id, full_name, email, role FROM users");
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            echo json_encode($users);
            break;
    }
} elseif ($requestUri[0] === 'login' && $requestMethod === 'POST') {
    $data = getJsonInput();
    echo json_encode(loginUser($data));
} elseif ($requestUri[0] === 'tasks' && $requestMethod === 'POST') {
    $data = getJsonInput();
    echo json_encode(createTask($data));
} elseif ($requestUri[0] === 'task' && $requestUri[1] === 'status' && $requestMethod === 'POST') {
    $data = getJsonInput();
    echo json_encode(updateTaskStatus($data));
} elseif ($requestUri[0] === 'checkout_requests' && $requestMethod === 'POST') {
    $data = getJsonInput();
    echo json_encode(createCheckoutRequest($data));
} elseif ($requestUri[0] === 'user' && isset($requestUri[1], $requestUri[2])) {
    $userId = (int)$requestUri[1];
    if ($requestUri[2] === 'tasks' && $requestMethod === 'GET') {
        echo json_encode(getTasksByUserId($userId));
    } elseif ($requestUri[2] === 'withdrawals' && $requestMethod === 'GET') {
        echo json_encode(getWithdrawalsByUserId($userId));
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Invalid sub-endpoint']);
    }
} elseif ($requestUri[0] === 'user' && isset($requestUri[1]) && $requestMethod === 'GET') {
    $userId = (int)$requestUri[1];
    echo json_encode(getUserDetailsById($userId));
} elseif ($requestUri[0] === 'checkout_request' && isset($requestUri[1], $requestUri[2]) && $requestUri[2] === 'approve' && $requestMethod === 'POST') {
    $id = (int)$requestUri[1];
    $data = getJsonInput();
    $transactionNumber = $data['transaction_number'] ?? null;

    if (!$transactionNumber) {
        http_response_code(400);
        echo json_encode(['error' => 'Transaction number required']);
        exit;
    }

    // Fetch user_id and amount from checkout_requests
    $stmt = $mysqli->prepare("SELECT user_id, amount, status FROM checkout_requests WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $checkout = $result->fetch_assoc();

    if (!$checkout) {
        http_response_code(404);
        echo json_encode(['error' => 'Checkout request not found']);
        exit;
    }

    if ($checkout['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['error' => 'Checkout already processed']);
        exit;
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("UPDATE checkout_requests SET status = 'approved', transfer_number = ? WHERE id = ?");
        $stmt->bind_param("si", $transactionNumber, $id);
        $stmt->execute();

        $mysqli->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $mysqli->rollback();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} elseif ($requestUri[0] === 'checkout_request' && isset($requestUri[1], $requestUri[2]) && $requestUri[2] === 'reject' && $requestMethod === 'POST') {
    $id = (int)$requestUri[1]; // Extract the ID from URL
    // Fetch current status to avoid double rejection
    $stmt = $mysqli->prepare("SELECT status FROM checkout_requests WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $checkout = $result->fetch_assoc();

    if (!$checkout) {
        http_response_code(404);
        echo json_encode(['error' => 'Checkout request not found']);
        exit;
    }

    if ($checkout['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['error' => 'Checkout already processed']);
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE checkout_requests SET status = 'rejected' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    echo json_encode(['success' => $stmt->affected_rows > 0]);
}
 else {
    http_response_code(404);
    echo json_encode(['error' => 'Invalid endpoint']);
}
