<?php
// update_user.php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_POST['user_id'];
    
    // Recoger todos los campos editables
    $data = [
        'first_Name' => $_POST['first_Name'],
        'second_Name' => $_POST['second_Name'],
        'first_LastName' => $_POST['first_LastName'],
        'second_LastName' => $_POST['second_LastName'],
        'mail' => $_POST['mail'],
        'personal_mail' => $_POST['personal_mail'],
        'phone' => $_POST['phone'],
        'extension' => $_POST['extension'],
        'activo_fijo' => $_POST['activo_fijo'],
        'city' => $_POST['city'],
        'site' => $_POST['site'],
        'company' => $_POST['company'],
        'position' => $_POST['position'],
        'role' => $_POST['role']
    ];
    
    $database = new Database();
    $conn = $database->getConnection();
    
    // Construir query dinámico
    $set_clause = [];
    foreach ($data as $field => $value) {
        $set_clause[] = "$field = :$field";
    }
    
    $query = "UPDATE employee SET " . implode(', ', $set_clause) . ", updated_at = NOW() WHERE id = :id";
    $stmt = $conn->prepare($query);
    
    // Bind parameters
    foreach ($data as $field => $value) {
        $stmt->bindValue(":$field", $value);
    }
    $stmt->bindParam(':id', $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Usuario actualizado correctamente";
    } else {
        $_SESSION['error'] = "Error al actualizar el usuario";
    }
    
    header("Location: usuarios.php");
    exit();
}