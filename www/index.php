<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

/** Versión (fecha de modificación) de un archivo estático, para que el navegador no use una copia antigua. */
function versionArchivo(string $archivo): string
{
    $ruta = __DIR__ . DIRECTORY_SEPARATOR . $archivo;
    return is_file($ruta) ? (string)filemtime($ruta) : '1';
}

$nombreEmpresa = 'Mi Empresa';
$errorBd = null;
try {
    $n = db()->query("SELECT valor FROM configuracion WHERE clave = 'empresa_nombre'")->fetchColumn();
    if (is_string($n) && $n !== '') {
        $nombreEmpresa = $n;
    }
} catch (Throwable $e) {
    $errorBd = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Planificador de Turnos - <?= htmlspecialchars($nombreEmpresa, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="style.css?v=<?= versionArchivo('style.css') ?>">
</head>
<body data-empresa="<?= htmlspecialchars($nombreEmpresa, ENT_QUOTES, 'UTF-8') ?>">

<?php if ($errorBd !== null): ?>
    <main class="fallo-bd">
        <h1>Error de base de datos</h1>
        <p><?= htmlspecialchars($errorBd, ENT_QUOTES, 'UTF-8') ?></p>
    </main>
</body>
</html>
<?php exit; endif; ?>

<!-- LOGIN -->
<div id="pantalla-login" class="pantalla-login" style="display: none;">
    <form id="form-login" class="login-caja" autocomplete="off">
        <h1>Planificador de Turnos</h1>
        <p class="login-sub">Acceso administrador</p>
        <label class="campo"><span>Usuario</span><input type="text" id="login-usuario" required autofocus></label>
        <label class="campo"><span>Contraseña</span><input type="password" id="login-password" required></label>
        <p id="login-error" class="mensaje-error" style="display: none;"></p>
        <button type="submit" id="btn-login" class="btn btn-primario">Entrar</button>
    </form>
</div>

<!-- CAMBIO OBLIGATORIO DE CONTRASEÑA -->
<div id="pantalla-cambio" class="pantalla-login" style="display: none;">
    <form id="form-cambio" class="login-caja" autocomplete="off">
        <h1>Cambiar contraseña</h1>
        <p class="login-sub">Debes cambiar la contraseña por defecto.</p>
        <label class="campo"><span>Contraseña actual</span><input type="password" id="cambio-actual" required></label>
        <label class="campo"><span>Nueva contraseña (mín. 6)</span><input type="password" id="cambio-nueva" required minlength="6"></label>
        <p id="cambio-error" class="mensaje-error" style="display: none;"></p>
        <button type="submit" class="btn btn-primario">Guardar</button>
    </form>
</div>

<!-- APLICACIÓN -->
<div id="app" class="app" style="display: none;">
    <aside class="sidebar">
        <header class="marca">
            <span id="marca-logo"></span>
            <div>
                <h2 id="marca-nombre"><?= htmlspecialchars($nombreEmpresa, ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="sidebar-sub">Planificador de turnos</p>
            </div>
        </header>

        <section class="panel">
            <h3>Empleados</h3>
            <form id="form-empleado" class="fila-form" autocomplete="off">
                <input type="text" id="empleado-nombre" placeholder="Nombre del empleado" required maxlength="60">
                <input type="color" id="empleado-color" value="#3b82f6" title="Color">
                <button type="submit" class="btn btn-primario btn-mini" data-ico="plus" title="Añadir"></button>
            </form>
            <ul id="lista-empleados" class="lista-empleados"></ul>
            <p class="ayuda">Al añadir un empleado se abre su ficha para definir su horario semanal.</p>
        </section>

        <section class="panel">
            <h3>Resumen del periodo</h3>
            <table class="tabla-resumen">
                <thead><tr><th>Empleado</th><th>Horas</th><th>Días</th><th>Media</th></tr></thead>
                <tbody id="tabla-resumen"></tbody>
            </table>
        </section>
    </aside>

    <main class="contenido">
        <header class="contenido-cabecera">
            <label class="filtro-empleado"><span>Ver:</span>
                <select id="filtro-empleado"><option value="">Todos los empleados</option></select>
            </label>
            <div class="cabecera-der">
                <button type="button" id="btn-nuevo-turno" class="btn btn-primario" data-ico="plus">Nuevo turno</button>
                <button type="button" id="btn-generar" class="btn btn-secundario" data-ico="zap" title="Crear los turnos del periodo según el horario de cada ficha">Generar periodo</button>
                <button type="button" id="btn-copiar" class="btn btn-secundario" data-ico="copy" title="Copia los turnos de la semana anterior a la visible">Copiar semana anterior</button>
                <button type="button" id="btn-modo-sel" class="btn btn-secundario" data-ico="select" title="Pulsar celdas para seleccionarlas (también Ctrl+clic)">Selección múltiple</button>
                <button type="button" id="btn-ausencias" class="btn btn-secundario" data-ico="calx" title="Vacaciones, descansos y festivos">Ausencias</button>
                <button type="button" id="btn-imprimir" class="btn btn-secundario" data-ico="print">Imprimir PDF</button>
                <button type="button" id="btn-csv" class="btn btn-secundario" data-ico="download">CSV</button>
                <button type="button" id="btn-modo-oscuro" class="btn btn-icono" title="Modo claro/oscuro"></button>
                <button type="button" id="btn-ajustes" class="btn btn-icono" data-ico="settings" title="Ajustes"></button>
            </div>
        </header>

        <div class="barra-nav">
            <div class="nav-grupo">
                <button type="button" id="nav-ant" class="btn btn-secundario btn-icono" data-ico="chevl" title="Anterior"></button>
                <button type="button" id="nav-hoy" class="btn btn-secundario">Hoy</button>
                <button type="button" id="nav-sig" class="btn btn-secundario btn-icono" data-ico="chevr" title="Siguiente"></button>
            </div>
            <div id="nav-titulo" class="nav-titulo"></div>
            <div class="segmentado">
                <button type="button" id="vista-semana" data-vista="semana" class="activo">Semana</button>
                <button type="button" id="vista-mes" data-vista="mes">Mes</button>
            </div>
            <div class="nav-espacio"></div>
            <button type="button" id="btn-vaciar" class="btn btn-secundario btn-mini" data-ico="trash" title="Borra los turnos visibles (se puede deshacer)">Vaciar periodo</button>
        </div>

        <div id="leyenda" class="leyenda"></div>
        <div id="print-cabecera" class="print-cabecera"></div>

        <div id="cuadrante-wrap" class="cuadrante-wrap">
            <div id="cuadrante"></div>
        </div>

        <div id="barra-seleccion" class="barra-seleccion" style="display: none;"></div>
    </main>
</div>

<!-- MODAL TURNO -->
<div id="modal-turno" class="modal" style="display: none;">
    <div class="modal-caja">
        <h3 id="modal-titulo">Nuevo turno</h3>
        <form id="form-turno" autocomplete="off">
            <input type="hidden" id="turno-id">
            <label class="campo solo-individual"><span>Empleado</span><select id="turno-empleado" required></select></label>
            <label class="campo solo-individual"><span>Fecha</span><div class="fecha-in" id="turno-fecha"></div></label>
            <label class="campo"><span>Tipo de turno</span><select id="turno-tipo"></select></label>
            <div class="fila-doble">
                <label class="campo"><span>Entrada</span><div class="hora-sel" id="turno-inicio"></div></label>
                <label class="campo"><span>Salida</span><div class="hora-sel" id="turno-fin"></div></label>
            </div>
            <p class="ayuda">Formato 24 h. Si la salida es anterior a la entrada, el turno termina al día siguiente (turno nocturno).</p>
            <div id="turno-marcas" class="fila-botones solo-individual" style="margin-bottom: 10px;">
                <span class="ayuda" style="margin: 0; align-self: center;">O marcar el día como:</span>
                <button type="button" class="btn btn-secundario btn-mini" data-marcar="descanso">Descanso</button>
                <button type="button" class="btn btn-secundario btn-mini" data-marcar="vacaciones">Vacaciones</button>
            </div>
            <p id="turno-error" class="mensaje-error" style="display: none;"></p>
            <div class="modal-acciones">
                <button type="button" id="btn-borrar-turno" class="btn btn-peligro" style="display: none;">Borrar</button>
                <div class="espaciador"></div>
                <button type="button" id="btn-cancelar-turno" class="btn btn-secundario">Cancelar</button>
                <button type="submit" class="btn btn-primario">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL FICHA DE EMPLEADO -->
<div id="modal-ficha" class="modal" style="display: none;">
    <div class="modal-caja modal-ancha">
        <h3>Ficha del empleado</h3>
        <form id="form-ficha" autocomplete="off">
            <input type="hidden" id="ficha-id">
            <div class="fila-form ficha-cabecera">
                <input type="text" id="ficha-nombre" placeholder="Nombre" required maxlength="60">
                <input type="color" id="ficha-color" value="#3b82f6" title="Color">
            </div>

            <h4 class="subtitulo">Horario semanal fijo</h4>
            <p class="ayuda">Marque los días que trabaja y su horario (24 h). Puede usar un tipo de turno como atajo y añadir un segundo tramo para turnos partidos.</p>
            <div id="ficha-horario" class="ficha-horario"></div>
            <button type="button" id="btn-copiar-lunes" class="btn btn-secundario btn-mini">Copiar el lunes a martes–viernes</button>

            <h4 class="subtitulo">Generar turnos de este empleado</h4>
            <div class="fila-doble">
                <label class="campo"><span>Desde</span><div class="fecha-in" id="ficha-desde"></div></label>
                <label class="campo"><span>Hasta (incluido)</span><div class="fecha-in" id="ficha-hasta"></div></label>
            </div>
            <p class="ayuda">Se guardan los cambios de la ficha y se crean los turnos de los días marcados. No se modifican los turnos existentes y se omiten vacaciones, descansos y festivos.</p>
            <button type="button" id="btn-generar-turnos" class="btn btn-secundario">Generar turnos del periodo</button>

            <p id="ficha-error" class="mensaje-error" style="display: none; margin-top: 10px;"></p>
            <div class="modal-acciones">
                <button type="button" id="btn-borrar-empleado" class="btn btn-peligro">Borrar empleado</button>
                <div class="espaciador"></div>
                <button type="button" id="btn-cancelar-ficha" class="btn btn-secundario">Cancelar</button>
                <button type="submit" class="btn btn-primario">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL GENERAR PERIODO -->
<div id="modal-generar" class="modal" style="display: none;">
    <div class="modal-caja modal-media">
        <h3>Generar turnos del periodo</h3>
        <form id="form-generar" autocomplete="off">
            <div class="fila-doble">
                <label class="campo"><span>Desde</span><div class="fecha-in" id="gen-desde"></div></label>
                <label class="campo"><span>Hasta (incluido)</span><div class="fecha-in" id="gen-hasta"></div></label>
            </div>
            <h4 class="subtitulo" style="margin-top: 4px;">Empleados</h4>
            <div id="gen-lista" class="gen-lista"></div>
            <p class="ayuda">Se usa el horario semanal de cada ficha. No se tocan los turnos existentes y se omiten vacaciones, descansos y festivos.</p>
            <p id="gen-error" class="mensaje-error" style="display: none;"></p>
            <div class="modal-acciones">
                <div class="espaciador"></div>
                <button type="button" id="btn-cancelar-generar" class="btn btn-secundario">Cancelar</button>
                <button type="submit" class="btn btn-primario" data-ico="zap">Generar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL AUSENCIAS -->
<div id="modal-ausencias" class="modal" style="display: none;">
    <div class="modal-caja modal-media">
        <h3>Vacaciones, descansos y festivos</h3>
        <form id="form-dia" class="form-dia" autocomplete="off">
            <div class="fila-doble">
                <label class="campo"><span>Desde</span><div class="fecha-in" id="dia-fecha"></div></label>
                <label class="campo"><span>Hasta (incluido)</span><div class="fecha-in" id="dia-fecha-fin"></div></label>
            </div>
            <div class="fila-doble">
                <label class="campo"><span>Tipo</span>
                    <select id="dia-tipo" required>
                        <option value="vacaciones">Vacaciones</option>
                        <option value="descanso">Descanso</option>
                        <option value="festivo">Festivo</option>
                    </select>
                </label>
                <label class="campo"><span>Quién</span><select id="dia-empleado"><option value="">Toda la empresa</option></select></label>
            </div>
            <label class="campo"><span>Nota (opcional)</span><input type="text" id="dia-nota" maxlength="120"></label>
            <p id="dia-error" class="mensaje-error" style="display: none;"></p>
            <button type="submit" class="btn btn-primario">Añadir</button>
        </form>
        <h4 class="subtitulo">Próximos días marcados</h4>
        <ul id="lista-dias" class="lista-dias"></ul>
        <div class="modal-acciones">
            <div class="espaciador"></div>
            <button type="button" id="btn-cerrar-ausencias" class="btn btn-secundario">Cerrar</button>
        </div>
    </div>
</div>

<!-- MODAL AJUSTES -->
<div id="modal-ajustes" class="modal" style="display: none;">
    <div class="modal-caja modal-media">
        <h3>Ajustes</h3>
        <div class="bloque-ajustes">
            <label class="campo"><span>Nombre de la empresa</span><input type="text" id="aj-nombre" maxlength="60"></label>
            <div class="campo"><span>Logotipo (aparece en el PDF impreso)</span>
                <div class="logo-prev"><span id="aj-logo-prev"></span></div>
                <div class="fila-botones">
                    <input type="file" id="aj-logo-file" accept="image/png,image/jpeg,image/gif,image/webp" style="display: none;">
                    <button type="button" id="btn-elegir-logo" class="btn btn-secundario btn-mini">Elegir imagen…</button>
                    <button type="button" id="btn-quitar-logo" class="btn btn-secundario btn-mini">Quitar</button>
                </div>
            </div>
            <label class="campo"><span>Horas máximas por semana (aviso al superarlas)</span><input type="number" id="aj-horas" min="1" max="80" step="1" style="max-width: 120px;"></label>
            <p id="aj-error" class="mensaje-error" style="display: none;"></p>
            <button type="button" id="btn-guardar-ajustes" class="btn btn-primario">Guardar ajustes</button>
        </div>
        <div class="bloque-ajustes">
            <div class="fila-botones">
                <button type="button" id="btn-tipos" class="btn btn-secundario">Tipos de turno</button>
                <button type="button" id="btn-backup" class="btn btn-secundario" data-ico="download">Copia de seguridad</button>
                <button type="button" id="btn-cambiar-pass" class="btn btn-secundario" data-ico="key">Cambiar contraseña</button>
                <button type="button" id="btn-logout" class="btn btn-peligro" data-ico="logout">Cerrar sesión</button>
            </div>
        </div>
        <div class="bloque-ajustes">
            <h4 class="subtitulo" style="margin-top: 0;">Restaurar copia de seguridad</h4>
            <p class="ayuda">Sustituye <b>todos</b> los datos actuales por los de un archivo .db descargado con «Copia de seguridad». Antes se guarda automáticamente una copia de los datos actuales.</p>
            <input type="file" id="rest-file" accept=".db,application/octet-stream" style="display: none;">
            <div class="fila-botones" style="margin-bottom: 10px;">
                <button type="button" id="btn-elegir-copia" class="btn btn-secundario btn-mini">Elegir archivo .db…</button>
                <span id="rest-nombre" class="ayuda" style="margin: 0; align-self: center;">Ningún archivo seleccionado</span>
            </div>
            <label class="campo"><span>Su contraseña actual (para confirmar)</span><input type="password" id="rest-pass" autocomplete="off"></label>
            <p id="rest-error" class="mensaje-error" style="display: none;"></p>
            <button type="button" id="btn-restaurar" class="btn btn-peligro">Restaurar copia…</button>
        </div>
        <div class="bloque-ajustes">
            <p class="ayuda" style="margin: 0;">Ubicación de los datos: <code id="aj-ruta"></code></p>
            <p id="aj-aviso-bd" class="mensaje-error" style="display: none; margin-top: 10px;">Atención: todavía existe un <code>turnos.db</code> dentro de la carpeta del programa (www). Cierre el programa y bórrelo manualmente.</p>
        </div>
        <div class="modal-acciones">
            <div class="espaciador"></div>
            <button type="button" id="btn-cerrar-ajustes" class="btn btn-secundario">Cerrar</button>
        </div>
    </div>
</div>

<!-- MODAL TIPOS DE TURNO -->
<div id="modal-tipos" class="modal" style="display: none;">
    <div class="modal-caja modal-media">
        <h3>Tipos de turno</h3>
        <p class="ayuda">Los turnos cuyo horario coincide con un tipo se muestran con su color en el cuadrante.</p>
        <ul id="tipos-lista" class="lista-tipos"></ul>
        <h4 class="subtitulo" id="tipo-form-titulo">Nuevo tipo</h4>
        <form id="form-tipo" autocomplete="off">
            <input type="hidden" id="tipo-id">
            <div class="fila-form" style="margin-bottom: 10px;">
                <input type="text" id="tipo-nombre" placeholder="Nombre (p. ej. Mañana)" maxlength="30" required>
                <input type="color" id="tipo-color" value="#3b82f6" title="Color">
            </div>
            <div class="fila-doble">
                <label class="campo"><span>Entrada</span><div class="hora-sel" id="tipo-inicio"></div></label>
                <label class="campo"><span>Salida</span><div class="hora-sel" id="tipo-fin"></div></label>
            </div>
            <p id="tipo-error" class="mensaje-error" style="display: none;"></p>
            <div class="modal-acciones">
                <button type="button" id="btn-tipo-nuevo" class="btn btn-secundario">Limpiar</button>
                <div class="espaciador"></div>
                <button type="button" id="btn-cerrar-tipos" class="btn btn-secundario">Cerrar</button>
                <button type="submit" class="btn btn-primario">Guardar tipo</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL CAMBIAR CONTRASEÑA -->
<div id="modal-pass" class="modal" style="display: none;">
    <div class="modal-caja">
        <h3>Cambiar contraseña</h3>
        <form id="form-cambiar-pass" autocomplete="off">
            <label class="campo"><span>Contraseña actual</span><input type="password" id="pass-actual" required></label>
            <label class="campo"><span>Nueva contraseña (mín. 6)</span><input type="password" id="pass-nueva" required minlength="6"></label>
            <p id="pass-error" class="mensaje-error" style="display: none;"></p>
            <div class="modal-acciones">
                <div class="espaciador"></div>
                <button type="button" id="btn-cancelar-pass" class="btn btn-secundario">Cancelar</button>
                <button type="submit" class="btn btn-primario">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- DIÁLOGO PROPIO (sustituye a alert/confirm del navegador) -->
<div id="modal-dialogo" class="modal modal-dialogo" style="display: none;">
    <div class="modal-caja">
        <p id="dialogo-texto" class="dialogo-texto"></p>
        <div class="modal-acciones">
            <div class="espaciador"></div>
            <button type="button" id="dialogo-cancelar" class="btn btn-secundario">Cancelar</button>
            <button type="button" id="dialogo-ok" class="btn btn-primario">Aceptar</button>
        </div>
    </div>
</div>

<div id="datepicker" class="datepicker" style="display: none;"></div>
<div id="toast" class="toast" style="display: none;"></div>

<script src="app.js?v=<?= versionArchivo('app.js') ?>"></script>
</body>
</html>
