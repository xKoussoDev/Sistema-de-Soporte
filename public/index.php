<?php
require_once '../includes/auth_check.php';
require_once '../config/database.php';

// Inicializar variables para evitar errores
$total_abiertos = $total_pendientes = $total_resueltos = $total_tickets = 0;
$chart_labels_donut_json = $chart_values_donut_json = '[]';
$chart_labels_bar_json = $chart_values_bar_json = '[]';

// --- CÁLCULOS Y DATOS SOLO PARA ADMINISTRADORES ---
if ($_SESSION['id_rol'] == 1) {
    $total_abiertos = $pdo->query("SELECT COUNT(*) FROM Tickets WHERE estado = 'Abierto'")->fetchColumn();
    $total_pendientes = $pdo->query("SELECT COUNT(*) FROM Tickets WHERE estado IN ('En Progreso', 'En Espera')")->fetchColumn();
    $total_resueltos = $pdo->query("SELECT COUNT(*) FROM Tickets WHERE estado IN ('Resuelto', 'Cerrado')")->fetchColumn();
    $total_tickets = $pdo->query("SELECT COUNT(*) FROM Tickets WHERE estado != 'Anulado'")->fetchColumn(); 

    $stmt_chart_donut = $pdo->query("SELECT estado, COUNT(*) as total FROM Tickets WHERE estado != 'Anulado' GROUP BY estado ORDER BY estado");
    $chart_data_donut = $stmt_chart_donut->fetchAll(PDO::FETCH_ASSOC);
    $chart_labels_donut = []; $chart_values_donut = [];
    foreach ($chart_data_donut as $data) {
        $chart_labels_donut[] = $data['estado'];
        $chart_values_donut[] = $data['total'];
    }
    $chart_labels_donut_json = json_encode($chart_labels_donut);
    $chart_values_donut_json = json_encode($chart_values_donut);

    $meses_es = ["", "Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
    $chart_labels_bar = [];
    $chart_data_bar_default = [];
    for ($i = 2; $i >= 0; $i--) {
        $date = new DateTime("first day of -$i month");
        $month_key = $date->format('Y-m');
        $month_name = $meses_es[(int)$date->format('n')] . "'" . $date->format('y');
        $chart_labels_bar[] = $month_name;
        $chart_data_bar_default[$month_key] = 0;
    }
    $start_date = (new DateTime("first day of -2 month"))->format('Y-m-d 00:00:00');
    $stmt_bar_chart = $pdo->prepare("SELECT YEAR(fecha_creacion) as anio, MONTH(fecha_creacion) as mes, COUNT(*) as total FROM Tickets WHERE fecha_creacion >= ? GROUP BY anio, mes ORDER BY anio, mes");
    $stmt_bar_chart->execute([$start_date]);
    $monthly_data = $stmt_bar_chart->fetchAll(PDO::FETCH_ASSOC);
    foreach ($monthly_data as $data) {
        $month_key = $data['anio'] . '-' . str_pad($data['mes'], 2, '0', STR_PAD_LEFT);
        if (isset($chart_data_bar_default[$month_key])) {
            $chart_data_bar_default[$month_key] = $data['total'];
        }
    }
    $chart_values_bar_json = json_encode(array_values($chart_data_bar_default));
    $chart_labels_bar_json = json_encode($chart_labels_bar);
}

// --- LÓGICA DE FILTROS Y BÚSQUEDA ---
$filtro_termino = $_GET['termino'] ?? '';
$filtro_cliente = $_GET['cliente'] ?? '';
$filtro_agente = $_GET['agente'] ?? '';
$filtro_prioridad = $_GET['prioridad'] ?? '';
$filtro_estado_tabla = $_GET['estado_tabla'] ?? '';
$filtro_facturacion = $_GET['facturacion'] ?? '';
$filtro_fecha_inicio = $_GET['fecha_inicio'] ?? '';
$filtro_fecha_fin = $_GET['fecha_fin'] ?? '';

$where_conditions = [];
$params = [];

if ($_SESSION['id_rol'] != 1) {
    $stmt_agente_logueado = $pdo->prepare("SELECT id_agente FROM Agentes WHERE id_usuario = ?");
    $stmt_agente_logueado->execute([$_SESSION['id_usuario']]);
    $id_agente_actual = $stmt_agente_logueado->fetchColumn();
    $where_conditions[] = "t.id_agente_asignado = :id_agente_logueado";
    $params[':id_agente_logueado'] = $id_agente_actual ?: 0;
}

if (!empty($filtro_termino)) { $where_conditions[] = "(t.asunto LIKE :termino OR t.id_ticket = :id_ticket)"; $params[':termino'] = '%' . $filtro_termino . '%'; $params[':id_ticket'] = $filtro_termino; }
if (!empty($filtro_cliente)) { $where_conditions[] = "t.id_cliente = :cliente"; $params[':cliente'] = $filtro_cliente; }
if (!empty($filtro_agente) && $_SESSION['id_rol'] == 1) { $where_conditions[] = "t.id_agente_asignado = :agente"; $params[':agente'] = $filtro_agente; }
if (!empty($filtro_prioridad)) { $where_conditions[] = "t.prioridad = :prioridad"; $params[':prioridad'] = $filtro_prioridad; }
if (!empty($filtro_estado_tabla)) { $where_conditions[] = "t.estado = :estado_tabla"; $params[':estado_tabla'] = $filtro_estado_tabla; }
if (!empty($filtro_facturacion) && $_SESSION['id_rol'] == 1) { $where_conditions[] = "t.estado_facturacion = :facturacion"; $params[':facturacion'] = $filtro_facturacion; }
if (!empty($filtro_fecha_inicio)) { $where_conditions[] = "DATE(t.fecha_creacion) >= :fecha_inicio"; $params[':fecha_inicio'] = $filtro_fecha_inicio; }
if (!empty($filtro_fecha_fin)) { $where_conditions[] = "DATE(t.fecha_creacion) <= :fecha_fin"; $params[':fecha_fin'] = $filtro_fecha_fin; }

$sql_lista = "SELECT t.id_ticket, t.asunto, t.estado, t.prioridad, t.fecha_creacion, c.nombre AS nombre_cliente, u.nombre_completo AS nombre_agente, tc.nombre_tipo, t.fecha_vencimiento, t.costo, t.moneda, t.estado_facturacion FROM Tickets AS t JOIN Clientes AS c ON t.id_cliente = c.id_cliente LEFT JOIN Agentes AS ag ON t.id_agente_asignado = ag.id_agente LEFT JOIN Usuarios AS u ON ag.id_usuario = u.id_usuario LEFT JOIN TiposDeCaso AS tc ON t.id_tipo_caso = tc.id_tipo_caso";
if (!empty($where_conditions)) {
    $sql_lista .= " WHERE " . implode(' AND ', $where_conditions);
}
$sql_lista .= " ORDER BY t.fecha_creacion DESC";
$stmt_lista = $pdo->prepare($sql_lista);
$stmt_lista->execute($params);
$tickets = $stmt_lista->fetchAll();

$status_classes = ['Abierto' => 'primary', 'En Progreso' => 'info', 'En Espera' => 'warning', 'Resuelto' => 'success', 'Cerrado' => 'secondary', 'Anulado' => 'dark'];
$priority_classes = ['Baja' => 'success', 'Media' => 'warning', 'Alta' => 'danger', 'Urgente' => 'danger fw-bold'];
$facturacion_classes = ['Pendiente' => 'warning', 'Facturado' => 'info', 'Pagado' => 'success', 'Anulado' => 'secondary'];
$agentes_disponibles = $pdo->query("SELECT a.id_agente, u.nombre_completo FROM Agentes a JOIN Usuarios u ON a.id_usuario = u.id_usuario WHERE u.activo = 1 ORDER BY u.nombre_completo")->fetchAll();
$clientes_disponibles = $pdo->query("SELECT id_cliente, nombre FROM Clientes ORDER BY nombre ASC")->fetchAll();
$mensaje_exito = (isset($_GET['status']) && $_GET['status'] === 'created') ? '<div class="alert alert-success">¡Ticket creado con éxito!</div>' : '';

require_once '../includes/header.php';
?>

<h2 class="mb-4">Dashboard General</h2>

<?php if ($_SESSION['id_rol'] == 1): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-3 col-md-6"><div class="card text-white bg-primary shadow h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><h5 class="card-title fs-2"><?php echo $total_abiertos; ?></h5><p class="card-text">Abiertos</p></div><i class="bi bi-envelope-open-fill fs-1 opacity-50"></i></div></div></div>
    <div class="col-lg-3 col-md-6"><div class="card text-white bg-warning shadow h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><h5 class="card-title fs-2"><?php echo $total_pendientes; ?></h5><p class="card-text">Pendientes</p></div><i class="bi bi-clock-history fs-1 opacity-50"></i></div></div></div>
    <div class="col-lg-3 col-md-6"><div class="card text-white bg-success shadow h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><h5 class="card-title fs-2"><?php echo $total_resueltos; ?></h5><p class="card-text">Resueltos</p></div><i class="bi bi-check-circle-fill fs-1 opacity-50"></i></div></div></div>
    <div class="col-lg-3 col-md-6"><div class="card bg-light shadow h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><h5 class="card-title fs-2"><?php echo $total_tickets; ?></h5><p class="card-text">Total Activos</p></div><i class="bi bi-bar-chart-fill fs-1 opacity-50"></i></div></div></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-5"><div class="card h-100"><div class="card-header fw-bold"><i class="bi bi-pie-chart-fill"></i> Resumen por Estado</div><div class="card-body d-flex justify-content-center align-items-center"><canvas id="ticketsChartDonut" style="max-height: 300px;"></canvas></div></div></div>
    <div class="col-lg-7"><div class="card h-100"><div class="card-header fw-bold"><i class="bi bi-bar-chart-line-fill"></i> Tickets Creados (Últimos 3 Meses)</div><div class="card-body"><canvas id="ticketsChartBar" style="max-height: 300px;"></canvas></div></div></div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header fw-bold"><a class="text-decoration-none text-dark" data-bs-toggle="collapse" href="#collapseFilters" role="button" aria-expanded="true"><i class="bi bi-funnel-fill"></i> Filtros y Reportes</a></div>
    <div class="collapse show" id="collapseFilters">
        <div class="card-body">
            <form id="formFiltros" action="index.php" method="GET">
                <div class="row g-3">
                    <div class="col-lg-4 col-md-6"><label for="termino" class="form-label">Buscar por Asunto/ID:</label><input type="text" id="termino" name="termino" class="form-control" value="<?php echo htmlspecialchars($filtro_termino); ?>"></div>
                    <div class="col-lg-4 col-md-6"><label for="cliente" class="form-label">Cliente:</label><select id="cliente" name="cliente" class="form-select"><option value="">Todos</option><?php foreach($clientes_disponibles as $cliente): ?><option value="<?php echo $cliente['id_cliente']; ?>" <?php if($filtro_cliente == $cliente['id_cliente']) echo 'selected'; ?>><?php echo htmlspecialchars($cliente['nombre']); ?></option><?php endforeach; ?></select></div>
                    <?php if ($_SESSION['id_rol'] == 1): ?><div class="col-lg-4 col-md-6"><label for="agente" class="form-label">Agente:</label><select id="agente" name="agente" class="form-select"><option value="">Todos</option><?php foreach($agentes_disponibles as $agente): ?><option value="<?php echo $agente['id_agente']; ?>" <?php if($filtro_agente == $agente['id_agente']) echo 'selected'; ?>><?php echo htmlspecialchars($agente['nombre_completo']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
                    <div class="col-lg-2 col-md-6"><label for="prioridad" class="form-label">Prioridad:</label><select id="prioridad" name="prioridad" class="form-select"><option value="">Todas</option><option value="Baja" <?php if($filtro_prioridad == 'Baja') echo 'selected'; ?>>Baja</option><option value="Media" <?php if($filtro_prioridad == 'Media') echo 'selected'; ?>>Media</option><option value="Alta" <?php if($filtro_prioridad == 'Alta') echo 'selected'; ?>>Alta</option><option value="Urgente" <?php if($filtro_prioridad == 'Urgente') echo 'selected'; ?>>Urgente</option></select></div>
                    <div class="col-lg-2 col-md-6"><label for="estado_tabla" class="form-label">Estado Ticket:</label><select id="estado_tabla" name="estado_tabla" class="form-select"><option value="">Todos</option><option value="Abierto" <?php if($filtro_estado_tabla == 'Abierto') echo 'selected'; ?>>Abierto</option><option value="En Progreso" <?php if($filtro_estado_tabla == 'En Progreso') echo 'selected'; ?>>En Progreso</option><option value="En Espera" <?php if($filtro_estado_tabla == 'En Espera') echo 'selected'; ?>>En Espera</option><option value="Resuelto" <?php if($filtro_estado_tabla == 'Resuelto') echo 'selected'; ?>>Resuelto</option><option value="Cerrado" <?php if($filtro_estado_tabla == 'Cerrado') echo 'selected'; ?>>Cerrado</option><option value="Anulado" <?php if($filtro_estado_tabla == 'Anulado') echo 'selected'; ?>>Anulado</option></select></div>
                    <div class="col-lg-2 col-md-6"><label for="fecha_inicio" class="form-label">Fecha Inicio:</label><input type="date" id="fecha_inicio" name="fecha_inicio" class="form-control" value="<?php echo htmlspecialchars($filtro_fecha_inicio); ?>"></div>
                    <div class="col-lg-2 col-md-6"><label for="fecha_fin" class="form-label">Fecha Fin:</label><input type="date" id="fecha_fin" name="fecha_fin" class="form-control" value="<?php echo htmlspecialchars($filtro_fecha_fin); ?>"></div>
                    <?php if ($_SESSION['id_rol'] == 1): ?><div class="col-lg-2 col-md-6"><label for="facturacion" class="form-label">Estado Facturación:</label><select id="facturacion" name="facturacion" class="form-select"><option value="">Todos</option><option value="Pendiente" <?php if($filtro_facturacion == 'Pendiente') echo 'selected'; ?>>Pendiente</option><option value="Facturado" <?php if($filtro_facturacion == 'Facturado') echo 'selected'; ?>>Facturado</option><option value="Pagado" <?php if($filtro_facturacion == 'Pagado') echo 'selected'; ?>>Pagado</option><option value="Anulado" <?php if($filtro_facturacion == 'Anulado') echo 'selected'; ?>>Anulado</option></select></div><?php endif; ?>
                    <div class="col-lg-2 col-md-12 d-flex align-items-end"><button type="submit" class="btn btn-primary me-2">Filtrar</button><a href="index.php" class="btn btn-secondary">Limpiar</a></div>
                </div>
            </form>
            <?php if ($_SESSION['id_rol'] == 1): ?>
            <hr>
            <p class="small text-muted mb-2">La exportación aplicará los filtros de búsqueda actuales (excepto la búsqueda por texto).</p>
            <div><button type="button" onclick="exportar('excel')" class="btn btn-success"><i class="bi bi-file-earmark-excel-fill"></i> Excel</button> <button type="button" onclick="exportar('pdf')" class="btn btn-danger"><i class="bi bi-file-earmark-pdf-fill"></i> PDF</button> <button type="button" onclick="exportar('imprimir')" class="btn btn-info"><i class="bi bi-printer-fill"></i> Imprimir</button></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><?php echo (empty(array_filter([$filtro_termino, $filtro_cliente, $filtro_agente, $filtro_prioridad, $filtro_estado_tabla, $filtro_facturacion, $filtro_fecha_inicio, $filtro_fecha_fin]))) ? 'Mis Tickets' : 'Resultados de la Búsqueda'; ?></h2>
    <?php if ($_SESSION['id_rol'] == 1): ?>
    <a href="crear_ticket.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Crear Nuevo Ticket</a>
    <?php endif; ?>
</div>
<?php echo $mensaje_exito; ?>
<div class="card">
    <div class="card-header fw-bold"><i class="bi bi-table"></i> Lista de Tickets (<?php echo count($tickets); ?> encontrados)</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>SLA</th>
                        <th>ID</th>
                        <th>Asunto</th>
                        <th>Cliente</th>
                        <th>Agente</th>
                        <th>Tipo de Caso</th>
                        <th>Estado</th>
                        <th>Prioridad</th>
                        <th>Fecha</th>
                        <?php if ($_SESSION['id_rol'] == 1): ?>
                            <th>Costo</th>
                            <th>Moneda</th>
                            <th>Est. Facturación</th>
                        <?php endif; ?>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr><td colspan="<?php echo ($_SESSION['id_rol'] == 1) ? '13' : '10'; ?>" class="text-center">No se encontraron tickets con los filtros aplicados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td class="text-center">
                                    <?php
                                    $sla_status = ''; $sla_class = ''; $sla_icon = '';
                                    if ($ticket['fecha_vencimiento'] && !in_array($ticket['estado'], ['Resuelto', 'Cerrado', 'Anulado'])) {
                                        $ahora = new DateTime(); $vencimiento = new DateTime($ticket['fecha_vencimiento']); $diferencia = $ahora->diff($vencimiento);
                                        if ($ahora > $vencimiento) { $sla_status = 'Vencido'; $sla_class = 'text-danger'; $sla_icon = 'bi-x-circle-fill'; } 
                                        elseif ($diferencia->days < 2) { $sla_status = 'Por Vencer'; $sla_class = 'text-warning'; $sla_icon = 'bi-exclamation-triangle-fill'; }
                                    }
                                    if ($sla_status): ?><i class="bi <?php echo $sla_icon; ?> <?php echo $sla_class; ?>" title="<?php echo $sla_status; ?>"></i><?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($ticket['id_ticket']); ?></td>
                                <td><?php echo htmlspecialchars($ticket['asunto']); ?></td>
                                <td><?php echo htmlspecialchars($ticket['nombre_cliente']); ?></td>
                                <td><?php echo htmlspecialchars($ticket['nombre_agente'] ?? 'Sin asignar'); ?></td>
                                <td><?php echo htmlspecialchars($ticket['nombre_tipo'] ?? 'N/A'); ?></td>
                                <td><span class="badge bg-<?php echo $status_classes[$ticket['estado']] ?? 'light'; ?>"><?php echo htmlspecialchars($ticket['estado']); ?></span></td>
                                <td><span class="badge bg-<?php echo $priority_classes[$ticket['prioridad']] ?? 'light'; ?>"><?php echo htmlspecialchars($ticket['prioridad']); ?></span></td>
                                <td><?php echo date('d/m/Y', strtotime($ticket['fecha_creacion'])); ?></td>
                                <?php if ($_SESSION['id_rol'] == 1): ?>
                                    <td><?php echo $ticket['costo'] ? number_format($ticket['costo'], 2) : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($ticket['moneda'] ?? 'N/A'); ?></td>
                                    <td><span class="badge bg-<?php echo $facturacion_classes[$ticket['estado_facturacion']] ?? 'light'; ?>"><?php echo htmlspecialchars($ticket['estado_facturacion'] ?? 'N/A'); ?></span></td>
                                <?php endif; ?>
                                <td><a href="ver_ticket.php?id=<?php echo $ticket['id_ticket']; ?>" class="btn btn-sm btn-primary">Ver</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function exportar(formato) {
    const form = document.getElementById('formFiltros');
    const params = new URLSearchParams(new FormData(form)).toString();
    let url = '';
    if (formato === 'excel') { url = `exportar_excel.php?${params}`; } 
    else if (formato === 'pdf') { url = `exportar_pdf.php?${params}`; } 
    else if (formato === 'imprimir') { url = `imprimir_tickets.php?${params}`; }
    if (url) { formato === 'imprimir' ? window.open(url, '_blank') : window.location.href = url; }
}
document.addEventListener("DOMContentLoaded", function() {
    if (document.getElementById('ticketsChartDonut')) {
        const ctxDonut = document.getElementById('ticketsChartDonut').getContext('2d');
        new Chart(ctxDonut, { type: 'doughnut', data: { labels: <?php echo $chart_labels_donut_json; ?>, datasets: [{ label: 'Tickets', data: <?php echo $chart_values_donut_json; ?>, backgroundColor: ['#0d6efd', '#ffc107', '#198754', '#6c757d', '#0dcaf0', '#fd7e14'], hoverOffset: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' }}}});
    }
    if (document.getElementById('ticketsChartBar')) {
        const ctxBar = document.getElementById('ticketsChartBar').getContext('2d');
        new Chart(ctxBar, { type: 'bar', data: { labels: <?php echo $chart_labels_bar_json; ?>, datasets: [{ label: 'Tickets Creados', data: <?php echo $chart_values_bar_json; ?>, backgroundColor: 'rgba(54, 162, 235, 0.6)', borderColor: 'rgba(54, 162, 235, 1)', borderWidth: 1 }] }, options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 }}}, plugins: { legend: { display: false }}}});
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>