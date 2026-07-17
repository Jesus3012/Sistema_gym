<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (!defined('LEGAL_AVISO_VERSION')) {
    define('LEGAL_AVISO_VERSION', '1.0');
}

if (!defined('LEGAL_TERMINOS_VERSION')) {
    define('LEGAL_TERMINOS_VERSION', '1.0');
}

if (!defined('LEGAL_VERSION_FECHA')) {
    define('LEGAL_VERSION_FECHA', '15/07/2026');
}

if (!defined('LEGAL_RESPONSABLE_NOMBRE')) {
    define('LEGAL_RESPONSABLE_NOMBRE', 'RexCoreSolutions');
}

if (!defined('LEGAL_RESPONSABLE_EMAIL')) {
    define(
        'LEGAL_RESPONSABLE_EMAIL',
        'rexcoresolutions@gmail.com'
    );
}

function legal_h($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function legal_base_url()
{
    $scriptName = str_replace(
        '\\',
        '/',
        (string) ($_SERVER['SCRIPT_NAME'] ?? '')
    );

    $baseUrl = rtrim(
        str_replace('\\', '/', dirname($scriptName)),
        '/'
    );

    if ($baseUrl === '.' || $baseUrl === '/') {
        return '';
    }

    return $baseUrl;
}

function legal_redirect($destination)
{
    $destination = (string) $destination;

    if (!headers_sent()) {
        header('Location: ' . $destination);
        exit();
    }

    $json = json_encode(
        $destination,
        JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
    );

    echo '<script>window.location.replace(' . $json . ');</script>';
    exit();
}

function legal_current_local_url()
{
    $uri = trim((string) ($_SERVER['REQUEST_URI'] ?? ''));

    if (
        $uri === ''
        || preg_match('/[\r\n]/', $uri)
    ) {
        return legal_base_url() . '/dashboard.php';
    }

    $parts = parse_url($uri);

    if (
        $parts === false
        || isset($parts['scheme'])
        || isset($parts['host'])
    ) {
        return legal_base_url() . '/dashboard.php';
    }

    $path = (string) ($parts['path'] ?? '');
    $query = isset($parts['query'])
        ? '?' . (string) $parts['query']
        : '';

    if (
        $path === ''
        || strpos($path, '..') !== false
    ) {
        return legal_base_url() . '/dashboard.php';
    }

    return $path . $query;
}

function legal_safe_return_url($value)
{
    $value = trim((string) $value);

    if (
        $value === ''
        || preg_match('/[\r\n]/', $value)
    ) {
        return legal_base_url() . '/dashboard.php';
    }

    $parts = parse_url($value);

    if (
        $parts === false
        || isset($parts['scheme'])
        || isset($parts['host'])
    ) {
        return legal_base_url() . '/dashboard.php';
    }

    $path = (string) ($parts['path'] ?? '');
    $query = isset($parts['query'])
        ? '?' . (string) $parts['query']
        : '';

    if (
        $path === ''
        || strpos($path, '..') !== false
        || basename($path) === 'legal.php'
    ) {
        return legal_base_url() . '/dashboard.php';
    }

    return $path . $query;
}

function legal_is_ajax()
{
    $accept = strtolower(
        (string) ($_SERVER['HTTP_ACCEPT'] ?? '')
    );

    $requestedWith = strtolower(
        (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')
    );

    return strpos($accept, 'application/json') !== false
        || $requestedWith === 'xmlhttprequest';
}

function legal_get_database()
{
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new RuntimeException(
            'No fue posible conectar con la base de datos.'
        );
    }

    $db->set_charset('utf8mb4');

    return $db;
}

function legal_ensure_table($db)
{
    /*
     * Evidencia mínima de aceptación:
     * usuario, versiones, hashes y fecha.
     */
    $sql = "
        CREATE TABLE IF NOT EXISTS usuarios_aceptacion_legal_v2 (
            usuario_id INT(11) NOT NULL,
            aviso_version VARCHAR(30) NOT NULL,
            aviso_hash CHAR(64) NOT NULL,
            terminos_version VARCHAR(30) NOT NULL,
            terminos_hash CHAR(64) NOT NULL,
            fecha_aceptacion DATETIME NOT NULL,
            updated_at TIMESTAMP NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (usuario_id),
            KEY idx_legal_fecha (fecha_aceptacion)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ";

    if (!$db->query($sql)) {
        throw new RuntimeException(
            'No fue posible crear la tabla legal: '
            . $db->error
        );
    }

    $result = $db->query(
        "SHOW COLUMNS
         FROM usuarios_aceptacion_legal_v2"
    );

    if (!$result) {
        throw new RuntimeException(
            'No fue posible verificar la tabla legal.'
        );
    }

    $columns = [];

    while ($row = $result->fetch_assoc()) {
        $field = (string) ($row['Field'] ?? '');

        if ($field !== '') {
            $columns[] = $field;
        }
    }

    $required = [
        'usuario_id',
        'aviso_version',
        'aviso_hash',
        'terminos_version',
        'terminos_hash',
        'fecha_aceptacion',
    ];

    if (count(array_diff($required, $columns)) !== 0) {
        throw new RuntimeException(
            'La tabla legal existente no tiene la estructura requerida.'
        );
    }

    /*
     * Limpieza de datos técnicos guardados por versiones anteriores.
     * Si ALTER no está permitido, los valores quedan vacíos y el sistema
     * deja de consultar y guardar esas columnas.
     */
    $legacyColumns = [
        'ip_aceptacion',
        'user_agent',
        'session_hash',
    ];

    foreach ($legacyColumns as $legacyColumn) {
        if (!in_array($legacyColumn, $columns, true)) {
            continue;
        }

        $db->query(
            "UPDATE usuarios_aceptacion_legal_v2
             SET `{$legacyColumn}` = NULL"
        );

        if (
            !$db->query(
                "ALTER TABLE usuarios_aceptacion_legal_v2
                 DROP COLUMN `{$legacyColumn}`"
            )
        ) {
            error_log(
                '[Legal] No se pudo eliminar la columna heredada '
                . $legacyColumn
                . ': '
                . $db->error
            );
        }
    }
}

function legal_get_gym_config($db)
{
    $config = [
        'nombre' => 'Gimnasio',
        'direccion' => 'Domicilio registrado por el responsable',
        'email' => '',
        'telefono' => 'No disponible',
        'logo' => '',
    ];

    $result = $db->query(
        "SELECT nombre, direccion, email, telefono, logo
         FROM configuracion_gimnasio
         WHERE id = 1
         LIMIT 1"
    );

    if ($result && $row = $result->fetch_assoc()) {
        foreach (array_keys($config) as $field) {
            $value = trim(
                (string) ($row[$field] ?? '')
            );

            if ($value !== '') {
                $config[$field] = $value;
            }
        }
    }

    if ($config['email'] === '') {
        $resultMail = $db->query(
            "SELECT remitente_email
             FROM configuracion_correo
             WHERE id = 1
             LIMIT 1"
        );

        if (
            $resultMail
            && $mailRow = $resultMail->fetch_assoc()
        ) {
            $config['email'] = trim(
                (string) (
                    $mailRow['remitente_email']
                    ?? ''
                )
            );
        }
    }

    if ($config['email'] === '') {
        $config['email'] = 'Correo no configurado';
    }

    return $config;
}

function legal_get_documents($config)
{
    $company = legal_h(LEGAL_RESPONSABLE_NOMBRE);
    $contactEmail = legal_h(LEGAL_RESPONSABLE_EMAIL);
    $gymName = legal_h(
        trim((string) ($config['nombre'] ?? 'Gimnasio'))
    );
    $date = legal_h(LEGAL_VERSION_FECHA);

    $privacy = <<<HTML
<h2>1. Empresa responsable de la plataforma</h2>
<p>
    <strong>{$company}</strong> es la empresa responsable de la operación,
    administración técnica, soporte y mantenimiento de esta plataforma.
</p>
<p>
    Para asuntos relacionados con privacidad, acceso o tratamiento de los
    datos de cuenta puede utilizarse el correo
    <strong>{$contactEmail}</strong>.
</p>

<h2>2. Alcance del aviso</h2>
<p>
    Este aviso corresponde a administradores, recepcionistas,
    entrenadores y demás usuarios internos autorizados para utilizar la
    aplicación.
</p>
<p>
    La plataforma es utilizada por <strong>{$gymName}</strong> para sus
    operaciones internas. Los datos de socios o clientes capturados por
    el establecimiento deben tratarse conforme al aviso específico que
    dicho establecimiento ponga a disposición de sus titulares.
</p>

<h2>3. Datos personales tratados</h2>
<ul>
    <li>Nombre, correo electrónico, fotografía, rol y estado de la cuenta.</li>
    <li>Contraseña protegida mediante hash, sesiones y cambios de acceso.</li>
    <li>Fechas y horarios necesarios para administrar la cuenta y sus operaciones.</li>
    <li>Operaciones realizadas en ventas, caja, membresías, asistencias, inventario, clases y configuraciones.</li>
    <li>Usuario, versiones, hashes y fecha asociados con la aceptación de los documentos legales.</li>
</ul>

<h2>4. Finalidades necesarias</h2>
<ul>
    <li>Crear, aprobar, autenticar y administrar la cuenta.</li>
    <li>Aplicar permisos de acuerdo con el rol asignado.</li>
    <li>Atribuir y auditar operaciones realizadas dentro del sistema.</li>
    <li>Prevenir accesos no autorizados, alteraciones y abuso.</li>
    <li>Enviar comunicaciones operativas y recuperación de contraseña.</li>
    <li>Dar soporte, diagnosticar fallos y proteger la aplicación.</li>
    <li>Cumplir obligaciones legales, contractuales y administrativas.</li>
</ul>

<h2>5. Información de socios y clientes</h2>
<p>
    El usuario puede acceder, de acuerdo con su rol, a información de
    socios o clientes, como datos de contacto, membresías, pagos,
    asistencias, códigos QR y, cuando se utilicen, datos biométricos.
    Esta información es confidencial y solo puede tratarse para las
    funciones expresamente autorizadas.
</p>

<h2>6. Proveedores y comunicaciones</h2>
<p>
    Los datos podrán ser tratados por proveedores indispensables de
    hospedaje, correo electrónico, respaldo, soporte, seguridad o pagos,
    así como por autoridades cuando exista una obligación legal.
    Los datos personales no se venden.
</p>

<h2>7. Sesiones y almacenamiento del navegador</h2>
<p>
    La aplicación utiliza identificadores de sesión necesarios para
    autenticar y proteger la cuenta. Puede usar almacenamiento local para
    recordar información no sensible, pero no debe guardar contraseñas en
    texto legible.
</p>

<h2>8. Conservación</h2>
<p>
    La información se conservará durante el tiempo necesario para
    administrar el acceso, mantener auditoría, atender controversias,
    cumplir obligaciones y preservar evidencia de operaciones.
</p>

<h2>9. Derechos del titular</h2>
<p>
    El titular puede solicitar acceso, rectificación, cancelación u
    oposición, así como revocar consentimientos cuando proceda, mediante
    el correo <strong>{$contactEmail}</strong>. La solicitud deberá
    identificar al titular y describir el derecho que desea ejercer.
</p>

<h2>10. Seguridad</h2>
<p>
    Se utilizan controles de sesión, permisos por rol, hashes de
    contraseña, registros de actividad y validaciones. El usuario debe
    proteger sus credenciales, cerrar sesión en equipos compartidos y
    reportar accesos no reconocidos.
</p>

<h2>11. Modificaciones</h2>
<p>
    Cuando exista un cambio material se incrementará la versión del
    documento y se solicitará una nueva aceptación.
</p>

<div class="legal-document-note">
    Versión publicada el {$date}.
</div>
HTML;

    $terms = <<<HTML
<h2>1. Aceptación obligatoria</h2>
<p>
    Estos términos regulan el acceso y uso interno de la aplicación
    desarrollada y administrada por <strong>{$company}</strong>.
    Si el usuario no acepta ambos documentos, no podrá ingresar a los
    módulos protegidos.
</p>

<h2>2. Autorización limitada de uso</h2>
<p>
    Se concede una autorización personal, limitada, revocable, no
    exclusiva y no transferible para realizar únicamente las funciones
    asignadas. El acceso no transmite la propiedad del software, el
    diseño, la base de datos ni la documentación.
</p>

<h2>3. Cuenta y credenciales</h2>
<ul>
    <li>La cuenta y la contraseña son personales y no pueden compartirse.</li>
    <li>Está prohibido utilizar cuentas ajenas o intentar aumentar privilegios.</li>
    <li>El usuario debe cerrar sesión en dispositivos compartidos.</li>
    <li>Debe reportar inmediatamente cualquier actividad no reconocida.</li>
</ul>

<h2>4. Uso autorizado</h2>
<p>
    El sistema solo puede utilizarse para las operaciones autorizadas:
    socios, inscripciones, pagos, ventas, inventario, clases, asistencias,
    caja, reportes, notificaciones y configuraciones permitidas por el rol.
</p>

<h2>5. Conductas prohibidas</h2>
<ul>
    <li>Consultar, modificar, eliminar o exportar información sin autorización.</li>
    <li>Alterar registros para obtener un beneficio o causar un perjuicio.</li>
    <li>Introducir archivos, código o instrucciones destinados a vulnerar el sistema.</li>
    <li>Desactivar permisos, sesiones, validaciones, auditoría o seguridad.</li>
    <li>Compartir información, credenciales o configuraciones con terceros.</li>
</ul>

<div class="legal-protection-block">
    <h2>6. Protección de la aplicación y prohibición de plagio</h2>

    <p>
        El código fuente y objeto, la arquitectura, estructura de base de
        datos, documentación, textos, plantillas, correos, reportes,
        archivos PDF, flujos de trabajo, elementos gráficos originales y
        la composición particular de la interfaz pertenecen a
        <strong>{$company}</strong> o se utilizan con autorización.
    </p>

    <p>
        El acceso <strong>no autoriza</strong> copiar, reproducir,
        distribuir, vender, sublicenciar, adaptar o utilizar los elementos
        originales o confidenciales para construir otra aplicación.
    </p>

    <div class="legal-copy-warning">
        <strong>Queda expresamente prohibido:</strong>
        <ul>
            <li>Copiar total o sustancialmente la interfaz o su composición original.</li>
            <li>Extraer o reutilizar PHP, HTML, CSS, JavaScript, SQL, imágenes, textos, componentes o plantillas sin autorización.</li>
            <li>Tomar capturas, grabaciones o documentación con el propósito de clonar la aplicación.</li>
            <li>Entregar código, accesos o materiales a terceros para desarrollar un producto derivado o confundible.</li>
            <li>Realizar ingeniería inversa, decompilación o desensamblaje fuera de los casos obligatoriamente permitidos.</li>
            <li>Eliminar avisos de titularidad, controles de acceso, registros o medidas de seguridad.</li>
        </ul>
    </div>

    <p>
        La aplicación puede utilizar registros y hashes necesarios para
        proteger su integridad y detectar usos indebidos. El incumplimiento
        puede producir bloqueo de cuenta, preservación de evidencia,
        medidas laborales o contractuales y las acciones que correspondan.
    </p>

    <p>
        Esta cláusula no pretende apropiarse de ideas, funciones generales
        o patrones de uso común. Se refiere al código, la expresión
        original, la combinación particular de elementos y la información
        confidencial.
    </p>
</div>

<h2>7. Confidencialidad</h2>
<p>
    La información de socios, empleados, proveedores, ventas, pagos,
    membresías, huellas, códigos QR, reportes, configuraciones y
    credenciales es confidencial. Esta obligación continúa después de
    terminar el acceso o la relación con el establecimiento.
</p>

<h2>8. Auditoría</h2>
<p>
    El sistema puede registrar cambios, aprobaciones y operaciones para
    seguridad y auditoría. Está prohibido intentar modificar o eliminar
    dichos registros.
</p>

<h2>9. Disponibilidad y mantenimiento</h2>
<p>
    La aplicación puede suspenderse temporalmente por mantenimiento,
    actualización o seguridad. El usuario debe reportar errores y
    abstenerse de explotarlos.
</p>

<h2>10. Suspensión del acceso</h2>
<p>
    La cuenta podrá bloquearse por incumplimiento, riesgo de seguridad,
    cambio de funciones, inactividad o terminación de la relación laboral,
    profesional o contractual.
</p>

<h2>11. Nuevas versiones</h2>
<p>
    Cuando los términos cambien materialmente se solicitará nuevamente la
    aceptación antes de permitir el acceso.
</p>

<h2>12. Evidencia electrónica</h2>
<p>
    La aceptación registra únicamente la cuenta del usuario, las versiones,
    los hashes y la fecha de aceptación.
</p>

<div class="legal-document-note">
    Versión publicada el {$date}. Contacto:
    <strong>{$contactEmail}</strong>.
</div>
HTML;

    $normalize = static function ($content) {
        return (string) preg_replace(
            '/\s+/',
            ' ',
            trim((string) $content)
        );
    };

    return [
        'aviso' => [
            'version' => LEGAL_AVISO_VERSION,
            'title' => 'Aviso de privacidad',
            'content' => $privacy,
            'hash' => hash(
                'sha256',
                $normalize($privacy)
            ),
        ],
        'terminos' => [
            'version' => LEGAL_TERMINOS_VERSION,
            'title' => 'Términos y condiciones',
            'content' => $terms,
            'hash' => hash(
                'sha256',
                $normalize($terms)
            ),
        ],
    ];
}

function legal_get_acceptance($db, $userId)
{
    $stmt = $db->prepare(
        "SELECT
            aviso_version,
            aviso_hash,
            terminos_version,
            terminos_hash,
            fecha_aceptacion
         FROM usuarios_aceptacion_legal_v2
         WHERE usuario_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible consultar la aceptación legal.'
        );
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    return $row ?: null;
}

function legal_acceptance_is_current($acceptance, $documents)
{
    if (!$acceptance) {
        return false;
    }

    /*
     * La aceptación se invalida únicamente cuando cambia la versión
     * oficial del aviso o de los términos.
     *
     * Los hashes siguen guardándose como evidencia del contenido
     * aceptado, pero cambios normales en nombre, correo, teléfono,
     * dirección o logo del gimnasio ya no obligan a aceptar de nuevo.
     */
    return
        (string) ($acceptance['aviso_version'] ?? '')
            === (string) ($documents['aviso']['version'] ?? '')
        && (string) ($acceptance['terminos_version'] ?? '')
            === (string) ($documents['terminos']['version'] ?? '');
}

function legal_save_acceptance($db, $userId, $documents)
{
    $stmt = $db->prepare(
        "INSERT INTO usuarios_aceptacion_legal_v2
            (
                usuario_id,
                aviso_version,
                aviso_hash,
                terminos_version,
                terminos_hash,
                fecha_aceptacion
            )
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            aviso_version = VALUES(aviso_version),
            aviso_hash = VALUES(aviso_hash),
            terminos_version = VALUES(terminos_version),
            terminos_hash = VALUES(terminos_hash),
            fecha_aceptacion = NOW()"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible preparar el registro legal.'
        );
    }

    $avisoVersion = (string) $documents['aviso']['version'];
    $avisoHash = (string) $documents['aviso']['hash'];
    $terminosVersion = (string) $documents['terminos']['version'];
    $terminosHash = (string) $documents['terminos']['hash'];

    $stmt->bind_param(
        'issss',
        $userId,
        $avisoVersion,
        $avisoHash,
        $terminosVersion,
        $terminosHash
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'No fue posible guardar la aceptación: '
            . $error
        );
    }

    $stmt->close();
}

function legal_user_is_admin()
{
    $role = strtolower(
        trim((string) ($_SESSION['user_rol'] ?? ''))
    );

    return in_array(
        $role,
        ['admin', 'administrador'],
        true
    );
}

function legal_require_acceptance()
{
    if (empty($_SESSION['user_id'])) {
        return;
    }

    $currentPage = basename(
        (string) parse_url(
            $_SERVER['PHP_SELF'] ?? '',
            PHP_URL_PATH
        )
    );

    /*
     * legal.php debe poder abrirse aunque el usuario no haya aceptado.
     * También se evita aplicar el guard a endpoints públicos.
     */
    $exceptions = [
        'legal.php',
        'login.php',
        'logout.php',
        'recuperar-password.php',
        'restablecer-password.php',
    ];

    if (in_array($currentPage, $exceptions, true)) {
        return;
    }

    try {
        $db = legal_get_database();
        legal_ensure_table($db);

        $config = legal_get_gym_config($db);
        $documents = legal_get_documents($config);

        $acceptance = legal_get_acceptance(
            $db,
            (int) $_SESSION['user_id']
        );

        if (
            legal_acceptance_is_current(
                $acceptance,
                $documents
            )
        ) {
            return;
        }

        $returnUrl = legal_current_local_url();
        $legalUrl = legal_base_url()
            . '/legal.php?obligatorio=1&return='
            . rawurlencode($returnUrl);

        if (legal_is_ajax()) {
            http_response_code(428);
            header(
                'Content-Type: application/json; charset=UTF-8'
            );

            echo json_encode(
                [
                    'success' => false,
                    'requires_legal_acceptance' => true,
                    'message' =>
                        'Debes aceptar el aviso de privacidad y los términos.',
                    'redirect' => $legalUrl,
                ],
                JSON_UNESCAPED_UNICODE
            );

            exit();
        }

        legal_redirect($legalUrl);
    } catch (Throwable $error) {
        error_log(
            '[Legal guard] ' . $error->getMessage()
        );

        http_response_code(500);

        $message =
            'No fue posible validar la aceptación legal. ' .
            'Verifica los permisos de la base de datos.';

        if (legal_user_is_admin()) {
            $message .=
                ' Detalle: '
                . legal_h($error->getMessage());
        }

        exit($message);
    }
}
