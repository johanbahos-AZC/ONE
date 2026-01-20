<?php
require_once '../includes/database.php';
require_once '../includes/functions.php';

$database = new Database();
$conn = $database->getConnection();
$functions = new Functions();

// Obtener información del último candidato registrado si es necesario
$ultimo_candidato = null;
if (isset($_SESSION['ultimo_candidato_id'])) {
    $query = "SELECT * FROM employee WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $_SESSION['ultimo_candidato_id']);
    $stmt->execute();
    $ultimo_candidato = $stmt->fetch(PDO::FETCH_ASSOC);
    unset($_SESSION['ultimo_candidato_id']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro Exitoso - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <style>
        :root {
            --primary-red: #be1622;
            --dark-gray: #353132;
            --light-gray: #9d9d9c;
            --dark-blue: #003a5d;
        }
        
        .success-container {
            background: var(--dark-blue);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .success-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 30px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
            overflow: hidden;
            border: 3px solid var(--dark-blue);
        }
        .success-header {
            background: var(--dark-blue);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .success-icon {
            font-size: 80px;
            margin-bottom: 20px;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.3));
        }
        .success-body {
            padding: 40px 30px;
            background: #ffffff;
        }
        .success-step {
            display: flex;
            align-items: flex-start;
            margin-bottom: 20px;
            padding: 20px;
            border-radius: 10px;
            background: #f8f9fa;
            border-left: 4px solid var(--primary-red);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .success-step:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(190, 22, 34, 0.1);
        }
        .step-number {
            background: var(--primary-red);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 15px;
            flex-shrink: 0;
            font-size: 14px;
            box-shadow: 0 2px 4px rgba(190, 22, 34, 0.3);
        }
        .alert-info-custom {
            background: #f8f9fa;
            border: 2px solid var(--light-gray);
            border-left: 6px solid var(--dark-blue);
            color: var(--dark-gray);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .btn-custom-primary {
            background: var(--dark-blue);
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-custom-primary:hover {
            background: #004d7a;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 58, 93, 0.3);
            color: white;
        }
        .btn-custom-secondary {
            background: var(--light-gray);
            border: none;
            color: var(--dark-gray);
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-custom-secondary:hover {
            background: #8a8a89;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(157, 157, 156, 0.3);
            color: var(--dark-gray);
        }
        .timer {
            font-size: 14px;
            color: var(--light-gray);
            text-align: center;
            margin-top: 25px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid var(--light-gray);
        }
        .countdown-number {
            color: var(--primary-red);
            font-weight: bold;
            font-size: 16px;
        }
        h1, h4 {
            color: var(--dark-gray);
        }
        .text-muted {
            color: var(--light-gray) !important;
        }
        strong {
            color: var(--dark-blue);
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-card">
            <div class="success-header">
                <i class="bi bi-check-circle-fill me-3" style="color: var(--white); font-size: 60px;"></i>
                <h1 class="mb-3" style="color: white;">¡Registro Exitoso!</h1>
                <p class="mb-0 fs-5" style="color: white;">Tu candidatura ha sido registrada correctamente</p>
            </div>
            
            <div class="success-body">
                <div class="alert-info-custom">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-check-circle-fill me-3" style="color: var(--dark-blue); font-size: 24px;"></i>
                        <div>
                            <strong class="fs-5">Hemos recibido tu formulario exitosamente</strong>
                            <br>
                            <span class="text-muted">Se ha enviado un correo de confirmación a tu dirección de email.</span>
                        </div>
                    </div>
                </div>

                <h4 class="mb-4" style="color: var(--dark-blue);">Próximos pasos en tu proceso:</h4>
                
                <div class="success-step">
                    <div class="step-number">1</div>
                    <div>
                        <strong>Revisión de documentos</strong>
                        <p class="mb-0 text-muted">Nuestro equipo de Talento Humano revisará tu información y documentos en un plazo de 3-5 días hábiles.</p>
                    </div>
                </div>
                
                <div class="success-step">
                    <div class="step-number">2</div>
                    <div>
                        <strong>Contacto para entrevista</strong>
                        <p class="mb-0 text-muted">Si tu perfil coincide con nuestras necesidades, te contactaremos para coordinar una entrevista.</p>
                    </div>
                </div>
                
                <div class="success-step">
                    <div class="step-number">3</div>
                    <div>
                        <strong>Proceso de selección</strong>
                        <p class="mb-0 text-muted">Participarás en las diferentes etapas de nuestro proceso de selección.</p>
                    </div>
                </div>

                
            </div>
        </div>
    </div>

    <script>
        // Contador para cerrar automáticamente
        let seconds = 10;
        const countdownElement = document.getElementById('countdown');
        
        const countdown = setInterval(function() {
            seconds--;
            countdownElement.textContent = seconds;
            
            if (seconds <= 0) {
                clearInterval(countdown);
                window.close();
            }
        }, 1000);

        // También ofrecer cerrar con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.close();
            }
        });

        // Efectos de hover mejorados
        document.addEventListener('DOMContentLoaded', function() {
            const steps = document.querySelectorAll('.success-step');
            steps.forEach(step => {
                step.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-3px)';
                    this.style.boxShadow = '0 8px 20px rgba(190, 22, 34, 0.15)';
                });
                step.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = '0 5px 15px rgba(190, 22, 34, 0.1)';
                });
            });
        });
    </script>
</body>
</html>