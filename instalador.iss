; =====================================================================
;  instalador.iss  -  Bytes PC - Control Horario
;
;  Requiere Inno Setup 6.3 o superior.
;  (Con una versión anterior, cambia en [Setup]
;   ArchitecturesInstallIn64BitMode=x64compatible  por  =x64)
;
;  IMPORTANTE: este archivo debe guardarse como "UTF-8 con BOM"
;  (el editor de Inno Setup lo hace solo) para que se vean bien las tildes.
; =====================================================================

#define AppNombre   "Bytes PC - Control Horario"
#define AppCarpeta  "BytesPC_ControlHorario"
#define AppVersion  "1.0.0"
#define AppEditor   "Bytes PC"

; Nombre del ejecutable de PHP Desktop. Si lo renombras, cámbialo aquí.
#define AppExe      "phpdesktop-chrome.exe"

; Icono (.ico multi-resolución). Debe estar junto a este archivo .iss
#define AppIcono    "icono.ico"

; Carpeta con PHP Desktop YA PREPARADO (exe, www, php, locales, settings.json...)
#define Origen      "C:\BytesPC\paquete"


[Setup]
; GUID único de la aplicación. NO LO CAMBIES NUNCA: es lo que permite
; que una versión nueva se instale encima de la anterior (actualización).
AppId={{FEED6D2B-EA5A-4AAE-BF3B-F279406E82A1}
AppName={#AppNombre}
AppVersion={#AppVersion}
AppPublisher={#AppEditor}
VersionInfoVersion={#AppVersion}
VersionInfoDescription={#AppNombre}

; {autopf} + instalación en modo 64 bits = C:\Program Files\BytesPC_ControlHorario
; (sin ArchitecturesInstallIn64BitMode, un exe de 32 bits iría a "Program Files (x86)")
DefaultDirName={autopf}\{#AppCarpeta}
ArchitecturesInstallIn64BitMode=x64compatible
DisableProgramGroupPage=yes
PrivilegesRequired=admin

SetupIconFile={#AppIcono}
UninstallDisplayIcon={app}\{#AppIcono}
UninstallDisplayName={#AppNombre}

OutputDir=Salida
OutputBaseFilename=Instalador_BytesPC_ControlHorario_{#AppVersion}
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern

; El cierre de la aplicación se hace a mano en [Code] (ver CerrarAplicacion).
CloseApplications=no
RestartApplications=no


[Languages]
Name: "spanish"; MessagesFile: "compiler:Languages\Spanish.isl"


[Tasks]
; Casilla marcada por defecto: el usuario puede desmarcarla.
Name: "iconoescritorio"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"


[Files]
Source: "{#AppIcono}"; DestDir: "{app}"; Flags: ignoreversion

; Todo el paquete de PHP Desktop. Se EXCLUYE lo que nunca debe distribuirse:
;   webcache\*            caché de Chrome generada al probar la app
;   *.log                 registros de pruebas
;   www\turnos.db(-journal)  base de datos de desarrollo (los datos reales viven en AppData)
;   www\reset.php         script de reseteo de contraseña sin autenticación
Source: "{#Origen}\*"; DestDir: "{app}"; Excludes: "webcache\*,*.log,www\turnos.db,www\turnos.db-journal,www\reset.php"; Flags: ignoreversion recursesubdirs createallsubdirs


[Dirs]
; PERMISOS: la carpeta de datos del usuario (%APPDATA%\BytesPCControlHorario) NO necesita
; permisos especiales: es del propio usuario y db.php la crea al arrancar. Por eso el
; instalador NO crea ni toca nada en AppData (si lo hiciera con la cuenta elevada, podría
; hacerlo en el perfil de otro usuario).
; Lo que SÍ necesita escritura dentro de Program Files es la caché de Chrome de PHP Desktop
; (cookies, localStorage...). Se concede "Modificar" al grupo Usuarios SOLO a esa carpeta;
; el resto de la instalación (www, php...) queda de solo lectura.
Name: "{app}\webcache"; Permissions: users-modify


[Icons]
Name: "{autoprograms}\{#AppNombre}"; Filename: "{app}\{#AppExe}"; WorkingDir: "{app}"; IconFilename: "{app}\{#AppIcono}"
Name: "{autodesktop}\{#AppNombre}";  Filename: "{app}\{#AppExe}"; WorkingDir: "{app}"; IconFilename: "{app}\{#AppIcono}"; Tasks: iconoescritorio


[Run]
; Casilla "Ejecutar al terminar". runasoriginaluser es CLAVE: el instalador va elevado
; (administrador) y, sin este flag, la app arrancaría como administrador y su base de datos
; se crearía en el AppData equivocado.
Filename: "{app}\{#AppExe}"; Description: "Ejecutar {#AppNombre}"; WorkingDir: "{app}"; Flags: nowait postinstall skipifsilent runasoriginaluser


[UninstallDelete]
; Se limpian SOLO los restos que genera PHP Desktop dentro de la carpeta del programa.
; NO se toca %APPDATA%\BytesPCControlHorario: los turnos del usuario se conservan.
Type: filesandordirs; Name: "{app}\webcache"
Type: files;          Name: "{app}\*.log"
Type: dirifempty;     Name: "{app}"


[Code]
// Cierra cualquier proceso que se este ejecutando desde la carpeta de instalacion
// (phpdesktop-chrome.exe, sus subprocesos y php-cgi.exe). Sin esto, una actualizacion
// falla porque Windows no deja sobrescribir archivos en uso. Solo afecta a procesos cuyo
// ejecutable esta dentro de la carpeta de la aplicacion: no toca otros PHP Desktop ni
// otros PHP del equipo.
procedure CerrarAplicacion();
var
  Ps, Params: String;
  Codigo: Integer;
begin
  Ps := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
  Params :=
    '-NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "' +
    'Get-Process | Where-Object { $_.Path -like ''' + ExpandConstant('{app}') + '\*'' ' +
    '-and $_.Name -notlike ''unins*'' } | ' +
    'Stop-Process -Force -ErrorAction SilentlyContinue"';
  Exec(Ps, Params, '', SW_HIDE, ewWaitUntilTerminated, Codigo);
  Sleep(800);
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
begin
  CerrarAplicacion();
  Result := '';
end;

function InitializeUninstall(): Boolean;
begin
  CerrarAplicacion();
  Result := True;
end;
