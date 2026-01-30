<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

// CSRF Check
$headers = getallheaders();
$csrf_token = $headers['X-CSRF-Token'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'CSRF Token Mismatch']);
        exit;
    }
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Search / List
// Just grabbing users, filtering if search term is present.
if ($action === 'fetch_all' && $method === 'GET') {
    $search = $_GET['search'] ?? '';
    $sql = "SELECT * FROM users";
    
    if ($search) {
        $sql .= " WHERE name LIKE ? OR email LIKE ? OR phone LIKE ?";
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $conn->prepare($sql);
    if ($search) {
        $term = "%$search%";
        $stmt->bind_param("sss", $term, $term, $term);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    echo json_encode(['success' => true, 'data' => $result->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

// Get One
// Fetch single user details for editing.
if ($action === 'fetch_one' && $method === 'GET') {
    $id = $_GET['id'] ?? 0;
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'data' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Not found']);
    }
    exit;
}

// Create
// Standard insert. Validating everything before hitting DB.
if ($action === 'create' && $method === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gender = $_POST['gender'] ?? 'Male';

    // Validation
    // Keeping it simple - basic required checks and format validation.
    if (!$name || !$email || !$phone) {
        echo json_encode(['success' => false, 'message' => 'All fields required']);
        exit;
    }
    if (strlen($name) > 30) {
        echo json_encode(['success' => false, 'message' => 'Name too long']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { // PHP's built-in filter is cleaner
        echo json_encode(['success' => false, 'message' => 'Invalid email']);
        exit;
    }
    if (!preg_match('/^\d{10}$/', $phone)) {
        echo json_encode(['success' => false, 'message' => 'Phone must be 10 digits']);
        exit;
    }

    // Check email
    // Prevent duplicate emails, standard practice.
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Email exists']);
        exit;
    }

    // Upload Image
    // Only processing if file is actually uploaded. Strict on types.
    $imageName = null;
    if (!empty($_FILES['profile_image']['name'])) {
        $file = $_FILES['profile_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            echo json_encode(['success' => false, 'message' => 'Only JPG/PNG allowed']);
            exit;
        }
        
        $imageName = uniqid() . '.' . $ext;
        move_uploaded_file($file['tmp_name'], 'uploads/' . $imageName);
    }

    $stmt = $conn->prepare("INSERT INTO users (name, email, phone, gender, profile_image) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $name, $email, $phone, $gender, $imageName);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'User created']);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB Error']);
    }
    exit;
}

// Update
// Handle updates. Tricky part is image - might be new, removed, or kept same.
if ($action === 'update' && $method === 'POST') {
    $id = $_POST['id'] ?? 0;
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gender = $_POST['gender'] ?? 'Male';
    $removeImg = $_POST['remove_image'] ?? '0';

    if (!$id) { echo json_encode(['success' => false, 'message' => 'No ID']); exit; }

    // Validation
    if (!$name || !$email || !$phone) {
        echo json_encode(['success' => false, 'message' => 'All fields required']);
        exit;
    }
    if (strlen($name) > 30) {
        echo json_encode(['success' => false, 'message' => 'Name too long']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email']);
        exit;
    }
    if (!preg_match('/^\d{10}$/', $phone)) {
        echo json_encode(['success' => false, 'message' => 'Phone must be 10 digits']);
        exit;
    }

    // Check email unique (excluding current user)
    // Make sure we don't accidentally take someone else's email.
    $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $check->bind_param("si", $email, $id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Email exists']);
        exit;
    }

    // Handle Image
    // Logic: If new file -> upload & replace. If remove flag set -> nullify. Else -> keep existing.
    $newImage = null;
    $shouldUpdateImage = false;

    if (!empty($_FILES['profile_image']['name'])) {
        $file = $_FILES['profile_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            echo json_encode(['success' => false, 'message' => 'Only JPG/PNG allowed']);
            exit;
        }

        $newImage = uniqid() . '.' . $ext;
        move_uploaded_file($file['tmp_name'], 'uploads/' . $newImage);
        $shouldUpdateImage = true;
    } elseif ($removeImg === '1') {
        $newImage = null;
        $shouldUpdateImage = true;
    }

    // Delete old image if we're changing it
    // Don't want orphan files cluttering the uploads folder.
    if ($shouldUpdateImage) {
        $q = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
        $q->bind_param("i", $id);
        $q->execute();
        $oldRow = $q->get_result()->fetch_assoc();
        if ($oldRow && $oldRow['profile_image']) {
            if (file_exists('uploads/' . $oldRow['profile_image'])) {
                unlink('uploads/' . $oldRow['profile_image']);
            }
        }
    }

    if ($shouldUpdateImage) {
        $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, gender=?, profile_image=? WHERE id=?");
        $stmt->bind_param("sssssi", $name, $email, $phone, $gender, $newImage, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, gender=? WHERE id=?");
        $stmt->bind_param("ssssi", $name, $email, $phone, $gender, $id);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Update failed']);
    }
    exit;
}

// Delete
// Wipe the user and their image file if they have one.
if ($action === 'delete' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? 0;

    if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

    // Delete image file
    // Cleaning up the filesystem before removing DB record.
    $q = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
    $q->bind_param("i", $id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    
    if ($row && $row['profile_image']) {
        if (file_exists('uploads/' . $row['profile_image'])) {
            unlink('uploads/' . $row['profile_image']);
        }
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Deleted']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Delete failed']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
