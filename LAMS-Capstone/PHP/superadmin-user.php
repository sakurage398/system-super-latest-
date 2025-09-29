<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection and audit functions
require_once 'db_connection.php';
require_once 'audit_functions.php';

// Set content type header
header('Content-Type: application/json');

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        switch ($action) {
            case 'add':
                addUser($conn);
                break;
            case 'edit':
                editUser($conn);
                break;
            case 'delete':
                deleteUser($conn);
                break;
            case 'getUsers':
                getUsers($conn);
                break;
            case 'getUser':
                if (empty($_POST['id'])) {
                    echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
                    return;
                }
                
                $id = (int)$_POST['id'];
                $query = "SELECT id, name, role, username, created_at FROM users WHERE id = $id";
                $result = $conn->query($query);
                
                if ($result && $result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    echo json_encode(['status' => 'success', 'user' => $user]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'User not found']);
                }
                break;
            case 'getUsersForAudit':
                getUsersForAudit($conn);
                break;
            case 'getUserAuditLogs':
                getUserAuditLogs($conn);
                break;
            default:
                echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Exception: ' . $e->getMessage()]);
    }

    // Close connection
    $conn->close();
    exit();
}

/**
 * Custom function to hash password and limit to 8 characters
 */
function customHash($password) {
    return substr(md5($password), 0, 8);
}

/**
 * Add a new user to the database
 */
function addUser($conn) {
    // Validate input
    if (empty($_POST['name']) || empty($_POST['role']) || empty($_POST['username']) || empty($_POST['password']) || empty($_POST['pincode'])) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
        return;
    }

    $name = $conn->real_escape_string($_POST['name']);
    $role = 'Admin'; // Force Admin role only
    $username = $conn->real_escape_string($_POST['username']);
    $password = $conn->real_escape_string($_POST['password']);
    $pincode = $conn->real_escape_string($_POST['pincode']);
    
    // Validate pincode is 6 digits
    if (!preg_match('/^\d{6}$/', $pincode)) {
        echo json_encode(['status' => 'error', 'message' => 'Pincode must be 6 digits']);
        return;
    }
    
    // Check if username already exists
    $checkQuery = "SELECT id FROM users WHERE username = '$username'";
    $result = $conn->query($checkQuery);
    
    if ($result === false) {
        echo json_encode(['status' => 'error', 'message' => 'Database query error: ' . $conn->error]);
        return;
    }
    
    if ($result->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
        return;
    }
    
    // Hash password with the custom 8-character hash
    $hashedPassword = customHash($password);
    
    // Get current user info for audit trail
    $currentUser = getCurrentUserInfo();
    
    // Insert new user
    $query = "INSERT INTO users (name, role, username, password, pincode) VALUES ('$name', '$role', '$username', '$hashedPassword', '$pincode')";
    
    if ($conn->query($query) === TRUE) {
        $userId = $conn->insert_id;
        
        // Log the action in audit trail
        logAuditTrail(
            $conn,
            $currentUser['id'],
            $currentUser['username'],
            'USER_CREATE',
            "Created new admin user: $name ($username)",
            'users',
            $userId,
            null,
            ['name' => $name, 'role' => $role, 'username' => $username]
        );
        
        echo json_encode([
            'status' => 'success', 
            'message' => 'User added successfully', 
            'user' => [
                'id' => $userId,
                'name' => $name,
                'role' => $role,
                'username' => $username
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error adding user: ' . $conn->error]);
    }
}

/**
 * Edit an existing user
 */
function editUser($conn) {
    // Validate input
    if (empty($_POST['id']) || empty($_POST['name']) || empty($_POST['username'])) {
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
        return;
    }
    
    $id = (int)$_POST['id'];
    $name = $conn->real_escape_string($_POST['name']);
    $role = 'Admin'; // Force Admin role only
    $username = $conn->real_escape_string($_POST['username']);
    $password = isset($_POST['password']) ? $conn->real_escape_string($_POST['password']) : '';
    $pincode = isset($_POST['pincode']) ? $conn->real_escape_string($_POST['pincode']) : '';
    
    // Validate pincode if provided
    if (!empty($pincode) && !preg_match('/^\d{6}$/', $pincode)) {
        echo json_encode(['status' => 'error', 'message' => 'Pincode must be 6 digits']);
        return;
    }
    
    // Get current user data for audit trail
    $oldDataQuery = "SELECT name, role, username FROM users WHERE id = $id";
    $oldDataResult = $conn->query($oldDataQuery);
    $oldData = $oldDataResult->fetch_assoc();
    
    // Check if username already exists and is not the current user
    $checkQuery = "SELECT id FROM users WHERE username = '$username' AND id != $id";
    $result = $conn->query($checkQuery);
    
    if ($result === false) {
        echo json_encode(['status' => 'error', 'message' => 'Database query error: ' . $conn->error]);
        return;
    }
    
    if ($result->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
        return;
    }
    
    // Update user
    $queryParts = ["name = '$name'", "role = '$role'", "username = '$username'"];
    
    // Only update password if a new one was provided
    if (!empty($password)) {
        $hashedPassword = customHash($password);
        $queryParts[] = "password = '$hashedPassword'";
    }
    
    // Update pincode if provided
    if (!empty($pincode)) {
        $queryParts[] = "pincode = '$pincode'";
    }
    
    $updateClause = implode(", ", $queryParts);
    $query = "UPDATE users SET $updateClause WHERE id = $id";
    
    if ($conn->query($query) === TRUE) {
        // Get current user info for audit trail
        $currentUser = getCurrentUserInfo();
        
        // Prepare new values for audit trail
        $newValues = ['name' => $name, 'role' => $role, 'username' => $username];
        if (!empty($password)) {
            $newValues['password'] = '***'; // Don't log actual password
        }
        if (!empty($pincode)) {
            $newValues['pincode'] = '***'; // Don't log actual pincode
        }
        
        // Log the action in audit trail
        logAuditTrail(
            $conn,
            $currentUser['id'],
            $currentUser['username'],
            'USER_UPDATE',
            "Updated admin user: $name ($username)",
            'users',
            $id,
            $oldData,
            $newValues
        );
        
        echo json_encode([
            'status' => 'success', 
            'message' => 'User updated successfully',
            'user' => [
                'id' => $id,
                'name' => $name,
                'role' => $role,
                'username' => $username
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error updating user: ' . $conn->error]);
    }
}

/**
 * Delete a user
 */
function deleteUser($conn) {
    // Validate input
    if (empty($_POST['id'])) {
        echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
        return;
    }
    
    $id = (int)$_POST['id'];
    
    // Check if user exists
    $checkQuery = "SELECT id, name, username FROM users WHERE id = $id";
    $result = $conn->query($checkQuery);
    
    if ($result === false) {
        echo json_encode(['status' => 'error', 'message' => 'Database query error: ' . $conn->error]);
        return;
    }
    
    if ($result->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        return;
    }
    
    $userData = $result->fetch_assoc();
    
    // Prevent deletion of the current user
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] === $id) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot delete your own account']);
        return;
    }
    
    // Get current user info for audit trail
    $currentUser = getCurrentUserInfo();
    
    // Delete user
    $query = "DELETE FROM users WHERE id = $id";
    
    if ($conn->query($query) === TRUE) {
        // Log the action in audit trail
        logAuditTrail(
            $conn,
            $currentUser['id'],
            $currentUser['username'],
            'USER_DELETE',
            "Deleted admin user: {$userData['name']} ({$userData['username']})",
            'users',
            $id,
            $userData,
            null
        );
        
        echo json_encode(['status' => 'success', 'message' => 'User deleted successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error deleting user: ' . $conn->error]);
    }
}

/**
 * Get all users based on role filter
 */
function getUsers($conn) {
    $role = 'Admin'; // Only get Admin users
    $searchTerm = isset($_POST['search']) ? $conn->real_escape_string($_POST['search']) : '';
    
    $whereClause = ["role = 'Admin'"]; // Only Admin users
    
    if (!empty($searchTerm)) {
        $whereClause[] = "(name LIKE '%$searchTerm%' OR username LIKE '%$searchTerm%')";
    }
    
    $whereStatement = !empty($whereClause) ? "WHERE " . implode(" AND ", $whereClause) : "";
    
    $query = "SELECT id, name, role, username, created_at FROM users $whereStatement ORDER BY name";
    $result = $conn->query($query);
    
    if ($result) {
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'role' => $row['role'],
                'username' => $row['username'],
                'created_at' => $row['created_at']
            ];
        }
        echo json_encode(['status' => 'success', 'users' => $users]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error fetching users: ' . $conn->error]);
    }
}

/**
 * Get all users for audit trail view
 */
function getUsersForAudit($conn) {
    $searchTerm = isset($_POST['search']) ? $conn->real_escape_string($_POST['search']) : '';
    
    $whereClause = ["role = 'Admin'"];
    
    if (!empty($searchTerm)) {
        $whereClause[] = "(name LIKE '%$searchTerm%' OR username LIKE '%$searchTerm%')";
    }
    
    $whereStatement = !empty($whereClause) ? "WHERE " . implode(" AND ", $whereClause) : "";
    
    $query = "SELECT id, name, role, username FROM users $whereStatement ORDER BY name";
    $result = $conn->query($query);
    
    if ($result) {
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'role' => $row['role'],
                'username' => $row['username']
            ];
        }
        echo json_encode(['status' => 'success', 'users' => $users]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error fetching users: ' . $conn->error]);
    }
}

/**
 * Get audit logs for specific user
 */
function getUserAuditLogs($conn) {
    if (empty($_POST['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
        return;
    }
    
    $userId = (int)$_POST['user_id'];
    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
    $offset = ($page - 1) * $limit;
    
    $search = isset($_POST['search']) ? $conn->real_escape_string($_POST['search']) : '';
    $actionFilter = isset($_POST['action_filter']) ? $conn->real_escape_string($_POST['action_filter']) : '';
    
    $whereClause = ["user_id = $userId"];
    
    if (!empty($search)) {
        $whereClause[] = "(description LIKE '%$search%')";
    }
    
    if (!empty($actionFilter)) {
        $whereClause[] = "action = '$actionFilter'";
    }
    
    $whereStatement = !empty($whereClause) ? "WHERE " . implode(" AND ", $whereClause) : "";
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM audit_trail $whereStatement";
    $countResult = $conn->query($countQuery);
    $totalRows = $countResult->fetch_assoc()['total'];
    
    // Get logs
    $query = "SELECT * FROM audit_trail $whereStatement ORDER BY timestamp DESC LIMIT $limit OFFSET $offset";
    $result = $conn->query($query);
    
    if ($result) {
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = [
                'id' => $row['id'],
                'action' => $row['action'],
                'description' => $row['description'],
                'table_name' => $row['table_name'],
                'record_id' => $row['record_id'],
                'old_values' => $row['old_values'] ? json_decode($row['old_values'], true) : null,
                'new_values' => $row['new_values'] ? json_decode($row['new_values'], true) : null,
                'ip_address' => $row['ip_address'],
                'timestamp' => $row['timestamp']
            ];
        }
        
        echo json_encode([
            'status' => 'success', 
            'logs' => $logs,
            'total' => $totalRows,
            'page' => $page,
            'total_pages' => ceil($totalRows / $limit)
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error fetching user audit logs: ' . $conn->error]);
    }
}
?>