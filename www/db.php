<?php
declare(strict_types=1);

/*
 * db.php - Conexión SQLite, migraciones y utilidades de sesión por token.
 * Compatible con PHP >= 7.1. No usa $_SESSION ni funciones mb_*.
 */

const COOKIE_TOKEN = 'turnos_token';
const SESION_HORAS = 168; // 7 días de validez del token
const NOMBRE_CARPETA_DATOS = 'BytesPCControlHorario';

date_default_timezone_set('Europe/Madrid');

/*
 * UBICACIÓN DE LA BASE DE DATOS
 *   true  = %APPDATA%\BytesPCControlHorario\turnos.db  (producción: fuera de la carpeta www)
 *   false = junto a este archivo (solo para desarrollo local)
 */
$usarAppData = true;

/** Carpeta AppData\Roaming del usuario de Windows, o '' si no se puede localizar. */
function detectarAppData(): string
{
    $candidatos = [];
    foreach ([getenv('APPDATA'), isset($_SERVER['APPDATA']) ? $_SERVER['APPDATA'] : '', isset($_ENV['APPDATA']) ? $_ENV['APPDATA'] : ''] as $v) {
        if (is_string($v) && $v !== '') {
            $candidatos[] = $v;
        }
    }
    $perfil = getenv('USERPROFILE');
    if (is_string($perfil) && $perfil !== '') {
        $candidatos[] = rtrim($perfil, '\\/') . '\\AppData\\Roaming';
    }
    foreach ($candidatos as $c) {
        if (is_dir($c)) {
            return $c;
        }
    }

    // Plan B: el registro de Windows no depende de las variables de entorno del proceso.
    if (stripos(PHP_OS, 'WIN') === 0 && function_exists('shell_exec')) {
        $salida = @shell_exec('reg query "HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Shell Folders" /v AppData 2>NUL');
        if (is_string($salida) && preg_match('/AppData\s+REG_SZ\s+(.+)/i', $salida, $m) === 1) {
            $ruta = trim($m[1]);
            if (is_dir($ruta)) {
                return $ruta;
            }
        }
    }
    return '';
}

/** Carpeta donde se guardan los datos. La crea si no existe. */
function carpetaDatos(): string
{
    global $usarAppData;
    static $carpeta = null;
    if ($carpeta !== null) {
        return $carpeta;
    }

    if ($usarAppData === false) {
        $carpeta = __DIR__;
        return $carpeta;
    }

    $base = detectarAppData();
    if ($base === '') {
        throw new RuntimeException(
            'No se pudo localizar la carpeta AppData del usuario. '
            . 'Para trabajar en modo desarrollo ponga $usarAppData = false; en db.php.'
        );
    }
    $dir = rtrim($base, '\\/') . DIRECTORY_SEPARATOR . NOMBRE_CARPETA_DATOS;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de datos: ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('La carpeta de datos no tiene permiso de escritura: ' . $dir);
    }
    $carpeta = $dir;
    return $carpeta;
}

function rutaBaseDatos(): string
{
    return carpetaDatos() . DIRECTORY_SEPARATOR . 'turnos.db';
}

/** true si todavía existe una base de datos antigua dentro de www (accesible desde el navegador). */
function hayBaseAntiguaExpuesta(): bool
{
    global $usarAppData;
    return $usarAppData !== false && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'turnos.db');
}

/**
 * Migración única: si existía turnos.db dentro de www y aún no hay base en la carpeta de datos,
 * se copia (verificando el tamaño) y se borra la original para que no quede expuesta.
 */
function migrarBaseAntigua(string $destino): void
{
    $antigua = __DIR__ . DIRECTORY_SEPARATOR . 'turnos.db';
    if (!is_file($antigua) || is_file($destino)) {
        return;
    }
    if (!@copy($antigua, $destino) || filesize($destino) !== filesize($antigua)) {
        @unlink($destino);
        throw new RuntimeException('No se pudo copiar la base de datos antigua a ' . $destino);
    }
    @unlink($antigua);
}

/**
 * Si hay una restauración pendiente (turnos.restaurar), la aplica ANTES de abrir la base de datos:
 * guarda una copia de los datos actuales en copias_previas\, reemplaza el archivo y elimina restos
 * de journal. Un bloqueo de archivo evita que dos peticiones simultáneas lo hagan a la vez.
 * Devuelve true si se ha restaurado algo.
 */
function aplicarRestauracionPendiente(string $ruta): bool
{
    $carpeta   = dirname($ruta);
    $pendiente = $carpeta . DIRECTORY_SEPARATOR . 'turnos.restaurar';
    if (!is_file($pendiente)) {
        return false;
    }

    $lock = @fopen($carpeta . DIRECTORY_SEPARATOR . 'restaurar.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('No se pudo preparar la restauración (bloqueo).');
    }
    flock($lock, LOCK_EX);
    try {
        if (!is_file($pendiente)) {
            return false; // otra petición ya la aplicó
        }

        if (is_file($ruta)) {
            $copias = $carpeta . DIRECTORY_SEPARATOR . 'copias_previas';
            if (!is_dir($copias) && !@mkdir($copias, 0775, true) && !is_dir($copias)) {
                throw new RuntimeException('No se pudo crear la carpeta de copias previas.');
            }
            $destinoCopia = $copias . DIRECTORY_SEPARATOR . 'turnos_antes_de_restaurar_' . date('Ymd_His') . '.db';
            if (!@copy($ruta, $destinoCopia)) {
                throw new RuntimeException('No se pudo guardar la copia de seguridad previa. La restauración se ha cancelado.');
            }
            // Se conservan solo las 10 copias previas más recientes.
            $antiguas = glob($copias . DIRECTORY_SEPARATOR . 'turnos_antes_de_restaurar_*.db');
            if (is_array($antiguas) && count($antiguas) > 10) {
                sort($antiguas);
                foreach (array_slice($antiguas, 0, count($antiguas) - 10) as $f) {
                    @unlink($f);
                }
            }
        }

        $hecho = false;
        for ($i = 0; $i < 15 && !$hecho; $i++) {
            $hecho = @rename($pendiente, $ruta);
            if (!$hecho) {
                usleep(200000); // Windows: el archivo puede estar un instante en uso
            }
        }
        if (!$hecho) {
            @rename($pendiente, $pendiente . '.fallida');
            throw new RuntimeException('No se pudo reemplazar la base de datos. Cierre otras ventanas del programa e inténtelo de nuevo.');
        }
        foreach (['-journal', '-wal', '-shm'] as $sufijo) {
            if (is_file($ruta . $sufijo)) {
                @unlink($ruta . $sufijo);
            }
        }
        return true;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $ruta = rutaBaseDatos();
    migrarBaseAntigua($ruta);
    $restaurada = aplicarRestauracionPendiente($ruta);

    $conexion = new PDO('sqlite:' . $ruta, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ]);

    $conexion->exec('PRAGMA foreign_keys = ON');
    $conexion->exec('PRAGMA busy_timeout = 5000');

    $conexion->exec("
        CREATE TABLE IF NOT EXISTS empleados (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre         TEXT NOT NULL,
            color_asignado TEXT NOT NULL DEFAULT '#3b82f6'
        );
        CREATE TABLE IF NOT EXISTS turnos (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            empleado_id INTEGER NOT NULL,
            fecha       TEXT NOT NULL,
            hora_inicio TEXT NOT NULL,
            hora_fin    TEXT NOT NULL,
            FOREIGN KEY (empleado_id) REFERENCES empleados (id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_turnos_fecha ON turnos (fecha);

        CREATE TABLE IF NOT EXISTS admin (
            id                    INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario               TEXT NOT NULL UNIQUE,
            password_hash         TEXT NOT NULL,
            debe_cambiar_password INTEGER NOT NULL DEFAULT 1,
            intentos_fallidos     INTEGER NOT NULL DEFAULT 0,
            bloqueado_hasta       TEXT NULL,
            token_sesion          TEXT NULL,
            token_expira          TEXT NULL
        );

        CREATE TABLE IF NOT EXISTS dias_especiales (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            fecha       TEXT NOT NULL,
            tipo        TEXT NOT NULL CHECK (tipo IN ('descanso','vacaciones','festivo')),
            empleado_id INTEGER NULL,
            nota        TEXT NULL,
            FOREIGN KEY (empleado_id) REFERENCES empleados (id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_dias_fecha ON dias_especiales (fecha);

        CREATE TABLE IF NOT EXISTS horarios_empleado (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            empleado_id INTEGER NOT NULL,
            dia_semana  INTEGER NOT NULL CHECK (dia_semana BETWEEN 1 AND 7),
            hora_inicio TEXT NOT NULL,
            hora_fin    TEXT NOT NULL,
            UNIQUE (empleado_id, dia_semana),
            FOREIGN KEY (empleado_id) REFERENCES empleados (id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS tipos_turno (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre      TEXT NOT NULL,
            color       TEXT NOT NULL DEFAULT '#3b82f6',
            hora_inicio TEXT NOT NULL,
            hora_fin    TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS configuracion (
            clave TEXT PRIMARY KEY,
            valor TEXT NOT NULL
        );
    ");

    // Migración: bases de datos antiguas no tienen las columnas del token.
    asegurarColumna($conexion, 'admin', 'token_sesion', 'TEXT NULL');
    asegurarColumna($conexion, 'admin', 'token_expira', 'TEXT NULL');
    $conexion->exec('CREATE INDEX IF NOT EXISTS idx_admin_token ON admin (token_sesion)');
    // Segundo tramo opcional por día en el horario semanal (turno partido).
    asegurarColumna($conexion, 'horarios_empleado', 'hora_inicio2', 'TEXT NULL');
    asegurarColumna($conexion, 'horarios_empleado', 'hora_fin2', 'TEXT NULL');

    // Tras restaurar una copia, se invalidan las sesiones abiertas y los bloqueos de login.
    if ($restaurada) {
        $conexion->exec('UPDATE admin SET token_sesion = NULL, token_expira = NULL, intentos_fallidos = 0, bloqueado_hasta = NULL');
    }

    // Admin por defecto: admin / admin123 (se fuerza el cambio en el primer login)
    $existe = $conexion->query('SELECT COUNT(*) FROM admin')->fetchColumn();
    if ((int)$existe === 0) {
        $stmt = $conexion->prepare(
            'INSERT INTO admin (usuario, password_hash, debe_cambiar_password) VALUES (:u, :h, 1)'
        );
        $stmt->execute([':u' => 'admin', ':h' => password_hash('admin123', PASSWORD_DEFAULT)]);
    }

    // Configuración por defecto
    $conexion->exec("INSERT OR IGNORE INTO configuracion (clave, valor) VALUES ('horas_max_semana', '40')");

    // Tipos de turno de ejemplo (solo la primera vez; si se borran, no vuelven a crearse).
    $sembrado = $conexion->query("SELECT valor FROM configuracion WHERE clave = 'tipos_sembrados'")->fetchColumn();
    if ($sembrado === false) {
        if ((int)$conexion->query('SELECT COUNT(*) FROM tipos_turno')->fetchColumn() === 0) {
            $ins = $conexion->prepare('INSERT INTO tipos_turno (nombre, color, hora_inicio, hora_fin) VALUES (:n, :c, :i, :f)');
            $ins->execute([':n' => 'Mañana', ':c' => '#f59e0b', ':i' => '07:00', ':f' => '15:00']);
            $ins->execute([':n' => 'Tarde',  ':c' => '#3b82f6', ':i' => '15:00', ':f' => '23:00']);
            $ins->execute([':n' => 'Noche',  ':c' => '#6366f1', ':i' => '23:00', ':f' => '07:00']);
        }
        $conexion->exec("INSERT OR IGNORE INTO configuracion (clave, valor) VALUES ('tipos_sembrados', '1')");
    }

    $pdo = $conexion;
    return $pdo;
}

function asegurarColumna(PDO $conexion, string $tabla, string $columna, string $definicion): void
{
    $columnas = $conexion->query('PRAGMA table_info(' . $tabla . ')')->fetchAll();
    foreach ($columnas as $c) {
        if ($c['name'] === $columna) {
            return;
        }
    }
    $conexion->exec('ALTER TABLE ' . $tabla . ' ADD COLUMN ' . $columna . ' ' . $definicion);
}

/* =========================================================
   Sesión por token (sin $_SESSION)
   ========================================================= */

/** En la base de datos se guarda solo el hash del token, nunca el token en claro. */
function tokenHash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Lee el token de la petición. Orden: cabecera X-Auth-Token, parámetro ?token=
 * (descargas), cookie. Devuelve '' si no hay ninguno con formato válido.
 */
function tokenDePeticion(): string
{
    $candidatos = [];
    if (isset($_SERVER['HTTP_X_AUTH_TOKEN']) && is_string($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        $candidatos[] = $_SERVER['HTTP_X_AUTH_TOKEN'];
    }
    if (isset($_GET['token']) && is_string($_GET['token'])) {
        $candidatos[] = $_GET['token'];
    }
    if (isset($_COOKIE[COOKIE_TOKEN]) && is_string($_COOKIE[COOKIE_TOKEN])) {
        $candidatos[] = $_COOKIE[COOKIE_TOKEN];
    }
    foreach ($candidatos as $t) {
        $t = trim($t);
        if (preg_match('/^[a-f0-9]{64}$/', $t) === 1) {
            return $t;
        }
    }
    return '';
}

/** Devuelve la fila del admin si el token es válido y no ha caducado; si no, null. */
function sesionActual(PDO $pdo): ?array
{
    $token = tokenDePeticion();
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, usuario, debe_cambiar_password, token_expira FROM admin WHERE token_sesion = :t LIMIT 1'
    );
    $stmt->execute([':t' => tokenHash($token)]);
    $fila = $stmt->fetch();
    if (!$fila) {
        return null;
    }
    if ((string)$fila['token_expira'] === '' || (string)$fila['token_expira'] < gmdate('Y-m-d H:i:s')) {
        return null;
    }
    return $fila;
}

/** Genera un token nuevo, lo guarda (hash) en la tabla admin y envía la cookie. */
function emitirSesion(PDO $pdo, int $adminId): string
{
    $token  = bin2hex(random_bytes(32));
    $expira = time() + SESION_HORAS * 3600;
    $stmt = $pdo->prepare('UPDATE admin SET token_sesion = :t, token_expira = :x WHERE id = :id');
    $stmt->execute([
        ':t'  => tokenHash($token),
        ':x'  => gmdate('Y-m-d H:i:s', $expira),
        ':id' => $adminId,
    ]);
    enviarCookieToken($token, $expira);
    return $token;
}

/** Borra el token de la base de datos y destruye la cookie. */
function destruirSesion(PDO $pdo, int $adminId): void
{
    $stmt = $pdo->prepare('UPDATE admin SET token_sesion = NULL, token_expira = NULL WHERE id = :id');
    $stmt->execute([':id' => $adminId]);
    enviarCookieToken('', time() - 3600);
}

function enviarCookieToken(string $valor, int $expira): void
{
    if (headers_sent()) {
        return;
    }
    if (PHP_VERSION_ID >= 70300) {
        setcookie(COOKIE_TOKEN, $valor, [
            'expires'  => $expira,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie(COOKIE_TOKEN, $valor, $expira, '/', '', false, true);
    }
}
