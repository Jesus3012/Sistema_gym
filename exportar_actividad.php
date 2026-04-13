<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();
$user_id = $_SESSION['user_id'];
$format = $_GET['format'] ?? 'pdf';

// Obtener actividades
$query = "SELECT 'venta' as tipo, id, fecha_venta as fecha, total as monto FROM ventas WHERE usuario_id = ?
          UNION ALL
          SELECT 'asistencia' as tipo, id, CONCAT(fecha, ' ', hora_entrada) as fecha, NULL as monto FROM asistencias WHERE verificado_por = ?
          ORDER BY fecha DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$actividades = $stmt->get_result();

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="mi_actividad.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Tipo', 'ID', 'Fecha', 'Monto']);
    
    while ($row = $actividades->fetch_assoc()) {
        fputcsv($output, [
            $row['tipo'] === 'venta' ? 'Venta' : 'Asistencia',
            $row['id'],
            $row['fecha'],
            $row['monto'] ? '$' . number_format($row['monto'], 2) : '-'
        ]);
    }
    fclose($output);
} else {
    // Generar PDF (requiere FPDF)
    require_once 'fpdf/fpdf.php';
    
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Mi Actividad', 0, 1, 'C');
    $pdf->Ln(10);
    
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(40, 8, 'Tipo', 1);
    $pdf->Cell(30, 8, 'ID', 1);
    $pdf->Cell(70, 8, 'Fecha', 1);
    $pdf->Cell(40, 8, 'Monto', 1);
    $pdf->Ln();
    
    $pdf->SetFont('Arial', '', 10);
    while ($row = $actividades->fetch_assoc()) {
        $pdf->Cell(40, 8, $row['tipo'] === 'venta' ? 'Venta' : 'Asistencia', 1);
        $pdf->Cell(30, 8, $row['id'], 1);
        $pdf->Cell(70, 8, $row['fecha'], 1);
        $pdf->Cell(40, 8, $row['monto'] ? '$' . number_format($row['monto'], 2) : '-', 1);
        $pdf->Ln();
    }
    
    $pdf->Output('D', 'mi_actividad.pdf');
}
?>