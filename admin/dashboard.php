<?php
    session_start();
    include '../config.php';
    require_once __DIR__ . '/includes/admin_bootstrap.php';

    ensureEmpStructure($conn);
    ensureRoomRates($conn);
    admin_refresh_session($conn, $_SESSION['adminmail'] ?? '');
    admin_require_permission('dashboard');

    // roombook
    $roombooksql ="Select * from roombook";
    $roombookre = mysqli_query($conn, $roombooksql);
    $roombookrow = mysqli_num_rows($roombookre);

    // staff
    $staffsql ="Select * from staff";
    $staffre = mysqli_query($conn, $staffsql);
    $staffrow = mysqli_num_rows($staffre);

    // room
    $roomsql ="Select * from room";
    $roomre = mysqli_query($conn, $roomsql);
    $roomrow = mysqli_num_rows($roomre);

    //roombook roomtype
    $chartroom1 = "SELECT * FROM roombook WHERE RoomType='Habitación Doble'";
    $chartroom1re = mysqli_query($conn, $chartroom1);
    $chartroom1row = mysqli_num_rows($chartroom1re);

    $chartroom2 = "SELECT * FROM roombook WHERE RoomType='Habitación Suite'";
    $chartroom2re = mysqli_query($conn, $chartroom2);
    $chartroom2row = mysqli_num_rows($chartroom2re);

    $chartroom3 = "SELECT * FROM roombook WHERE RoomType='Habitación Múltiple'";
    $chartroom3re = mysqli_query($conn, $chartroom3);
    $chartroom3row = mysqli_num_rows($chartroom3re);

    $chartroom4 = "SELECT * FROM roombook WHERE RoomType='Habitación Sencilla'";
    $chartroom4re = mysqli_query($conn, $chartroom4);
    $chartroom4row = mysqli_num_rows($chartroom4re);
?>
<!-- moriss profit -->
<?php 	
					$query = "SELECT * FROM payment";
					$result = mysqli_query($conn, $query);
					$chart_data = '';
					$tot = 0;
					while($row = mysqli_fetch_array($result))
					{
              $chart_data .= "{ date:'".$row["cout"]."', profit:".$row["finaltotal"]*10/100 ."}, ";
              $tot = $tot + $row["finaltotal"]*10/100;
					}

					$chart_data = substr($chart_data, 0, -2);
				
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="./css/dashboard.css">
    <!-- chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- morish bar -->
    <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/morris.js/0.5.1/morris.css">
    <script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.0/jquery.min.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/raphael/2.1.0/raphael-min.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/morris.js/0.5.1/morris.min.js"></script>

    <title>Hotel Andino - Admin </title>
</head>
<body>
   <div class="databox">
        <div class="box roombookbox">
          <h2>Habitaciones reservadas</h1>  
          <h1><?php echo $roombookrow ?> / <?php echo $roomrow ?></h1>
        </div>
        <div class="box guestbox">
        <h2>Total de personal</h1> 
          <h1><?php echo $staffrow ?></h1>
        </div>
        <div class="box profitbox">
  <h2>Ganancias</h2>
  <h1>COL$ <?php echo number_format($tot, 0, ',', '.'); ?></h1>
</div>
    </div>
    <div class="chartbox">
        <div class="bookroomchart">
            <canvas id="bookroomchart"></canvas>
            <h3 style="text-align: center;margin:10px 0;">Habitaciones reservadas por tipo</h3>
        </div>
        <div class="profitchart" >
            <div id="profitchart"></div>
            <h3 style="text-align: center;margin:10px 0;">Ganancias</h3>
        </div>
    </div>
</body>



<script>
        const labels = [
          'Habitación Doble',
          'Habitación Suite',
          'Habitación Múltiple',
          'Habitación Sencilla',
        ];
      
        const data = {
          labels: labels,
          datasets: [{
            label: 'Reservas registradas',
            backgroundColor: [
                'rgba(255, 99, 132, 1)',
                'rgba(255, 159, 64, 1)',
                'rgba(54, 162, 235, 1)',
                'rgba(153, 102, 255, 1)',
            ],
            borderColor: 'black',
            data: [<?php echo $chartroom1row ?>,<?php echo $chartroom2row ?>,<?php echo $chartroom3row ?>,<?php echo $chartroom4row ?>],
          }]
        };
  
        const doughnutchart = {
          type: 'doughnut',
          data: data,
          options: {}
        };
        
      const myChart = new Chart(
      document.getElementById('bookroomchart'),
      doughnutchart);
</script>

<script>
Morris.Bar({
  element : 'profitchart',
  data:[<?php echo $chart_data; ?>],
  xkey:'date',
  ykeys:['profit'],
  labels:['Ganancias (COP)'],
  hideHover:'auto',
  stacked:true,
  barColors:['rgba(153, 102, 255, 1)'],

  // ✔ Etiquetas del eje Y en pesos
  yLabelFormat: function (y) {
    return 'COL$ ' + y.toLocaleString('es-CO');
  },

  // ✔ Tooltip (hover) en pesos
  hoverCallback: function (index, options, content, row) {
    return '<div class="morris-hover-row-label">' + row.date + '</div>' +
           '<div class="morris-hover-point">COL$ ' + row.profit.toLocaleString('es-CO') + '</div>';
  }
});
</script>

</html>