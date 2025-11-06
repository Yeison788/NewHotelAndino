<?php

session_start();
include '../config.php';
require_once __DIR__ . '/includes/admin_bootstrap.php';

ensureEmpStructure($conn);
ensureRoomRates($conn);
admin_ensure_guest_portal($conn);
admin_refresh_session($conn, $_SESSION['adminmail'] ?? '');
admin_require_permission('reservas');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header('Location: roombook.php');
    exit;
}

$sql ="Select * from roombook where id = '$id'";
$re = mysqli_query($conn,$sql);
while($row=mysqli_fetch_array($re))
{
	$Name = $row['Name'];
    $Email = $row['Email'];
    $Country = $row['Country'];
    $Phone = $row['Phone'];
    $RoomType = $row['RoomType'];
    $Bed = $row['Bed'];
    $NoofRoom = $row['NoofRoom'];
    $Meal = $row['Meal'];
    $cin = $row['cin'];
    $cout = $row['cout'];
    $noofday = $row['nodays'];
    $stat = $row['stat'];
}


if($stat == "NotConfirm")
{
    $st = "Confirm";

    $sql = "UPDATE roombook SET stat = '$st' WHERE id = '$id'";
    $result = mysqli_query($conn,$sql);

    if($result){

        $type_of_room = admin_room_base_price($conn, $RoomType);

        $bedFactor = match ($Bed) {
            '1 cliente' => 0.01,
            '2 clientes' => 0.02,
            '3 clientes' => 0.03,
            '4 clientes' => 0.04,
            default => 0.0,
        };
        $type_of_bed = $type_of_room * $bedFactor;

        $mealMultiplier = match ($Meal) {
            'Room only' => 0,
            'Breakfast' => 2,
            'Half Board' => 3,
            'Full Board' => 4,
            default => 0,
        };
        $type_of_meal = $type_of_bed * $mealMultiplier;
                                                            
        $ttot = $type_of_room *  $noofday * $NoofRoom;
        $mepr = $type_of_meal *  $noofday;
        $btot = $type_of_bed * $noofday;

        $fintot = $ttot + $mepr + $btot;

        $psql = "INSERT INTO payment(id,Name,Email,RoomType,Bed,NoofRoom,cin,cout,noofdays,roomtotal,bedtotal,meal,mealtotal,finaltotal) VALUES ('$id', '$Name', '$Email', '$RoomType', '$Bed', '$NoofRoom', '$cin', '$cout', '$noofday', '$ttot', '$btot', '$Meal', '$mepr', '$fintot')";

        mysqli_query($conn,$psql);

        header("Location:roombook.php");
    }
}
// else
// {
//     echo "<script>alert('Guest Already Confirmed')</script>";
//     header("Location:roombook.php");
// }


?>