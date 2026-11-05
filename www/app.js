(function () {
    'use strict';

    var $ = function (s) { return document.querySelector(s); };
    var qsa = function (s) { return Array.prototype.slice.call(document.querySelectorAll(s)); };
    var API = 'api.php';
    var CLAVE_TOKEN = 'turnos_token';
    var CLAVE_OSCURO = 'turnos_oscuro';
    var CLAVE_VISTA = 'turnos_vista';

    var TIPOS_DIA = { descanso: 'Descanso', vacaciones: 'Vacaciones', festivo: 'Festivo' };
    var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    var MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    var DIAS_SEMANA = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    var DIAS_INICIAL = ['', 'L', 'M', 'X', 'J', 'V', 'S', 'D'];
    var JS_CORTO = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];   // índice = Date.getDay()
    var JS_INICIAL = ['D', 'L', 'M', 'X', 'J', 'V', 'S'];

    var ICO = {
        plus: '<path d="M12 5v14M5 12h14"/>',
        edit: '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/>',
        trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/>',
        calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        calx: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M10 14l4 4M14 14l-4 4"/>',
        chevl: '<path d="M15 18l-6-6 6-6"/>',
        chevr: '<path d="M9 18l6-6-6-6"/>',
        print: '<path d="M6 9V2h12v7"/><rect x="6" y="14" width="12" height="8"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>',
        download: '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        moon: '<path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/>',
        settings: '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        zap: '<path d="M13 2L3 14h9l-1 8 10-12h-9z"/>',
        copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>',
        select: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 12l2 2 4-4"/>',
        x: '<path d="M18 6L6 18M6 6l12 12"/>',
        alert: '<path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0zM12 9v4M12 17h.01"/>',
        logout: '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
        key: '<circle cx="8" cy="15" r="4"/><path d="M10.9 12.1L21 2M16 7l3 3"/>'
    };
    function ico(n) { return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true">' + (ICO[n] || '') + '</svg>'; }

    var token = '';
    try { token = window.localStorage.getItem(CLAVE_TOKEN) || ''; } catch (e) { token = ''; }

    var estado = {
        vista: 'semana', ancla: new Date(), turnos: [], dias: [], resumen: [],
        seleccion: {}, modoSel: false, cargaN: 0, arrastrando: 0
    };
    var empleadosCache = [], tiposCache = [], diasLista = [];
    var horasMax = 40;
    var empresa = { nombre: document.body.getAttribute('data-empresa') || 'Mi Empresa', logo: '', rutaDatos: '', bdAntigua: false };
    var logoPend = null;   // null = sin cambios; '' = quitar; 'data:...' = nuevo
    var modoTurno = 'nuevo';

    /* =========================================================
       Utilidades
       ========================================================= */

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function dos(n) { return (n < 10 ? '0' : '') + n; }
    function fechaLocal(d) { return d.getFullYear() + '-' + dos(d.getMonth() + 1) + '-' + dos(d.getDate()); }
    function sumarDiasLocal(d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }
    function dateDeIso(iso) { var p = String(iso).split('-'); return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])); }
    function fechaMas(iso, n) { return fechaLocal(sumarDiasLocal(dateDeIso(iso), n)); }
    function lunesDeDate(d) { var n = d.getDay() || 7; return new Date(d.getFullYear(), d.getMonth(), d.getDate() - (n - 1)); }
    function lunesIso(iso) { return fechaLocal(lunesDeDate(dateDeIso(iso))); }
    function fechaLatina(f) {
        var p = String(f).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : f;
    }
    function mins(h) { return Number(h.slice(0, 2)) * 60 + Number(h.slice(3, 5)); }
    function corto(h) { var p = h.split(':'); return p[1] === '00' ? String(Number(p[0])) : Number(p[0]) + ':' + p[1]; }
    function fmtHoras(min) {
        var h = Math.floor(min / 60), m = min % 60;
        return m ? h + 'h ' + m + 'm' : h + 'h';
    }
    function colorTexto(hex) {
        var r = parseInt(hex.substr(1, 2), 16), g = parseInt(hex.substr(3, 2), 16), b = parseInt(hex.substr(5, 2), 16);
        return ((r * 299 + g * 587 + b * 114) / 1000) >= 150 ? '#111827' : '#ffffff';
    }
    // Reparte un turno por días naturales: [[fecha, minutos], ...] (igual que en el servidor).
    function segmentos(fecha, ini, fin) {
        var i = mins(ini), f = mins(fin);
        if (f > i) { return [[fecha, f - i]]; }
        var s = [[fecha, 1440 - i]];
        if (f > 0) { s.push([fechaMas(fecha, 1), f]); }
        return s;
    }
    function cruzaMedianoche(t) { return mins(t.hora_fin) <= mins(t.hora_inicio); }
    function porId(lista, id) {
        for (var i = 0; i < lista.length; i++) { if (Number(lista[i].id) === Number(id)) { return lista[i]; } }
        return null;
    }
    function mostrarMensaje(sel, texto) {
        var el = $(sel);
        el.textContent = texto || '';
        el.style.display = texto ? 'block' : 'none';
    }
    function guardarToken(t) {
        token = t || '';
        try {
            if (token) { window.localStorage.setItem(CLAVE_TOKEN, token); }
            else { window.localStorage.removeItem(CLAVE_TOKEN); }
        } catch (e) { /* localStorage no disponible: queda la cookie */ }
    }
    function abrir(sel) { $(sel).style.display = 'flex'; }
    function cerrar(sel) { $(sel).style.display = 'none'; }

    /* =========================================================
       Diálogos propios y avisos con "Deshacer"
       ========================================================= */

    function dialogo(texto, conCancelar) {
        return new Promise(function (resolve) {
            var modal = $('#modal-dialogo');
            $('#dialogo-texto').textContent = texto;
            $('#dialogo-cancelar').style.display = conCancelar ? 'inline-flex' : 'none';
            function fin(valor) { modal.style.display = 'none'; resolve(valor); }
            $('#dialogo-ok').onclick = function () { fin(true); };
            $('#dialogo-cancelar').onclick = function () { fin(false); };
            modal.style.display = 'flex';
            $('#dialogo-ok').focus();
        });
    }
    function avisar(texto) { return dialogo(texto, false); }
    function confirmar(texto) { return dialogo(texto, true); }

    var toastTimer = null;
    function ocultarToast() { $('#toast').style.display = 'none'; }
    function toast(texto, accion, fn) {
        var t = $('#toast');
        t.innerHTML = '<span></span>' + (accion ? '<button type="button" class="btn-enlace">' + esc(accion) + '</button>' : '');
        t.firstChild.textContent = texto;
        t.style.display = 'flex';
        if (accion) {
            t.querySelector('button').onclick = function () { ocultarToast(); segura(fn); };
        }
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(ocultarToast, accion ? 10000 : 4000);
    }

    /* =========================================================
       Selector de fecha (dd/mm/aaaa + calendario) y de hora (24 h)
       ========================================================= */

    function parsearFecha(txt) {
        var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(String(txt).trim());
        if (!m) { return ''; }
        var d = Number(m[1]), mo = Number(m[2]), y = Number(m[3]);
        var f = new Date(y, mo - 1, d);
        if (f.getFullYear() !== y || f.getMonth() !== mo - 1 || f.getDate() !== d) { return ''; }
        return y + '-' + dos(mo) + '-' + dos(d);
    }

    function initFechaIn(el) {
        el.innerHTML = '<input type="text" class="fi" placeholder="dd/mm/aaaa" maxlength="10" inputmode="numeric" autocomplete="off">' +
            '<button type="button" class="fi-btn" tabindex="-1" title="Elegir en el calendario">' + ico('calendar') + '</button>';
        var inp = el.querySelector('.fi');
        inp.addEventListener('input', function () {
            var v = inp.value.replace(/\D/g, '').slice(0, 8);
            if (v.length > 4) { v = v.slice(0, 2) + '/' + v.slice(2, 4) + '/' + v.slice(4); }
            else if (v.length > 2) { v = v.slice(0, 2) + '/' + v.slice(2); }
            inp.value = v;
            inp.classList.remove('invalido');
        });
        inp.addEventListener('change', function () {
            if (inp.value !== '' && !parsearFecha(inp.value)) { inp.classList.add('invalido'); }
            if (el.onCambio) { el.onCambio(); }
        });
        el.querySelector('.fi-btn').addEventListener('click', function () { abrirDP(el); });
    }
    function getFecha(el) {
        var v = parsearFecha(el.querySelector('.fi').value);
        if (!v) { throw new Error('Fecha no válida. Escríbala como dd/mm/aaaa o elíjala en el calendario.'); }
        return v;
    }
    function setFecha(el, iso) {
        var inp = el.querySelector('.fi');
        inp.value = fechaLatina(iso);
        inp.classList.remove('invalido');
    }

    var dp = { el: null, mes: null };
    function abrirDP(el) {
        var iso = parsearFecha(el.querySelector('.fi').value);
        var base = iso ? dateDeIso(iso) : new Date();
        dp.el = el;
        dp.mes = new Date(base.getFullYear(), base.getMonth(), 1);
        renderDP();
        var p = $('#datepicker');
        p.style.display = 'block';
        var r = el.getBoundingClientRect();
        var arriba = r.bottom + 4;
        if (arriba + 300 > window.innerHeight) { arriba = Math.max(8, r.top - 304); }
        p.style.top = arriba + 'px';
        p.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 264)) + 'px';
    }
    function cerrarDP() { $('#datepicker').style.display = 'none'; dp.el = null; }
    function renderDP() {
        var elegido = parsearFecha(dp.el.querySelector('.fi').value);
        var hoy = fechaLocal(new Date());
        var primero = new Date(dp.mes.getFullYear(), dp.mes.getMonth(), 1);
        var inicio = lunesDeDate(primero);
        var h = '<div class="dp-cab"><button type="button" data-dp-nav="-1">' + ico('chevl') + '</button><span>' +
            MESES[dp.mes.getMonth()].charAt(0).toUpperCase() + MESES[dp.mes.getMonth()].slice(1) + ' ' + dp.mes.getFullYear() +
            '</span><button type="button" data-dp-nav="1">' + ico('chevr') + '</button></div><div class="dp-rejilla">';
        for (var s = 1; s <= 7; s++) { h += '<span class="dp-sem">' + DIAS_INICIAL[s] + '</span>'; }
        for (var i = 0; i < 42; i++) {
            var d = sumarDiasLocal(inicio, i), iso = fechaLocal(d);
            var cls = 'dp-dia' + (d.getMonth() !== dp.mes.getMonth() ? ' fuera' : '') + (iso === hoy ? ' hoy' : '') + (iso === elegido ? ' elegido' : '');
            h += '<button type="button" class="' + cls + '" data-dp-dia="' + iso + '">' + d.getDate() + '</button>';
        }
        h += '</div><button type="button" class="dp-hoy" data-dp-dia="' + hoy + '">Hoy</button>';
        $('#datepicker').innerHTML = h;
    }
    $('#datepicker').addEventListener('click', function (e) {
        var nav = e.target.closest('[data-dp-nav]');
        if (nav) {
            dp.mes = new Date(dp.mes.getFullYear(), dp.mes.getMonth() + Number(nav.getAttribute('data-dp-nav')), 1);
            renderDP();
            return;
        }
        var dia = e.target.closest('[data-dp-dia]');
        if (dia && dp.el) {
            var el = dp.el;
            setFecha(el, dia.getAttribute('data-dp-dia'));
            cerrarDP();
            if (el.onCambio) { el.onCambio(); }
        }
    });
    document.addEventListener('mousedown', function (e) {
        if ($('#datepicker').style.display === 'none') { return; }
        if (e.target.closest('#datepicker') || e.target.closest('.fi-btn')) { return; }
        cerrarDP();
    });

    function rellenar(sel, valores, etiqueta) {
        sel.innerHTML = valores.map(function (v) { return '<option value="' + v + '">' + etiqueta(v) + '</option>'; }).join('');
    }
    function initHoraSel(el) {
        el.innerHTML = '<select class="h"></select><span class="dospuntos">:</span><select class="mi"></select>';
        var horas = [], minutos = [];
        for (var h = 0; h < 24; h++) { horas.push(h); }
        for (var m = 0; m < 60; m += 5) { minutos.push(m); }
        rellenar(el.querySelector('.h'), horas, dos);
        rellenar(el.querySelector('.mi'), minutos, dos);
    }
    function setHora(el, hhmm) {
        var p = String(hhmm).split(':');
        var sm = el.querySelector('.mi'), mm = Number(p[1]);
        var existe = false, valores = [];
        for (var i = 0; i < sm.options.length; i++) {
            valores.push(Number(sm.options[i].value));
            if (Number(sm.options[i].value) === mm) { existe = true; }
        }
        if (!existe) { valores.push(mm); valores.sort(function (a, b) { return a - b; }); rellenar(sm, valores, dos); }
        el.querySelector('.h').value = String(Number(p[0]));
        sm.value = String(mm);
    }
    function getHora(el) { return dos(Number(el.querySelector('.h').value)) + ':' + dos(Number(el.querySelector('.mi').value)); }

    /* =========================================================
       Llamadas a la API
       ========================================================= */

    async function peticion(url, opciones) {
        var cabeceras = Object.assign({}, opciones.headers || {});
        if (token) { cabeceras['X-Auth-Token'] = token; }

        var respuesta, cuerpo;
        try {
            respuesta = await fetch(url, Object.assign({}, opciones, { headers: cabeceras, credentials: 'same-origin', cache: 'no-store' }));
            cuerpo = await respuesta.text();
        } catch (e) {
            throw new Error('No se pudo conectar con el servidor.');
        }

        var datos;
        try {
            datos = JSON.parse(cuerpo);
        } catch (e) {
            console.error('Respuesta no JSON del servidor:', cuerpo);
            throw new Error('Error del servidor: la respuesta no es válida.');
        }

        if (!respuesta.ok) {
            var err = new Error((datos && datos.error) || ('Error del servidor (' + respuesta.status + ').'));
            if (respuesta.status === 401 && datos && datos.sesion_expirada) {
                guardarToken('');
                mostrarLogin();
                err.silencioso = true;
            } else if (respuesta.status === 403 && datos && datos.debe_cambiar_password) {
                mostrarPantalla('#pantalla-cambio');
                err.silencioso = true;
            }
            if (datos && datos.detalle) { console.error('Detalle del servidor:', datos.detalle); }
            throw err;
        }
        return datos;
    }
    function urlApi(params, conToken) {
        var url = new URL(API, window.location.href);
        Object.keys(params).forEach(function (k) { url.searchParams.set(k, params[k]); });
        if (conToken && token) { url.searchParams.set('token', token); }
        return url.toString();
    }
    function apiGet(params) { return peticion(urlApi(params, false), { method: 'GET' }); }
    function apiPost(cuerpo) {
        return peticion(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cuerpo) });
    }
    // Ejecuta una acción mostrando cualquier error en un diálogo (salvo los de sesión, ya gestionados).
    async function segura(fn) {
        try { await fn(); }
        catch (err) { if (!err.silencioso) { await avisar(err.message); } }
    }

    /* =========================================================
       Pantallas y sesión
       ========================================================= */

    var MODALES = ['#modal-tipos', '#modal-pass', '#modal-turno', '#modal-generar', '#modal-ficha', '#modal-ausencias', '#modal-ajustes'];

    function mostrarPantalla(id) {
        ['#pantalla-login', '#pantalla-cambio', '#app'].forEach(function (s) { $(s).style.display = (s === id) ? 'flex' : 'none'; });
        MODALES.concat(['#modal-dialogo', '#datepicker', '#toast']).forEach(function (s) { $(s).style.display = 'none'; });
        void document.body.offsetHeight; // fuerza el repintado en PHP Desktop
    }
    function mostrarLogin() {
        mostrarPantalla('#pantalla-login');
        $('#login-password').value = '';
        var usuario = $('#login-usuario');
        if (usuario.value === '') { usuario.focus(); } else { $('#login-password').focus(); }
    }
    function esperarPintado() {
        return new Promise(function (resolve) { window.requestAnimationFrame(function () { window.requestAnimationFrame(resolve); }); });
    }
    async function mostrarApp() {
        mostrarPantalla('#app');
        await esperarPintado();
        await Promise.all([cargarEmpleados(), cargarTipos(), cargarConfig()]);
        await cargarCuadrante();
    }

    $('#form-login').addEventListener('submit', async function (e) {
        e.preventDefault();
        mostrarMensaje('#login-error', '');
        var boton = $('#btn-login');
        boton.disabled = true;
        try {
            var res = await apiPost({ accion: 'login', usuario: $('#login-usuario').value.trim(), password: $('#login-password').value });
            guardarToken(res.token);
            $('#login-password').value = '';
            if (res.debe_cambiar_password) { mostrarPantalla('#pantalla-cambio'); $('#cambio-actual').focus(); }
            else { await mostrarApp(); }
        } catch (err) {
            if (!err.silencioso) { mostrarMensaje('#login-error', err.message); }
        } finally {
            boton.disabled = false;
        }
    });

    $('#form-cambio').addEventListener('submit', async function (e) {
        e.preventDefault();
        mostrarMensaje('#cambio-error', '');
        try {
            var res = await apiPost({ accion: 'cambiar_password', actual: $('#cambio-actual').value, nueva: $('#cambio-nueva').value });
            guardarToken(res.token);
            $('#form-cambio').reset();
            await mostrarApp();
        } catch (err) {
            if (!err.silencioso) { mostrarMensaje('#cambio-error', err.message); }
        }
    });

    $('#btn-logout').addEventListener('click', async function () {
        if (!(await confirmar('¿Cerrar sesión?'))) { return; }
        try { await apiPost({ accion: 'logout' }); } catch (err) { /* aunque falle, se cierra en local */ }
        guardarToken('');
        mostrarLogin();
    });

    $('#btn-cambiar-pass').addEventListener('click', function () {
        $('#form-cambiar-pass').reset();
        mostrarMensaje('#pass-error', '');
        abrir('#modal-pass');
    });
    $('#btn-cancelar-pass').addEventListener('click', function () { cerrar('#modal-pass'); });
    $('#form-cambiar-pass').addEventListener('submit', async function (e) {
        e.preventDefault();
        mostrarMensaje('#pass-error', '');
        try {
            var res = await apiPost({ accion: 'cambiar_password', actual: $('#pass-actual').value, nueva: $('#pass-nueva').value });
            guardarToken(res.token);
            cerrar('#modal-pass');
            await avisar('Contraseña cambiada.');
        } catch (err) {
            if (!err.silencioso) { mostrarMensaje('#pass-error', err.message); }
        }
    });

    /* =========================================================
       Configuración, marca y ajustes
       ========================================================= */

    function aplicarMarca() {
        $('#marca-nombre').textContent = empresa.nombre;
        $('#marca-logo').innerHTML = empresa.logo ? '<img alt="" src="' + esc(empresa.logo) + '">' : '';
        document.title = 'Planificador de Turnos - ' + empresa.nombre;
        prepararImpresion();
    }
    async function cargarConfig() {
        try {
            var c = await apiGet({ recurso: 'configuracion' });
            horasMax = Number(c.horas_max_semana) || 40;
            empresa.nombre = c.empresa_nombre || 'Mi Empresa';
            empresa.logo = c.empresa_logo || '';
            empresa.rutaDatos = c.ruta_datos || '';
            empresa.bdAntigua = !!c.bd_antigua_expuesta;
            aplicarMarca();
        } catch (err) {
            if (!err.silencioso) { console.error(err); }
        }
    }

    function reducirLogo(archivo) {
        return new Promise(function (resolve, reject) {
            var lector = new FileReader();
            lector.onload = function () {
                var img = new Image();
                img.onload = function () {
                    var k = Math.min(1, 300 / img.width, 120 / img.height);
                    var c = document.createElement('canvas');
                    c.width = Math.max(1, Math.round(img.width * k));
                    c.height = Math.max(1, Math.round(img.height * k));
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    resolve(c.toDataURL('image/png'));
                };
                img.onerror = function () { reject(new Error('No se pudo leer la imagen.')); };
                img.src = lector.result;
            };
            lector.onerror = function () { reject(new Error('No se pudo leer el archivo.')); };
            lector.readAsDataURL(archivo);
        });
    }
    function pintarLogoPrev() {
        var logo = (logoPend !== null) ? logoPend : empresa.logo;
        $('#aj-logo-prev').innerHTML = logo ? '<img alt="" src="' + esc(logo) + '">' : '<span class="ayuda" style="margin:0">Sin logotipo</span>';
    }

    $('#btn-ajustes').addEventListener('click', function () {
        logoPend = null;
        mostrarMensaje('#aj-error', '');
        $('#aj-nombre').value = empresa.nombre;
        $('#aj-horas').value = horasMax;
        $('#aj-ruta').textContent = empresa.rutaDatos || '(desconocida)';
        $('#aj-aviso-bd').style.display = empresa.bdAntigua ? 'block' : 'none';
        $('#rest-file').value = '';
        $('#rest-nombre').textContent = 'Ningún archivo seleccionado';
        $('#rest-pass').value = '';
        mostrarMensaje('#rest-error', '');
        pintarLogoPrev();
        abrir('#modal-ajustes');
    });
    $('#btn-cerrar-ajustes').addEventListener('click', function () { cerrar('#modal-ajustes'); });
    $('#btn-elegir-logo').addEventListener('click', function () { $('#aj-logo-file').click(); });
    $('#btn-quitar-logo').addEventListener('click', function () { logoPend = ''; pintarLogoPrev(); });
    $('#aj-logo-file').addEventListener('change', async function (e) {
        var archivo = e.target.files && e.target.files[0];
        e.target.value = '';
        if (!archivo) { return; }
        try { logoPend = await reducirLogo(archivo); pintarLogoPrev(); }
        catch (err) { mostrarMensaje('#aj-error', err.message); }
    });
    $('#btn-guardar-ajustes').addEventListener('click', async function () {
        mostrarMensaje('#aj-error', '');
        try {
            await apiPost({ accion: 'set_config', clave: 'empresa_nombre', valor: $('#aj-nombre').value });
            await apiPost({ accion: 'set_config', clave: 'horas_max_semana', valor: $('#aj-horas').value });
            if (logoPend !== null) { await apiPost({ accion: 'set_config', clave: 'empresa_logo', valor: logoPend }); }
            logoPend = null;
            await cargarConfig();
            await cargarCuadrante();
            cerrar('#modal-ajustes');
            toast('Ajustes guardados.');
        } catch (err) {
            if (!err.silencioso) { mostrarMensaje('#aj-error', err.message); }
        }
    });
    $('#btn-backup').addEventListener('click', function () { window.location.href = urlApi({ recurso: 'backup' }, true); });

    /* ---- Restaurar copia de seguridad ---- */

    $('#btn-elegir-copia').addEventListener('click', function () { $('#rest-file').click(); });
    $('#rest-file').addEventListener('change', function (e) {
        var f = e.target.files && e.target.files[0];
        $('#rest-nombre').textContent = f ? f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB)' : 'Ningún archivo seleccionado';
    });
    $('#btn-restaurar').addEventListener('click', async function () {
        mostrarMensaje('#rest-error', '');
        var f = $('#rest-file').files && $('#rest-file').files[0];
        if (!f) { mostrarMensaje('#rest-error', 'Elija primero un archivo .db.'); return; }
        if (!$('#rest-pass').value) { mostrarMensaje('#rest-error', 'Escriba su contraseña actual para confirmar.'); return; }
        if (!(await confirmar('Se van a SUSTITUIR TODOS los datos actuales por los de «' + f.name + '».\n' +
            'Antes se guardará automáticamente una copia de los datos actuales.\n' +
            'Después deberá iniciar sesión de nuevo, con la contraseña que tenía esa copia.\n\n¿Continuar?'))) { return; }

        var fd = new FormData();
        fd.append('accion', 'restaurar_backup');
        fd.append('contrasena', $('#rest-pass').value);
        fd.append('archivo', f);
        try {
            await peticion(API, { method: 'POST', body: fd });
            guardarToken('');
            await avisar('Copia restaurada correctamente. La aplicación se va a recargar.');
            window.location.reload();
        } catch (err) {
            if (!err.silencioso) { mostrarMensaje('#rest-error', err.message); }
        }
    });

    /* =========================================================
       Tipos de turno
       ========================================================= */

    async function cargarTipos() {
        try { tiposCache = await apiGet({ recurso: 'tipos_turno' }); }
        catch (err) { if (!err.silencioso) { console.error(err); } }
    }
    function tipoPorHoras(ini, fin) {
        for (var i = 0; i < tiposCache.length; i++) {
            if (tiposCache[i].hora_inicio === ini && tiposCache[i].hora_fin === fin) { return tiposCache[i]; }
        }
        return null;
    }
    function opcionesTipos() {
        return '<option value="">Personalizado</option>' + tiposCache.map(function (t) {
            return '<option value="' + t.id + '">' + esc(t.nombre) + ' (' + t.hora_inicio + '–' + t.hora_fin + ')</option>';
        }).join('');
    }
    function renderLeyenda() {
        var h = tiposCache.map(function (t) {
            return '<span class="ley"><i style="background:' + esc(t.color) + '"></i>' + esc(t.nombre) + ' ' + t.hora_inicio + '–' + t.hora_fin + '</span>';
        }).join('');
        h += '<span class="ley"><i style="background:#15803d"></i>Vacaciones</span><span class="ley"><i style="background:#5b6772"></i>Descanso</span><span class="ley"><i style="background:#c81e1e"></i>Festivo</span>';
        $('#leyenda').innerHTML = h;
    }
    function renderTipos() {
        $('#tipos-lista').innerHTML = tiposCache.length ? tiposCache.map(function (t) {
            return '<li class="item-tipo"><span class="chip" style="background:' + esc(t.color) + '"></span>' +
                '<span class="nombre">' + esc(t.nombre) + '</span><span class="horas">' + t.hora_inicio + '–' + t.hora_fin + '</span>' +
                '<button type="button" class="btn-editar-mini" data-editar-tipo="' + t.id + '" title="Editar">' + ico('edit') + '</button>' +
                '<button type="button" class="btn-borrar-mini" data-borrar-tipo="' + t.id + '" title="Borrar">' + ico('trash') + '</button></li>';
        }).join('') : '<li class="vacio">No hay tipos de turno.</li>';
    }
    function limpiarFormTipo() {
        $('#tipo-id').value = '';
        $('#tipo-nombre').value = '';
        $('#tipo-color').value = '#3b82f6';
        setHora($('#tipo-inicio'), '09:00');
        setHora($('#tipo-fin'), '17:00');
        $('#tipo-form-titulo').textContent = 'Nuevo tipo';
        mostrarMensaje('#tipo-error', '');
    }
    $('#btn-tipos').addEventListener('click', function () { renderTipos(); limpiarFormTipo(); abrir('#modal-tipos'); });
    $('#btn-cerrar-tipos').addEventListener('click', function () { cerrar('#modal-tipos'); });
    $('#btn-tipo-nuevo').addEventListener('click', limpiarFormTipo);
    $('#tipos-lista').addEventListener('click', function (e) {
        var ed = e.target.closest('[data-editar-tipo]');
        if (ed) {
            var t = porId(tiposCache, ed.getAttribute('data-editar-tipo'));
            if (!t) { return; }
            $('#tipo-id').value = t.id;
            $('#tipo-nombre').value = t.nombre;
            $('#tipo-color').value = t.color;
            setHora($('#tipo-inicio'), t.hora_inicio);
            setHora($('#tipo-fin'), t.hora_fin);
            $('#tipo-form-titulo').textContent = 'Editar tipo';
            return;
        }
        var del = e.target.closest('[data-borrar-tipo]');
        if (del) {
            segura(async function () {
                if (!(await confirmar('¿Borrar este tipo de turno? Los turnos ya creados no se borran.'))) { return; }
                await apiPost({ accion: 'borrar_tipo', id: Number(del.getAttribute('data-borrar-tipo')) });
                await cargarTipos();
                renderTipos();
                await cargarCuadrante();
            });
        }
    });
    $('#form-tipo').addEventListener('submit', async function (e) {
        e.preventDefault();
        mostrarMensaje('#tipo-error', '');
        try {
            await apiPost({
                accion: 'guardar_tipo', id: Number($('#tipo-id').value || 0), nombre: $('#tipo-nombre').value,
                color: $('#tipo-color').value, hora_inicio: getHora($('#tipo-inicio')), hora_fin: getHora($('#tipo-fin'))
            });
            await cargarTipos();
            renderTipos();
            limpiarFormTipo();
            await cargarCuadrante();
        } catch (err) {
            if (!err.silencioso) { mostrarMensaje('#tipo-error', err.message); }
        }
    });

    /* =========================================================
       Empleados y ficha
       ========================================================= */

    async function cargarEmpleados() {
        try {
            empleadosCache = await apiGet({ recurso: 'empleados' });
            renderEmpleados();
        } catch (err) {
            if (!err.silencioso) { console.error(err); }
        }
    }
    function resumenDias(e) {
        if (!e.horario || e.horario.length === 0) { return 'sin horario'; }
        return e.horario.map(function (h) { return DIAS_INICIAL[h.dia] + (h.inicio2 ? '*' : ''); }).join(' ');
    }
    function renderEmpleados() {
        $('#lista-empleados').innerHTML = empleadosCache.length ? empleadosCache.map(function (e) {
            return '<li class="item-empleado">' +
                '<span class="chip" style="background:' + esc(e.color_asignado) + '"></span>' +
                '<span class="nombre">' + esc(e.nombre) + '<small class="dias-resumen">' + esc(resumenDias(e)) + '</small></span>' +
                '<button type="button" class="btn-editar-mini" data-editar-empleado="' + e.id + '" title="Abrir ficha">' + ico('edit') + '</button>' +
                '<button type="button" class="btn-borrar-mini" data-borrar-empleado="' + e.id + '" title="Borrar">' + ico('trash') + '</button>' +
                '</li>';
        }).join('') : '<li class="vacio">Aún no hay empleados.</li>';

        var opciones = empleadosCache.map(function (e) { return '<option value="' + e.id + '">' + esc(e.nombre) + '</option>'; }).join('');
        var filtroActual = $('#filtro-empleado').value;
        var diaEmpActual = $('#dia-empleado').value;
        $('#turno-empleado').innerHTML = opciones;
        $('#filtro-empleado').innerHTML = '<option value="">Todos los empleados</option>' + opciones;
        $('#filtro-empleado').value = filtroActual;
        if ($('#filtro-empleado').value !== filtroActual) { $('#filtro-empleado').value = ''; }
        $('#dia-empleado').innerHTML = '<option value="">Toda la empresa</option>' + opciones;
        $('#dia-empleado').value = diaEmpActual;
        if ($('#dia-empleado').value !== diaEmpActual) { $('#dia-empleado').value = ''; }
    }

    $('#form-empleado').addEventListener('submit', function (e) {
        e.preventDefault();
        segura(async function () {
            var res = await apiPost({ accion: 'crear_empleado', nombre: $('#empleado-nombre').value, color: $('#empleado-color').value });
            $('#empleado-nombre').value = '';
            await cargarEmpleados();
            await cargarCuadrante();
            abrirFicha(res.id);
        });
    });

    async function borrarEmpleadoConfirmado(id) {
        var emp = porId(empleadosCache, id);
        var nombre = emp ? emp.nombre : 'este empleado';
        if (!(await confirmar('¿Borrar a ' + nombre + '? Se borrarán también sus turnos, su horario y sus días especiales.'))) { return false; }
        await apiPost({ accion: 'borrar_empleado', id: Number(id) });
        await cargarEmpleados();
        await cargarCuadrante();
        return true;
    }
    $('#lista-empleados').addEventListener('click', function (e) {
        var borrar = e.target.closest('[data-borrar-empleado]');
        if (borrar) { segura(function () { return borrarEmpleadoConfirmado(borrar.getAttribute('data-borrar-empleado')); }); return; }
        var editar = e.target.closest('[data-editar-empleado]');
        if (editar) { abrirFicha(editar.getAttribute('data-editar-empleado')); }
    });

    /* ---- Ficha: horario semanal con turno partido ---- */

    function filaDia(n) { return $('#ficha-horario .fila-horario[data-dia="' + n + '"]'); }

    function construirFicha() {
        var h = '';
        for (var i = 1; i <= 7; i++) {
            h += '<div class="fila-horario" data-dia="' + i + '">' +
                '<label class="chk"><input type="checkbox" class="trabaja"> ' + DIAS_SEMANA[i] + '</label>' +
                '<div class="tramos">' +
                '<div class="tramo t1"><select class="tipo-rapido" title="Rellenar con un tipo de turno"></select>' +
                '<div class="hora-sel e1"></div><span class="sep">–</span><div class="hora-sel s1"></div>' +
                '<button type="button" class="btn-enlace add2">+ turno partido</button></div>' +
                '<div class="tramo t2" style="display:none"><span class="etq">2.º tramo</span>' +
                '<div class="hora-sel e2"></div><span class="sep">–</span><div class="hora-sel s2"></div>' +
                '<button type="button" class="btn-borrar-mini quitar2" title="Quitar el segundo tramo">' + ico('x') + '</button></div>' +
                '</div></div>';
        }
        $('#ficha-horario').innerHTML = h;
        qsa('#ficha-horario .hora-sel').forEach(initHoraSel);
    }
    function fijarFila(n, activo, i1, f1, i2, f2) {
        var f = filaDia(n);
        f.querySelector('.trabaja').checked = activo;
        f.classList.toggle('activa', activo);
        setHora(f.querySelector('.e1'), i1 || '09:00');
        setHora(f.querySelector('.s1'), f1 || '17:00');
        var dos2 = !!(i2 && f2);
        f.querySelector('.t2').style.display = dos2 ? 'flex' : 'none';
        f.querySelector('.add2').style.display = dos2 ? 'none' : '';
        setHora(f.querySelector('.e2'), i2 || '16:00');
        setHora(f.querySelector('.s2'), f2 || '20:00');
    }
    $('#ficha-horario').addEventListener('change', function (e) {
        var fila = e.target.closest('.fila-horario');
        if (!fila) { return; }
        if (e.target.classList.contains('tipo-rapido')) {
            var t = porId(tiposCache, e.target.value);
            if (t) { setHora(fila.querySelector('.e1'), t.hora_inicio); setHora(fila.querySelector('.s1'), t.hora_fin); }
            e.target.value = '';
        }
        if (!e.target.classList.contains('trabaja')) { fila.querySelector('.trabaja').checked = true; }
        fila.classList.toggle('activa', fila.querySelector('.trabaja').checked);
    });
    $('#ficha-horario').addEventListener('click', function (e) {
        var fila = e.target.closest('.fila-horario');
        if (!fila) { return; }
        if (e.target.closest('.add2')) {
            fila.querySelector('.t2').style.display = 'flex';
            fila.querySelector('.add2').style.display = 'none';
            fila.querySelector('.trabaja').checked = true;
            fila.classList.add('activa');
        } else if (e.target.closest('.quitar2')) {
            fila.querySelector('.t2').style.display = 'none';
            fila.querySelector('.add2').style.display = '';
        }
    });

    function abrirFicha(id) {
        var emp = porId(empleadosCache, id);
        if (!emp) { return; }
        mostrarMensaje('#ficha-error', '');
        $('#ficha-id').value = emp.id;
        $('#ficha-nombre').value = emp.nombre;
        $('#ficha-color').value = emp.color_asignado;
        qsa('#ficha-horario .tipo-rapido').forEach(function (s) { s.innerHTML = opcionesTipos().replace('Personalizado', 'Tipo…'); });
        for (var d = 1; d <= 7; d++) { fijarFila(d, false); }
        (emp.horario || []).forEach(function (h) { fijarFila(h.dia, true, h.inicio, h.fin, h.inicio2, h.fin2); });
        var r = rango();
        setFecha($('#ficha-desde'), fechaLocal(r[0]));
        setFecha($('#ficha-hasta'), fechaLocal(sumarDiasLocal(r[1], -1)));
        abrir('#modal-ficha');
    }
    function recogerFicha() {
        var horario = [];
        for (var d = 1; d <= 7; d++) {
            var f = filaDia(d);
            if (!f.querySelector('.trabaja').checked) { continue; }
            var item = { dia: d, inicio: getHora(f.querySelector('.e1')), fin: getHora(f.querySelector('.s1')) };
            if (f.querySelector('.t2').style.display !== 'none') {
                item.inicio2 = getHora(f.querySelector('.e2'));
                item.fin2 = getHora(f.querySelector('.s2'));
            }
            horario.push(item);
        }
        return { accion: 'guardar_empleado', id: Number($('#ficha-id').value), nombre: $('#ficha-nombre').value, color: $('#ficha-color').value, horario: horario };
    }
    $('#btn-copiar-lunes').addEventListener('click', function () {
        var l = filaDia(1);
        var activo = l.querySelector('.trabaja').checked;
        var dos2 = l.querySelector('.t2').style.display !== 'none';
        for (var d = 2; d <= 5; d++) {
            fijarFila(d, activo, getHora(l.querySelector('.e1')), getHora(l.querySelector('.s1')),
                dos2 ? getHora(l.querySelector('.e2')) : null, dos2 ? getHora(l.querySelector('.s2')) : null);
        }
    });
    $('#btn-cancelar-ficha').addEventListener('click', function () { cerrar('#modal-ficha'); });
    $('#form-ficha').addEventListener('submit', async function (e) {
        e.preventDefault();
        mostrarMensaje('#ficha-error', '');
        try {
            await apiPost(recogerFicha());
            cerrar('#modal-ficha');
            await cargarEmpleados();
            await cargarCuadrante();
        } catch (err) {
            if (!err.silencioso) { mostrarMensaje('#ficha-error', err.message); }
        }
    });
    function textoResultadoGeneracion(res) {
        var msg = 'Turnos creados: ' + res.creados + '.';
        if (res.omitidos_especiales) { msg += '\nOmitidos por vacaciones, descanso o festivo: ' + res.omitidos_especiales + '.'; }
        if (res.omitidos_solapados) { msg += '\nOmitidos por solaparse con turnos existentes: ' + res.omitidos_solapados + '.'; }
        if (res.sin_horario && res.sin_horario.length) { msg += '\nSin horario semanal definido: ' + res.sin_horario.join(', ') + '.'; }
        return msg;
    }
    $('#btn-generar-turnos').addEventListener('click', async function () {
        mostrarMensaje('#ficha-error', '');
        try {
            var desde = getFecha($('#ficha-desde')), hasta = getFecha($('#ficha-hasta'));
            if (hasta < desde) { throw new Error('La fecha final no puede ser anterior a la inicial.'); }
            var datos = recogerFicha();
            if (datos.horario.length === 0) { throw new Error('Marque al menos un día de trabajo en el horario semanal.'); }
            await apiPost(datos);
            var res = await apiPost({ accion: 'generar_turnos', empleado_ids: [datos.id], desde: desde, hasta: hasta });
            cerrar('#modal-ficha');
            await cargarEmpleados();
            await cargarCuadrante();
            await avisar(textoResultadoGeneracion(res));
        } catch (err) {
            if (!err.silencioso) { mostrarMensaje('#ficha-error', err.message); }
        }
    });
    $('#btn-borrar-empleado').addEventListener('click', function () {
        segura(async function () {
            if (await borrarEmpleadoConfirmado($('#ficha-id').value)) { cerrar('#modal-ficha'); }
        });
    });

    /* =========================================================
       Generar periodo y copiar semana
       ========================================================= */

    $('#btn-generar').addEventListener('click', function () {
        if (empleadosCache.length === 0) { avisar('Primero añada al menos un empleado.'); return; }
        mostrarMensaje('#gen-error', '');
        var r = rango();
        setFecha($('#gen-desde'), fechaLocal(r[0]));
        setFecha($('#gen-hasta'), fechaLocal(sumarDiasLocal(r[1], -1)));
        $('#gen-lista').innerHTML = empleadosCache.map(function (e) {
            var tiene = !!(e.horario && e.horario.length);
            return '<label class="fila-check"><input type="checkbox" value="' + e.id + '"' + (tiene ? ' checked' : ' disabled') + '>' +
                '<span class="chip" style="background:' + esc(e.color_asignado) + '"></span><span>' + esc(e.nombre) + '</span>' +
                (tiene ? '' : '<small>sin horario: abra su ficha</small>') + '</label>';
        }).join('');
        abrir('#modal-generar');
    });
    $('#btn-cancelar-generar').addEventListener('click', function () { cerrar('#modal-generar'); });
    $('#form-generar').addEventListener('submit', async function (e) {
        e.preventDefault();
        mostrarMensaje('#gen-error', '');
        try {
            var desde = getFecha($('#gen-desde')), hasta = getFecha($('#gen-hasta'));
            if (hasta < desde) { throw new Error('La fecha final no puede ser anterior a la inicial.'); }
            var ids = qsa('#gen-lista input:checked').map(function (c) { return Number(c.value); });
            if (ids.length === 0) { throw new Error('Seleccione al menos un empleado con horario.'); }
            var res = await apiPost({ accion: 'generar_turnos', empleado_ids: ids, desde: desde, hasta: hasta });
            cerrar('#modal-generar');
            await cargarCuadrante();
            await avisar(textoResultadoGeneracion(res));
        } catch (err) {
            if (!err.silencioso) { mostrarMensaje('#gen-error', err.message); }
        }
    });

    $('#btn-copiar').addEventListener('click', function () {
        if (estado.vista !== 'semana') { avisar('Cambie a la vista «Semana» para copiar la semana anterior.'); return; }
        segura(async function () {
            var r = rango();
            var ant = fechaLatina(fechaMas(fechaLocal(r[0]), -7)) + ' – ' + fechaLatina(fechaMas(fechaLocal(r[0]), -1));
            if (!(await confirmar('Se copiarán los turnos de la semana anterior (' + ant + ') a la semana visible.\nNo se tocan los turnos existentes ni se asigna en días de vacaciones, descanso o festivo.\n\n¿Continuar?'))) { return; }
            var res = await apiPost({ accion: 'copiar_periodo', desde: fechaLocal(r[0]), hasta: fechaLocal(r[1]), dias: 7, empleado_id: Number($('#filtro-empleado').value || 0) });
            await cargarCuadrante();
            await avisar(textoResultadoGeneracion(res));
        });
    });

    /* =========================================================
       Cuadrante
       ========================================================= */

    function rango() {
        var a = estado.ancla;
        if (estado.vista === 'semana') { var l = lunesDeDate(a); return [l, sumarDiasLocal(l, 7)]; }
        return [new Date(a.getFullYear(), a.getMonth(), 1), new Date(a.getFullYear(), a.getMonth() + 1, 1)];
    }
    function tituloPeriodo() {
        var r = rango(), s = r[0], e = sumarDiasLocal(r[1], -1);
        if (estado.vista === 'mes') { return MESES[s.getMonth()].charAt(0).toUpperCase() + MESES[s.getMonth()].slice(1) + ' de ' + s.getFullYear(); }
        if (s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear()) {
            return s.getDate() + ' – ' + e.getDate() + ' de ' + MESES[e.getMonth()] + ' de ' + e.getFullYear();
        }
        return s.getDate() + ' de ' + MESES[s.getMonth()] + ' – ' + e.getDate() + ' de ' + MESES[e.getMonth()] + ' de ' + e.getFullYear();
    }
    function actualizarNav() {
        $('#nav-titulo').textContent = tituloPeriodo();
        $('#vista-semana').classList.toggle('activo', estado.vista === 'semana');
        $('#vista-mes').classList.toggle('activo', estado.vista === 'mes');
        $('#btn-copiar').disabled = (estado.vista !== 'semana');
        prepararImpresion();
    }
    function prepararImpresion() {
        var h = empresa.logo ? '<img alt="" src="' + esc(empresa.logo) + '">' : '';
        h += '<div><div class="pc-titulo">' + esc(empresa.nombre) + ' — Cuadrante de turnos</div><div class="pc-sub">' +
            esc(tituloPeriodo()) + ' · Impreso el ' + fechaLatina(fechaLocal(new Date())) + '</div></div>';
        $('#print-cabecera').innerHTML = h;
    }

    async function cargarCuadrante() {
        var n = ++estado.cargaN;
        var r = rango();
        var ini = fechaLocal(r[0]), fin = fechaLocal(r[1]);
        var padIni = fechaLocal(lunesDeDate(r[0]));
        var padFin = fechaLocal(sumarDiasLocal(lunesDeDate(sumarDiasLocal(r[1], -1)), 7));
        var pt = { recurso: 'turnos', start: padIni, end: padFin };
        var filtro = $('#filtro-empleado').value;
        if (filtro) { pt.empleado_id = filtro; }
        try {
            var res = await Promise.all([
                apiGet(pt),
                apiGet({ recurso: 'dias_especiales', start: ini, end: fin }),
                apiGet({ recurso: 'resumen_semanal', start: ini, end: fin })
            ]);
            if (n !== estado.cargaN) { return; }
            estado.turnos = res[0]; estado.dias = res[1]; estado.resumen = res[2];
        } catch (err) {
            if (!err.silencioso) { $('#cuadrante').innerHTML = '<p class="fallo-bd">' + esc(err.message) + '</p>'; }
            return;
        }
        renderLeyenda();
        renderCuadrante();
        renderResumen();
    }

    function calcularExceso(r) {
        var suma = {};
        estado.turnos.forEach(function (t) {
            segmentos(t.fecha, t.hora_inicio, t.hora_fin).forEach(function (s) {
                var w = lunesIso(s[0]);
                suma[t.empleado_id] = suma[t.empleado_id] || {};
                suma[t.empleado_id][w] = (suma[t.empleado_id][w] || 0) + s[1];
            });
        });
        var ini = fechaLocal(r[0]), fin = fechaLocal(r[1]), out = {};
        Object.keys(suma).forEach(function (k) {
            Object.keys(suma[k]).forEach(function (w) {
                if (fechaMas(w, 6) < ini || w >= fin) { return; }
                if (suma[k][w] > horasMax * 60) {
                    out[k] = (out[k] ? out[k] + '\n' : '') + 'Semana del ' + fechaLatina(w) + ': ' + fmtHoras(suma[k][w]) + ' (máx. ' + horasMax + ' h)';
                }
            });
        });
        return out;
    }

    function chipHtml(t, compacto) {
        var emp = porId(empleadosCache, t.empleado_id);
        var tp = tipoPorHoras(t.hora_inicio, t.hora_fin);
        var color = tp ? tp.color : (emp ? emp.color_asignado : '#64748b');
        var cruza = cruzaMedianoche(t);
        var etiqueta = compacto ? corto(t.hora_inicio) + '-' + corto(t.hora_fin)
            : (tp ? esc(tp.nombre) + ' ' : '') + t.hora_inicio + '–' + t.hora_fin;
        var tip = (emp ? emp.nombre + ': ' : '') + (tp ? tp.nombre + ' ' : '') + t.hora_inicio + '–' + t.hora_fin + (cruza ? ' (termina al día siguiente)' : '');
        return '<div class="chip-turno" draggable="true" data-turno="' + t.id + '" style="background:' + esc(color) + ';color:' + colorTexto(color) +
            '" title="' + esc(tip) + '">' + etiqueta + (cruza ? '<span class="mas1">+1</span>' : '') + '</div>';
    }

    function bienvenidaHtml() {
        return '<div class="bienvenida"><h2>Bienvenido al planificador de turnos</h2>' +
            '<p>Tres pasos para tener su primer cuadrante:</p><ol>' +
            '<li><b>Añada a sus empleados</b> con el formulario de la izquierda.</li>' +
            '<li><b>Defina su horario semanal</b> en la ficha de cada uno (días, horas y turnos partidos).</li>' +
            '<li><b>Genere el periodo</b> con el botón «Generar periodo»: se crean todos los turnos de una vez, respetando vacaciones y festivos.</li></ol>' +
            '<div class="bienvenida-acciones"><button type="button" class="btn btn-primario" data-accion="bv-empleado">Añadir primer empleado</button>' +
            '<button type="button" class="btn btn-secundario" data-accion="bv-demo">Cargar datos de ejemplo</button></div></div>';
    }

    function renderCuadrante() {
        var cont = $('#cuadrante');
        actualizarNav();
        if (empleadosCache.length === 0) { cont.innerHTML = bienvenidaHtml(); return; }

        var r = rango(), dias = [];
        for (var d = new Date(r[0]); d < r[1]; d = sumarDiasLocal(d, 1)) { dias.push(d); }
        var iniIso = fechaLocal(r[0]), finIso = fechaLocal(r[1]);
        var filtro = $('#filtro-empleado').value;
        var visibles = empleadosCache.filter(function (e) { return !filtro || String(e.id) === filtro; });
        var semanal = (estado.vista === 'semana');
        var hoyIso = fechaLocal(new Date());

        var celdas = {}, fantasma = {}, espEmpresa = {}, espEmp = {}, totales = {}, hayTurnos = false;
        estado.turnos.forEach(function (t) {
            var k = t.empleado_id + '|' + t.fecha;
            (celdas[k] = celdas[k] || []).push(t);
            if (t.fecha >= iniIso && t.fecha < finIso) { hayTurnos = true; }
            if (cruzaMedianoche(t)) {
                var k2 = t.empleado_id + '|' + fechaMas(t.fecha, 1);
                (fantasma[k2] = fantasma[k2] || []).push(t);
            }
        });
        estado.dias.forEach(function (x) {
            if (x.empleado_id === null) { (espEmpresa[x.fecha] = espEmpresa[x.fecha] || []).push(x); }
            else { var k = x.empleado_id + '|' + x.fecha; (espEmp[k] = espEmp[k] || []).push(x); }
        });
        estado.resumen.forEach(function (x) { totales[x.empleado_id] = x.total_min; });
        var exceso = calcularExceso(r);
        var cobertura = {};

        var h = '';
        if (!hayTurnos) {
            h += '<div class="hint-vacio">No hay turnos en este periodo. Use «Generar periodo» para crearlos desde los horarios de las fichas, o pulse una celda para añadir un turno.</div>';
        }
        h += '<table class="tabla-cuadrante ' + (semanal ? 'vista-semana' : 'vista-mes') + '"><thead><tr><th class="col-emp">Empleado</th>';
        dias.forEach(function (dd) {
            var iso = fechaLocal(dd), dow = dd.getDay();
            var esp = espEmpresa[iso];
            var cls = 'col-dia' + (dow === 0 || dow === 6 ? ' finde' : '') + (iso === hoyIso ? ' hoy' : '') + (esp ? ' esp-' + esp[0].tipo : '');
            var tit = esp ? esp.map(function (x) { return TIPOS_DIA[x.tipo] + (x.nota ? ': ' + x.nota : ''); }).join('\n') : '';
            h += '<th class="' + cls + '" title="' + esc(tit) + '"><span class="dn">' + (semanal ? JS_CORTO[dow] : JS_INICIAL[dow]) + '</span>' +
                '<span class="dd">' + (semanal ? dd.getDate() + ' ' + MESES_CORTOS[dd.getMonth()] : dd.getDate()) + '</span>' +
                (esp && semanal ? '<span class="th-tag">' + TIPOS_DIA[esp[0].tipo] + '</span>' : '') + '</th>';
        });
        h += '<th class="col-total">Horas</th></tr></thead><tbody>';

        visibles.forEach(function (e) {
            var aviso = exceso[e.id];
            h += '<tr><th class="col-emp" scope="row"><button type="button" class="emp-nombre" data-ficha="' + e.id + '" title="Abrir ficha">' +
                '<span class="chip" style="background:' + esc(e.color_asignado) + '"></span><span class="t">' + esc(e.nombre) + '</span>' +
                (aviso ? '<span class="aviso-ico" title="' + esc(aviso) + '">' + ico('alert') + '</span>' : '') + '</button></th>';
            dias.forEach(function (dd) {
                var iso = fechaLocal(dd), dow = dd.getDay(), k = e.id + '|' + iso;
                var empresaEsp = espEmpresa[iso], propios = espEmp[k] || [];
                var cls = 'celda' + (dow === 0 || dow === 6 ? ' finde' : '') + (iso === hoyIso ? ' hoy' : '') + (estado.seleccion[k] ? ' sel' : '');
                if (propios.length) { cls += ' esp-' + propios[0].tipo; } else if (empresaEsp) { cls += ' esp-' + empresaEsp[0].tipo; }
                h += '<td class="' + cls + '" data-emp="' + e.id + '" data-fecha="' + iso + '">';
                propios.forEach(function (x) {
                    h += '<span class="tag-esp t-' + x.tipo + '" data-dia="' + x.id + '" title="' + esc((x.nota || TIPOS_DIA[x.tipo]) + ' (pulse para quitar)') + '">' +
                        (semanal ? TIPOS_DIA[x.tipo] : TIPOS_DIA[x.tipo].charAt(0)) + '</span>';
                });
                (celdas[k] || []).forEach(function (t) { h += chipHtml(t, !semanal); });
                (fantasma[k] || []).forEach(function (t) { h += '<span class="continua" title="Viene del turno de la noche anterior">↳ ' + (semanal ? 'hasta ' : '') + corto(t.hora_fin) + '</span>'; });
                if ((celdas[k] || []).length) { cobertura[iso] = (cobertura[iso] || 0) + 1; }
                h += '<button type="button" class="celda-mas" tabindex="-1" title="Añadir turno">' + ico('plus') + '</button></td>';
            });
            h += '<td class="col-total' + (aviso ? ' exceso' : '') + '">' + fmtHoras(totales[e.id] || 0) + '</td></tr>';
        });

        h += '</tbody><tfoot><tr><th class="col-emp">Personas con turno</th>';
        dias.forEach(function (dd) {
            var n = cobertura[fechaLocal(dd)] || 0;
            h += '<td class="' + (n === 0 ? 'cero' : '') + '">' + n + '</td>';
        });
        h += '<td class="col-total"></td></tr></tfoot></table>';
        cont.innerHTML = h;
    }

    function renderResumen() {
        var filtro = $('#filtro-empleado').value;
        var exceso = calcularExceso(rango());
        var filas = estado.resumen.filter(function (d) { return !filtro || String(d.empleado_id) === filtro; });
        $('#tabla-resumen').innerHTML = filas.length ? filas.map(function (d) {
            return '<tr class="' + (exceso[d.empleado_id] ? 'excede' : '') + '">' +
                '<td><span class="celda-nombre"><span class="chip" style="background:' + esc(d.color) + '"></span>' + esc(d.nombre) + '</span></td>' +
                '<td>' + fmtHoras(d.total_min) + '</td><td>' + d.dias_trabajados + '</td><td>' + fmtHoras(d.media_diaria_min) + '</td></tr>';
        }).join('') : '<tr><td colspan="4" class="vacio">Sin empleados.</td></tr>';
    }

    /* ---- Navegación ---- */

    function moverPeriodo(delta) {
        var a = estado.ancla;
        estado.ancla = (estado.vista === 'semana') ? sumarDiasLocal(a, 7 * delta) : new Date(a.getFullYear(), a.getMonth() + delta, 1);
        limpiarSeleccion();
        cargarCuadrante();
    }
    $('#nav-ant').addEventListener('click', function () { moverPeriodo(-1); });
    $('#nav-sig').addEventListener('click', function () { moverPeriodo(1); });
    $('#nav-hoy').addEventListener('click', function () { estado.ancla = new Date(); limpiarSeleccion(); cargarCuadrante(); });
    qsa('[data-vista]').forEach(function (b) {
        b.addEventListener('click', function () {
            estado.vista = b.getAttribute('data-vista');
            try { window.localStorage.setItem(CLAVE_VISTA, estado.vista); } catch (e) { /* sin persistencia */ }
            limpiarSeleccion();
            cargarCuadrante();
        });
    });
    $('#filtro-empleado').addEventListener('change', function () { limpiarSeleccion(); cargarCuadrante(); });

    /* ---- Selección múltiple ---- */

    function limpiarSeleccion() {
        estado.seleccion = {};
        qsa('td.celda.sel').forEach(function (td) { td.classList.remove('sel'); });
        actualizarBarraSel();
    }
    function itemsSeleccion() {
        return Object.keys(estado.seleccion).map(function (k) { var p = k.split('|'); return { empleado_id: Number(p[0]), fecha: p[1] }; });
    }
    function actualizarBarraSel() {
        var n = Object.keys(estado.seleccion).length, b = $('#barra-seleccion');
        if (!n) { b.style.display = 'none'; return; }
        b.innerHTML = '<span><b>' + n + '</b> ' + (n === 1 ? 'celda seleccionada' : 'celdas seleccionadas') + '</span>' +
            '<button type="button" class="btn btn-primario btn-mini" data-sel="asignar">Asignar turno…</button>' +
            '<button type="button" class="btn btn-secundario btn-mini" data-sel="borrar">Borrar turnos</button>' +
            '<button type="button" class="btn btn-secundario btn-mini" data-sel="descanso">Descanso</button>' +
            '<button type="button" class="btn btn-secundario btn-mini" data-sel="vacaciones">Vacaciones</button>' +
            '<button type="button" class="btn btn-secundario btn-mini" data-sel="cancelar">Cancelar</button>';
        b.style.display = 'flex';
    }
    function alternarSeleccion(td) {
        var k = td.getAttribute('data-emp') + '|' + td.getAttribute('data-fecha');
        if (estado.seleccion[k]) { delete estado.seleccion[k]; td.classList.remove('sel'); }
        else { estado.seleccion[k] = true; td.classList.add('sel'); }
        actualizarBarraSel();
    }
    $('#btn-modo-sel').addEventListener('click', function () {
        estado.modoSel = !estado.modoSel;
        $('#btn-modo-sel').classList.toggle('activo', estado.modoSel);
        $('#cuadrante-wrap').classList.toggle('modo-seleccion', estado.modoSel);
        if (!estado.modoSel) { limpiarSeleccion(); }
    });
    $('#barra-seleccion').addEventListener('click', function (e) {
        var b = e.target.closest('[data-sel]');
        if (!b) { return; }
        var a = b.getAttribute('data-sel');
        if (a === 'cancelar') { limpiarSeleccion(); }
        else if (a === 'asignar') { abrirTurno({ lote: true }); }
        else if (a === 'borrar') {
            segura(async function () {
                var lista = estado.turnos.filter(function (t) { return estado.seleccion[t.empleado_id + '|' + t.fecha]; });
                if (lista.length === 0) { await avisar('Las celdas seleccionadas no tienen turnos.'); return; }
                if (!(await confirmar('¿Borrar ' + lista.length + (lista.length === 1 ? ' turno' : ' turnos') + ' de las celdas seleccionadas?'))) { return; }
                await borrarTurnosConUndo(lista);
                limpiarSeleccion();
            });
        } else {
            segura(async function () {
                var res = await apiPost({ accion: 'crear_dias_lote', tipo: a, items: itemsSeleccion() });
                limpiarSeleccion();
                await cargarCuadrante();
                toast('Marcados como ' + TIPOS_DIA[a].toLowerCase() + ': ' + res.creados + (res.creados === 1 ? ' día.' : ' días.'));
            });
        }
    });

    /* ---- Clic, arrastrar y soltar en el cuadrante ---- */

    function turnoPorId(id) { return porId(estado.turnos, id); }
    var cuad = $('#cuadrante');

    function accionBienvenida(a) {
        if (a === 'bv-empleado') { $('#empleado-nombre').focus(); return; }
        segura(async function () {
            if (!(await confirmar('Se crearán 4 empleados de ejemplo con sus horarios y los turnos del periodo visible. Podrá borrarlos después.\n\n¿Continuar?'))) { return; }
            var res = await apiPost({ accion: 'cargar_demo' });
            await cargarEmpleados();
            var r = rango();
            var hasta = estado.vista === 'semana' ? sumarDiasLocal(r[0], 27) : sumarDiasLocal(r[1], -1);
            await apiPost({ accion: 'generar_turnos', empleado_ids: res.ids, desde: fechaLocal(r[0]), hasta: fechaLocal(hasta) });
            await cargarCuadrante();
        });
    }

    cuad.addEventListener('click', function (e) {
        var acc = e.target.closest('[data-accion]');
        if (acc) { accionBienvenida(acc.getAttribute('data-accion')); return; }
        var ficha = e.target.closest('[data-ficha]');
        if (ficha) { abrirFicha(ficha.getAttribute('data-ficha')); return; }
        var td = e.target.closest('td.celda');
        if (!td) { return; }
        if (estado.modoSel || e.ctrlKey || e.metaKey) { alternarSeleccion(td); return; }
        var tag = e.target.closest('.tag-esp');
        if (tag) { borrarDia(Number(tag.getAttribute('data-dia'))); return; }
        var chip = e.target.closest('.chip-turno');
        if (chip) { var t = turnoPorId(Number(chip.getAttribute('data-turno'))); if (t) { abrirTurno({ turno: t }); } return; }
        abrirTurno({ empleadoId: Number(td.getAttribute('data-emp')), fecha: td.getAttribute('data-fecha') });
    });

    function limpiarDrop() { qsa('td.celda.drop').forEach(function (td) { td.classList.remove('drop'); }); }
    cuad.addEventListener('dragstart', function (e) {
        var chip = e.target.closest ? e.target.closest('.chip-turno') : null;
        if (!chip) { return; }
        estado.arrastrando = Number(chip.getAttribute('data-turno'));
        e.dataTransfer.setData('text/plain', String(estado.arrastrando));
        e.dataTransfer.effectAllowed = 'copyMove';
    });
    cuad.addEventListener('dragover', function (e) {
        if (!estado.arrastrando) { return; }
        var td = e.target.closest('td.celda');
        if (!td) { return; }
        e.preventDefault();
        limpiarDrop();
        td.classList.add('drop');
        e.dataTransfer.dropEffect = e.ctrlKey ? 'copy' : 'move';
    });
    cuad.addEventListener('drop', function (e) {
        if (!estado.arrastrando) { return; }
        e.preventDefault();
        var td = e.target.closest('td.celda');
        var t = turnoPorId(estado.arrastrando);
        var copiar = e.ctrlKey;
        estado.arrastrando = 0;
        limpiarDrop();
        if (!td || !t) { return; }
        var emp = Number(td.getAttribute('data-emp')), fecha = td.getAttribute('data-fecha');
        if (emp === t.empleado_id && fecha === t.fecha) { return; }
        segura(async function () {
            var res = await apiPost({ accion: 'guardar_turno', id: copiar ? 0 : t.id, empleado_id: emp, fecha: fecha, hora_inicio: t.hora_inicio, hora_fin: t.hora_fin });
            await cargarCuadrante();
            if (res.warning) { await avisar(res.warning); }
        });
    });
    cuad.addEventListener('dragend', function () { estado.arrastrando = 0; limpiarDrop(); });

    /* =========================================================
       Turnos: modal individual o por lotes, borrar con deshacer
       ========================================================= */

    async function borrarTurnosConUndo(lista) {
        await apiPost({ accion: 'borrar_turnos', ids: lista.map(function (t) { return t.id; }) });
        await cargarCuadrante();
        var items = lista.map(function (t) { return { empleado_id: t.empleado_id, fecha: t.fecha, hora_inicio: t.hora_inicio, hora_fin: t.hora_fin }; });
        toast(lista.length === 1 ? 'Turno borrado.' : lista.length + ' turnos borrados.', 'Deshacer', async function () {
            await apiPost({ accion: 'crear_turnos', items: items });
            await cargarCuadrante();
        });
    }

    function abrirTurno(o) {
        var lote = !!o.lote;
        if (!lote && empleadosCache.length === 0) { avisar('Primero añada al menos un empleado.'); return; }
        $('#form-turno').reset();
        mostrarMensaje('#turno-error', '');
        $('#turno-tipo').innerHTML = opcionesTipos();
        modoTurno = lote ? 'lote' : (o.turno ? 'editar' : 'nuevo');
        qsa('.solo-individual').forEach(function (el) { el.style.display = lote ? 'none' : ''; });
        $('#turno-marcas').style.display = (modoTurno === 'nuevo') ? 'flex' : 'none';
        $('#btn-borrar-turno').style.display = (modoTurno === 'editar') ? 'inline-flex' : 'none';

        if (o.turno) {
            $('#modal-titulo').textContent = 'Editar turno';
            $('#turno-id').value = o.turno.id;
            $('#turno-empleado').value = o.turno.empleado_id;
            setFecha($('#turno-fecha'), o.turno.fecha);
            setHora($('#turno-inicio'), o.turno.hora_inicio);
            setHora($('#turno-fin'), o.turno.hora_fin);
        } else {
            var n = Object.keys(estado.seleccion).length;
            $('#modal-titulo').textContent = lote ? 'Asignar turno a ' + n + (n === 1 ? ' celda' : ' celdas') : 'Nuevo turno';
            $('#turno-id').value = '';
            if (!lote) {
                var filtro = $('#filtro-empleado').value;
                $('#turno-empleado').value = o.empleadoId || filtro || (empleadosCache[0] ? empleadosCache[0].id : '');
                setFecha($('#turno-fecha'), o.fecha || fechaLocal(new Date()));
            }
            setHora($('#turno-inicio'), '09:00');
            setHora($('#turno-fin'), '17:00');
        }
        sincronizarTipo();
        abrir('#modal-turno');
    }
    function sincronizarTipo() {
        var t = tipoPorHoras(getHora($('#turno-inicio')), getHora($('#turno-fin')));
        $('#turno-tipo').value = t ? String(t.id) : '';
    }
    $('#turno-tipo').addEventListener('change', function () {
        var t = porId(tiposCache, $('#turno-tipo').value);
        if (t) { setHora($('#turno-inicio'), t.hora_inicio); setHora($('#turno-fin'), t.hora_fin); }
    });
    $('#turno-inicio').addEventListener('change', sincronizarTipo);
    $('#turno-fin').addEventListener('change', sincronizarTipo);

    $('#btn-nuevo-turno').addEventListener('click', function () { abrirTurno({}); });
    $('#btn-cancelar-turno').addEventListener('click', function () { cerrar('#modal-turno'); });

    $('#form-turno').addEventListener('submit', async function (e) {
        e.preventDefault();
        mostrarMensaje('#turno-error', '');
        try {
            var ini = getHora($('#turno-inicio')), fin = getHora($('#turno-fin'));
            if (ini === fin) { throw new Error('La hora de salida debe ser distinta de la de entrada.'); }
            if (modoTurno === 'lote') {
                var items = itemsSeleccion().map(function (x) { x.hora_inicio = ini; x.hora_fin = fin; return x; });
                var rl = await apiPost({ accion: 'crear_turnos', items: items });
                cerrar('#modal-turno');
                limpiarSeleccion();
                await cargarCuadrante();
                toast('Turnos creados: ' + rl.creados + (rl.omitidos_solapados ? ' · omitidos por solape: ' + rl.omitidos_solapados : '') + '.');
                return;
            }
            var res = await apiPost({
                accion: 'guardar_turno', id: Number($('#turno-id').value || 0), empleado_id: Number($('#turno-empleado').value),
                fecha: getFecha($('#turno-fecha')), hora_inicio: ini, hora_fin: fin
            });
            cerrar('#modal-turno');
            await cargarCuadrante();
            if (res.warning) { await avisar(res.warning); }
        } catch (err) {
            if (!err.silencioso) { mostrarMensaje('#turno-error', err.message); }
        }
    });

    $('#btn-borrar-turno').addEventListener('click', function () {
        var t = turnoPorId(Number($('#turno-id').value));
        if (!t) { return; }
        segura(async function () {
            if (!(await confirmar('¿Borrar este turno?'))) { return; }
            cerrar('#modal-turno');
            await borrarTurnosConUndo([t]);
        });
    });

    qsa('[data-marcar]').forEach(function (b) {
        b.addEventListener('click', function () {
            mostrarMensaje('#turno-error', '');
            segura(async function () {
                var f = getFecha($('#turno-fecha'));
                await apiPost({ accion: 'crear_dia', fecha: f, fecha_fin: f, tipo: b.getAttribute('data-marcar'), empleado_id: Number($('#turno-empleado').value), nota: '' });
                cerrar('#modal-turno');
                await cargarCuadrante();
            });
        });
    });

    $('#btn-vaciar').addEventListener('click', function () {
        segura(async function () {
            var r = rango(), ini = fechaLocal(r[0]), fin = fechaLocal(r[1]);
            var lista = estado.turnos.filter(function (t) { return t.fecha >= ini && t.fecha < fin; });
            if (lista.length === 0) { await avisar('No hay turnos en el periodo visible.'); return; }
            var quien = $('#filtro-empleado').value ? 'del empleado seleccionado' : 'de todos los empleados';
            if (!(await confirmar('¿Borrar los ' + lista.length + ' turnos del periodo visible (' + quien + ')?\nPodrá deshacerlo desde el aviso que aparecerá.'))) { return; }
            await borrarTurnosConUndo(lista);
        });
    });

    /* =========================================================
       Ausencias (vacaciones, descansos, festivos)
       ========================================================= */

    async function cargarDias() {
        try {
            var desde = new Date(), hasta = sumarDiasLocal(desde, 365);
            diasLista = await apiGet({ recurso: 'dias_especiales', start: fechaLocal(desde), end: fechaLocal(hasta) });
            $('#lista-dias').innerHTML = diasLista.length ? diasLista.map(function (d) {
                return '<li class="item-dia dia-' + esc(d.tipo) + '" title="' + esc(d.nota || '') + '">' +
                    '<span class="fecha">' + fechaLatina(d.fecha) + '</span><span class="tipo">' + esc(TIPOS_DIA[d.tipo] || d.tipo) + '</span>' +
                    '<span class="emp">' + esc(d.empleado_nombre || 'Empresa') + '</span>' +
                    '<button type="button" class="btn-borrar-mini" data-borrar-dia="' + d.id + '" title="Quitar">' + ico('trash') + '</button></li>';
            }).join('') : '<li class="vacio">No hay días marcados próximamente.</li>';
        } catch (err) {
            if (!err.silencioso) { console.error(err); }
        }
    }
    $('#btn-ausencias').addEventListener('click', function () {
        mostrarMensaje('#dia-error', '');
        var hoy = fechaLocal(new Date());
        setFecha($('#dia-fecha'), hoy);
        setFecha($('#dia-fecha-fin'), hoy);
        $('#dia-nota').value = '';
        cargarDias();
        abrir('#modal-ausencias');
    });
    $('#btn-cerrar-ausencias').addEventListener('click', function () { cerrar('#modal-ausencias'); });
    $('#dia-fecha').onCambio = function () {
        try { if (getFecha($('#dia-fecha-fin')) < getFecha($('#dia-fecha'))) { setFecha($('#dia-fecha-fin'), getFecha($('#dia-fecha'))); } }
        catch (err) { /* fecha a medio escribir */ }
    };
    $('#form-dia').addEventListener('submit', async function (e) {
        e.preventDefault();
        mostrarMensaje('#dia-error', '');
        try {
            var desde = getFecha($('#dia-fecha')), hasta = getFecha($('#dia-fecha-fin'));
            if (hasta < desde) { throw new Error('La fecha final no puede ser anterior a la inicial.'); }
            var res = await apiPost({
                accion: 'crear_dia', fecha: desde, fecha_fin: hasta, tipo: $('#dia-tipo').value,
                empleado_id: Number($('#dia-empleado').value || 0), nota: $('#dia-nota').value
            });
            $('#dia-nota').value = '';
            await cargarDias();
            await cargarCuadrante();
            if (res.creados === 0) { mostrarMensaje('#dia-error', 'Esos días ya estaban marcados.'); }
        } catch (err) {
            if (!err.silencioso) { mostrarMensaje('#dia-error', err.message); }
        }
    });
    function borrarDia(id) {
        segura(async function () {
            var d = porId(estado.dias, id) || porId(diasLista, id);
            if (!(await confirmar('¿Quitar este día especial?'))) { return; }
            await apiPost({ accion: 'borrar_dia', id: Number(id) });
            await cargarCuadrante();
            if ($('#modal-ausencias').style.display !== 'none') { await cargarDias(); }
            if (d) {
                toast('Día quitado.', 'Deshacer', async function () {
                    await apiPost({ accion: 'crear_dia', fecha: d.fecha, fecha_fin: d.fecha, tipo: d.tipo, empleado_id: d.empleado_id || 0, nota: d.nota || '' });
                    await cargarCuadrante();
                });
            }
        });
    }
    $('#lista-dias').addEventListener('click', function (e) {
        var b = e.target.closest('[data-borrar-dia]');
        if (b) { borrarDia(Number(b.getAttribute('data-borrar-dia'))); }
    });

    /* =========================================================
       Exportar, imprimir, tema, teclado
       ========================================================= */

    $('#btn-csv').addEventListener('click', function () {
        var r = rango();
        window.location.href = urlApi({ recurso: 'exportar_csv', start: fechaLocal(r[0]), end: fechaLocal(r[1]) }, true);
    });
    $('#btn-imprimir').addEventListener('click', function () { prepararImpresion(); window.print(); });

    function aplicarModoOscuro(activo) {
        document.body.classList.toggle('modo-oscuro', activo);
        $('#btn-modo-oscuro').innerHTML = ico(activo ? 'sun' : 'moon');
        try { window.localStorage.setItem(CLAVE_OSCURO, activo ? '1' : '0'); } catch (e) { /* sin persistencia */ }
    }
    $('#btn-modo-oscuro').addEventListener('click', function () { aplicarModoOscuro(!document.body.classList.contains('modo-oscuro')); });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        if ($('#datepicker').style.display !== 'none') { cerrarDP(); return; }
        if ($('#modal-dialogo').style.display !== 'none') {
            if ($('#dialogo-cancelar').style.display !== 'none') { $('#dialogo-cancelar').click(); } else { $('#dialogo-ok').click(); }
            return;
        }
        for (var i = 0; i < MODALES.length; i++) {
            if ($(MODALES[i]).style.display !== 'none') { cerrar(MODALES[i]); return; }
        }
        if (Object.keys(estado.seleccion).length) { limpiarSeleccion(); }
    });

    /* =========================================================
       Arranque
       ========================================================= */

    qsa('[data-ico]').forEach(function (el) { el.insertAdjacentHTML('afterbegin', ico(el.getAttribute('data-ico'))); });
    qsa('.fecha-in').forEach(initFechaIn);
    qsa('.hora-sel').forEach(initHoraSel);
    construirFicha();
    setHora($('#turno-inicio'), '09:00');
    setHora($('#turno-fin'), '17:00');
    limpiarFormTipo();
    $('#btn-modo-oscuro').innerHTML = ico('moon');

    (async function iniciar() {
        try {
            if (window.localStorage.getItem(CLAVE_OSCURO) === '1') { aplicarModoOscuro(true); }
            var v = window.localStorage.getItem(CLAVE_VISTA);
            if (v === 'semana' || v === 'mes') { estado.vista = v; }
        } catch (e) { /* sin persistencia */ }

        try {
            var s = await apiGet({ recurso: 'sesion' });
            if (s.debe_cambiar_password) { mostrarPantalla('#pantalla-cambio'); }
            else { await mostrarApp(); }
        } catch (err) {
            mostrarLogin();
            if (!err.silencioso) { mostrarMensaje('#login-error', err.message); }
        }
    })();
})();
