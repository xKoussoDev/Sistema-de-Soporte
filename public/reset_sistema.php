<?php
require_once '../includes/auth_check.php';

// Solo los administradores pueden ejecutar esta acción crítica
if ($_SESSION['id_rol'] != 1) {
    die("Acceso denegado. Solo los administradores pueden realizar esta acción.");
}

require_once '../config/database.php';

$mensaje = '';
$error = '';
$confirmado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirmar_reseteo'])) {
        try {
            // Desactivar temporalmente la revisión de llaves foráneas para permitir el borrado
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
            
            // Vaciar todas las tablas de datos, excepto Usuarios
            $pdo->exec('TRUNCATE TABLE Archivos_Adjuntos;');
            $pdo->exec('TRUNCATE TABLE Comentarios;');
            $pdo->exec('TRUNCATE TABLE Tickets;');
            $pdo->exec('TRUNCATE TABLE Clientes;');
            $pdo->exec('TRUNCATE TABLE Agentes;');
            $pdo->exec('TRUNCATE TABLE TiposDeCaso;');
            
            // Reactivar la revisión de llaves foráneas
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
            
            $mensaje = "¡Reseteo completado con éxito! Todas las tablas han sido vaciadas, excepto la tabla de Usuarios. El sistema está limpio.";
            $confirmado = true;

        } catch (Exception $e) {
            $error = "Ocurrió un error fatal durante el reseteo: " . $e->getMessage();
            // Siempre intentar reactivar las llaves foráneas en caso de error
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
        }
    }
}

require_once '../includes/header.php';
?>

<div class="container mt-4">
    <div class="card border-danger">
        <div class="card-header bg-danger text-white">
            <h2 class="mb-0"><i class="bi bi-exclamation-octagon-fill"></i> Resetear Sistema a Estado de Fábrica</h2>
        </div>
        <div class="card-body">
            <?php if ($mensaje): ?>
                <div class="alert alert-success">
                    <h4>Proceso Finalizado</h4>
                    <p><?php echo $mensaje; ?></p>
                    <a href="index.php" class="btn btn-primary">Volver al Dashboard</a>
                </div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger">
                    <h4>Error</h4>
                    <p><?php echo $error; ?></p>
                    <a href="index.php" class="btn btn-secondary">Volver al Dashboard</a>
                </div>
            <?php else: ?>
                <h4 class="card-title text-danger">¡Estás a punto de borrar casi toda la base de datos!</h4>
                <p>Este proceso eliminará permanentemente todos los datos de las siguientes tablas:</p>
                <ul>
                    <li>Clientes</li>
                    <li>Tipos de Caso</li>
                    <li>Agentes</li>
                    <li>Tickets</li>
                    <li>Comentarios</li>
                    <li>Archivos Adjuntos</li>
                </ul>
                <p class="fw-bold">La única tabla que se conservará intacta es la tabla de `Usuarios`.</p>
                <hr>
                <p><strong>Esta acción no se puede deshacer. ¿Estás absolutamente seguro de que quieres resetear el sistema?</strong></p>
                
                <form action="reset_sistema.php" method="POST">
                    <button type="submit" name="confirmar_reseteo" class="btn btn-danger btn-lg">
                        <i class="bi bi-trash-fill"></i> Sí, entiendo las consecuencias y quiero resetear el sistema
                    </button>
                    <a href="index.php" class="btn btn-secondary btn-lg">No, cancelar y volver</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>