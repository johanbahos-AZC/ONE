<?php
// includes/auth.php
if (!class_exists('Auth')) {
    
require_once 'database.php';
require_once 'debug.php';

class Auth {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        debug_log("Auth: Conexión a BD establecida");
    }
    
    public function login($email, $password) {
        debug_log("Login intent: email=$email");
        
        $query = "SELECT id, mail, password, first_Name, first_LastName, role 
                  FROM employee 
                  WHERE mail = :email AND role != 'retirado' 
                  LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        
        if (!$stmt->execute()) {
            debug_log("Error en consulta SQL: " . implode(", ", $stmt->errorInfo()));
            return false;
        }
        
        if ($stmt->rowCount() == 1) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            debug_log("Usuario encontrado: " . print_r($row, true));
            
            // Comparación directa de contraseña
            $is_valid = ($password === $row['password']);
            debug_log("Resultado comparación: " . ($is_valid ? 'TRUE' : 'FALSE'));
            
            if ($is_valid) {
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['user_email'] = $row['mail'];
                $_SESSION['user_name'] = $row['first_Name'] . ' ' . $row['first_LastName'];
                $_SESSION['user_role'] = $row['role'];
                
                debug_log("Login exitoso para usuario: " . $row['mail']);
                return true;
            } else {
                debug_log("Password verification FAILED for user: " . $row['mail']);
                debug_log("Password esperado: '" . $row['password'] . "'");
                debug_log("Password recibido: '$password'");
            }
        } else {
            debug_log("Usuario no encontrado: $email");
        }
        
        return false;
    }
    
    public function isLoggedIn() {
        $logged_in = isset($_SESSION['user_id']);
        debug_log("isLoggedIn check: " . ($logged_in ? 'TRUE' : 'FALSE'));
        return $logged_in;
    }
    
    public function redirectIfNotLoggedIn() {
        debug_log("redirectIfNotLoggedIn called");
        if (!$this->isLoggedIn()) {
            debug_log("Redirigiendo a login");
            header("Location: ../login.php");
            exit();
        }
    }
    
    public function logout() {
        debug_log("Logout realizado");
        session_destroy();
        header("Location: ../login.php");
        exit();
    }
    
    public function hasRole($requiredRole) {
        $userRole = $_SESSION['user_role'] ?? '';
        $hasRole = ($userRole === $requiredRole);
        debug_log("hasRole check: $userRole === $requiredRole = " . ($hasRole ? 'TRUE' : 'FALSE'));
        return $hasRole;
    }
    
    public function hasAnyRole($requiredRoles) {
        $userRole = $_SESSION['user_role'] ?? '';
        $hasAny = in_array($userRole, $requiredRoles);
        debug_log("hasAnyRole check: $userRole in [" . implode(',', $requiredRoles) . "] = " . ($hasAny ? 'TRUE' : 'FALSE'));
        return $hasAny;
    }
}

} // Fin del if class_exists
?>