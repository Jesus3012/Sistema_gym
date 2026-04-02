<?php
// api/capturar_huella.php
header('Content-Type: application/json');

// Configuración del lector USB
// Reemplaza esto con la configuración de tu lector específico
function capturarHuellaReal() {
    // ========== INTEGRACIÓN CON TU LECTOR USB ==========
    
    // Opción 1: Usar SDK de PHP (ej: DigitalPersona, SecuGen)
    /*
    require_once('path/to/fingerprint/sdk.php');
    $scanner = new FingerprintScanner();
    $result = $scanner->capture();
    
    if ($result['success']) {
        return [
            'success' => true,
            'huella_data' => $result['data'],
            'template' => $result['template']
        ];
    }
    */
    
    // Opción 2: Llamar a un script externo (Python, Node.js)
    /*
    $output = shell_exec('python ../scripts/fingerprint_scanner.py 2>&1');
    $result = json_decode($output, true);
    if ($result && $result['success']) {
        return $result;
    }
    */
    
    // Opción 3: Comunicación directa por USB (usando librería php-serial)
    /*
    require_once('vendor/php-serial/php_serial.class.php');
    $serial = new phpSerial();
    $serial->deviceSet("/dev/ttyUSB0");
    $serial->confBaudRate(9600);
    $serial->confParity("none");
    $serial->confCharacterLength(8);
    $serial->confStopBits(1);
    $serial->deviceOpen();
    $serial->sendMessage("CAPTURE");
    $response = $serial->readPort();
    $serial->deviceClose();
    
    if ($response) {
        return [
            'success' => true,
            'huella_data' => base64_encode($response)
        ];
    }
    */
    
    // Temporal: simulación
    return [
        'success' => true,
        'huella_data' => 'FP_' . date('YmdHis') . '_' . uniqid(),
        'template' => base64_encode('fingerprint_template_' . uniqid())
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'capturar') {
        $result = capturarHuellaReal();
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
}
?>