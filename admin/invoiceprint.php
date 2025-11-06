<?php
session_start();
include '../config.php';
require_once __DIR__ . '/includes/admin_bootstrap.php';

ensureEmpStructure($conn);
ensureRoomRates($conn);
admin_refresh_session($conn, $_SESSION['adminmail'] ?? '');
admin_require_permission('pagos');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$payment = null;

if ($id > 0) {
    $stmt = $conn->prepare('SELECT * FROM payment WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                $payment = $result->fetch_assoc();
                $result->free();
            }
        }
        $stmt->close();
    }
}

if (!$payment) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Factura no encontrada</title>';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"></head>';
    echo '<body class="bg-light"><div class="container py-5"><div class="alert alert-warning shadow-sm">';
    echo '<h1 class="h4">Factura no encontrada</h1><p class="mb-0">No fue posible localizar la factura solicitada.</p>';
    echo '</div></div></body></html>';
    exit;
}

$Name = $payment['Name'] ?? '';
$troom = $payment['RoomType'] ?? '';
$bed = $payment['Bed'] ?? '';
$nroom = max(1, (int) ($payment['NoofRoom'] ?? 0));
$cin = $payment['cin'] ?? '';
$cout = $payment['cout'] ?? '';
$meal = $payment['meal'] ?? '';
$days = max(1, (int) ($payment['noofdays'] ?? 0));

$type_of_room = admin_room_base_price($conn, $troom);

$bedFactor = match ($bed) {
    '1 cliente' => 0.01,
    '2 clientes' => 0.02,
    '3 clientes' => 0.03,
    '4 clientes' => 0.04,
    default => 0.0,
};
$type_of_bed = $type_of_room * $bedFactor;

$mealMultiplier = match ($meal) {
    'Room only' => 0,
    'Breakfast' => 2,
    'Half Board' => 3,
    'Full Board' => 4,
    default => 0,
};
$type_of_meal = $type_of_bed * $mealMultiplier;

$roomTotal = $type_of_room * $days * $nroom;
$bedTotal = $type_of_bed * $days;
$mealTotal = $type_of_meal * $days;
$fintot = $roomTotal + $bedTotal + $mealTotal;
?>
<html>

<head>
        <meta charset="utf-8">
        <title>Factura</title>
        <link rel="stylesheet" href="style.css">
        <link rel="license" href="https://www.opensource.org/licenses/mit-license/">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
        <script src="script.js"></script>
        <style>
                /* reset */

                * {
                        border: 0;
                        box-sizing: content-box;
                        color: inherit;
                        font-family: inherit;
                        font-size: inherit;
                        font-style: inherit;
                        font-weight: inherit;
                        line-height: inherit;
                        list-style: none;
                        margin: 0;
                        padding: 0;
                        text-decoration: none;
                        vertical-align: top;
                }

                /* content editable */

                *[contenteditable] {
                        border-radius: 0.25em;
                        min-width: 1em;
                        outline: 0;
                }

                *[contenteditable] {
                        cursor: pointer;
                }

                *[contenteditable]:hover,
                *[contenteditable]:focus,
                td:hover *[contenteditable],
                td:focus *[contenteditable],
                img.hover {
                        background: #DEF;
                        box-shadow: 0 0 1em 0.5em #DEF;
                }

                span[contenteditable] {
                        display: inline-block;
                }

                /* heading */

                h1 {
                        font: bold 100% sans-serif;
                        letter-spacing: 0.5em;
                        text-align: center;
                        text-transform: uppercase;
                }

                /* table */

                table {
                        font-size: 75%;
                        table-layout: fixed;
                        width: 100%;
                }

                table {
                        border-collapse: separate;
                        border-spacing: 2px;
                }

                th,
                td {
                        border-width: 1px;
                        padding: 0.5em;
                        position: relative;
                        text-align: left;
                }

                th,
                td {
                        border-radius: 0.25em;
                        border-style: solid;
                }

                th {
                        background: #EEE;
                        border-color: #BBB;
                }

                td {
                        border-color: #DDD;
                }

                /* page */

                html {
                        font: 16px/1 'Open Sans', sans-serif;
                        overflow: auto;
                        padding: 0.5in;
                }

                html {
                        background: #999;
                        cursor: default;
                }

                body {
                        box-sizing: border-box;
                        height: 11in;
                        margin: 0 auto;
                        overflow: hidden;
                        padding: 0.5in;
                        width: 8.5in;
                }

                body {
                        background: #FFF;
                        border-radius: 1px;
                        box-shadow: 0 0 1in -0.25in rgba(0, 0, 0, 0.5);
                }

                /* header */

                header {
                        margin: 0 0 3em;
                }

                header:after {
                        clear: both;
                        content: "";
                        display: table;
                }

                header h1 {
                        background: #000;
                        border-radius: 0.25em;
                        color: #FFF;
                        margin: 0 0 1em;
                        padding: 0.5em 0;
                }

                header address {
                        float: left;
                        font-size: 75%;
                        font-style: normal;
                        line-height: 1.25;
                        margin: 0 1em 1em 0;
                }

                header address p {
                        margin: 0 0 0.25em;
                }

                header span,
                header img {
                        display: block;
                        float: right;
                }

                header span {
                        margin: 0 0 1em 1em;
                        max-height: 25%;
                        max-width: 60%;
                        position: relative;
                }

                header img {
                        max-height: 100%;
                        max-width: 100%;
                }

                header input {
                        cursor: pointer;
                        /* -ms-filter: "progid:DXImageTransform.Microsoft.Alpha(Opacity=0)"; */
                        height: 100%;
                        left: 0;
                        opacity: 0;
                        position: absolute;
                        top: 0;
                        width: 100%;
                }

                /* article */

                article,
                article address,
                table.meta,
                table.inventory {
                        margin: 0 0 3em;
                }

                article:after {
                        clear: both;
                        content: "";
                        display: table;
                }

                article h1 {
                        clip: rect(0 0 0 0);
                        position: absolute;
                }

                article address {
                        float: left;
                        font-size: 125%;
                        font-weight: bold;
                }

                /* table meta & balance */

                table.meta,
                table.balance {
                        float: right;
                        width: 36%;
                }

                table.meta:after,
                table.balance:after {
                        clear: both;
                        content: "";
                        display: table;
                }

                /* table meta */

                table.meta th {
                        width: 40%;
                }

                table.meta td {
                        width: 60%;
                }

                /* table items */

                table.inventory {
                        clear: both;
                        width: 100%;
                }

                table.inventory th {
                        font-weight: bold;
                        text-align: center;
                }

                table.inventory td:nth-child(1) {
                        width: 26%;
                }

                table.inventory td:nth-child(2) {
                        width: 38%;
                }

                table.inventory td:nth-child(3) {
                        text-align: right;
                        width: 12%;
                }

                table.inventory td:nth-child(4) {
                        text-align: right;
                        width: 12%;
                }

                table.inventory td:nth-child(5) {
                        text-align: right;
                        width: 12%;
                }

                /* table balance */

                table.balance th,
                table.balance td {
                        width: 50%;
                }

                table.balance td {
                        text-align: right;
                }

                /* aside */

                aside h1 {
                        border: none;
                        border-width: 0 0 1px;
                        margin: 0 0 1em;
                }

                aside h1 {
                        border-color: #999;
                        border-bottom-style: solid;
                }

                /* javascript */

                .add,
                .cut {
                        border-width: 1px;
                        display: block;
                        font-size: .8rem;
                        padding: 0.25em 0.5em;
                        float: left;
                        text-align: center;
                        width: 0.6em;
                }

                .add,
                .cut {
                        background: #9AF;
                        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
                        background-image: -moz-linear-gradient(#00ADEE 5%, #0078A5 100%);
                        background-image: -webkit-linear-gradient(#00ADEE 5%, #0078A5 100%);
                        border-radius: 0.5em;
                        border-color: #0076A3;
                        color: #FFF;
                        cursor: pointer;
                        font-weight: bold;
                        text-shadow: 0 -1px 2px rgba(0, 0, 0, 0.333);
                }

                .add {
                        margin: -2.5em 0 0;
                }

                .add:hover {
                        background: #00ADEE;
                }

                .cut {
                        opacity: 0;
                        position: absolute;
                        top: 0;
                        left: -1.5em;
                }

                .cut {
                        -webkit-transition: opacity 100ms ease-in;
                }

                tr:hover .cut {
                        opacity: 1;
                }

                button {
                        background-color: #007bff; /* Blue background */
                        color: white; /* White text */
                        padding: 10px 20px; /* Spacing inside the button */
                        border: none; /* Remove border */
                        border-radius: 5px; /* Rounded corners */
                        font-size: 16px; /* Text size */
                        cursor: pointer; /* Pointer cursor on hover */
                        display: block; /* Ensures it takes up its own line */
                        margin: 20px auto; /* Centers the button horizontally */
                }

                button:hover {
                        background-color: #0056b3; /* Darker blue on hover */
                }


                @media print {
                        * {
                                -webkit-print-color-adjust: exact;
                        }

                        html {
                                background: none;
                                padding: 0;
                        }

                        body {
                                box-shadow: none;
                                margin: 0;
                        }

                        span:empty {
                                display: none;
                        }

                        .add,
                        .cut {
                                display: none;
                        }
                }

                @page {
                        margin: 0;
                }
        </style>

</head>

<body>


        <header>
                <h1>Factura</h1>
                <address>
                        <p>HOTEL ANDINO,</p>
                        <p>(+57) 3206850961</p>
                </address>
                <span><img alt="" src="../image/LogoAndino.png"></span>
        </header>
        <article>
                <h1>Destinatario</h1>
                <address>
                        <p><?php echo htmlspecialchars($Name, ENT_QUOTES, 'UTF-8'); ?> <br></p>
                </address>
                <table class="meta">
                        <tr>
                                <th><span>Factura #</span></th>
                                <td><span><?php echo htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                        <tr>
                                <th><span>Fecha</span></th>
                                <td><span><?php echo htmlspecialchars($cout, ENT_QUOTES, 'UTF-8'); ?> </span></td>
                        </tr>

                </table>
                <table class="inventory">
                        <thead>
                                <tr>
                                        <th><span>Artículo</span></th>
                                        <th><span>Número de días</span></th>
                                        <th><span>Tarifa</span></th>
                                        <th><span>Cantidad</span></th>
                                        <th><span>Precio</span></th>
                                </tr>
                        </thead>
                        <tbody>
                                <tr>
                                        <td><span><?php echo htmlspecialchars($troom, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><span><?php echo htmlspecialchars((string) $days, ENT_QUOTES, 'UTF-8'); ?> </span></td>
                                        <td><span data-prefix>$</span><span><?php echo number_format($type_of_room, 0, ',', '.'); ?></span></td>
                                        <td><span><?php echo htmlspecialchars((string) $nroom, ENT_QUOTES, 'UTF-8'); ?> </span></td>
                                        <td><span data-prefix>$</span><span><?php echo number_format($roomTotal, 0, ',', '.'); ?></span></td>
                                </tr>
                                <tr>
                                        <td><span>Capacidad <?php echo htmlspecialchars($bed, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><span><?php echo htmlspecialchars((string) $days, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><span data-prefix>$</span><span><?php echo number_format($type_of_bed, 0, ',', '.'); ?></span></td>
                                        <td><span><?php echo htmlspecialchars((string) $nroom, ENT_QUOTES, 'UTF-8'); ?> </span></td>
                                        <td><span data-prefix>$</span><span><?php echo number_format($bedTotal, 0, ',', '.'); ?></span></td>
                                </tr>
                                <tr>
                                        <td><span><?php echo htmlspecialchars($meal, ENT_QUOTES, 'UTF-8'); ?> </span></td>
                                        <td><span><?php echo htmlspecialchars((string) $days, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><span data-prefix>$</span><span><?php echo number_format($type_of_meal, 0, ',', '.'); ?></span></td>
                                        <td><span><?php echo htmlspecialchars((string) $nroom, ENT_QUOTES, 'UTF-8'); ?> </span></td>
                                        <td><span data-prefix>$</span><span><?php echo number_format($mealTotal, 0, ',', '.'); ?></span></td>
                                </tr>
                        </tbody>
                </table>
                <table class="balance">
                        <tr>
                                <th><span>Total</span></th>
                                <td><span data-prefix>$</span><span><?php echo number_format($fintot, 0, ',', '.'); ?></span></td>
                        </tr>
                        <tr>
                                <th><span>Amount Paid</span></th>
                                <td><span data-prefix>$</span><span>0.00</span></td>
                        </tr>
                        <tr>
                                <th><span>Balance Due</span></th>
                                <td><span data-prefix>$</span><span><?php echo number_format($fintot, 0, ',', '.'); ?></span></td>
                        </tr>
                </table>
        </article>
        <aside>
                <h1><span>Contact us</span></h1>
                <div>
                        <p align="center">Email :- contacto@hotelandino.com || Web :- www.hotelandino.com || Phone :- +57 000000
0000 </p>
                </div>
        </aside>
        <button onclick="window.print()">Imprimir Factura</button>
        <!-- <button onclick="generatePDF()">Download Factura</button>

        <script>
                function generatePDF() {
                        const { jsPDF } = window.jspdf;
                        const doc = new jsPDF();

                        // Get the HTML content
                        const invoiceContent = document.body.innerHTML;

                        // Add HTML content to the PDF
                        doc.html(invoiceContent, {
                                callback: function (doc) {
                                        // Save the generated PDF
                                        doc.save(`<?php echo $id; ?>.pdf`); // Use the ID from your PHP code as filename
                                },
                                x: 10,
                                y: 10
                        });
                }
        </script> -->
</body>

</html>
