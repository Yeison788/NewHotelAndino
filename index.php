<?php 
include 'config.php';
session_start();

require_once __DIR__ . '/admin/includes/admin_bootstrap.php';
ensureEmpStructure($conn);
ensureRoomRates($conn);

$openOnboarding = false; // 👈 abrir modal tras registro

/* =========================
   Manejo de formularios PHP
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // == Login de Usuario ==
    if (isset($_POST['user_login_submit'])) {
        $Email = $_POST['Email'] ?? '';
        $Password = $_POST['Password'] ?? '';

        $sql = "SELECT * FROM signup WHERE Email = ? AND BINARY Password = ?";
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "ss", $Email, $Password);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($result && $result->num_rows > 0) {
                $_SESSION['usermail'] = $Email;
                header("Location: home.php");
                exit;
            } else {
                $loginUserError = true;
            }
            mysqli_stmt_close($stmt);
        } else { 
            $loginUserError = true; 
        }
    }

    // == Login de Empleado (Admin) ==
    if (isset($_POST['Emp_login_submit'])) {
        $Email = trim($_POST['Emp_Email'] ?? '');
        $Password = $_POST['Emp_Password'] ?? '';

        $sql = "SELECT Emp_Email, Emp_Password, FullName, Role, Permissions, IsSuperAdmin FROM emp_login WHERE Emp_Email = ? LIMIT 1";
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $Email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);

            if ($row) {
                $storedPassword = $row['Emp_Password'] ?? '';
                $isValid = hash_equals($storedPassword, $Password);
                if (!$isValid && strlen($storedPassword) > 0) {
                    $isValid = password_verify($Password, $storedPassword);
                }

                if ($isValid) {
                    $perms = [];
                    if (!empty($row['Permissions'])) {
                        $decoded = json_decode($row['Permissions'], true);
                        if (is_array($decoded)) {
                            $perms = array_values(array_unique(array_map('strval', $decoded)));
                        }
                    }
                    $isSuper = !empty($row['IsSuperAdmin']);
                    if ($row['Emp_Email'] === 'admin@hotelandino.com') {
                        $isSuper = true;
                        $perms = array_keys(admin_available_permissions());
                    }

                    $_SESSION['adminmail'] = $row['Emp_Email'];
                    $_SESSION['admin_name'] = $row['FullName'] ?: $row['Emp_Email'];
                    $_SESSION['admin_role'] = $row['Role'] ?: '';
                    $_SESSION['admin_permissions'] = $perms;
                    $_SESSION['admin_is_super'] = $isSuper;

                    header("Location: ./admin/admin.php");
                    exit;
                }
            }

            $loginEmpError = true;
        } else {
            $loginEmpError = true;
        }
    }

    // == Registro de Usuario ==
    if (isset($_POST['user_signup_submit'])) {
        $Username  = $_POST['Username']  ?? '';
        $Email     = $_POST['Email']     ?? '';
        $Password  = $_POST['Password']  ?? '';
        $CPassword = $_POST['CPassword'] ?? '';

        if ($Username === "" || $Email === "" || $Password === "") {
            $signupError = "Completa los datos correctamente";
        } else {
            if ($Password === $CPassword) {
                $sql = "SELECT 1 FROM signup WHERE Email = ?";
                if ($stmt = mysqli_prepare($conn, $sql)) {
                    mysqli_stmt_bind_param($stmt, "s", $Email);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);

                    if ($result && $result->num_rows > 0) {
                        $signupError = "El correo ya existe";
                    } else {
                        $sqlIns = "INSERT INTO signup (Username, Email, Password) VALUES (?, ?, ?)";
                        if ($stmtIns = mysqli_prepare($conn, $sqlIns)) {
                            mysqli_stmt_bind_param($stmtIns, "sss", $Username, $Email, $Password);
                            $ok = mysqli_stmt_execute($stmtIns);
                            mysqli_stmt_close($stmtIns);

                            if ($ok) {
                                $_SESSION['usermail'] = $Email;
                                $openOnboarding = true; // ✅ abrir modal
                            } else { 
                                $signupError = "Algo salió mal"; 
                            }
                        } else { 
                            $signupError = "Algo salió mal"; 
                        }
                    }
                    mysqli_stmt_close($stmt);
                } else { 
                    $signupError = "Algo salió mal"; 
                }
            } else { 
                $signupError = "Las contraseñas no coinciden"; 
            }
        }
    }

    // == Guardado de intereses desde el MODAL (mismo index) ==
    if (isset($_POST['save']) || isset($_POST['skip'])) {

        if (empty($_SESSION['usermail'])) {
            header("Location: index.php");
            exit;
        }

        $usermail = $_SESSION['usermail'];

        // Obtener UserID
        $sqlUser = "SELECT UserID FROM signup WHERE Email = ?";
        $userId = null;
        if ($stmt = mysqli_prepare($conn, $sqlUser)) {
            mysqli_stmt_bind_param($stmt, "s", $usermail);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            if (!empty($row['UserID'])) $userId = (int)$row['UserID'];
        }
        if (!$userId) { header("Location: index.php"); exit; }

        // Si pulsó "Guardar", insertar intereses (dividir por comas/; /| / saltos)
        if (isset($_POST['save']) && !empty($_POST['interests']) && is_array($_POST['interests'])) {
            $tokens = [];
            foreach ($_POST['interests'] as $raw) {
                foreach (preg_split('/[,\|;\/]+/u', $raw) as $part) {
                    $t = trim($part);
                    if ($t === '') continue;
                    $t = mb_substr($t, 0, 50, 'UTF-8');
                    $tokens[strtolower($t)] = $t; // dedup case-insensitive
                }
            }
            $unique = array_values($tokens);

            if ($stmt = mysqli_prepare($conn, "DELETE FROM user_interests WHERE UserID = ?")) {
                mysqli_stmt_bind_param($stmt, "i", $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            if (!empty($unique) && ($stmtIns = mysqli_prepare($conn, "INSERT INTO user_interests (UserID, Interest) VALUES (?, ?)"))) {
                foreach ($unique as $it) {
                    mysqli_stmt_bind_param($stmtIns, "is", $userId, $it);
                    mysqli_stmt_execute($stmtIns);
                }
                mysqli_stmt_close($stmtIns);
            }
        }

        // Marcar onboarding completado (guardar o saltar)
        if ($stmtUpd = mysqli_prepare($conn, "UPDATE signup SET OnboardingDone = 1 WHERE UserID = ?")) {
            mysqli_stmt_bind_param($stmtUpd, "i", $userId);
            mysqli_stmt_execute($stmtUpd);
            mysqli_stmt_close($stmtUpd);
        }

        header("Location: home.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hotel Andino</title>

  <link rel="stylesheet" href="./css/login.css?v=6">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- sweet alert / aos / pace -->
  <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
  <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css"/>
  <script src="https://cdn.jsdelivr.net/npm/pace-js@latest/pace.min.js"></script>
  <link rel="stylesheet" href="./css/flash.css">

  <!-- ==== ESTILOS inline: carrusel izq. + login der. + fondo con blobs ==== -->
  <style>
    :root{ --gold:#d4af37; --gold-2:#e4c766; --gold-dark:#b8860b; }
    *{ box-sizing:border-box; }

    body{
      min-height:100vh; margin:0;
      background:
        radial-gradient(900px 600px at -10% -10%, #fff9e5 0%, transparent 50%),
        radial-gradient(900px 600px at 110% 0%, #f0f6ff 0%, transparent 55%),
        linear-gradient(135deg, #ffffff 0%, #f8fbff 40%, #fef9ec 100%);
    }

    section{ height:100vh; }
    .carousel_section{ width:50%; float:left; }
    .carousel_section + #auth_section{ width:50%; float:left; }

    @media (max-width: 768px){
      .carousel_section{ display:none; }
      .carousel_section + #auth_section{ width:100%; float:none; }
      body{ display:flex; align-items:center; justify-content:center; }
      section{ height:auto; }
    }

    .carousel-image{ height:100vh; width:100%; object-fit:cover; display:block; }
    .carousel-inner{ position:relative; }
    .carousel-inner::after{ content:""; position:absolute; inset:0; background-color:rgba(0,0,0,0.2); }

    #auth_section{
      position:relative;
      display:flex; flex-direction:column; align-items:center; justify-content:center;
      padding:28px 16px;
    }
    #auth_section::before, #auth_section::after{
      content:""; position:absolute; border-radius:50%; filter:blur(40px); z-index:0;
    }
    #auth_section::before{
      width:360px; height:360px; top:-80px; left:-60px;
      background: radial-gradient(circle at 30% 30%, rgba(228,199,102,0.55), transparent 60%);
    }
    #auth_section::after{
      width:340px; height:340px; bottom:-70px; right:-60px;
      background: radial-gradient(circle at 70% 70%, rgba(135,178,255,0.45), transparent 60%);
    }

    .logo{ display:flex; align-items:center; gap:12px; margin-bottom:10px; position:relative; z-index:1; }
    .logo .hotellogo{ height:56px; width:auto; object-fit:contain; }
    .logo p{ margin:0; font-weight:700; font-size:28px; color:var(--gold-dark); }

    .auth_container{
      width:min(92vw, 460px);
      padding:26px 24px; border-radius:18px; position:relative; z-index:1;
      border:1px solid rgba(212,175,55,.35);
      background: rgba(255,255,255,.9); backdrop-filter: blur(6px);
      box-shadow: 0 10px 30px rgba(0,0,0,.15);
    }

    .features-row{ display:flex; justify-content:center; gap:10px; flex-wrap:wrap; margin: 0 0 10px 0; }
    .features-row .chip{
      display:inline-flex; align-items:center; gap:6px;
      padding:6px 10px; border-radius:999px;
      background: rgba(255,255,255,.95);
      border:1px solid #eee;
      box-shadow: 0 6px 14px rgba(0,0,0,.05);
      font-size:12px; color:#555;
    }

    #Log_in{ display:flex; flex-direction:column; align-items:center; }
    #Log_in h2, #sign_up h2{ font-size:22px; font-weight:700; margin:6px 0 8px; color:#333; text-align:center; }

    .role_btn{
      width:100%; display:flex; justify-content:center; align-items:center;
      gap:14px; margin:12px 0 18px; flex-wrap:wrap;
    }
    .role_btn .btns{
      display:inline-flex; align-items:center; justify-content:center;
      height:40px; min-width:140px; padding:0 18px; border-radius:999px;
      border:1.5px solid var(--gold); background:#fff; color:#b8860b;
      font-weight:700; font-size:15px; cursor:pointer;
      transition: transform .08s ease, background-color .25s ease, color .25s ease, box-shadow .25s ease;
    }
    .role_btn .btns:hover, .role_btn .btns.active{
      background: linear-gradient(180deg, var(--gold-2), var(--gold));
      color:#fff; box-shadow: 0 6px 16px rgba(212,175,55,.35);
      transform: translateY(-1px);
    }

    .authsection{ width:100%; display:none; animation: fade .25s ease; }
    .user_login.active, .employee_login.active{ display:block; }

    .form-floating{ margin:12px 0; }
    .form-floating>.form-control{
      border-radius:12px; border:1.5px solid #e9e9ef; padding-left:14px;
    }
    .form-floating>.form-control:focus{
      border-color: var(--gold);
      box-shadow: 0 0 0 .2rem rgba(212,175,55,.15);
    }

    .auth_btn{
      width:100%; height:46px; border:none; border-radius:12px; margin-top:6px;
      background: linear-gradient(180deg, var(--gold-2), var(--gold));
      color:#fff; font-weight:700; font-size:16px; cursor:pointer;
      transition: transform .08s ease, box-shadow .25s ease, filter .15s ease;
    }
    .auth_btn:hover{ box-shadow:0 10px 20px rgba(212,175,55,.35); filter:brightness(1.02); transform:translateY(-1px); }

    .footer_line{ margin:14px 0 4px; text-align:center; }
    .page_move_btn{ color:var(--gold-dark); cursor:pointer; font-weight:600; }
    .page_move_btn:hover{ color:var(--gold); }

    @keyframes fade{ from{opacity:0; transform:translateY(8px);} to{opacity:1; transform:translateY(0);} }
  </style>
</head>

<body>

<!-- ==== Carrusel a la izquierda ==== -->
<section id="carouselExampleControls" class="carousel slide carousel_section" data-bs-ride="carousel" data-bs-interval="4000">
  <div class="carousel-inner">
    <div class="carousel-item active">
      <img class="carousel-image" src="./image/hotel1.jpg" alt="Hotel Andino 1">
    </div>
  </div>
</section>

<!-- ==== Login a la derecha ==== -->
<section id="auth_section">

  <div class="logo">
    <img class="hotellogo" src="./image/LogoAndino.png" alt="logo">
    <p>Hotel Andino</p>
  </div>

  <div class="auth_container" data-aos="fade-up">
    <!-- Chips / Features -->
    <div class="features-row">
      <span class="chip"><i class="fa-solid fa-wifi"></i> Wi-Fi</span>
      <span class="chip"><i class="fa-solid fa-mug-saucer"></i> Desayuno</span>
      <span class="chip"><i class="fa-solid fa-dumbbell"></i> Gimnasio</span>
      <span class="chip"><i class="fa-solid fa-spa"></i> Spa</span>
    </div>

    <!--============ login =============-->
    <div id="Log_in">
      <h2>Iniciar Sesión</h2>

      <div class="role_btn">
        <div class="btns active">Usuario</div>
        <div class="btns">Admin</div>
      </div>

      <!-- Alertas de PHP (SweetAlert) -->
      <?php if (!empty($loginUserError)): ?>
        <script>swal({ title: 'Algo salió mal', icon: 'error' });</script>
      <?php endif; ?>
      <?php if (!empty($loginEmpError)): ?>
        <script>swal({ title: 'Algo salió mal', icon: 'error' });</script>
      <?php endif; ?>

      <!-- // ==user login== -->
      <form class="user_login authsection active" id="userlogin" action="" method="POST">
        <div class="form-floating mb-3">
          <input type="text" class="form-control" name="Username" id="loginUsername" placeholder=" ">
          <label for="loginUsername">Nombre de usuario</label>
        </div>
        <div class="form-floating mb-3">
          <input type="email" class="form-control" name="Email" id="loginEmail" placeholder=" ">
          <label for="loginEmail">Correo electrónico</label>
        </div>
        <div class="form-floating mb-4">
          <input type="password" class="form-control" name="Password" id="loginPassword" placeholder=" ">
          <label for="loginPassword">Contraseña</label>
        </div>
        <button type="submit" name="user_login_submit" class="auth_btn">Iniciar sesión</button>
        <div class="footer_line">
          <h6>¿No tienes una cuenta? <span class="page_move_btn" onclick="signuppage()">Regístrate</span></h6>
        </div>
      </form>

      <!-- == Emp Login == -->
      <form class="employee_login authsection" id="employeelogin" action="" method="POST">
        <div class="form-floating mb-3">
          <input type="email" class="form-control" name="Emp_Email" id="empEmail" placeholder=" ">
          <label for="empEmail">Correo electrónico</label>
        </div>
        <div class="form-floating mb-4">
          <input type="password" class="form-control" name="Emp_Password" id="empPassword" placeholder=" ">
          <label for="empPassword">Contraseña</label>
        </div>
        <button type="submit" name="Emp_login_submit" class="auth_btn">Iniciar sesión</button>
      </form>

    </div>

    <!--============ signup =============-->
    <?php if (!empty($signupError)): ?>
      <script>swal({ title: '<?= htmlspecialchars($signupError, ENT_QUOTES, "UTF-8"); ?>', icon: 'error' });</script>
    <?php endif; ?>

    <div id="sign_up">
      <h2>Registrarse</h2>
      <form class="user_signup" id="usersignup" action="" method="POST">
        <div class="form-floating mb-3">
          <input type="text" class="form-control" name="Username" id="signupUsername" placeholder=" ">
          <label for="signupUsername">Nombre de usuario</label>
        </div>
        <div class="form-floating mb-3">
          <input type="email" class="form-control" name="Email" id="signupEmail" placeholder=" ">
          <label for="signupEmail">Correo electrónico</label>
        </div>
        <div class="form-floating mb-3">
          <input type="password" class="form-control" name="Password" id="signupPassword" placeholder=" ">
          <label for="signupPassword">Contraseña</label>
        </div>
        <div class="form-floating mb-4">
          <input type="password" class="form-control" name="CPassword" id="signupCPassword" placeholder=" ">
          <label for="signupCPassword">Confirmar contraseña</label>
        </div>
        <button type="submit" name="user_signup_submit" class="auth_btn">Registrarse</button>
        <div class="footer_line">
          <h6>¿Ya tienes una cuenta? <span class="page_move_btn" onclick="loginpage()">Inicia sesión</span></h6>
        </div>
      </form>
    </div>

  </div> <!-- /.auth_container -->

</section>

<!-- ===== Modal Onboarding (tags) ===== -->
<div class="modal fade" id="onboardingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius:14px;">
      <div class="modal-header" style="border-bottom:0;">
        <h5 class="modal-title">Cuéntanos tus intereses ✨</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <!-- Postea a este mismo index.php -->
      <form method="POST" action="" id="onb-modal-form">
        <div class="modal-body">
          <p class="text-muted mb-2">Escribe tus gustos separados por coma o Enter. Ej.: <em>cafés, parques, museos</em>.</p>
          <div class="border rounded p-2" id="tags-wrap" style="display:flex;flex-wrap:wrap;gap:8px;min-height:52px;background:#fafafa;">
            <input id="tag-input-modal" class="border-0 flex-grow-1" type="text" placeholder="Ej.: fotografía, parques para niños, cafés bonitos" style="outline:none;background:transparent;min-width:160px;">
          </div>
        </div>
        <div class="modal-footer" style="border-top:0;">
          <button type="submit" name="skip" class="btn btn-light">Saltar</button>
          <button type="submit" name="save" class="btn btn-primary">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="./javascript/index.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@next/dist/aos.js"></script>
<script> AOS.init(); </script>

<!-- ===== JS: abrir el modal y gestionar tags (divide por comas/Enter/pegar) ===== -->
<script>
(function(){
  // Abrir modal si el servidor lo indica
  const shouldOpen = <?= $openOnboarding ? 'true' : 'false' ?>;
  if (shouldOpen && typeof bootstrap !== 'undefined') {
    new bootstrap.Modal(document.getElementById('onboardingModal'), {backdrop:'static', keyboard:false}).show();
  }

  const wrap  = document.getElementById('tags-wrap');
  const input = document.getElementById('tag-input-modal');
  const form  = document.getElementById('onb-modal-form');

  function addTag(t){
    t = (t||'').trim();
    if(!t) return;
    const exists = Array.from(wrap.querySelectorAll('input[name="interests[]"]'))
      .some(h => h.value.toLowerCase() === t.toLowerCase());
    if(exists) return;

    const span = document.createElement('span');
    span.className = 'badge bg-light text-dark border';
    span.style.fontSize = '14px';
    span.style.padding = '8px 10px';
    span.innerHTML = `
      <input type="hidden" name="interests[]" value="${t}">
      <span>${t}</span>
      <button type="button" class="btn btn-sm btn-link p-0 ms-1" aria-label="Quitar">&times;</button>
    `;
    wrap.insertBefore(span, input);
  }

  function addChunk(chunk){
    (chunk||'').split(/[,\n;\/]+/).forEach(part => addTag(part.trim()));
  }

  input?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      addChunk(input.value);
      input.value = '';
    } else if (e.key === 'Backspace' && input.value === '') {
      const last = wrap.querySelector('span.badge:last-of-type');
      if(last) last.remove();
    }
  });

  input?.addEventListener('paste', (e) => {
    const text = (e.clipboardData || window.clipboardData).getData('text') || '';
    if (/[,\n;\/]/.test(text)) {
      e.preventDefault();
      addChunk(text);
    }
  });

  wrap?.addEventListener('click', (e) => {
    if (e.target.matches('button[aria-label="Quitar"]')) {
      e.target.closest('.badge').remove();
    }
  });

  // ✅ FIX: si el usuario escribió y no presionó Enter, dividimos en submit
  form?.addEventListener('submit', () => {
    const v = (input?.value || '').trim();
    if (v) { addChunk(v); input.value = ''; }
  });
})();
</script>
</body>
</html>
