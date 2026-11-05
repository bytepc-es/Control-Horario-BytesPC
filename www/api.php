<?php
declare(strict_types=1);

/*
 * api.php - API JSON del planificador de turnos.
 * Compatible con PHP >= 7.1 (PHP Desktop). No usa $_SESSION ni funciones mb_*.
 * Cualquier error (excepción, warning o fallo fatal) se devuelve SIEMPRE como JSON.
 */

const MAX_INTENTOS = 5;     // intentos de login fallidos antes de bloquear
const BLOQUEO_SEG  = 300;   // duración del bloqueo (segundos)

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Madrid');

/* =========================================================
   Salida JSON y manejo de errores
   ========================================================= */

function limpiarBuffers(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function responder($datos, int $codigo = 200): void
{
    limpiarBuffers();
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function fallo(string $mensaje, int $codigo = 400, array $extra = []): void
{
    responder(array_merge(['error' => $mensaje], $extra), $codigo);
}

// Los warnings/notices se convierten en excepciones para que nunca se mezclen con el JSON.
set_error_handler(function ($no, $msg, $archivo, $linea) {
    if (!(error_reporting() & $no)) {
        return false;
    }
    if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
        return true;
    }
    throw new ErrorException($msg, 0, $no, $archivo, $linea);
});

set_exception_handler(function ($e) {
    responder(['error' => 'Error interno del servidor.', 'detalle' => $e->getMessage()], 500);
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        responder(['error' => 'Error fatal del servidor.', 'detalle' => $e['message']], 500);
    }
});

require __DIR__ . '/db.php';

/* =========================================================
   Utilidades de entrada y validación
   ========================================================= */

function leerCuerpo(): array
{
    $raw = (string)file_get_contents('php://input');
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        fallo('Petición no válida.');
    }
    return $d;
}

/** Texto recortado de un campo del cuerpo JSON ('' si no existe o no es escalar). */
function texto(array $d, string $clave): string
{
    return (isset($d[$clave]) && is_scalar($d[$clave])) ? trim((string)$d[$clave]) : '';
}

/** Igual que texto() pero sin recortar (contraseñas). */
function cruda(array $d, string $clave): string
{
    return (isset($d[$clave]) && is_scalar($d[$clave])) ? (string)$d[$clave] : '';
}

function entero(array $d, string $clave): int
{
    return (isset($d[$clave]) && is_numeric($d[$clave])) ? (int)$d[$clave] : 0;
}

function param(string $clave): string
{
    return (isset($_GET[$clave]) && is_string($_GET[$clave])) ? trim($_GET[$clave]) : '';
}

/** Longitud en caracteres UTF-8 sin depender de mbstring. */
function longitud(string $s): int
{
    $n = preg_match_all('/./su', $s);
    return $n === false ? strlen($s) : $n;
}

function fechaValida(string $f): bool
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) !== 1) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function horaValida(string $h): bool
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) === 1;
}

function colorValido(string $c): bool
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1;
}

/** Devuelve [inicio, fin) validado a partir de ?start=...&end=... */
function rangoDesdeGet(): array
{
    $inicio = param('start');
    $fin    = param('end');
    if (!fechaValida($inicio) || !fechaValida($fin) || $fin < $inicio) {
        fallo('Rango de fechas no válido.');
    }
    if (numeroDia($fin) - numeroDia($inicio) > 400) {
        fallo('El rango de fechas es demasiado grande.');
    }
    return [$inicio, $fin];
}

/* =========================================================
   Fechas y horas (aritmética en UTC, sin problemas de horario de verano)
   ========================================================= */

function fechaUtc(string $fecha): DateTimeImmutable
{
    return new DateTimeImmutable($fecha . ' 00:00:00', new DateTimeZone('UTC'));
}

function sumarDias(string $fecha, int $dias): string
{
    return fechaUtc($fecha)->modify(($dias >= 0 ? '+' : '') . $dias . ' day')->format('Y-m-d');
}

/** Número de día absoluto (días desde 1970-01-01). */
function numeroDia(string $fecha): int
{
    return intdiv(fechaUtc($fecha)->getTimestamp(), 86400);
}

function lunesDe(string $fecha): string
{
    $dt = fechaUtc($fecha);
    $n  = (int)$dt->format('N'); // 1 = lunes ... 7 = domingo
    return $dt->modify('-' . ($n - 1) . ' day')->format('Y-m-d');
}

function minutosDeHora(string $hora): int
{
    return (int)substr($hora, 0, 2) * 60 + (int)substr($hora, 3, 2);
}

function fechaLatina(string $fecha): string
{
    return substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2) . '/' . substr($fecha, 0, 4);
}

function formatoHoras(int $minutos): string
{
    $h = intdiv($minutos, 60);
    $m = $minutos % 60;
    return $m === 0 ? $h . ' h' : $h . ' h ' . $m . ' min';
}

/**
 * Divide un turno en segmentos por día natural: [[fecha, minutos], ...].
 * Si la salida es menor o igual que la entrada, el turno cruza la medianoche.
 * (Entrada == salida se rechaza al guardar, por lo que no existe en la base de datos.)
 */
function segmentosTurno(string $fecha, string $inicio, string $fin): array
{
    $i = minutosDeHora($inicio);
    $f = minutosDeHora($fin);
    if ($f > $i) {
        return [[$fecha, $f - $i]];
    }
    $segmentos = [[$fecha, 1440 - $i]];
    if ($f > 0) {
        $segmentos[] = [sumarDias($fecha, 1), $f];
    }
    return $segmentos;
}

/** Intervalo absoluto [inicio, fin) en minutos desde 1970 para comparar solapamientos. */
function intervaloAbsoluto(string $fecha, string $inicio, string $fin): array
{
    $desde    = numeroDia($fecha) * 1440 + minutosDeHora($inicio);
    $duracion = minutosDeHora($fin) - minutosDeHora($inicio);
    if ($duracion <= 0) {
        $duracion += 1440;
    }
    return [$desde, $desde + $duracion];
}

/**
 * ¿Se solapa el turno propuesto con otro turno del mismo empleado?
 * Dos intervalos [s1,e1) y [s2,e2) se solapan si s1 < e2 y s2 < e1.
 * Se miran los turnos del día anterior (pueden terminar hoy) y del siguiente.
 */
function haySolapamiento(PDO $pdo, int $empleadoId, string $fecha, string $inicio, string $fin, int $excluirId = 0): bool
{
    list($s1, $e1) = intervaloAbsoluto($fecha, $inicio, $fin);

    $stmt = $pdo->prepare(
        'SELECT fecha, hora_inicio, hora_fin FROM turnos
         WHERE empleado_id = :e AND id <> :id AND fecha >= :d AND fecha <= :h'
    );
    $stmt->execute([
        ':e'  => $empleadoId,
        ':id' => $excluirId,
        ':d'  => sumarDias($fecha, -1),
        ':h'  => sumarDias($fecha, 1),
    ]);

    foreach ($stmt->fetchAll() as $t) {
        list($s2, $e2) = intervaloAbsoluto($t['fecha'], $t['hora_inicio'], $t['hora_fin']);
        if ($s1 < $e2 && $s2 < $e1) {
            return true;
        }
    }
    return false;
}

/* =========================================================
   Sesión (tokens en base de datos)
   ========================================================= */

function exigirSesion(PDO $pdo, bool $permitirPendiente = false): array
{
    $admin = sesionActual($pdo);
    if ($admin === null) {
        fallo('Sesión no válida o caducada.', 401, ['sesion_expirada' => true]);
    }
    if (!$permitirPendiente && (int)$admin['debe_cambiar_password'] === 1) {
        fallo('Debes cambiar la contraseña antes de continuar.', 403, ['debe_cambiar_password' => true]);
    }
    return $admin;
}

function login(PDO $pdo, array $d): void
{
    $usuario  = texto($d, 'usuario');
    $password = cruda($d, 'password');
    if ($usuario === '' || $password === '') {
        fallo('Introduce usuario y contraseña.');
    }

    $stmt = $pdo->prepare('SELECT * FROM admin WHERE usuario = :u LIMIT 1');
    $stmt->execute([':u' => $usuario]);
    $admin = $stmt->fetch();

    if (!$admin) {
        usleep(300000);
        fallo('Usuario o contraseña incorrectos.', 401);
    }

    $bloqueado = (string)($admin['bloqueado_hasta'] ?? '');
    if ($bloqueado !== '' && $bloqueado > gmdate('Y-m-d H:i:s')) {
        $minutos = (int)ceil((strtotime($bloqueado . ' UTC') - time()) / 60);
        if ($minutos < 1) {
            $minutos = 1;
        }
        fallo('Demasiados intentos fallidos. Inténtalo de nuevo en ' . $minutos . ' min.', 429);
    }

    if (!password_verify($password, (string)$admin['password_hash'])) {
        $intentos = (int)$admin['intentos_fallidos'] + 1;
        $hasta    = null;
        if ($intentos >= MAX_INTENTOS) {
            $hasta    = gmdate('Y-m-d H:i:s', time() + BLOQUEO_SEG);
            $intentos = 0;
        }
        $upd = $pdo->prepare('UPDATE admin SET intentos_fallidos = :i, bloqueado_hasta = :b WHERE id = :id');
        $upd->execute([':i' => $intentos, ':b' => $hasta, ':id' => (int)$admin['id']]);
        fallo('Usuario o contraseña incorrectos.', 401);
    }

    $upd = $pdo->prepare('UPDATE admin SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = :id');
    $upd->execute([':id' => (int)$admin['id']]);

    $token = emitirSesion($pdo, (int)$admin['id']);
    responder([
        'ok'                    => true,
        'token'                 => $token,
        'debe_cambiar_password' => ((int)$admin['debe_cambiar_password'] === 1),
    ]);
}

function cerrarSesion(PDO $pdo, array $admin): void
{
    destruirSesion($pdo, (int)$admin['id']);
    responder(['ok' => true]);
}

function cambiarPassword(PDO $pdo, array $admin, array $d): void
{
    $actual = cruda($d, 'actual');
    $nueva  = cruda($d, 'nueva');

    $stmt = $pdo->prepare('SELECT password_hash FROM admin WHERE id = :id');
    $stmt->execute([':id' => (int)$admin['id']]);
    $hashActual = (string)$stmt->fetchColumn();

    if (!password_verify($actual, $hashActual)) {
        fallo('La contraseña actual no es correcta.');
    }
    if (longitud($nueva) < 6) {
        fallo('La nueva contraseña debe tener al menos 6 caracteres.');
    }
    if ($nueva === $actual) {
        fallo('La nueva contraseña debe ser distinta de la actual.');
    }

    $upd = $pdo->prepare('UPDATE admin SET password_hash = :h, debe_cambiar_password = 0 WHERE id = :id');
    $upd->execute([':h' => password_hash($nueva, PASSWORD_DEFAULT), ':id' => (int)$admin['id']]);

    // Se regenera el token: la sesión continúa con uno nuevo.
    $token = emitirSesion($pdo, (int)$admin['id']);
    responder(['ok' => true, 'token' => $token]);
}

/* =========================================================
   Empleados
   ========================================================= */

function listarEmpleados(PDO $pdo): void
{
    $filas = $pdo->query('SELECT id, nombre, color_asignado FROM empleados ORDER BY nombre COLLATE NOCASE')->fetchAll();

    $horarios = [];
    $stmt = $pdo->query('SELECT empleado_id, dia_semana, hora_inicio, hora_fin, hora_inicio2, hora_fin2 FROM horarios_empleado ORDER BY dia_semana');
    foreach ($stmt->fetchAll() as $h) {
        $horarios[(int)$h['empleado_id']][] = [
            'dia'     => (int)$h['dia_semana'],
            'inicio'  => $h['hora_inicio'],
            'fin'     => $h['hora_fin'],
            'inicio2' => $h['hora_inicio2'],
            'fin2'    => $h['hora_fin2'],
        ];
    }

    foreach ($filas as &$f) {
        $f['id']      = (int)$f['id'];
        $f['horario'] = isset($horarios[$f['id']]) ? $horarios[$f['id']] : [];
    }
    unset($f);
    responder($filas);
}

function crearEmpleado(PDO $pdo, array $d): void
{
    $nombre = texto($d, 'nombre');
    if ($nombre === '' || longitud($nombre) > 60) {
        fallo('Nombre inválido.');
    }
    $color = texto($d, 'color');
    if (!colorValido($color)) {
        $color = '#3b82f6';
    }
    $stmt = $pdo->prepare('INSERT INTO empleados (nombre, color_asignado) VALUES (:n, :c)');
    $stmt->execute([':n' => $nombre, ':c' => $color]);
    responder(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

/** Borra un empleado y todo lo suyo en una transacción (no depende de ON DELETE CASCADE). */
function borrarEmpleado(PDO $pdo, int $id): void
{
    if (!existeEmpleado($pdo, $id)) {
        fallo('El empleado no existe.', 404);
    }
    $pdo->beginTransaction();
    try {
        foreach (['turnos', 'dias_especiales', 'horarios_empleado'] as $tabla) {
            $pdo->prepare('DELETE FROM ' . $tabla . ' WHERE empleado_id = :id')->execute([':id' => $id]);
        }
        $pdo->prepare('DELETE FROM empleados WHERE id = :id')->execute([':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    responder(['ok' => true]);
}

/** Tramos de un día de la semana: [[inicio, fin], [inicio2, fin2]?]. */
function cargarHorario(PDO $pdo, int $empleadoId): array
{
    $stmt = $pdo->prepare(
        'SELECT dia_semana, hora_inicio, hora_fin, hora_inicio2, hora_fin2 FROM horarios_empleado WHERE empleado_id = :e'
    );
    $stmt->execute([':e' => $empleadoId]);
    $horario = [];
    foreach ($stmt->fetchAll() as $r) {
        $tramos = [[$r['hora_inicio'], $r['hora_fin']]];
        if ($r['hora_inicio2'] !== null && $r['hora_fin2'] !== null && $r['hora_inicio2'] !== '') {
            $tramos[] = [$r['hora_inicio2'], $r['hora_fin2']];
        }
        $horario[(int)$r['dia_semana']] = $tramos;
    }
    return $horario;
}

/** Guarda la ficha: nombre, color y horario semanal base (hasta dos tramos por día). */
function guardarEmpleado(PDO $pdo, array $d): void
{
    $id = entero($d, 'id');
    if (!existeEmpleado($pdo, $id)) {
        fallo('Empleado no válido.');
    }
    $nombre = texto($d, 'nombre');
    if ($nombre === '' || longitud($nombre) > 60) {
        fallo('Nombre inválido.');
    }
    $color = texto($d, 'color');
    if (!colorValido($color)) {
        $color = '#3b82f6';
    }

    $horario = (isset($d['horario']) && is_array($d['horario'])) ? $d['horario'] : [];
    $filas = [];
    foreach ($horario as $h) {
        if (!is_array($h)) {
            continue;
        }
        $dia     = entero($h, 'dia');
        $inicio  = texto($h, 'inicio');
        $fin     = texto($h, 'fin');
        $inicio2 = texto($h, 'inicio2');
        $fin2    = texto($h, 'fin2');
        if ($dia < 1 || $dia > 7) {
            fallo('Día de la semana no válido.');
        }
        if (!horaValida($inicio) || !horaValida($fin) || $inicio === $fin) {
            fallo('Revisa las horas de ' . nombreDia($dia) . ': la salida debe ser distinta de la entrada.');
        }
        $segundo = ($inicio2 !== '' || $fin2 !== '');
        if ($segundo) {
            if (!horaValida($inicio2) || !horaValida($fin2) || $inicio2 === $fin2) {
                fallo('Revisa el segundo tramo de ' . nombreDia($dia) . '.');
            }
            list($s1, $e1) = intervaloAbsoluto('2000-01-03', $inicio, $fin);
            list($s2, $e2) = intervaloAbsoluto('2000-01-03', $inicio2, $fin2);
            if ($s1 < $e2 && $s2 < $e1) {
                fallo('Los dos tramos de ' . nombreDia($dia) . ' se solapan.');
            }
        }
        $filas[$dia] = [$inicio, $fin, $segundo ? $inicio2 : null, $segundo ? $fin2 : null];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE empleados SET nombre = :n, color_asignado = :c WHERE id = :id')
            ->execute([':n' => $nombre, ':c' => $color, ':id' => $id]);
        $pdo->prepare('DELETE FROM horarios_empleado WHERE empleado_id = :id')->execute([':id' => $id]);
        $ins = $pdo->prepare(
            'INSERT INTO horarios_empleado (empleado_id, dia_semana, hora_inicio, hora_fin, hora_inicio2, hora_fin2)
             VALUES (:e, :d, :i, :f, :i2, :f2)'
        );
        foreach ($filas as $dia => $x) {
            $ins->execute([':e' => $id, ':d' => (int)$dia, ':i' => $x[0], ':f' => $x[1], ':i2' => $x[2], ':f2' => $x[3]]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    responder(['ok' => true]);
}

function nombreDia(int $n): string
{
    $nombres = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
    return isset($nombres[$n]) ? $nombres[$n] : 'ese día';
}

function borrarFila(PDO $pdo, string $tabla, int $id): void
{
    if (!in_array($tabla, ['empleados', 'turnos', 'dias_especiales', 'tipos_turno'], true)) {
        fallo('Tabla no válida.', 500);
    }
    if ($id <= 0) {
        fallo('Identificador no válido.');
    }
    $stmt = $pdo->prepare('DELETE FROM ' . $tabla . ' WHERE id = :id');
    $stmt->execute([':id' => $id]);
    responder(['ok' => true]);
}

function existeEmpleado(PDO $pdo, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM empleados WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetchColumn() !== false;
}

/* =========================================================
   Turnos
   ========================================================= */

function listarTurnos(PDO $pdo): void
{
    list($inicio, $fin) = rangoDesdeGet();

    // Se incluye el día anterior: un turno nocturno puede terminar dentro del rango.
    $sql = 'SELECT id, empleado_id, fecha, hora_inicio, hora_fin FROM turnos WHERE fecha >= :d AND fecha < :h';
    $par = [':d' => sumarDias($inicio, -1), ':h' => $fin];

    $filtro = (int)param('empleado_id');
    if ($filtro > 0) {
        $sql .= ' AND empleado_id = :e';
        $par[':e'] = $filtro;
    }
    $sql .= ' ORDER BY fecha, hora_inicio';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($par);
    $filas = $stmt->fetchAll();
    foreach ($filas as &$f) {
        $f['id']          = (int)$f['id'];
        $f['empleado_id'] = (int)$f['empleado_id'];
    }
    unset($f);
    responder($filas);
}

/** Fechas (Y-m-d => true) con día especial para el empleado o para toda la empresa. */
function fechasEspeciales(PDO $pdo, int $empleadoId, string $desde, string $hasta): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT fecha FROM dias_especiales
         WHERE fecha >= :d AND fecha <= :h AND (empleado_id IS NULL OR empleado_id = :e)'
    );
    $stmt->execute([':d' => $desde, ':h' => $hasta, ':e' => $empleadoId]);
    $res = [];
    foreach ($stmt->fetchAll() as $f) {
        $res[$f['fecha']] = true;
    }
    return $res;
}

/** Genera los turnos de un empleado según su horario semanal. Debe llamarse dentro de una transacción. */
function generarParaEmpleado(PDO $pdo, int $empleadoId, string $desde, string $hasta): array
{
    $horario = cargarHorario($pdo, $empleadoId);
    $res = ['creados' => 0, 'omitidos_especiales' => 0, 'omitidos_solapados' => 0, 'sin_horario' => (count($horario) === 0)];
    if ($res['sin_horario']) {
        return $res;
    }

    $especiales = fechasEspeciales($pdo, $empleadoId, $desde, $hasta);
    $dias = numeroDia($hasta) - numeroDia($desde);
    $ins  = $pdo->prepare('INSERT INTO turnos (empleado_id, fecha, hora_inicio, hora_fin) VALUES (:e, :f, :i, :h)');

    for ($n = 0; $n <= $dias; $n++) {
        $fecha = sumarDias($desde, $n);
        $dow   = (int)fechaUtc($fecha)->format('N');
        if (!isset($horario[$dow])) {
            continue;
        }
        if (isset($especiales[$fecha])) {
            $res['omitidos_especiales']++;
            continue;
        }
        foreach ($horario[$dow] as $tramo) {
            if (haySolapamiento($pdo, $empleadoId, $fecha, $tramo[0], $tramo[1])) {
                $res['omitidos_solapados']++;
                continue;
            }
            $ins->execute([':e' => $empleadoId, ':f' => $fecha, ':i' => $tramo[0], ':h' => $tramo[1]]);
            $res['creados']++;
        }
    }
    return $res;
}

/**
 * Crea turnos para uno o varios empleados entre dos fechas (ambas incluidas) según su horario semanal.
 * No toca los turnos existentes y omite días especiales y solapamientos.
 */
function generarTurnos(PDO $pdo, array $d): void
{
    $desde = texto($d, 'desde');
    $hasta = texto($d, 'hasta');
    if (!fechaValida($desde) || !fechaValida($hasta) || $hasta < $desde) {
        fallo('Rango de fechas no válido.');
    }
    if (numeroDia($hasta) - numeroDia($desde) > 365) {
        fallo('Puedes generar como máximo un año de turnos de una vez.');
    }

    $ids = [];
    if (isset($d['empleado_ids']) && is_array($d['empleado_ids'])) {
        foreach ($d['empleado_ids'] as $v) {
            if (is_numeric($v) && (int)$v > 0) {
                $ids[(int)$v] = true;
            }
        }
    } elseif (entero($d, 'empleado_id') > 0) {
        $ids[entero($d, 'empleado_id')] = true;
    }
    $ids = array_keys($ids);
    if (count($ids) === 0) {
        fallo('Selecciona al menos un empleado.');
    }
    foreach ($ids as $id) {
        if (!existeEmpleado($pdo, $id)) {
            fallo('Empleado no válido.');
        }
    }

    $total = ['creados' => 0, 'omitidos_especiales' => 0, 'omitidos_solapados' => 0];
    $sinHorario = [];
    $pdo->beginTransaction();
    try {
        foreach ($ids as $id) {
            $r = generarParaEmpleado($pdo, $id, $desde, $hasta);
            if ($r['sin_horario']) {
                $q = $pdo->prepare('SELECT nombre FROM empleados WHERE id = :id');
                $q->execute([':id' => $id]);
                $sinHorario[] = (string)$q->fetchColumn();
                continue;
            }
            $total['creados']             += $r['creados'];
            $total['omitidos_especiales'] += $r['omitidos_especiales'];
            $total['omitidos_solapados']  += $r['omitidos_solapados'];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    responder(array_merge(['ok' => true, 'sin_horario' => $sinHorario], $total));
}

/** Copia los turnos de [desde-dias, hasta-dias) a [desde, hasta). hasta es exclusivo. */
function copiarPeriodo(PDO $pdo, array $d): void
{
    $desde  = texto($d, 'desde');
    $hasta  = texto($d, 'hasta');
    $offset = entero($d, 'dias');
    if ($offset < 1 || $offset > 366) {
        $offset = 7;
    }
    if (!fechaValida($desde) || !fechaValida($hasta) || $hasta <= $desde) {
        fallo('Rango de fechas no válido.');
    }
    if (numeroDia($hasta) - numeroDia($desde) > 400) {
        fallo('El rango de fechas es demasiado grande.');
    }

    $sql = 'SELECT empleado_id, fecha, hora_inicio, hora_fin FROM turnos WHERE fecha >= :d AND fecha < :h';
    $par = [':d' => sumarDias($desde, -$offset), ':h' => sumarDias($hasta, -$offset)];
    $filtro = entero($d, 'empleado_id');
    if ($filtro > 0) {
        $sql .= ' AND empleado_id = :e';
        $par[':e'] = $filtro;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($par);
    $origen = $stmt->fetchAll();

    // Días especiales del periodo destino: '*' = toda la empresa.
    $esp = $pdo->prepare('SELECT fecha, empleado_id FROM dias_especiales WHERE fecha >= :d AND fecha < :h');
    $esp->execute([':d' => $desde, ':h' => $hasta]);
    $especiales = [];
    foreach ($esp->fetchAll() as $e) {
        $especiales[($e['empleado_id'] === null ? '*' : (string)$e['empleado_id']) . '|' . $e['fecha']] = true;
    }

    $creados = 0;
    $omitidosEspeciales = 0;
    $omitidosSolapados  = 0;
    $ins = $pdo->prepare('INSERT INTO turnos (empleado_id, fecha, hora_inicio, hora_fin) VALUES (:e, :f, :i, :h)');

    $pdo->beginTransaction();
    try {
        foreach ($origen as $t) {
            $empId = (int)$t['empleado_id'];
            $nueva = sumarDias($t['fecha'], $offset);
            if (isset($especiales['*|' . $nueva]) || isset($especiales[$empId . '|' . $nueva])) {
                $omitidosEspeciales++;
                continue;
            }
            if (haySolapamiento($pdo, $empId, $nueva, $t['hora_inicio'], $t['hora_fin'])) {
                $omitidosSolapados++;
                continue;
            }
            $ins->execute([':e' => $empId, ':f' => $nueva, ':i' => $t['hora_inicio'], ':h' => $t['hora_fin']]);
            $creados++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    responder([
        'ok' => true, 'creados' => $creados,
        'omitidos_especiales' => $omitidosEspeciales, 'omitidos_solapados' => $omitidosSolapados,
    ]);
}

/** Crea varios turnos de golpe (asignación múltiple y deshacer). Los que se solapan se omiten. */
function crearTurnos(PDO $pdo, array $d): void
{
    $items = (isset($d['items']) && is_array($d['items'])) ? $d['items'] : [];
    if (count($items) === 0 || count($items) > 1000) {
        fallo('Lista de turnos no válida.');
    }

    $validos = [];
    $empleados = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            fallo('Lista de turnos no válida.');
        }
        $empId  = entero($it, 'empleado_id');
        $fecha  = texto($it, 'fecha');
        $inicio = texto($it, 'hora_inicio');
        $fin    = texto($it, 'hora_fin');
        if (!isset($empleados[$empId])) {
            $empleados[$empId] = existeEmpleado($pdo, $empId);
        }
        if (!$empleados[$empId] || !fechaValida($fecha) || !horaValida($inicio) || !horaValida($fin) || $inicio === $fin) {
            fallo('Hay turnos con datos no válidos.');
        }
        $validos[] = [$empId, $fecha, $inicio, $fin];
    }

    $creados = 0;
    $omitidosSolapados = 0;
    $ins = $pdo->prepare('INSERT INTO turnos (empleado_id, fecha, hora_inicio, hora_fin) VALUES (:e, :f, :i, :h)');
    $pdo->beginTransaction();
    try {
        foreach ($validos as $v) {
            if (haySolapamiento($pdo, $v[0], $v[1], $v[2], $v[3])) {
                $omitidosSolapados++;
                continue;
            }
            $ins->execute([':e' => $v[0], ':f' => $v[1], ':i' => $v[2], ':h' => $v[3]]);
            $creados++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    responder(['ok' => true, 'creados' => $creados, 'omitidos_solapados' => $omitidosSolapados]);
}

function borrarTurnos(PDO $pdo, array $d): void
{
    $ids = [];
    if (isset($d['ids']) && is_array($d['ids'])) {
        foreach ($d['ids'] as $v) {
            if (is_numeric($v) && (int)$v > 0) {
                $ids[] = (int)$v;
            }
        }
    }
    if (count($ids) === 0 || count($ids) > 2000) {
        fallo('Lista de turnos no válida.');
    }
    $del = $pdo->prepare('DELETE FROM turnos WHERE id = :id');
    $pdo->beginTransaction();
    try {
        foreach ($ids as $id) {
            $del->execute([':id' => $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    responder(['ok' => true, 'borrados' => count($ids)]);
}

/** Marca varios días a la vez: items = [{empleado_id (0 = empresa), fecha}]. */
function crearDiasLote(PDO $pdo, array $d): void
{
    $tipo  = texto($d, 'tipo');
    $nota  = texto($d, 'nota');
    $items = (isset($d['items']) && is_array($d['items'])) ? $d['items'] : [];
    if (!in_array($tipo, ['descanso', 'vacaciones', 'festivo'], true)) {
        fallo('Tipo de día no válido.');
    }
    if (count($items) === 0 || count($items) > 500) {
        fallo('Lista de días no válida.');
    }
    if (longitud($nota) > 120) {
        fallo('La nota es demasiado larga (máx. 120 caracteres).');
    }

    $validos = [];
    $empleados = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            fallo('Lista de días no válida.');
        }
        $empId = entero($it, 'empleado_id');
        $fecha = texto($it, 'fecha');
        if ($empId > 0) {
            if (!isset($empleados[$empId])) {
                $empleados[$empId] = existeEmpleado($pdo, $empId);
            }
            if (!$empleados[$empId]) {
                fallo('Empleado no válido.');
            }
        }
        if (!fechaValida($fecha)) {
            fallo('Fecha no válida.');
        }
        $validos[] = [$empId > 0 ? $empId : null, $fecha];
    }

    $existe = $pdo->prepare('SELECT 1 FROM dias_especiales WHERE fecha = :f AND tipo = :t AND empleado_id IS :e LIMIT 1');
    $ins    = $pdo->prepare('INSERT INTO dias_especiales (fecha, tipo, empleado_id, nota) VALUES (:f, :t, :e, :n)');
    $creados = 0;
    $pdo->beginTransaction();
    try {
        foreach ($validos as $v) {
            $existe->execute([':f' => $v[1], ':t' => $tipo, ':e' => $v[0]]);
            if ($existe->fetchColumn() !== false) {
                continue;
            }
            $ins->execute([':f' => $v[1], ':t' => $tipo, ':e' => $v[0], ':n' => $nota !== '' ? $nota : null]);
            $creados++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    responder(['ok' => true, 'creados' => $creados]);
}

/* =========================================================
   Tipos de turno
   ========================================================= */

function listarTipos(PDO $pdo): void
{
    $filas = $pdo->query('SELECT id, nombre, color, hora_inicio, hora_fin FROM tipos_turno ORDER BY hora_inicio, nombre COLLATE NOCASE')->fetchAll();
    foreach ($filas as &$f) {
        $f['id'] = (int)$f['id'];
    }
    unset($f);
    responder($filas);
}

function guardarTipo(PDO $pdo, array $d): void
{
    $id     = entero($d, 'id');
    $nombre = texto($d, 'nombre');
    $color  = texto($d, 'color');
    $inicio = texto($d, 'hora_inicio');
    $fin    = texto($d, 'hora_fin');
    if ($nombre === '' || longitud($nombre) > 30) {
        fallo('El nombre del tipo de turno es obligatorio (máx. 30 caracteres).');
    }
    if (!colorValido($color)) {
        $color = '#3b82f6';
    }
    if (!horaValida($inicio) || !horaValida($fin) || $inicio === $fin) {
        fallo('La salida debe ser distinta de la entrada.');
    }
    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE tipos_turno SET nombre = :n, color = :c, hora_inicio = :i, hora_fin = :f WHERE id = :id');
        $stmt->execute([':n' => $nombre, ':c' => $color, ':i' => $inicio, ':f' => $fin, ':id' => $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO tipos_turno (nombre, color, hora_inicio, hora_fin) VALUES (:n, :c, :i, :f)');
        $stmt->execute([':n' => $nombre, ':c' => $color, ':i' => $inicio, ':f' => $fin]);
    }
    responder(['ok' => true]);
}

/* =========================================================
   Datos de ejemplo (solo si no hay empleados)
   ========================================================= */

function cargarDemo(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM empleados')->fetchColumn() > 0) {
        fallo('Los datos de ejemplo solo se pueden cargar cuando no hay empleados.');
    }
    $laborables = [1, 2, 3, 4, 5];
    $demo = [
        ['Ana García',   '#3b82f6', $laborables,   [['08:00', '15:00']]],
        ['Luis Martín',  '#f97316', $laborables,   [['15:00', '23:00']]],
        ['Marta Ruiz',   '#10b981', $laborables,   [['09:00', '13:00'], ['16:00', '20:00']]],
        ['Pablo Soto',   '#8b5cf6', [5, 6, 7],     [['23:00', '07:00']]],
    ];

    $insE = $pdo->prepare('INSERT INTO empleados (nombre, color_asignado) VALUES (:n, :c)');
    $insH = $pdo->prepare(
        'INSERT INTO horarios_empleado (empleado_id, dia_semana, hora_inicio, hora_fin, hora_inicio2, hora_fin2)
         VALUES (:e, :d, :i, :f, :i2, :f2)'
    );
    $ids = [];
    $pdo->beginTransaction();
    try {
        foreach ($demo as $e) {
            $insE->execute([':n' => $e[0], ':c' => $e[1]]);
            $id = (int)$pdo->lastInsertId();
            $ids[] = $id;
            foreach ($e[2] as $dia) {
                $insH->execute([
                    ':e' => $id, ':d' => $dia, ':i' => $e[3][0][0], ':f' => $e[3][0][1],
                    ':i2' => isset($e[3][1]) ? $e[3][1][0] : null,
                    ':f2' => isset($e[3][1]) ? $e[3][1][1] : null,
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    responder(['ok' => true, 'ids' => $ids]);
}

function horasMaxSemana(PDO $pdo): int
{
    $v = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'horas_max_semana'")->fetchColumn();
    $n = ($v === false) ? 40 : (int)$v;
    return $n >= 1 ? $n : 40;
}

function guardarTurno(PDO $pdo, array $d): void
{
    $id         = entero($d, 'id');
    $empleadoId = entero($d, 'empleado_id');
    $fecha      = texto($d, 'fecha');
    $inicio     = texto($d, 'hora_inicio');
    $fin        = texto($d, 'hora_fin');

    if (!existeEmpleado($pdo, $empleadoId)) {
        fallo('Empleado no válido.');
    }
    if (!fechaValida($fecha)) {
        fallo('Fecha no válida.');
    }
    if (!horaValida($inicio) || !horaValida($fin)) {
        fallo('Horas no válidas.');
    }
    if ($inicio === $fin) {
        fallo('La hora de salida debe ser distinta de la de entrada.');
    }
    if (haySolapamiento($pdo, $empleadoId, $fecha, $inicio, $fin, $id)) {
        fallo('El turno se solapa con otro turno de este empleado.', 409);
    }

    if ($id > 0) {
        $chk = $pdo->prepare('SELECT 1 FROM turnos WHERE id = :id');
        $chk->execute([':id' => $id]);
        if ($chk->fetchColumn() === false) {
            fallo('El turno ya no existe.', 404);
        }
        $stmt = $pdo->prepare(
            'UPDATE turnos SET empleado_id = :e, fecha = :f, hora_inicio = :i, hora_fin = :h WHERE id = :id'
        );
        $stmt->execute([':e' => $empleadoId, ':f' => $fecha, ':i' => $inicio, ':h' => $fin, ':id' => $id]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO turnos (empleado_id, fecha, hora_inicio, hora_fin) VALUES (:e, :f, :i, :h)'
        );
        $stmt->execute([':e' => $empleadoId, ':f' => $fecha, ':i' => $inicio, ':h' => $fin]);
        $id = (int)$pdo->lastInsertId();
    }

    // Avisos (no bloquean el guardado)
    $avisos = [];

    $lunes = lunesDe($fecha);
    $semana = calcularMinutos($pdo, $lunes, sumarDias($lunes, 7), $empleadoId);
    $total  = isset($semana[$empleadoId]) ? $semana[$empleadoId]['total'] : 0;
    $maximo = horasMaxSemana($pdo);
    if ($total > $maximo * 60) {
        $avisos[] = 'Aviso: este empleado suma ' . formatoHoras($total) . ' en la semana (máximo ' . $maximo . ' h).';
    }

    $esp = $pdo->prepare(
        'SELECT DISTINCT tipo FROM dias_especiales WHERE fecha = :f AND (empleado_id IS NULL OR empleado_id = :e)'
    );
    $esp->execute([':f' => $fecha, ':e' => $empleadoId]);
    $tipos = array_column($esp->fetchAll(), 'tipo');
    if (count($tipos) > 0) {
        $avisos[] = 'Aviso: el ' . fechaLatina($fecha) . ' está marcado como ' . implode(', ', $tipos) . '.';
    }

    $resp = ['ok' => true, 'id' => $id];
    if (count($avisos) > 0) {
        $resp['warning'] = implode("\n", $avisos);
    }
    responder($resp);
}

/* =========================================================
   Cálculo de horas y resumen
   ========================================================= */

/**
 * Minutos trabajados por empleado dentro de [inicio, fin).
 * Cada turno se reparte por día natural, así un turno nocturno o que cruza el
 * límite de la semana solo cuenta en cada periodo los minutos que le corresponden.
 * Devuelve [empleado_id => ['total' => int, 'dias' => [fecha => true]]].
 */
function calcularMinutos(PDO $pdo, string $inicio, string $fin, ?int $empleadoId = null): array
{
    $sql = 'SELECT empleado_id, fecha, hora_inicio, hora_fin FROM turnos WHERE fecha >= :d AND fecha < :h';
    $par = [':d' => sumarDias($inicio, -1), ':h' => $fin];
    if ($empleadoId !== null) {
        $sql .= ' AND empleado_id = :e';
        $par[':e'] = $empleadoId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($par);

    $res = [];
    foreach ($stmt->fetchAll() as $t) {
        $id = (int)$t['empleado_id'];
        foreach (segmentosTurno($t['fecha'], $t['hora_inicio'], $t['hora_fin']) as $seg) {
            list($dia, $minutos) = $seg;
            if ($dia < $inicio || $dia >= $fin) {
                continue;
            }
            if (!isset($res[$id])) {
                $res[$id] = ['total' => 0, 'dias' => []];
            }
            $res[$id]['total'] += $minutos;
            $res[$id]['dias'][$dia] = true;
        }
    }
    return $res;
}

function resumenSemanal(PDO $pdo): void
{
    list($inicio, $fin) = rangoDesdeGet();
    $calculo   = calcularMinutos($pdo, $inicio, $fin);
    $empleados = $pdo->query('SELECT id, nombre, color_asignado FROM empleados ORDER BY nombre COLLATE NOCASE')->fetchAll();

    $salida = [];
    foreach ($empleados as $e) {
        $id    = (int)$e['id'];
        $total = isset($calculo[$id]) ? $calculo[$id]['total'] : 0;
        $dias  = isset($calculo[$id]) ? count($calculo[$id]['dias']) : 0;
        $salida[] = [
            'empleado_id'      => $id,
            'nombre'           => $e['nombre'],
            'color'            => $e['color_asignado'],
            'total_min'        => $total,
            'dias_trabajados'  => $dias,
            'media_diaria_min' => $dias > 0 ? intdiv($total, $dias) : 0,
        ];
    }
    responder($salida);
}

/* =========================================================
   Días especiales y configuración
   ========================================================= */

function listarDias(PDO $pdo): void
{
    list($inicio, $fin) = rangoDesdeGet();
    $stmt = $pdo->prepare(
        'SELECT d.id, d.fecha, d.tipo, d.empleado_id, d.nota, e.nombre AS empleado_nombre
         FROM dias_especiales d LEFT JOIN empleados e ON e.id = d.empleado_id
         WHERE d.fecha >= :d AND d.fecha < :h
         ORDER BY d.fecha, d.id'
    );
    $stmt->execute([':d' => $inicio, ':h' => $fin]);
    $filas = $stmt->fetchAll();
    foreach ($filas as &$f) {
        $f['id']          = (int)$f['id'];
        $f['empleado_id'] = ($f['empleado_id'] === null) ? null : (int)$f['empleado_id'];
    }
    unset($f);
    responder($filas);
}

function crearDia(PDO $pdo, array $d): void
{
    $fecha      = texto($d, 'fecha');
    $fechaFin   = texto($d, 'fecha_fin');
    $tipo       = texto($d, 'tipo');
    $empleadoId = entero($d, 'empleado_id');
    $nota       = texto($d, 'nota');

    if ($fechaFin === '') {
        $fechaFin = $fecha;
    }
    if (!fechaValida($fecha) || !fechaValida($fechaFin)) {
        fallo('Fecha no válida.');
    }
    if ($fechaFin < $fecha) {
        fallo('La fecha final no puede ser anterior a la inicial.');
    }
    $dias = numeroDia($fechaFin) - numeroDia($fecha);
    if ($dias > 365) {
        fallo('El periodo no puede superar un año.');
    }
    if (!in_array($tipo, ['descanso', 'vacaciones', 'festivo'], true)) {
        fallo('Tipo de día no válido.');
    }
    if ($empleadoId > 0 && !existeEmpleado($pdo, $empleadoId)) {
        fallo('Empleado no válido.');
    }
    if (longitud($nota) > 120) {
        fallo('La nota es demasiado larga (máx. 120 caracteres).');
    }

    $empParam = $empleadoId > 0 ? $empleadoId : null;
    $existe = $pdo->prepare('SELECT 1 FROM dias_especiales WHERE fecha = :f AND tipo = :t AND empleado_id IS :e LIMIT 1');
    $ins    = $pdo->prepare('INSERT INTO dias_especiales (fecha, tipo, empleado_id, nota) VALUES (:f, :t, :e, :n)');

    $creados = 0;
    $pdo->beginTransaction();
    try {
        for ($i = 0; $i <= $dias; $i++) {
            $f = sumarDias($fecha, $i);
            $existe->execute([':f' => $f, ':t' => $tipo, ':e' => $empParam]);
            if ($existe->fetchColumn() !== false) {
                continue;
            }
            $ins->execute([':f' => $f, ':t' => $tipo, ':e' => $empParam, ':n' => $nota !== '' ? $nota : null]);
            $creados++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    responder(['ok' => true, 'creados' => $creados]);
}

function valorConfig(PDO $pdo, string $clave, string $defecto): string
{
    $stmt = $pdo->prepare('SELECT valor FROM configuracion WHERE clave = :c');
    $stmt->execute([':c' => $clave]);
    $v = $stmt->fetchColumn();
    return ($v === false || $v === '') ? $defecto : (string)$v;
}

function leerConfiguracion(PDO $pdo): void
{
    responder([
        'horas_max_semana' => horasMaxSemana($pdo),
        'empresa_nombre'   => valorConfig($pdo, 'empresa_nombre', 'Mi Empresa'),
        'empresa_logo'     => valorConfig($pdo, 'empresa_logo', ''),
        'ruta_datos'       => rutaBaseDatos(),
        'bd_antigua_expuesta' => hayBaseAntiguaExpuesta(),
    ]);
}

function guardarConfiguracion(PDO $pdo, array $d): void
{
    $clave = texto($d, 'clave');
    if ($clave === 'horas_max_semana') {
        $valor = entero($d, 'valor');
        if ($valor < 1 || $valor > 80) {
            fallo('Las horas máximas deben estar entre 1 y 80.');
        }
        $guardar = (string)$valor;
    } elseif ($clave === 'empresa_nombre') {
        $guardar = texto($d, 'valor');
        if ($guardar === '' || longitud($guardar) > 60) {
            fallo('El nombre de la empresa es obligatorio (máx. 60 caracteres).');
        }
    } elseif ($clave === 'empresa_logo') {
        $guardar = cruda($d, 'valor');
        if ($guardar !== '' && (strlen($guardar) > 400000
            || preg_match('/^data:image\/(png|jpeg|gif|webp);base64,[A-Za-z0-9+\/=]+$/', $guardar) !== 1)) {
            fallo('El logotipo no es válido (PNG, JPG, GIF o WebP, máx. 300 KB).');
        }
    } else {
        fallo('Clave de configuración no válida.');
    }
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO configuracion (clave, valor) VALUES (:c, :v)');
    $stmt->execute([':c' => $clave, ':v' => $guardar]);
    responder(['ok' => true]);
}

/* =========================================================
   Descargas: copia de seguridad y CSV
   ========================================================= */

function descargarBackup(): void
{
    $ruta = rutaBaseDatos();
    if (!is_file($ruta)) {
        fallo('No se encuentra la base de datos.', 404);
    }
    limpiarBuffers();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="turnos_' . date('Ymd_His') . '.db"');
    header('Content-Length: ' . filesize($ruta));
    header('Cache-Control: no-store');
    readfile($ruta);
    exit;
}

function csvSeguro(string $valor): string
{
    // Evita la inyección de fórmulas al abrir el CSV en Excel.
    return (preg_match('/^[=+\-@\t\r]/', $valor) === 1) ? "'" . $valor : $valor;
}

function exportarCsv(PDO $pdo): void
{
    list($inicio, $fin) = rangoDesdeGet();
    $stmt = $pdo->prepare(
        'SELECT t.fecha, t.hora_inicio, t.hora_fin, e.nombre
         FROM turnos t JOIN empleados e ON e.id = t.empleado_id
         WHERE t.fecha >= :d AND t.fecha < :h
         ORDER BY t.fecha, e.nombre COLLATE NOCASE, t.hora_inicio'
    );
    $stmt->execute([':d' => $inicio, ':h' => $fin]);
    $filas = $stmt->fetchAll();

    limpiarBuffers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="turnos_' . $inicio . '_' . $fin . '.csv"');
    header('Cache-Control: no-store');

    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF"); // BOM para que Excel detecte UTF-8
    fputcsv($salida, ['Empleado', 'Fecha', 'Entrada', 'Salida', 'Horas'], ';', '"', '\\');
    foreach ($filas as $t) {
        $min = minutosDeHora($t['hora_fin']) - minutosDeHora($t['hora_inicio']);
        if ($min <= 0) {
            $min += 1440;
        }
        fputcsv($salida, [
            csvSeguro((string)$t['nombre']),
            fechaLatina($t['fecha']),
            $t['hora_inicio'],
            $t['hora_fin'],
            number_format($min / 60, 2, ',', ''),
        ], ';', '"', '\\');
    }
    fclose($salida);
    exit;
}

/* =========================================================
   Restaurar copia de seguridad
   ========================================================= */

/**
 * Comprueba que un archivo es una base de datos de este programa.
 * Devuelve un mensaje de error, o null si es válida. Trabaja sobre una copia temporal,
 * nunca sobre la base de datos en uso.
 */
function validarBaseSqlite(string $ruta): ?string
{
    try {
        $v = new PDO('sqlite:' . $ruta, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $integridad = (string)$v->query('PRAGMA integrity_check')->fetchColumn();
        if (strtolower($integridad) !== 'ok') {
            return 'La copia está dañada (ha fallado la comprobación de integridad).';
        }

        $tablas = [];
        foreach ($v->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $tablas[strtolower((string)$t)] = true;
        }
        $requeridas = [
            'empleados'       => ['id', 'nombre', 'color_asignado'],
            'turnos'          => ['id', 'empleado_id', 'fecha', 'hora_inicio', 'hora_fin'],
            'admin'           => ['id', 'usuario', 'password_hash'],
            'dias_especiales' => ['id', 'fecha', 'tipo', 'empleado_id'],
            'configuracion'   => ['clave', 'valor'],
        ];
        foreach ($requeridas as $tabla => $columnas) {
            if (!isset($tablas[$tabla])) {
                return 'El archivo no es una copia de este programa (falta la tabla «' . $tabla . '»).';
            }
            $presentes = [];
            foreach ($v->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $presentes[strtolower((string)$c['name'])] = true;
            }
            foreach ($columnas as $col) {
                if (!isset($presentes[$col])) {
                    return 'El archivo no es una copia de este programa (falta la columna «' . $col . '» en «' . $tabla . '»).';
                }
            }
        }

        $extra = (int)$v->query("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('trigger', 'view')")->fetchColumn();
        if ($extra > 0) {
            return 'La copia contiene elementos no permitidos (disparadores o vistas).';
        }
        if ((int)$v->query('SELECT COUNT(*) FROM admin')->fetchColumn() < 1) {
            return 'La copia no contiene ningún usuario administrador.';
        }
    } catch (Throwable $e) {
        return 'No se pudo leer la copia: no parece una base de datos válida.';
    }
    return null;
}

/**
 * Recibe un .db por POST (multipart), lo valida y lo deja preparado como «turnos.restaurar».
 * El reemplazo real lo hace db() en la siguiente petición, antes de abrir la base de datos,
 * así nunca se sustituye un archivo que esta misma petición tenga abierto.
 */
function restaurarBackup(PDO $pdo, array $admin): void
{
    $stmt = $pdo->prepare('SELECT password_hash FROM admin WHERE id = :id');
    $stmt->execute([':id' => (int)$admin['id']]);
    if (!password_verify(cruda($_POST, 'contrasena'), (string)$stmt->fetchColumn())) {
        fallo('La contraseña no es correcta.');
    }

    if (!isset($_FILES['archivo']) || !is_array($_FILES['archivo'])) {
        fallo('No se ha recibido ningún archivo.');
    }
    $f   = $_FILES['archivo'];
    $err = isset($f['error']) ? (int)$f['error'] : UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            fallo('El archivo supera el tamaño máximo de subida permitido por PHP (upload_max_filesize / post_max_size en php.ini).');
        }
        fallo('No se pudo subir el archivo (código ' . $err . ').');
    }

    $tmp = isset($f['tmp_name']) ? (string)$f['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        fallo('El archivo recibido no es válido.');
    }
    $tam = (int)filesize($tmp);
    if ($tam < 512 || $tam > 200 * 1024 * 1024) {
        fallo('El archivo no tiene un tamaño válido para una copia de seguridad.');
    }
    $fh  = fopen($tmp, 'rb');
    $cab = ($fh === false) ? '' : (string)fread($fh, 16);
    if ($fh !== false) {
        fclose($fh);
    }
    if ($cab !== "SQLite format 3\0") {
        fallo('El archivo no es una base de datos SQLite.');
    }

    $carpeta = carpetaDatos();
    $prueba  = $carpeta . DIRECTORY_SEPARATOR . 'turnos.validando';
    if (!move_uploaded_file($tmp, $prueba)) {
        fallo('No se pudo guardar el archivo para comprobarlo.', 500);
    }
    $error = validarBaseSqlite($prueba);
    if ($error !== null) {
        @unlink($prueba);
        fallo($error);
    }

    $pendiente = $carpeta . DIRECTORY_SEPARATOR . 'turnos.restaurar';
    if (is_file($pendiente)) {
        @unlink($pendiente);
    }
    if (!@rename($prueba, $pendiente)) {
        @unlink($prueba);
        fallo('No se pudo preparar la restauración.', 500);
    }
    responder(['ok' => true]);
}

/* =========================================================
   Enrutado
   ========================================================= */

function despacharPost(PDO $pdo): void
{
    // La restauración llega como multipart/form-data (archivo), no como JSON.
    if (isset($_POST['accion']) && $_POST['accion'] === 'restaurar_backup') {
        $admin = exigirSesion($pdo);
        restaurarBackup($pdo, $admin);
        return;
    }

    $d      = leerCuerpo();
    $accion = texto($d, 'accion');

    if ($accion === 'login') {
        login($pdo, $d);
        return;
    }

    $admin = exigirSesion($pdo, $accion === 'cambiar_password' || $accion === 'logout');

    switch ($accion) {
        case 'logout':
            cerrarSesion($pdo, $admin);
            break;
        case 'cambiar_password':
            cambiarPassword($pdo, $admin, $d);
            break;
        case 'crear_empleado':
            crearEmpleado($pdo, $d);
            break;
        case 'guardar_empleado':
            guardarEmpleado($pdo, $d);
            break;
        case 'borrar_empleado':
            borrarEmpleado($pdo, entero($d, 'id'));
            break;
        case 'generar_turnos':
            generarTurnos($pdo, $d);
            break;
        case 'copiar_periodo':
            copiarPeriodo($pdo, $d);
            break;
        case 'crear_turnos':
            crearTurnos($pdo, $d);
            break;
        case 'borrar_turnos':
            borrarTurnos($pdo, $d);
            break;
        case 'crear_dias_lote':
            crearDiasLote($pdo, $d);
            break;
        case 'guardar_tipo':
            guardarTipo($pdo, $d);
            break;
        case 'borrar_tipo':
            borrarFila($pdo, 'tipos_turno', entero($d, 'id'));
            break;
        case 'cargar_demo':
            cargarDemo($pdo);
            break;
        case 'guardar_turno':
            guardarTurno($pdo, $d);
            break;
        case 'borrar_turno':
            borrarFila($pdo, 'turnos', entero($d, 'id'));
            break;
        case 'crear_dia':
            crearDia($pdo, $d);
            break;
        case 'borrar_dia':
            borrarFila($pdo, 'dias_especiales', entero($d, 'id'));
            break;
        case 'set_config':
            guardarConfiguracion($pdo, $d);
            break;
        default:
            fallo('Acción desconocida.');
    }
}

function despacharGet(PDO $pdo): void
{
    $recurso = param('recurso');
    $admin   = exigirSesion($pdo, $recurso === 'sesion');

    switch ($recurso) {
        case 'sesion':
            responder([
                'ok'                    => true,
                'usuario'               => $admin['usuario'],
                'debe_cambiar_password' => ((int)$admin['debe_cambiar_password'] === 1),
            ]);
            break;
        case 'empleados':
            listarEmpleados($pdo);
            break;
        case 'configuracion':
            leerConfiguracion($pdo);
            break;
        case 'turnos':
            listarTurnos($pdo);
            break;
        case 'tipos_turno':
            listarTipos($pdo);
            break;
        case 'dias_especiales':
            listarDias($pdo);
            break;
        case 'resumen_semanal':
            resumenSemanal($pdo);
            break;
        case 'backup':
            descargarBackup();
            break;
        case 'exportar_csv':
            exportarCsv($pdo);
            break;
        default:
            fallo('Recurso desconocido.', 404);
    }
}

$metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$pdo    = db();

if ($metodo === 'POST') {
    despacharPost($pdo);
} elseif ($metodo === 'GET') {
    despacharGet($pdo);
} else {
    fallo('Método no permitido.', 405);
}
