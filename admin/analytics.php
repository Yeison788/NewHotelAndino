<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Graphs Display</title>
</head>
<body>
    <h1>Datos analiticos</h1>

    <?php
    // Folder path where images are stored
    $imageFolder = 'graphs';

    // Associative array of image filenames and titles
    $graphs = [
        "graph_1.png" => "No of Records by Country",
        "graph_2.png" => "No of Records - Weekly",
        "graph_3.png" => "Reservation Status",
        "graph_4.png" => "Arrival per month",
        "graph_5.png" => "Graph on Repetitive guest",
        "graph_6.png" => "No of visitors per month - 2018",
        "graph_7.png" => "Types of Booking",
        "graph_8.png" => "Requirement of Parking"
        // Add more entries as needed
    ];

    // Display each image with its title
    foreach ($graphs as $filename => $title) {
        $filePath = $imageFolder . '/' . $filename;
        echo "<div style='margin-bottom: 20px;'>";
        echo "<h2>" . htmlspecialchars($title) . "</h2>";
        echo "<img src='" . htmlspecialchars($filePath) . "' alt='" . htmlspecialchars($title) . "' style='width:100%; max-width:800px;'/>";
        echo "</div>";
    }
    ?>

    <br>
    <br>
    <br>
</body>
</html>
