<?php
// calificar_ticket.php
require_once '../includes/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ticket_id']) && isset($_GET['calificacion'])) {
    $database = new Database();
    $conn = $database->getConnection();
    
    $ticket_id = intval($_GET['ticket_id']);
    $calificacion = intval($_GET['calificacion']);
    $comentario = $_GET['comentario'] ?? '';
    
    // Verificar que el ticket existe y está completado
    $query_ticket = "SELECT id, estado FROM tickets WHERE id = :ticket_id AND estado = 'completado'";
    $stmt_ticket = $conn->prepare($query_ticket);
    $stmt_ticket->bindParam(':ticket_id', $ticket_id);
    $stmt_ticket->execute();
    $ticket = $stmt_ticket->fetch(PDO::FETCH_ASSOC);
    
    if ($ticket) {
        // Verificar si ya fue calificado
        $query_existe = "SELECT id FROM ticket_calificaciones WHERE ticket_id = :ticket_id";
        $stmt_existe = $conn->prepare($query_existe);
        $stmt_existe->bindParam(':ticket_id', $ticket_id);
        $stmt_existe->execute();
        
        if ($stmt_existe->rowCount() === 0) {
            // Insertar calificación
            $query_insert = "INSERT INTO ticket_calificaciones (ticket_id, calificacion, comentario, fecha_calificacion) 
                            VALUES (:ticket_id, :calificacion, :comentario, NOW())";
            $stmt_insert = $conn->prepare($query_insert);
            $stmt_insert->bindParam(':ticket_id', $ticket_id);
            $stmt_insert->bindParam(':calificacion', $calificacion);
            $stmt_insert->bindParam(':comentario', $comentario);
            
            if ($stmt_insert->execute()) {
                // Página de agradecimiento (HTML simple que se cierra solo)
                echo '<!DOCTYPE html>
                <html lang="es">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
                    <title>Gracias por tu calificación</title>
                    <style>
        body { 
            font-family: Arial, sans-serif; 
            text-align: center; 
            padding: 50px; 
            background-color: #003a5d;
            color: white;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background-color: white;
            padding: 40px;
            border-radius: 10px;
            border: 3px solid #be1622;
            color: #353132;
            max-width: 500px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .stars {
            font-size: 2.5em;
            color: #ffc107;
            margin: 20px 0;
        }
        h1 {
            color: #003a5d;
            margin-bottom: 20px;
        }
        p {
            color: #353132;
            margin: 10px 0;
        }
        small {
            color: #9d9d9c;
        }
    </style>
                    <script>
                        setTimeout(function() {
                            window.close();
                        }, 3000);
                    </script>
                </head>
                <body>
                    <div class="container">
                        <h1>¡Gracias por tu calificación!</h1>
                        <div class="stars">';
                
                for ($i = 1; $i <= 5; $i++) {
                    echo $i <= $calificacion ? '★' : '☆';
                }
                
                echo '</div>
                        <p>Tu opinión es muy importante para nosotros.</p>
                        <p><small>Esta ventana se cerrará automáticamente...</small></p>
                    </div>
                </body>
                </html>';
                exit;
            }
        } else {
            // Ya fue calificado
            echo '<!DOCTYPE html>
<html>
<head>
    <title>Ya calificado</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            text-align: center; 
            padding: 50px; 
            background-color: #003a5d;
            color: white;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            border: 3px solid #be1622;
            color: #353132;
        }
    </style>
    <script>setTimeout(function(){ window.close(); }, 2000);</script>
</head>
<body>
    <div class="container">
        <p>Este ticket ya fue calificado anteriormente.</p>
    </div>
</body>
</html>';
            exit;
        }
    }
}

// Si hay algún error o acceso directo
echo '<!DOCTYPE html>
<html>
<head>
    <title>Error</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            text-align: center; 
            padding: 50px; 
            background-color: #003a5d;
            color: white;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            border: 3px solid #be1622;
            color: #353132;
        }
    </style>
    <script>setTimeout(function(){ window.close(); }, 2000);</script>
</head>
<body>
    <p>Error al procesar la calificación.</p>
</body>
</html>';
?>