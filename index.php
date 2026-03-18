<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    if ($json) {
        $_SESSION['data'] = $json;
    }
    http_response_code(200);
    echo "OK";
    exit;
}

$data = isset($_SESSION['data']) ? json_decode($_SESSION['data'], true) : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="1">
<title>Données Pico</title>
<style>
body { font-family: Arial; padding: 20px; }
h1 { color: #2E8B57; }
table { border-collapse: collapse; width: 60%; margin-top: 20px;}
th, td { border: 1px solid #aaa; padding: 8px; text-align: center; }
th { background-color: #eee; }
</style>
</head>
<body>
<h1>Données des Pico</h1>

<?php if ($data): ?>
<table>
<tr>
<th>Pico ID</th>
<th>Vitesse (km/h)</th>
<th>Omega</th>
<th>Swing détecté</th>
</tr>
<tr>
<td><?= htmlspecialchars($data['pico_id']) ?></td>
<td><?= htmlspecialchars($data['vitesse_kmh']) ?></td>
<td><?= htmlspecialchars($data['omega']) ?></td>
<td><?= $data['swing_detecte'] ? 'Oui' : 'Non' ?></td>
</tr>
</table>
<?php else: ?>
<p>Aucune donnée reçue pour le moment.</p>
<?php endif; ?>

</body>
</html>
