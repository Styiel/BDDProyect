<?php
// =====================================================
//  PANEL SIMPLE PARA MARIADB — MODO MAESTRO
//  - Ver bases de datos y tablas
//  - Ver primeros 100 registros de una tabla
//  - Ejecutar SQL libre (INSERT/UPDATE/DELETE/DDL/SELECT)
// =====================================================

// Evitar que mysqli lance excepciones fatales (usar códigos de error)
mysqli_report(MYSQLI_REPORT_OFF);

// ======================
// CONFIGURACIÓN BÁSICA
// ======================
$DB_HOST = '127.0.0.1';
$DB_USER = 'webadmin_master'
$DB_PASS = 'TuPassw0rdWeb!';
$DB_PORT = 3306;

// Modo: 'master' = puede ejecutar cualquier SQL
//       'slave'  = solo SELECT/SHOW (por si luego copias este archivo a un esclavo)
$MODE = 'master';

// ======================
// CONEXIÓN
// ======================
$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, '', $DB_PORT);
if ($mysqli->connect_error) {
    die("Error de conexión a MariaDB: " . htmlspecialchars($mysqli->connect_error));
}

// Parámetros de navegación
$currentDb    = isset($_GET['db']) ? $_GET['db'] : null;
$currentTable = isset($_GET['table']) ? $_GET['table'] : null;

// ======================
// OBTENER LISTA DE BD
// ======================
$databases = [];
if ($res = $mysqli->query("SHOW DATABASES")) {
    while ($row = $res->fetch_row()) {
        $databases[] = $row[0];
    }
    $res->close();
}

// ======================
// MANEJO DE SQL MANUAL
// ======================
$sqlInput  = isset($_POST['sql']) ? trim($_POST['sql']) : '';
$sqlResult = null;
$sqlError  = '';

if ($sqlInput !== '') {
    $sqlToRun = $sqlInput;

    // Si hay BD seleccionada en la UI, la usamos como contexto
    if ($currentDb) {
        $dbEsc = $mysqli->real_escape_string($currentDb);
        if (!$mysqli->select_db($dbEsc)) {
            $sqlError = "No se pudo seleccionar la BD '$currentDb': " . $mysqli->error;
        }
    }

    // En modo esclavo podríamos limitar a SELECT/SHOW, pero aquí estamos en master
    if ($MODE === 'slave' && $sqlError === '') {
        // En modo esclavo solo permitir SELECT o SHOW
        if (!preg_match('/^\s*(SELECT|SHOW)\b/i', $sqlToRun)) {
            $sqlError = "Este servidor está en modo SOLO LECTURA. Solo se permiten sentencias SELECT o SHOW.";
        }
    }

    // Ejecutar la sentencia si no hay errores previos
    if ($sqlError === '') {
        $res = $mysqli->query($sqlToRun);
        if ($res instanceof mysqli_result) {
            $sqlResult = $res;
        } else {
            if ($mysqli->errno) {
                $sqlError = $mysqli->error;
            }
        }
    }
}

// ======================
// SI HAY BD Y TABLA, LEER DATOS
// ======================
$tableData    = null;
$tableFields  = [];
$tableError   = '';

if ($currentDb && $currentTable) {
    $dbEsc    = $mysqli->real_escape_string($currentDb);
    $tableEsc = $mysqli->real_escape_string($currentTable);

    if (!$mysqli->select_db($dbEsc)) {
        $tableError = "No se pudo seleccionar la BD: " . htmlspecialchars($mysqli->error);
    } else {
        $query = "SELECT * FROM `" . $tableEsc . "` LIMIT 100;";
        if ($res = $mysqli->query($query)) {
            $tableData   = $res;
            $tableFields = $res->fetch_fields();
        } else {
            $tableError = $mysqli->error;
        }
    }
}

// ======================
// OBTENER TABLAS DE LA BD ACTUAL
// ======================
$tables = [];
if ($currentDb) {
    $dbEsc = $mysqli->real_escape_string($currentDb);
    if ($res = $mysqli->query("SHOW TABLES FROM `" . $dbEsc . "`")) {
        while ($row = $res->fetch_row()) {
            $tables[] = $row[0];
        }
        $res->close();
    }
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Panel MariaDB - <?php echo htmlspecialchars(gethostname()); ?></title>
  <style>
    body{font-family: Arial, sans-serif; margin:0; background:#f4f4f4;}
    .header{
      padding:10px 20px;
      background:#222;
      color:#fff;
      display:flex;
      justify-content:space-between;
      align-items:center;
    }
    .header h1{margin:0;font-size:18px;}
    .wrap{display:flex; min-height:calc(100vh - 50px);}
    .sidebar{
      width:220px;
      background:#333;
      color:#eee;
      padding:10px;
      overflow-y:auto;
    }
    .sidebar h2{
      font-size:14px;
      margin:10px 0 5px;
      border-bottom:1px solid #555;
      padding-bottom:3px;
    }
    .sidebar a{
      color:#ddd;
      text-decoration:none;
      display:block;
      padding:3px 4px;
      font-size:13px;
      border-radius:3px;
    }
    .sidebar a:hover{
      background:#555;
    }
    .sidebar .active{
      background:#0b79d0;
      color:#fff;
    }
    .main{
      flex:1;
      padding:15px 20px;
      overflow-x:auto;
    }
    h2{margin-top:0;}
    table{border-collapse:collapse; margin-top:10px; background:#fff;}
    th,td{border:1px solid #ccc; padding:4px 8px; font-size:12px; white-space:nowrap;}
    th{background:#eee;}
    textarea{width:100%; min-height:100px; font-family:Consolas,monospace; font-size:12px;}
    .error{color:#c00; font-weight:bold; margin-top:10px;}
    .ok{color:#080; font-weight:bold; margin-top:10px;}
    .card{
      background:#fff;
      border-radius:4px;
      padding:10px 12px;
      box-shadow:0 1px 2px rgba(0,0,0,0.1);
      margin-bottom:15px;
    }
    .badge{
      display:inline-block;
      padding:2px 6px;
      font-size:11px;
      border-radius:3px;
      background:#555;
      color:#fff;
    }
    .badge-master{background:#0b79d0;}
    .badge-slave{background:#6c757d;}
    button{
      margin-top:6px;
      padding:6px 12px;
      font-size:13px;
      cursor:pointer;
    }
  </style>
</head>
<body>
  <div class="header">
    <h1>Panel MariaDB - <?php echo htmlspecialchars(gethostname()); ?></h1>
    <div>
      Modo:
      <?php if ($MODE === 'master'): ?>
        <span class="badge badge-master">MASTER (lectura y escritura)</span>
      <?php else: ?>
        <span class="badge badge-slave">SLAVE (solo lectura)</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="wrap">
    <div class="sidebar">
      <h2>Bases de datos</h2>
      <?php foreach ($databases as $db): ?>
        <?php
          $class = ($db === $currentDb) ? 'active' : '';
          $url   = '?db=' . urlencode($db);
        ?>
        <a href="<?php echo $url; ?>" class="<?php echo $class; ?>">
          <?php echo htmlspecialchars($db); ?>
        </a>
      <?php endforeach; ?>

      <?php if ($currentDb): ?>
        <h2>Tablas en <?php echo htmlspecialchars($currentDb); ?></h2>
        <?php foreach ($tables as $t): ?>
          <?php
            $class = ($t === $currentTable) ? 'active' : '';
            $url   = '?db=' . urlencode($currentDb) . '&table=' . urlencode($t);
          ?>
          <a href="<?php echo $url; ?>" class="<?php echo $class; ?>">
            <?php echo htmlspecialchars($t); ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="main">
      <div class="card">
        <h2>Información general</h2>
        <p>Base de datos seleccionada:
          <strong><?php echo $currentDb ? htmlspecialchars($currentDb) : '(ninguna)'; ?></strong>
        </p>
        <p>Tabla seleccionada:
          <strong><?php echo $currentTable ? htmlspecialchars($currentTable) : '(ninguna)'; ?></strong>
        </p>
      </div>

      <?php if ($currentDb && $currentTable): ?>
        <div class="card">
          <h2>Contenido de <?php echo htmlspecialchars($currentDb . "." . $currentTable); ?> (primeros 100 registros)</h2>
          <?php if ($tableError): ?>
            <p class="error">Error: <?php echo htmlspecialchars($tableError); ?></p>
          <?php elseif ($tableData instanceof mysqli_result): ?>
            <table>
              <tr>
                <?php foreach ($tableFields as $f): ?>
                  <th><?php echo htmlspecialchars($f->name); ?></th>
                <?php endforeach; ?>
              </tr>
              <?php while ($row = $tableData->fetch_assoc()): ?>
                <tr>
                  <?php foreach ($tableFields as $f): ?>
                    <td><?php echo htmlspecialchars((string)$row[$f->name]); ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endwhile; ?>
            </table>
          <?php else: ?>
            <p>No hay datos para mostrar.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="card">
        <h2>Consola SQL</h2>
        <?php if ($MODE === 'slave'): ?>
          <p style="color:#555;font-style:italic;">Este servidor está en modo esclavo (solo SELECT/SHOW permitido).</p>
        <?php else: ?>
          <p style="color:#555;font-style:italic;">Este servidor está en modo maestro. Puedes ejecutar cualquier sentencia SQL (cuidado 👀).</p>
        <?php endif; ?>

        <form method="post">
          <textarea name="sql" placeholder="Escribe aquí tu sentencia SQL..."><?php
            if ($sqlInput !== '') {
                echo htmlspecialchars($sqlInput);
            } elseif ($currentDb && $currentTable) {
                // BD y tabla seleccionadas -> sugerir un SELECT directo
                echo htmlspecialchars("SELECT * FROM `{$currentTable}` LIMIT 10;");
            } elseif ($currentDb) {
                echo htmlspecialchars("SHOW TABLES;");
            } else {
                echo "";
            }
          ?></textarea><br>
          <button type="submit">Ejecutar</button>
        </form>

        <?php if ($sqlError): ?>
          <p class="error">Error: <?php echo htmlspecialchars($sqlError); ?></p>
        <?php elseif ($sqlResult instanceof mysqli_result): ?>
          <p class="ok">Consulta ejecutada correctamente.</p>
          <table>
            <tr>
              <?php foreach ($sqlResult->fetch_fields() as $f): ?>
                <th><?php echo htmlspecialchars($f->name); ?></th>
              <?php endforeach; ?>
            </tr>
            <?php while ($row = $sqlResult->fetch_assoc()): ?>
              <tr>
                <?php foreach ($row as $value): ?>
                  <td><?php echo htmlspecialchars((string)$value); ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endwhile; ?>
          </table>
        <?php elseif ($sqlInput !== '' && !$sqlError): ?>
          <p class="ok">Consulta ejecutada correctamente (sin resultados).</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
