<?php
session_start();
include 'config.php';

// Proteger acceso si no hay sesión
if (empty($_SESSION['usermail'])) {
  header("Location: index.php");
  exit;
}

$usermail = $_SESSION['usermail'];

// ===== Guardar intereses =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['interests'])) {
    $sqlUser = "SELECT UserID FROM signup WHERE Email = ?";
    $stmt = mysqli_prepare($conn, $sqlUser);
    mysqli_stmt_bind_param($stmt, "s", $usermail);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $userId = $row['UserID'];

    // Guardar cada interés
    foreach ($_POST['interests'] as $interest) {
        $interest = trim($interest);
        if ($interest !== "") {
            $sqlIns = "INSERT INTO user_interests (UserID, Interest) VALUES (?, ?)";
            $stmtIns = mysqli_prepare($conn, $sqlIns);
            mysqli_stmt_bind_param($stmtIns, "is", $userId, $interest);
            mysqli_stmt_execute($stmtIns);
            mysqli_stmt_close($stmtIns);
        }
    }

    // Marcar como completado
    $sqlUpd = "UPDATE signup SET OnboardingDone = 1 WHERE UserID = ?";
    $stmtUpd = mysqli_prepare($conn, $sqlUpd);
    mysqli_stmt_bind_param($stmtUpd, "i", $userId);
    mysqli_stmt_execute($stmtUpd);

    // Redirigir a turismo
    header("Location: turismo.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Bienvenido - Completa tu perfil</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      background: #f7f9fc;
      font-family: 'Poppins', sans-serif;
    }
    .onboarding-box {
      max-width: 600px;
      margin: 50px auto;
      padding: 30px;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 6px 18px rgba(0,0,0,.12);
    }
    .chips-container {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 10px;
    }
    .chip {
      background: #e4c766;
      padding: 6px 12px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .chip button {
      border: none;
      background: none;
      cursor: pointer;
      font-size: 14px;
      line-height: 1;
    }
  </style>
</head>
<body>
  <div class="onboarding-box">
    <h2 class="mb-3">👋 Bienvenido a Hotel Andino</h2>
    <p>Cuéntanos tus intereses para recomendarte lugares cercanos:</p>

    <form method="POST" id="onboardingForm">
      <div class="mb-3">
        <input type="text" id="interestInput" class="form-control" placeholder="Escribe un interés y presiona Enter">
        <div class="chips-container" id="chips"></div>
      </div>
      <button type="submit" class="btn btn-success w-100">Guardar y continuar</button>
    </form>
  </div>

  <script>
    const input = document.getElementById("interestInput");
    const chips = document.getElementById("chips");
    const form = document.getElementById("onboardingForm");

    input.addEventListener("keypress", function(e) {
      if (e.key === "Enter") {
        e.preventDefault();
        const value = input.value.trim();
        if (value !== "") {
          addChip(value);
          input.value = "";
        }
      }
    });

    function addChip(text) {
      const chip = document.createElement("div");
      chip.className = "chip";
      chip.textContent = text;

      const btn = document.createElement("button");
      btn.type = "button";
      btn.textContent = "×";
      btn.onclick = () => chip.remove();

      chip.appendChild(btn);

      const hidden = document.createElement("input");
      hidden.type = "hidden";
      hidden.name = "interests[]";
      hidden.value = text;
      chip.appendChild(hidden);

      chips.appendChild(chip);
    }
  </script>
</body>
</html>
