<?php
session_start();
include 'config.php';

// Protegido por login (usuario estándar)
if (empty($_SESSION['usermail'])) {
  header("Location: index.php");
  exit;
}

$usermail = $_SESSION['usermail'];

/* === Coordenadas del hotel (ajusta a las reales) === */
$HOTEL_LAT = 5.9756607;     // latitud
$HOTEL_LNG = -74.5881752;   // longitud

/* === Tu API KEY de Google === */
$GOOGLE_MAPS_API_KEY = 'AIzaSyCe3Gv31Uv-174yWTCuOI67tkFQojj7Q2E';

/* === Si envían nuevas preferencias desde el editor dentro de turismo.php === */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prefs'])) {
  // Obtener UserID
  $sql = "SELECT UserID FROM signup WHERE Email = ?";
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($stmt, "s", $usermail);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $row = mysqli_fetch_assoc($res);
  mysqli_stmt_close($stmt);

  if ($row && !empty($row['UserID'])) {
    $userId = (int)$row['UserID'];

    // Limpiar y normalizar tags
    $tags = $_POST['custom_interests'] ?? [];
    $clean = [];
    foreach ($tags as $t) {
      $t = trim($t);
      if ($t === '') continue;
      $t = mb_substr($t, 0, 50, 'UTF-8');
      $clean[strtolower($t)] = $t; // quitar duplicados case-insensitive
    }
    $unique = array_values($clean);

    // Reemplazar preferencias del usuario
    $sql = "DELETE FROM user_interests WHERE UserID = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!empty($unique)) {
      $sql = "INSERT INTO user_interests (UserID, Interest) VALUES (?, ?)";
      $stmt = mysqli_prepare($conn, $sql);
      foreach ($unique as $t) {
        mysqli_stmt_bind_param($stmt, "is", $userId, $t);
        mysqli_stmt_execute($stmt);
      }
      mysqli_stmt_close($stmt);
    }
  }

  // Evitar reenvíos con refresh
  header("Location: turismo.php?saved=1");
  exit;
}

/* === Obtener intereses del usuario (si existen) === */
$interests = [];
$sql = "SELECT ui.Interest 
        FROM user_interests ui
        INNER JOIN signup s ON s.UserID = ui.UserID
        WHERE s.Email = ?";
if ($stmt = mysqli_prepare($conn, $sql)) {
  mysqli_stmt_bind_param($stmt, "s", $usermail);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  while ($row = mysqli_fetch_assoc($res)) {
    $interests[] = $row['Interest'];
  }
  mysqli_stmt_close($stmt);
}

/* ⬇️ AÑADE ESTA NORMALIZACIÓN AQUÍ */
$flat = [];
foreach ($interests as $s) {
  foreach (preg_split('/[,\|;\/]+/u', $s) as $p) {
    $p = trim($p);
    if ($p !== '') $flat[strtolower($p)] = $p; // dedup case-insensitive
  }
}
$interests = array_values($flat);

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Atracciones cercanas - Hotel Andino</title>

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Estilos de la página -->
  <link rel="stylesheet" href="./css/turismo.css?v=6">

  <style>
    .prefs-chip{ display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; background:#f1f3f5; margin:4px; }
    .prefs-chip .rm{ border:0; background:transparent; font-weight:700; cursor:pointer; line-height:1; }
    .prefs-wrap{ display:flex; flex-wrap:wrap; gap:8px; min-height:48px; border:1px solid #e5e5e5; border-radius:10px; padding:8px; background:#fafafa; }
    .prefs-input{ border:0; outline:none; min-width:160px; background:transparent; }
    .btn-outline-gold{ border:1px solid #d4af37; color:#b8860b; }
    .btn-outline-gold:hover{ background:#fff7da; color:#8d6a00; border-color:#d4af37; }
    .results-header small{ background:#111; color:#fff; padding:2px 8px; border-radius:999px; margin-left:8px; }
    .toolbar .form-label{ font-weight:600; }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar fixed-top shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center gap-2" href="home.php">
      <img src="./image/LogoAndino.png" alt="logo" style="height:36px">
      <span class="hotel-title">Hotel Andino</span>
    </a>
    <a class="btn btn-outline-gold" href="home.php">Volver</a>
  </div>
</nav>

<div class="container py-3">

  <!-- Controles: solo radio y rating + botón intereses -->
  <div class="toolbar card shadow-sm p-3 mb-2">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label mb-1">Radio</label>
        <select id="radiusSelect" class="form-select">
          <option value="1000" selected>1 km</option>
          <option value="10000">10 km</option>
          <option value="50000">50 km</option>
          <option value="100000">100 km (máx. efectivo 50 km)</option>
        </select>
      </div>

      <div class="col-6 col-md-3">
        <label class="form-label mb-1">Calificación mínima</label>
        <select id="ratingFilter" class="form-select">
          <option value="0" selected>Todas</option>
          <option value="3">⭐ 3+</option>
          <option value="4">⭐ 4+</option>
          <option value="4.5">⭐ 4.5+</option>
        </select>
      </div>

      <div class="col-12 col-md-6 d-grid">
        <button id="myInterestsBtn" class="btn btn-warning">
          🔎 Buscar según mis intereses
        </button>
        <?php if (isset($_GET['saved'])): ?>
  <!-- Toast arriba a la derecha, por encima de la navbar y separado unos px -->
  <div class="toast-container position-fixed top-0 end-0 p-3" id="toastArea" style="z-index:10000; top: 80px;">
    <div id="prefsToast" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body">
          Preferencias guardadas.
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
      </div>
    </div>
  </div>
  <script>
    document.addEventListener("DOMContentLoaded", function(){
      // Ajusta la separación según la altura real de tu navbar
      var nav = document.querySelector('.navbar');
      var area = document.getElementById('toastArea');
      if (nav && area) {
        var h = nav.offsetHeight || 56;
        area.style.top = (h + 8) + 'px';
      }
      new bootstrap.Toast(document.getElementById('prefsToast'), { delay: 2500 }).show();
    });
  </script>
<?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Panel Mis preferencias -->
  <div class="card shadow-sm p-3 mb-3 rounded-soft">
    <div class="d-flex align-items-center justify-content-between">
      <h6 class="m-0">Mis preferencias</h6>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#prefsEditor">
        Editar
      </button>
    </div>

    <div class="mt-2" id="saved-prefs">
      <?php if (!empty($interests)): ?>
        <?php foreach ($interests as $t): $safe = htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
          <span class="prefs-chip"><?= $safe ?></span>
        <?php endforeach; ?>
      <?php else: ?>
        <small class="text-muted">Aún no tienes preferencias guardadas.</small>
      <?php endif; ?>
    </div>

    <!-- Editor (colapsable): agregar/guardar preferencias -->
    <div class="collapse mt-3" id="prefsEditor">
      <form method="POST" class="mt-2">
        <label class="form-label">Añade o edita tus gustos (Enter para agregar):</label>
        <div class="prefs-wrap" id="prefs-wrap">
          <?php if (!empty($interests)): ?>
            <?php foreach ($interests as $t): $safe = htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
              <span class="prefs-chip">
                <input type="hidden" name="custom_interests[]" value="<?= $safe ?>">
                <span><?= $safe ?></span>
                <button class="rm" type="button" aria-label="Quitar">&times;</button>
              </span>
            <?php endforeach; ?>
          <?php endif; ?>
          <input id="prefs-input" class="prefs-input" type="text" placeholder="Ej.: fotografía, parques, cafés bonitos">
        </div>

        <div class="d-flex gap-2 mt-3">
          <button type="submit" name="save_prefs" class="btn btn-primary">
            Guardar preferencias
          </button>
          <button type="button" id="manualSearchBtn" class="btn btn-outline-gold">
            Buscar con estas (sin guardar)
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Layout principal: listado + mapa -->
  <div class="map-layout">
    <!-- Panel de resultados -->
    <aside id="results-panel">
      <div class="results-header">
        <strong>Lugares encontrados</strong>
        <small id="results-count">0</small>
      </div>
      <div id="results-list"></div>
    </aside>

    <!-- Mapa -->
    <div
      id="map"
      data-lat="<?= htmlspecialchars($HOTEL_LAT) ?>"
      data-lng="<?= htmlspecialchars($HOTEL_LNG) ?>">
    </div>
  </div>

</div> <!-- /.container -->

<!-- Panel lateral de detalles -->
<div id="place-details" class="hidden">
  <button id="details-close">&times;</button>
  <div class="details-body">
    <div class="details-photo"></div>
    <h5 id="details-name"></h5>
    <div class="details-rating"></div>
    <div class="details-address"></div>
    <div class="details-phone"></div>
    <div class="details-hours"></div>
    <div class="details-links"></div>
  </div>
</div>

<!-- Pasar coords e intereses al JS -->
<script>
  window.HOTEL = {
    lat: <?= json_encode($HOTEL_LAT) ?>,
    lng: <?= json_encode($HOTEL_LNG) ?>
  };
  window.USER_INTERESTS = <?= json_encode($interests, JSON_UNESCAPED_UNICODE) ?>;
</script>

<!-- Bootstrap JS: necesario para el collapse del botón "Editar" -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Tu lógica -->
<script src="./javascript/turismo.js?v=6"></script>

<!-- Google Maps + Places + Marker -->
<script async defer
  src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($GOOGLE_MAPS_API_KEY) ?>&libraries=places,marker&callback=initMap&language=es&region=CO&v=weekly">
</script>

<!-- Tag UI para el editor inline -->
<script>
(function(){
  const wrap = document.getElementById('prefs-wrap');
  const input = document.getElementById('prefs-input');

  function addTag(text){
    const t = (text||'').trim();
    if(!t) return;
    const exists = Array.from(wrap.querySelectorAll('input[name="custom_interests[]"]'))
      .some(h => h.value.toLowerCase() === t.toLowerCase());
    if(exists){ input.value=''; return; }

    const span = document.createElement('span');
    span.className = 'prefs-chip';
    span.innerHTML = `
      <input type="hidden" name="custom_interests[]" value="${t}">
      <span>${t}</span>
      <button class="rm" type="button" aria-label="Quitar">&times;</button>
    `;
    wrap.insertBefore(span, input);
    input.value='';
  }

  input?.addEventListener('keydown', (e) => {
    if(e.key === 'Enter'){
      e.preventDefault();
      addTag(input.value);
    } else if(e.key === 'Backspace' && input.value === ''){
      const last = wrap.querySelector('.prefs-chip:last-of-type');
      if(last) last.remove();
    }
  });

  wrap?.addEventListener('click', (e) => {
    if(e.target.classList.contains('rm')){
      e.target.closest('.prefs-chip').remove();
    }
  });
})();
</script>
</body>
</html>
