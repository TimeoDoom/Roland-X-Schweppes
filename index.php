<?php
// ════════════════════════════════════════════════════════════════════════════
//  BACKEND MULTI-PICO — index.php
//
//  Architecture :
//    • Un fichier  peak_{pico_id}.json  par Pico  (isolation totale des données)
//    • Modèle TTL (time-to-live) : le pic reste lisible par TOUS les clients
//      pendant PEAK_TTL secondes depuis sa détection, puis expire.
//      → Plus de flag "consumed" destructif : le 1er client à poller ne vole
//        plus le pic aux autres.
//    • Verrouillage fichier (LOCK_EX / LOCK_SH) pour éviter les race conditions
//      entre plusieurs Picos qui écriraient en même temps.
//    • data_{pico_id}.json gardé pour debug (trame brute la plus récente).
// ════════════════════════════════════════════════════════════════════════════

define('PEAK_TTL',    8);   // secondes pendant lesquelles un pic est "visible"
define('DATA_DIR',  __DIR__);

// ── Helpers fichier JSON avec verrou ────────────────────────────────────────

function peakPath(int $id): string {
    return DATA_DIR . '/peak_' . $id . '.json';
}

/**
 * Lit le fichier JSON d'un Pico avec verrou partagé (lecture).
 * Retourne un tableau associatif ou $default si le fichier est absent/invalide.
 */
function readPeakLocked(int $id, array $default = []): array {
    $path = peakPath($id);
    if (!file_exists($path)) return $default;
    $fp = fopen($path, 'r');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

/**
 * Écrit le fichier JSON d'un Pico avec verrou exclusif (écriture atomique).
 */
function writePeakLocked(int $id, array $data): void {
    $path = peakPath($id);
    $fp = fopen($path, 'c+');   // crée si absent, ne tronque pas d'abord
    if (!$fp) return;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ── 1. POST du Pico → accumulation du pic de vitesse ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['add_score'])
    && !isset($_GET['register_pico']) && !isset($_GET['toggle_pico'])
    && !isset($_GET['delete_pico'])   && !isset($_GET['clear_leaderboard'])) {

    $json = file_get_contents('php://input');
    if (!$json) { http_response_code(200); echo "OK"; exit; }

    $incoming = json_decode($json, true);
    if (!$incoming) { http_response_code(200); echo "OK"; exit; }

    $vitesse = floatval($incoming['vitesse_kmh'] ?? 0);
    $omega   = floatval($incoming['omega'] ?? 0);
    $swingIn = !empty($incoming['swing_detecte']);
    $picoId  = max(0, intval($incoming['pico_id'] ?? 0));

    $now  = time();
    $peak = readPeakLocked($picoId, [
        'vitesse_kmh'   => 0,
        'omega'         => 0,
        'swing_detecte' => false,
        'pico_id'       => $picoId,
        'expires_at'    => 0,
    ]);

    // Si le TTL du précédent pic est écoulé, on repart d'un état vierge
    if ($now >= ($peak['expires_at'] ?? 0)) {
        $peak = [
            'vitesse_kmh'   => 0,
            'omega'         => 0,
            'swing_detecte' => false,
            'pico_id'       => $picoId,
            'expires_at'    => 0,
        ];
    }

    // Accumulation du maximum sur toute la durée du swing
    if ($swingIn && $vitesse > $peak['vitesse_kmh']) {
        $peak['vitesse_kmh']   = $vitesse;
        $peak['omega']         = $omega;
        $peak['swing_detecte'] = true;
        $peak['pico_id']       = $picoId;
        $peak['expires_at']    = $now + PEAK_TTL;  // remet le compteur TTL
        error_log("[PICO $picoId] Nouveau pic : {$vitesse} km/h — expire dans " . PEAK_TTL . "s");
    }

    writePeakLocked($picoId, $peak);

    // Trame brute pour debug
    file_put_contents(DATA_DIR . '/data_' . $picoId . '.json', $json);

    // Mise à jour automatique de last_seen dans picos.json (pour le dot "online")
    $picoPath2 = DATA_DIR . '/picos.json';
    $fp2 = fopen($picoPath2, 'c+');
    if ($fp2) {
        flock($fp2, LOCK_EX);
        $raw2  = stream_get_contents($fp2);
        $picos = json_decode($raw2, true);
        if (!is_array($picos)) $picos = [];
        $found = false;
        foreach ($picos as &$p) {
            if (($p['pico_id'] ?? null) === $picoId) {
                $p['last_seen'] = $now;
                $p['active']    = true;
                $found = true;
                break;
            }
        }
        unset($p);
        if (!$found) {
            // Auto-enregistrement minimal si le Pico n'est pas encore déclaré
            $picos[] = [
                'pico_id'   => $picoId,
                'nom'       => 'Pico ' . $picoId,
                'ip'        => $_SERVER['REMOTE_ADDR'],
                'active'    => true,
                'last_seen' => $now,
            ];
        }
        ftruncate($fp2, 0); rewind($fp2);
        fwrite($fp2, json_encode($picos));
        flock($fp2, LOCK_UN);
        fclose($fp2);
    }

    http_response_code(200);
    echo "OK";
    exit;
}

// ── 2. Requête AJAX polling JS → retourne le pic si dans la fenêtre TTL ─────
//    ?api=1&pico_id=X
//    Tous les clients lisent le même pic sans se "voler" la donnée.
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $picoId = max(0, intval($_GET['pico_id'] ?? 0));

    // pico_id=0 → renvoie le pic le plus récent parmi tous les Picos actifs
    if ($picoId === 0) {
        $best = null;
        foreach (glob(DATA_DIR . '/peak_*.json') as $f) {
            $fp = fopen($f, 'r'); if (!$fp) continue;
            flock($fp, LOCK_SH);
            $raw = stream_get_contents($fp);
            flock($fp, LOCK_UN); fclose($fp);
            $p = json_decode($raw, true);
            if (!is_array($p)) continue;
            if (!empty($p['swing_detecte']) && time() < ($p['expires_at'] ?? 0)) {
                if (!$best || $p['vitesse_kmh'] > $best['vitesse_kmh']) $best = $p;
            }
        }
        echo json_encode($best ?? ['swing_detecte' => false, 'vitesse_kmh' => 0]);
        exit;
    }

    $peak = readPeakLocked($picoId, ['vitesse_kmh' => 0, 'swing_detecte' => false]);

    // Le pic est valide uniquement si swing détecté ET dans la fenêtre TTL
    if (!empty($peak['swing_detecte']) && time() < ($peak['expires_at'] ?? 0)) {
        echo json_encode($peak);
    } else {
        echo json_encode(['swing_detecte' => false, 'vitesse_kmh' => 0]);
    }
    exit;
}

// ── 3. API Leaderboard ──────────────────────────────────────────────────────
$lbPath   = __DIR__ . '/leaderboard.json';
$picoPath = __DIR__ . '/picos.json';

// Helper : lire un fichier JSON ou retourner un défaut
function readJson($file, $default) {
    if (!file_exists($file)) return $default;
    $d = json_decode(file_get_contents($file), true);
    return $d ?: $default;
}

// GET ?leaderboard=1  → retourne le top 20
if (isset($_GET['leaderboard'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $lb = readJson($lbPath, []);
    usort($lb, fn($a,$b) => $b['vitesse'] <=> $a['vitesse']);
    echo json_encode(array_slice($lb, 0, 20));
    exit;
}

// POST ?add_score=1  { nom, pico_id, vitesse }
if (isset($_GET['add_score']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $lb = readJson($lbPath, []);
    $lb[] = [
        'nom'     => htmlspecialchars(substr($body['nom'] ?? 'Joueur', 0, 24)),
        'pico_id' => intval($body['pico_id'] ?? 0),
        'vitesse' => floatval($body['vitesse'] ?? 0),
        'ts'      => time(),
    ];
    file_put_contents($lbPath, json_encode($lb));
    echo json_encode(['ok' => true]);
    exit;
}

// GET ?picos=1  → liste des Picos enregistrés
if (isset($_GET['picos'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(readJson($picoPath, []));
    exit;
}

// POST ?register_pico=1  { pico_id, nom, ip }
if (isset($_GET['register_pico']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $picos = readJson($picoPath, []);
    $id    = intval($body['pico_id'] ?? 0);
    // Mise à jour ou ajout
    $found = false;
    foreach ($picos as &$p) {
        if ($p['pico_id'] === $id) {
            $p['nom']    = htmlspecialchars(substr($body['nom'] ?? 'Pico '.$id, 0, 32));
            $p['ip']     = $_SERVER['REMOTE_ADDR'];
            $p['active'] = true;
            $p['last_seen'] = time();
            $found = true; break;
        }
    }
    if (!$found) {
        $picos[] = [
            'pico_id'   => $id,
            'nom'       => htmlspecialchars(substr($body['nom'] ?? 'Pico '.$id, 0, 32)),
            'ip'        => $_SERVER['REMOTE_ADDR'],
            'active'    => true,
            'last_seen' => time(),
        ];
    }
    file_put_contents($picoPath, json_encode($picos));
    echo json_encode(['ok' => true]);
    exit;
}

// POST ?toggle_pico=1  { pico_id, active }
if (isset($_GET['toggle_pico']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $picos = readJson($picoPath, []);
    $id    = intval($body['pico_id'] ?? 0);
    foreach ($picos as &$p) {
        if ($p['pico_id'] === $id) { $p['active'] = (bool)($body['active'] ?? true); break; }
    }
    file_put_contents($picoPath, json_encode($picos));
    echo json_encode(['ok' => true]);
    exit;
}

// DELETE ?delete_pico=1  { pico_id }
if (isset($_GET['delete_pico']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $picos = readJson($picoPath, []);
    $id    = intval($body['pico_id'] ?? 0);
    $picos = array_values(array_filter($picos, fn($p) => $p['pico_id'] !== $id));
    file_put_contents($picoPath, json_encode($picos));
    echo json_encode(['ok' => true]);
    exit;
}

// POST ?clear_leaderboard=1
if (isset($_GET['clear_leaderboard']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    file_put_contents($lbPath, json_encode([]));
    echo json_encode(['ok' => true]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Court Philippe-Chatrier — Roland Garros 3D</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow+Condensed:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:#000; overflow:hidden; font-family:'Barlow Condensed',sans-serif; color:#F5F0E8; }
  #c { display:block; width:100vw; height:100vh; }

  /* ── HUD bas — style référence jeu vidéo tennis ── */
  .hud {
    position:fixed; bottom:0; left:0; right:0; z-index:20;
    height:58px;
    background:rgba(8,6,4,0.92);
    border-top:2px solid rgba(196,98,45,0.4);
    display:flex; align-items:center; flex-wrap:nowrap; overflow:hidden;
    font-family:'Bebas Neue', sans-serif;
  }

  /* Bloc vitesse + record (gauche) */
  .hud-stats {
    display:flex; align-items:baseline; padding:0 24px;
    border-right:1px solid rgba(255,255,255,0.08);
    min-width:220px;
  }
  .stat-item { text-align:center; padding:0 14px; border-right:1px solid rgba(255,255,255,0.07); }
  .stat-item:last-child { border-right:none; }
  .stat-val { font-size:36px; line-height:1; color:#F0ECE4; transition:color .35s; }
  .stat-val.lit { color:#FFD040; text-shadow:0 0 18px rgba(255,210,40,.55); }
  .stat-lbl { font-size:9px; letter-spacing:2.5px; color:rgba(240,236,228,.28); text-transform:uppercase; margin-top:1px; }

  /* Barre de force */
  .power-section {
    flex:1; min-width:0; display:flex; align-items:center; gap:14px; padding:0 24px;
    border-right:1px solid rgba(255,255,255,0.08);
  }
  .power-lbl { font-size:10px; letter-spacing:2px; color:rgba(240,236,228,.3); text-transform:uppercase; white-space:nowrap; }
  .power-track { flex:1; height:8px; background:rgba(255,255,255,.07); border-radius:1px; overflow:hidden; position:relative; }
  .power-fill  { height:100%; width:0%; background:linear-gradient(90deg,#3EC86A,#FFD040,#D05020); border-radius:1px; transition:width .85s cubic-bezier(.16,1,.3,1); }

  /* Indicateur capteur (droite) */
  .sensor-status {
    font-family:'Bebas Neue'; font-size:13px; letter-spacing:3px;
    color: rgba(240,236,228,0.28); padding:0 28px;
    display:flex; align-items:center; gap:8px; white-space:nowrap;
    text-transform:uppercase;
  }
  .sensor-dot {
    width:7px; height:7px; border-radius:50%;
    background:#3EC86A; box-shadow:0 0 7px #3EC86A;
    animation:blink 1.4s ease-in-out infinite;
  }

  /* TOP bar */
  .topbar {
    position:fixed; top:0; left:0; right:0; z-index:20;
    display:flex; align-items:center; justify-content:space-between;
    padding:0 20px; height:44px;
    background:rgba(8,6,4,0.88);
    border-bottom:1px solid rgba(196,98,45,0.25);
  }
  .brand { display:flex; align-items:center; gap:10px; }
  .rg-circle {
    width:30px; height:30px; border-radius:50%;
    background:linear-gradient(135deg,#D4622A,#A84018);
    display:flex; align-items:center; justify-content:center;
    font-family:'Bebas Neue'; font-size:11px; letter-spacing:1px; color:#fff;
    box-shadow:0 0 8px rgba(196,98,45,.45);
  }
  .brand-name { font-family:'Bebas Neue'; font-size:20px; letter-spacing:3px; color:#F0ECE4; }
  .brand-name em { color:#D4622A; font-style:normal; }
  .brand-sub { font-size:8px; letter-spacing:3px; color:rgba(240,236,228,.28); text-transform:uppercase; }
  @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }

  /* Vignette cinématique */
  .vignette {
    position:fixed; inset:0; pointer-events:none; z-index:15;
    background:radial-gradient(ellipse 88% 82% at 50% 48%, transparent 50%, rgba(0,0,0,0.68) 100%);
  }

  /* ── OVERLAY RÉSULTAT SERVICE ── */
  #resultOverlay {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.35s ease;
  }
  #resultOverlay.show {
    opacity: 1;
    pointer-events: auto;
  }

  .result-card {
    background: rgba(6,4,2,0.88);
    border: 1px solid rgba(196,98,45,0.5);
    border-top: 3px solid #D4622A;
    padding: 32px 52px 28px;
    text-align: center;
    font-family: 'Bebas Neue', sans-serif;
    min-width: 340px;
    position: relative;
    transform: translateY(30px) scale(0.92);
    transition: transform 0.45s cubic-bezier(.34,1.56,.64,1);
    box-shadow: 0 0 60px rgba(0,0,0,0.8), 0 0 20px rgba(196,98,45,0.15);
  }
  #resultOverlay.show .result-card {
    transform: translateY(0) scale(1);
  }

  .result-label {
    font-size: 10px;
    letter-spacing: 4px;
    color: rgba(196,98,45,0.7);
    text-transform: uppercase;
    margin-bottom: 4px;
  }

  .result-title {
    font-size: 15px;
    letter-spacing: 6px;
    color: rgba(240,236,228,0.35);
    margin-bottom: 24px;
    text-transform: uppercase;
  }

  .result-rows {
    display: flex;
    gap: 32px;
    justify-content: center;
    margin-bottom: 24px;
  }

  .result-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
  }

  .result-stat-val {
    font-size: 72px;
    line-height: 1;
    color: #FFD040;
    text-shadow: 0 0 28px rgba(255,208,40,0.45);
    transition: color 0.3s;
  }
  .result-stat-val.green { color: #3EC86A; text-shadow: 0 0 28px rgba(62,200,106,0.45); }
  .result-stat-val.red   { color: #E05030; text-shadow: 0 0 28px rgba(224,80,48,0.45); }

  .result-stat-unit {
    font-size: 11px;
    letter-spacing: 3px;
    color: rgba(240,236,228,0.28);
    text-transform: uppercase;
  }

  .result-verdict {
    font-size: 22px;
    letter-spacing: 8px;
    padding: 10px 24px;
    border: 1px solid rgba(255,255,255,0.08);
    color: #F0ECE4;
    text-transform: uppercase;
    margin-bottom: 20px;
    display: inline-block;
  }
  .result-verdict.ace    { color:#FFD040; border-color:rgba(255,208,40,0.4); text-shadow:0 0 18px rgba(255,208,40,0.5); }
  .result-verdict.faute  { color:#E05030; border-color:rgba(224,80,48,0.4); }
  .result-verdict.service{ color:#3EC86A; border-color:rgba(62,200,106,0.4); }
  .result-verdict.spd-gold  { color:#FFD040; border-color:rgba(255,208,40,0.4); text-shadow:0 0 18px rgba(255,208,40,0.5); }
  .result-verdict.spd-green { color:#3EC86A; border-color:rgba(62,200,106,0.4); text-shadow:0 0 14px rgba(62,200,106,0.4); }
  .result-verdict.spd-blue  { color:#60D0FF; border-color:rgba(96,208,255,0.4); text-shadow:0 0 14px rgba(96,208,255,0.4); }
  .result-verdict.spd-white { color:#F0ECE4; border-color:rgba(240,236,228,0.2); }

  .result-bar {
    height: 3px;
    background: rgba(255,255,255,0.06);
    border-radius: 2px;
    margin-bottom: 20px;
    overflow: hidden;
  }
  .result-bar-fill {
    height: 100%;
    border-radius: 2px;
    width: 0%;
    transition: width 0.9s cubic-bezier(.16,1,.3,1);
    background: linear-gradient(90deg, #3EC86A, #FFD040, #D05020);
  }

  /* ═══════════════════════════════════════════════════
     BALLE DE TENNIS — bouton menu flottant
  ═══════════════════════════════════════════════════ */
  #menuBtn {
    position: fixed;
    top: 7px;
    right: 16px;
    z-index: 100;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    cursor: pointer;
    border: none;
    background: none;
    padding: 0;
    transition: transform 0.25s cubic-bezier(.34,1.56,.64,1), box-shadow 0.25s;
  }
  #menuBtn:hover { transform: scale(1.12); }
  #menuBtn:active { transform: scale(0.94); }
  #menuBtn svg { display: block; width: 30px; height: 30px; }

  /* Pastille rouge si pico non configuré */
  #menuBtn .notif-dot {
    position: absolute;
    top: -2px; right: -2px;
    width: 9px; height: 9px;
    border-radius: 50%;
    background: #E05030;
    border: 2px solid #000;
    display: none;
  }
  #menuBtn .notif-dot.show { display: block; }

  /* ── Menu déroulant ── */
  #menuPanel {
    position: fixed;
    top: 50px;
    right: 16px;
    z-index: 99;
    background: rgba(8,6,4,0.97);
    border: 1px solid rgba(196,98,45,0.4);
    border-top: 2px solid #D4622A;
    min-width: 220px;
    box-shadow: 0 16px 48px rgba(0,0,0,0.75);
    transform-origin: top right;
    transform: scale(0.88) translateY(-8px);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.22s cubic-bezier(.34,1.56,.64,1);
  }
  #menuPanel.open {
    opacity: 1;
    transform: scale(1) translateY(0);
    pointer-events: auto;
  }
  .menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    font-family: 'Bebas Neue', sans-serif;
    font-size: 15px;
    letter-spacing: 3px;
    color: rgba(240,236,228,0.7);
    cursor: pointer;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    transition: background 0.15s, color 0.15s;
    text-transform: uppercase;
    user-select: none;
  }
  .menu-item:last-child { border-bottom: none; }
  .menu-item:hover { background: rgba(196,98,45,0.12); color: #F0ECE4; }
  .menu-item svg { flex-shrink: 0; opacity: 0.55; }
  .menu-item:hover svg { opacity: 1; }

  /* ═══════════════════════════════════════════════════
     MODALES GÉNÉRIQUES
  ═══════════════════════════════════════════════════ */
  .modal-bg {
    position: fixed;
    inset: 0;
    z-index: 200;
    background: rgba(0,0,0,0.72);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
  }
  .modal-bg.open {
    opacity: 1;
    pointer-events: auto;
  }
  .modal {
    background: rgba(10,7,4,0.98);
    border: 1px solid rgba(196,98,45,0.4);
    border-top: 3px solid #D4622A;
    width: 90vw;
    max-width: 680px;
    max-height: 82vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 24px 80px rgba(0,0,0,0.85);
    transform: translateY(24px) scale(0.96);
    transition: transform 0.3s cubic-bezier(.34,1.56,.64,1);
  }
  .modal-bg.open .modal { transform: translateY(0) scale(1); }

  .modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 24px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0;
  }
  .modal-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 22px;
    letter-spacing: 5px;
    color: #F0ECE4;
    text-transform: uppercase;
  }
  .modal-sub {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 11px;
    letter-spacing: 2px;
    color: rgba(196,98,45,0.7);
    text-transform: uppercase;
    margin-top: 2px;
  }
  .modal-close {
    width: 32px; height: 32px;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 2px;
    background: none;
    color: rgba(240,236,228,0.4);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    transition: color 0.15s, background 0.15s;
    font-family: sans-serif;
  }
  .modal-close:hover { background: rgba(196,98,45,0.18); color: #F0ECE4; }

  .modal-body {
    overflow-y: auto;
    flex: 1;
    padding: 20px 24px;
    scrollbar-width: thin;
    scrollbar-color: rgba(196,98,45,0.3) transparent;
  }

  /* ── Leaderboard ── */
  .lb-table {
    width: 100%;
    border-collapse: collapse;
    font-family: 'Bebas Neue', sans-serif;
  }
  .lb-table th {
    font-size: 9px;
    letter-spacing: 3px;
    color: rgba(196,98,45,0.6);
    text-transform: uppercase;
    padding: 0 8px 10px;
    text-align: left;
    border-bottom: 1px solid rgba(255,255,255,0.07);
  }
  .lb-table th:last-child { text-align: right; }
  .lb-table td {
    padding: 10px 8px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    font-size: 16px;
    letter-spacing: 2px;
    color: rgba(240,236,228,0.65);
  }
  .lb-table td:last-child { text-align: right; }
  .lb-table tr:first-child td { color: #FFD040; font-size: 20px; }
  .lb-table tr:nth-child(2) td { color: #E0E0E0; }
  .lb-table tr:nth-child(3) td { color: #D49060; }
  .lb-rank { color: rgba(196,98,45,0.5) !important; font-size: 13px !important; width: 32px; }
  .lb-speed { font-size: 22px !important; color: #FFD040 !important; }
  .lb-table tr:nth-child(n+4) .lb-speed { color: #F0ECE4 !important; font-size: 18px !important; }
  .lb-empty {
    font-family: 'Barlow Condensed', sans-serif;
    text-align: center;
    padding: 40px;
    color: rgba(240,236,228,0.2);
    font-size: 14px;
    letter-spacing: 2px;
  }
  .lb-footer {
    display: flex;
    justify-content: flex-end;
    padding: 12px 24px 16px;
    border-top: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
  }
  .btn-danger {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 13px;
    letter-spacing: 3px;
    padding: 8px 20px;
    background: rgba(224,80,48,0.12);
    border: 1px solid rgba(224,80,48,0.35);
    color: rgba(224,80,48,0.7);
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    text-transform: uppercase;
  }
  .btn-danger:hover { background: rgba(224,80,48,0.25); color: #E05030; }

  /* ── Pico Manager ── */
  .pico-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
  }
  .pico-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: border-color 0.2s;
  }
  .pico-card.active-card { border-color: rgba(62,200,106,0.3); }
  .pico-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .pico-id-badge {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 11px;
    letter-spacing: 2px;
    color: rgba(196,98,45,0.7);
    background: rgba(196,98,45,0.1);
    padding: 2px 8px;
    border-radius: 1px;
  }
  .pico-status-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    transition: background 0.2s;
  }
  .pico-status-dot.on { background: #3EC86A; box-shadow: 0 0 6px #3EC86A; }
  .pico-name-row {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .pico-name-input {
    flex: 1;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.09);
    color: #F0ECE4;
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 14px;
    padding: 6px 10px;
    outline: none;
    transition: border-color 0.15s;
  }
  .pico-name-input:focus { border-color: rgba(196,98,45,0.5); }
  .pico-name-input::placeholder { color: rgba(240,236,228,0.2); }
  .pico-actions {
    display: flex;
    gap: 6px;
  }
  .btn-sm {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 12px;
    letter-spacing: 2px;
    padding: 5px 12px;
    background: rgba(196,98,45,0.12);
    border: 1px solid rgba(196,98,45,0.3);
    color: rgba(196,98,45,0.8);
    cursor: pointer;
    transition: background 0.15s;
    text-transform: uppercase;
    flex: 1;
  }
  .btn-sm:hover { background: rgba(196,98,45,0.25); color: #D4622A; }
  .btn-sm.danger { background: rgba(224,80,48,0.08); border-color: rgba(224,80,48,0.25); color: rgba(224,80,48,0.6); }
  .btn-sm.danger:hover { background: rgba(224,80,48,0.2); color: #E05030; }
  .btn-sm.toggle-on { background: rgba(62,200,106,0.1); border-color: rgba(62,200,106,0.3); color: rgba(62,200,106,0.8); }
  .pico-ip {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 11px;
    color: rgba(240,236,228,0.2);
    letter-spacing: 1px;
  }
  .pico-add-row {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
  }
  .pico-add-row input {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.09);
    color: #F0ECE4;
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 14px;
    padding: 8px 12px;
    outline: none;
    width: 90px;
    transition: border-color 0.15s;
  }
  .pico-add-row input:focus { border-color: rgba(196,98,45,0.5); }
  .pico-add-row input::placeholder { color: rgba(240,236,228,0.2); }
  .pico-add-row .input-name { flex: 1; width: auto; }
  .btn-primary {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 13px;
    letter-spacing: 2px;
    padding: 8px 20px;
    background: rgba(196,98,45,0.18);
    border: 1px solid rgba(196,98,45,0.5);
    color: #D4622A;
    cursor: pointer;
    transition: background 0.15s;
    text-transform: uppercase;
    white-space: nowrap;
  }
  .btn-primary:hover { background: rgba(196,98,45,0.32); }

  /* ── Sélecteur Pico dans la topbar ── */
  .pico-selector {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 4px 0 16px;
    border-left: 1px solid rgba(196,98,45,0.2);
  }
  .pico-selector-lbl {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 10px;
    letter-spacing: 3px;
    color: rgba(196,98,45,0.55);
    white-space: nowrap;
  }
  #picoSelect {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(196,98,45,0.3);
    color: #F0ECE4;
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 13px;
    letter-spacing: 1px;
    padding: 4px 8px;
    outline: none;
    cursor: pointer;
    max-width: 150px;
    transition: border-color 0.15s;
    margin-right : 20px;
  }
  #picoSelect:focus { border-color: rgba(196,98,45,0.7); }
  #picoSelect option { background: #0A0806; color: #F0ECE4; }
  .pico-selector-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: rgba(255,255,255,0.12);
    transition: background 0.4s, box-shadow 0.4s;
    flex-shrink: 0;
  }
  .pico-selector-dot.live {
    background: #3EC86A;
    box-shadow: 0 0 7px #3EC86A;
    animation: blink 1.4s ease-in-out infinite;
  }

</style>
</head>
<body>

<canvas id="c"></canvas>
<div class="vignette"></div>

<div id="resultOverlay">
  <div class="result-card">
    <div class="result-label">Roland-Garros · Philippe-Chatrier</div>
    <div class="result-title">Résultat du service</div>
    <div class="result-rows">
      <div class="result-stat">
        <div class="result-stat-val" id="resSpeed">--</div>
        <div class="result-stat-unit">km/h</div>
      </div>
    </div>
    <div class="result-bar"><div class="result-bar-fill" id="resBar"></div></div>
    <div class="result-verdict" id="resVerdict">—</div>
  </div>
</div>


<div class="topbar">
  <div class="brand">
    <div class="rg-circle">RG</div>
    <div>
      <div class="brand-name">COURT <em>PHILIPPE-CHATRIER</em></div>
      <div class="brand-sub">Roland-Garros · Paris</div>
    </div>
  </div>
  <!-- Sélecteur de Pico actif -->
  <div class="pico-selector" id="picoSelector">
    <span class="pico-selector-lbl">CAPTEUR</span>
    <select id="picoSelect" title="Choisir le Pico à surveiller">
      <option value="0">Tous</option>
    </select>
    <span class="pico-selector-dot" id="picoSelectorDot"></span>
  </div>
</div>

<!-- ═══ BALLE DE TENNIS — BOUTON MENU ═══ -->
<button id="menuBtn" title="Menu">
  <span class="notif-dot" id="menuNotif"></span>
  <!-- SVG balle de tennis réaliste -->
  <svg viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg">
    <defs>
      <radialGradient id="tg" cx="38%" cy="34%" r="62%">
        <stop offset="0%" stop-color="#E8DC40"/>
        <stop offset="55%" stop-color="#C8C018"/>
        <stop offset="100%" stop-color="#9A9410"/>
      </radialGradient>
    </defs>
    <!-- Corps de la balle -->
    <circle cx="15" cy="15" r="14" fill="url(#tg)"/>
    <!-- Ombre douce -->
    <circle cx="15" cy="15" r="14" fill="none" stroke="rgba(0,0,0,0.18)" stroke-width="0.6"/>
    <!-- Courbe gauche (feutre blanc) -->
    <path d="M 4.5 10 Q 10 15, 4.5 20" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" opacity="0.85"/>
    <!-- Courbe droite -->
    <path d="M 25.5 10 Q 20 15, 25.5 20" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" opacity="0.85"/>
    <!-- Reflet -->
    <ellipse cx="11" cy="9" rx="3.5" ry="2" fill="rgba(255,255,255,0.22)" transform="rotate(-20,11,9)"/>
  </svg>
</button>

<!-- ═══ MENU PANEL ═══ -->
<div id="menuPanel">
  <div class="menu-item" id="menuLeaderboard">
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
      <rect x="1" y="9" width="3" height="6" fill="#D4622A"/>
      <rect x="6" y="5" width="3" height="10" fill="#D4622A"/>
      <rect x="11" y="1" width="3" height="14" fill="#FFD040"/>
    </svg>
    Leaderboard
  </div>
  <div class="menu-item" id="menuPicos">
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
      <circle cx="5" cy="5" r="2.5" stroke="#D4622A" stroke-width="1.4"/>
      <circle cx="11" cy="5" r="2.5" stroke="#D4622A" stroke-width="1.4"/>
      <path d="M1 13c0-2.2 1.8-4 4-4s4 1.8 4 4" stroke="#D4622A" stroke-width="1.4" stroke-linecap="round"/>
      <path d="M11 9c1.1 0 2 .5 2.7 1.2" stroke="#D4622A" stroke-width="1.4" stroke-linecap="round"/>
    </svg>
    Connexion Picos
  </div>
</div>

<!-- ═══ MODALE LEADERBOARD ═══ -->
<div class="modal-bg" id="lbModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">🏆 Leaderboard</div>
        <div class="modal-sub">Roland-Garros · Vitesse de service</div>
      </div>
      <button class="modal-close" id="lbClose">✕</button>
    </div>
    <div class="modal-body" id="lbBody">
      <div class="lb-empty">Chargement…</div>
    </div>
    <div class="lb-footer">
      <button class="btn-danger" id="lbClear">Effacer le classement</button>
    </div>
  </div>
</div>

<!-- ═══ MODALE PICO MANAGER ═══ -->
<div class="modal-bg" id="picoModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">📡 Connexion Picos</div>
        <div class="modal-sub">Gestion des 20 capteurs Raspberry Pico</div>
      </div>
      <button class="modal-close" id="picoClose">✕</button>
    </div>
    <div class="modal-body" id="picoBody">
      <!-- Ajout rapide -->
      <div class="pico-add-row">
        <input type="number" id="addPicoId" min="1" max="20" placeholder="ID (1-20)">
        <input type="text" id="addPicoNom" class="input-name" placeholder="Nom du joueur / Pico">
        <button class="btn-primary" id="addPicoBtn">+ Ajouter</button>
      </div>
      <div class="pico-grid" id="picoGrid">
        <div class="lb-empty">Chargement…</div>
      </div>
    </div>
  </div>
</div>

<div class="hud">
  <!-- Stats gauche -->
  <div class="hud-stats">
    <div class="stat-item">
      <div class="stat-val" id="hSpeed">--</div>
      <div class="stat-lbl">km/h</div>
    </div>
    <div class="stat-item">
      <div class="stat-val" id="hBest">–</div>
      <div class="stat-lbl">Record</div>
    </div>
  </div>

  <!-- Barre force -->
  <div class="power-section">
    <span class="power-lbl">Force</span>
    <div class="power-track"><div class="power-fill" id="pFill"></div></div>
  </div>
</div>

<script>

// ═══════════════════════════════════════════════════════
//  COURT PHILIPPE-CHATRIER — Three.js r128
// ═══════════════════════════════════════════════════════

const canvas = document.getElementById('c');
const W = window.innerWidth, H = window.innerHeight;

// ── RENDERER — logarithmicDepthBuffer élimine les problèmes de précision depth
const renderer = new THREE.WebGLRenderer({
  canvas,
  antialias: true,
  powerPreference: 'high-performance',
  logarithmicDepthBuffer: true   // ← corrige tout problème de z-precision résiduel
});
renderer.setPixelRatio(Math.min(devicePixelRatio, 1.5));
renderer.setSize(W, H);
renderer.shadowMap.enabled = true;
renderer.shadowMap.type = THREE.PCFShadowMap;
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 0.72;
renderer.outputEncoding = THREE.sRGBEncoding;

// ── SCENE — ciel chaud après-midi Roland Garros
const scene = new THREE.Scene();
// Ciel chaud avec gradient — on utilise une texture canvas pour le background
(function() {
  const skyW=512, skyH=512;
  const skyCv=document.createElement('canvas'); skyCv.width=skyW; skyCv.height=skyH;
  const skyCtx=skyCv.getContext('2d');
  const skyG=skyCtx.createLinearGradient(0,0,0,skyH);
  skyG.addColorStop(0.0,'#4A6EA8');   // bleu zenith
  skyG.addColorStop(0.35,'#7AABDC'); // bleu ciel
  skyG.addColorStop(0.65,'#D4A060'); // horizon chaud
  skyG.addColorStop(0.85,'#E8C080'); // horizon orangé
  skyG.addColorStop(1.0,'#C88040');  // base chaude
  skyCtx.fillStyle=skyG; skyCtx.fillRect(0,0,skyW,skyH);
  // Halo solaire
  const sunGlow=skyCtx.createRadialGradient(skyW*0.6,skyH*0.15,0,skyW*0.6,skyH*0.15,skyW*0.35);
  sunGlow.addColorStop(0,'rgba(255,240,160,0.65)');
  sunGlow.addColorStop(0.4,'rgba(255,200,80,0.22)');
  sunGlow.addColorStop(1,'transparent');
  skyCtx.fillStyle=sunGlow; skyCtx.fillRect(0,0,skyW,skyH);
  const skyTex=new THREE.CanvasTexture(skyCv);
  scene.background=skyTex;
})();
scene.fog = new THREE.Fog(0xC09060, 55, 160);

// ── CAMERA — vue rapprochée derrière le joueur comme la photo de référence
const camera = new THREE.PerspectiveCamera(62, W/H, 0.5, 400);
camera.position.set(0, 17.5, 28.0);
camera.lookAt(0, 0.5, -6);

// ── LUMIÈRES — équilibrées, légèrement plus douces, moins contrastées
scene.add(new THREE.HemisphereLight(0xFFD090, 0xC87840, 0.52));
scene.add(new THREE.AmbientLight(0xFFD0A0, 0.45));

const sun = new THREE.DirectionalLight(0xFFE8C0, 2.1);
sun.position.set(8, 35, 30);
sun.castShadow = true;
sun.shadow.mapSize.set(1024, 1024);
sun.shadow.camera.near = 1;
sun.shadow.camera.far = 120;
sun.shadow.camera.left = -45;
sun.shadow.camera.right = 45;
sun.shadow.camera.top = 45;
sun.shadow.camera.bottom = -45;
sun.shadow.bias = -0.0002;
sun.shadow.normalBias = 0.015;
scene.add(sun);

const fill = new THREE.DirectionalLight(0x80A8E0, 0.22);
fill.position.set(-20, 12, -10);
scene.add(fill);

const back_light = new THREE.DirectionalLight(0xFFB060, 0.18);
back_light.position.set(0, 20, -50);
scene.add(back_light);

const flash = new THREE.PointLight(0xFFFFDD, 0, 10);
flash.position.set(-3, 3, 9);
scene.add(flash);

// ═══════════════════════════════════════════════════════
//  TEXTURES CANVAS
// ═══════════════════════════════════════════════════════

const COURT_W = 25;
const COURT_H = 52;

function clayTex() {
  // Haute résolution pour lignes nettes
  const S = 2048;
  const cv = document.createElement('canvas'); cv.width=S; cv.height=S;
  const cx = cv.getContext('2d');

  // Fond terre battue
  const g = cx.createLinearGradient(0,0,S,S);
  g.addColorStop(0.0,  '#2A1004');
  g.addColorStop(0.25, '#321406');
  g.addColorStop(0.5,  '#381608');
  g.addColorStop(0.75, '#2C1205');
  g.addColorStop(1.0,  '#220E04');
  cx.fillStyle=g; cx.fillRect(0,0,S,S);

  // Grain terre battue
  const rng = (seed) => { let x = Math.sin(seed*127.1+311.7)*43758.5; return x-Math.floor(x); };
  for(let i=0; i<1500; i++) {
    const x=rng(i*3)*S, y=rng(i*3+1)*S, r=rng(i*3+2)*2.0;
    cx.beginPath(); cx.arc(x,y,r,0,Math.PI*2);
    cx.fillStyle = rng(i*3+3) > 0.45 ? `rgba(14,4,1,0.12)` : `rgba(72,30,10,0.05)`;
    cx.fill();
  }

  // ── LIGNES BLANCHES directement dans la texture ──
  // Terrain: COURT_W=25 x COURT_H=52 unités monde
  // tx/tz convertissent coords monde -> pixels canvas
  const tx = (wx) => ((wx / COURT_W) + 0.5) * S;
  const tz = (wz) => ((wz / COURT_H) + 0.5) * S;

  cx.fillStyle = 'rgba(242,237,227,0.95)';

  // Dessine un rectangle centré en (wx,wz) de taille (ww x wd) en coords monde
  function rect(wx, wz, ww, wd) {
    const minW = 4; // épaisseur min en pixels
    const pw = Math.max(minW, (ww / COURT_W) * S);
    const ph = Math.max(minW, (wd / COURT_H) * S);
    cx.fillRect(tx(wx) - pw/2, tz(wz) - ph/2, pw, ph);
  }

  const HZ = 13.0, XD = 6.045, XS = 4.535, SZ = 5.056;

  // Baselines
  rect(0, -HZ, XD*2, 0.5);
  rect(0,  HZ, XD*2, 0.5);
  // Lignes latérales doubles (extérieures)
  rect(-XD, 0, 0.5, HZ*2);
  rect( XD, 0, 0.5, HZ*2);
  // Lignes latérales simples (intérieures)
  rect(-XS, 0, 0.5, HZ*2);
  rect( XS, 0, 0.5, HZ*2);
  // Lignes de service
  rect(0, -SZ, XS*2, 0.5);
  rect(0,  SZ, XS*2, 0.5);
  // Ligne centrale de service
  rect(0, 0, 0.5, SZ*2);
  // Marques centrales baselines
  rect(0, -HZ, 0.8, 0.5);
  rect(0,  HZ, 0.8, 0.5);

  const t = new THREE.CanvasTexture(cv);
  t.wrapS = THREE.ClampToEdgeWrapping;
  t.wrapT = THREE.ClampToEdgeWrapping;
  t.anisotropy = renderer.capabilities.getMaxAnisotropy();
  t.minFilter = THREE.LinearMipmapLinearFilter;
  t.generateMipmaps = true;
  return t;
}

// ═══════════════════════════════════════════════════════
//  COURT — TERRAIN
// ═══════════════════════════════════════════════════════

const clayT = clayTex();
const courtMesh = new THREE.Mesh(
  new THREE.PlaneGeometry(COURT_W, COURT_H, 4, 8),
  new THREE.MeshLambertMaterial({
    map: clayT,
    color: 0xFFFFFF,
  })
);
courtMesh.rotation.x = -Math.PI/2;
courtMesh.receiveShadow = true;
scene.add(courtMesh);

// ── Sol d'usure
function addWearZone(x, z, rx, rz, alpha) {
  const cv2 = document.createElement('canvas'); cv2.width=256; cv2.height=256;
  const cx2 = cv2.getContext('2d');
  const gr = cx2.createRadialGradient(128,128,0, 128,128,128);
  gr.addColorStop(0,   `rgba(30,10,3,${alpha})`);
  gr.addColorStop(0.6, `rgba(30,10,3,${alpha*0.4})`);
  gr.addColorStop(1,   'transparent');
  cx2.fillStyle=gr; cx2.fillRect(0,0,256,256);
  const m = new THREE.Mesh(
    new THREE.PlaneGeometry(rx*2, rz*2),
    new THREE.MeshLambertMaterial({
      map: new THREE.CanvasTexture(cv2),
      transparent: true, opacity: 1,
      depthWrite: false,
      polygonOffset: true, polygonOffsetFactor: -1, polygonOffsetUnits: -4
    })
  );
  m.rotation.x = -Math.PI/2;
  m.position.set(x, 0, z);
  scene.add(m);
}
addWearZone(0, -7.00, 4.5, 2.5, 0.55);
addWearZone(0,  7.00, 4.5, 2.5, 0.55);
addWearZone(0, -12.2, 5.0, 2.0, 0.45);
addWearZone(0,  12.2, 5.0, 2.0, 0.45);
addWearZone(0,  0,    3.4, 1.5, 0.35);

// ── LIGNES BLANCHES — BoxGeometry (boîtes plates) — aucun z-fighting possible
const HZ  = 13.0;
const XD  = 6.045;
const XS  = 4.535;
const SZ  = 5.056;
const LH  = 0.012;  // hauteur de la boîte (dépasse le sol)

const lineMatBox = new THREE.MeshBasicMaterial({ color: 0xF0EBE0 });

function courtLine(cx, cz, w, d) {
  // BoxGeometry : largeur X, hauteur Y (LH), profondeur Z
  const box = new THREE.Mesh(new THREE.BoxGeometry(w, LH, d), lineMatBox);
  box.position.set(cx, LH / 2, cz);
  scene.add(box);
  return box;
}

// Baselines
courtLine(0, -HZ, XD*2, 0.08);
courtLine(0,  HZ, XD*2, 0.08);
// Lignes latérales doubles
courtLine(-XD, 0, 0.06, HZ*2);
courtLine( XD, 0, 0.06, HZ*2);
// Lignes latérales simples
courtLine(-XS, 0, 0.06, HZ*2);
courtLine( XS, 0, 0.06, HZ*2);
// Lignes de service
courtLine(0, -SZ, XS*2, 0.06);
courtLine(0,  SZ, XS*2, 0.06);
// Ligne centrale de service
courtLine(0, 0, 0.06, SZ*2);
// Marques centrales baselines
courtLine(0, -HZ, 0.12, 0.08);
courtLine(0,  HZ, 0.12, 0.08);

// ═══════════════════════════════════════════════════════
//  FILET DE TENNIS — Court Philippe-Chatrier (ITF)
//  Largeur 12.8m · Poteaux 1.07m · Centre 0.914m
// ═══════════════════════════════════════════════════════
{
  const NW     = 14.5;
  const H_POST = 1.07;
  const H_CTR  = 0.914;
  const POST_X = NW / 2 + 0.05;

  // Courbure parabolique (caténaire approchée)
  const ch = t => H_POST - (H_POST - H_CTR) * 4 * t * (1 - t);

  const netG = new THREE.Group();
  scene.add(netG);

  const matPost  = new THREE.MeshPhongMaterial({ color:0xBBBBBB, specular:0x444444, shininess:55 });
  const matCable = new THREE.MeshLambertMaterial({ color:0xCCCCCC });
  const matBand  = new THREE.MeshPhongMaterial({ color:0xF4F4F4, specular:0x666666, shininess:25, side:THREE.DoubleSide });
  const matStrap = new THREE.MeshLambertMaterial({ color:0xEEEEEE, side:THREE.DoubleSide });

  // ── 1. Poteaux
  [-POST_X, POST_X].forEach(px => {
    const post = new THREE.Mesh(new THREE.CylinderGeometry(0.028,0.032,H_POST,10), matPost);
    post.position.set(px, H_POST/2, 0);
    post.castShadow = true;
    netG.add(post);
    const cap = new THREE.Mesh(new THREE.SphereGeometry(0.030,8,5,0,Math.PI*2,0,Math.PI/2), matPost);
    cap.position.set(px, H_POST, 0);
    netG.add(cap);
    const base = new THREE.Mesh(new THREE.CylinderGeometry(0.07,0.09,0.05,8),
      new THREE.MeshLambertMaterial({color:0x888888}));
    base.position.set(px, 0.025, 0);
    netG.add(base);
  });

  // ── 2. Câble supérieur segmenté (caténaire)
  const NSEG = 16;
  for(let i=0; i<NSEG; i++){
    const t0=i/NSEG, t1=(i+1)/NSEG;
    const x0=-NW/2+t0*NW, y0=ch(t0);
    const x1=-NW/2+t1*NW, y1=ch(t1);
    const seg=new THREE.Mesh(new THREE.CylinderGeometry(0.006,0.006,Math.sqrt((x1-x0)**2+(y1-y0)**2),5), matCable);
    seg.position.set((x0+x1)/2,(y0+y1)/2,0);
    seg.rotation.z=-Math.atan2(y1-y0,x1-x0);
    netG.add(seg);
  }

  // ── 3. Corps du filet (plan texturé semi-transparent)
  const netTex = (()=>{
    const CW=512,CH=128;
    const cv=document.createElement('canvas'); cv.width=CW; cv.height=CH;
    const cx=cv.getContext('2d');
    cx.clearRect(0,0,CW,CH);
    cx.strokeStyle='rgba(238,238,232,0.88)'; cx.lineWidth=1.4;
    const COLS=64, ROWS=16, cw=CW/COLS, rh=CH/ROWS;
    for(let c=0;c<=COLS;c++){cx.beginPath();cx.moveTo(c*cw,0);cx.lineTo(c*cw,CH);cx.stroke();}
    for(let r=0;r<=ROWS;r++){cx.beginPath();cx.moveTo(0,r*rh);cx.lineTo(CW,r*rh);cx.stroke();}
    const t=new THREE.CanvasTexture(cv);
    t.wrapS=THREE.ClampToEdgeWrapping; t.wrapT=THREE.ClampToEdgeWrapping;
    t.anisotropy=renderer.capabilities.getMaxAnisotropy();
    return t;
  })();
  const netBodyH = H_CTR;
  const netPlane = new THREE.Mesh(
    new THREE.PlaneGeometry(NW, netBodyH),
    new THREE.MeshLambertMaterial({
      map:netTex, transparent:true, opacity:0.70,
      side:THREE.DoubleSide, depthWrite:false, alphaTest:0.02
    })
  );
  netPlane.position.set(0, netBodyH/2, 0);
  netG.add(netPlane);

  // ── 4. Bande blanche supérieure (suit la courbure via segments BoxGeometry)
  const BH=0.062;
  for(let i=0; i<NSEG; i++){
    const t0=i/NSEG, t1=(i+1)/NSEG;
    const x0=-NW/2+t0*NW, y0=ch(t0)-BH/2;
    const x1=-NW/2+t1*NW, y1=ch(t1)-BH/2;
    const band=new THREE.Mesh(new THREE.BoxGeometry(Math.abs(x1-x0)+0.01,BH,0.020), matBand);
    band.position.set((x0+x1)/2,(y0+y1)/2,0);
    netG.add(band);
  }

  // ── 5. Sangle centrale plate (deux faces, pas de cylindre)
  [-0.011, 0.011].forEach(dz=>{
    const s=new THREE.Mesh(new THREE.PlaneGeometry(0.048,H_CTR), matStrap);
    s.position.set(0, H_CTR/2, dz);
    netG.add(s);
  });

  // ── 6. Corde de pied
  const foot=new THREE.Mesh(new THREE.CylinderGeometry(0.007,0.007,NW,8),
    new THREE.MeshLambertMaterial({color:0xBBBBBB}));
  foot.rotation.z=Math.PI/2;
  foot.position.set(0,0.007,0);
  netG.add(foot);
}

// ═══════════════════════════════════════════════════════
//  MOBILIER DE COURT — chaise arbitre, bancs, parasols
// ═══════════════════════════════════════════════════════
{
  // ── Matériaux partagés
  const matGray   = new THREE.MeshLambertMaterial({ color: 0x888070 });
  const matDarkGray = new THREE.MeshLambertMaterial({ color: 0x4A4438 });
  const matChair  = new THREE.MeshLambertMaterial({ color: 0x1A3A6A }); // bleu marine
  const matWood   = new THREE.MeshLambertMaterial({ color: 0x8B6040 });
  const matUmbrella = new THREE.MeshLambertMaterial({ color: 0x2B5EA7, side: THREE.DoubleSide });
  const matUmbrella2 = new THREE.MeshLambertMaterial({ color: 0xF5F0E8, side: THREE.DoubleSide });

  // ═══════════════════════
  //  CHAISE D'ARBITRE (côté droit du filet, x=7.5, z=0)
  // ═══════════════════════
  const chairG = new THREE.Group();
  scene.add(chairG);
  chairG.position.set(7.5, 0, 0);
  chairG.rotation.y = -Math.PI / 2; // Face the court (looking toward net/court center)

  // 4 pieds de la chaise
  const legGeo = new THREE.CylinderGeometry(0.04, 0.04, 3.8, 6);
  [[-0.28,-0.28],[0.28,-0.28],[-0.28,0.28],[0.28,0.28]].forEach(([lx,lz]) => {
    const leg = new THREE.Mesh(legGeo, matDarkGray);
    leg.position.set(lx, 1.9, lz); leg.castShadow = true; chairG.add(leg);
  });

  // Traverses horizontales (2 niveaux × 2 axes)
  [1.1, 2.5].forEach(hy => {
    [{rz:0,rx:Math.PI/2},{rz:Math.PI/2,rx:0}].forEach(({rz,rx}) => {
      const bar = new THREE.Mesh(new THREE.CylinderGeometry(0.025,0.025,0.6,6), matDarkGray);
      bar.rotation.z = rz; bar.rotation.x = rx;
      bar.position.set(0, hy, 0);
      chairG.add(bar);
    });
  });

  // Plateforme assise
  const platform = new THREE.Mesh(new THREE.BoxGeometry(0.72, 0.07, 0.72), matChair);
  platform.position.set(0, 3.85, 0); platform.castShadow = true; chairG.add(platform);

  // Dossier
  const backrest = new THREE.Mesh(new THREE.BoxGeometry(0.68, 0.55, 0.06), matChair);
  backrest.position.set(0, 4.18, -0.33); chairG.add(backrest);

  // Accoudoirs
  [-0.32, 0.32].forEach(ax => {
    const arm = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.06, 0.45), matDarkGray);
    arm.position.set(ax, 4.12, 0.0); chairG.add(arm);
  });

  // ── Arbitre — costume complet Roland Garros
  const matArbShirt = new THREE.MeshLambertMaterial({ color: 0x1A3A6A }); // veste bleu marine
  const matArbPants = new THREE.MeshLambertMaterial({ color: 0x121820 }); // pantalon sombre
  const matArbSkin  = new THREE.MeshLambertMaterial({ color: 0xC88050 });
  const matArbWhite = new THREE.MeshLambertMaterial({ color: 0xF0EDE8 }); // chemise blanche
  const matArbCap   = new THREE.MeshLambertMaterial({ color: 0x1A3A6A }); // casquette
  const matArbMic   = new THREE.MeshLambertMaterial({ color: 0x333333 });

  // Torse (veste)
  const arbTorse = new THREE.Mesh(new THREE.BoxGeometry(0.36, 0.46, 0.22), matArbShirt);
  arbTorse.position.set(0, 4.22, 0); chairG.add(arbTorse);
  // Chemise blanche (col visible)
  const arbCol = new THREE.Mesh(new THREE.BoxGeometry(0.18, 0.10, 0.06), matArbWhite);
  arbCol.position.set(0, 4.42, 0.10); chairG.add(arbCol);
  // Cravate fine orange RG
  const arbTie = new THREE.Mesh(new THREE.BoxGeometry(0.05, 0.28, 0.04),
    new THREE.MeshLambertMaterial({ color: 0xC4622D }));
  arbTie.position.set(0, 4.30, 0.12); chairG.add(arbTie);
  // Épaules légèrement élargies
  [-0.20, 0.20].forEach(ax => {
    const shoulder = new THREE.Mesh(new THREE.BoxGeometry(0.13, 0.12, 0.20), matArbShirt);
    shoulder.position.set(ax, 4.38, 0); chairG.add(shoulder);
    // Bras posés sur les accoudoirs
    const arm3 = new THREE.Mesh(new THREE.BoxGeometry(0.10, 0.10, 0.38), matArbShirt);
    arm3.position.set(ax*1.5, 4.14, 0.06); arm3.rotation.x = 0.15; chairG.add(arm3);
    // Main
    const hand = new THREE.Mesh(new THREE.BoxGeometry(0.09, 0.07, 0.09), matArbSkin);
    hand.position.set(ax*1.5, 4.10, 0.26); chairG.add(hand);
  });
  // Pantalon (jambes)
  [-0.10, 0.10].forEach(lx => {
    const thigh = new THREE.Mesh(new THREE.BoxGeometry(0.14, 0.38, 0.16), matArbPants);
    thigh.position.set(lx, 3.78, 0.05); thigh.rotation.x = 0.18; chairG.add(thigh);
    const shin = new THREE.Mesh(new THREE.BoxGeometry(0.11, 0.32, 0.12), matArbPants);
    shin.position.set(lx, 3.45, 0.22); shin.rotation.x = 0.10; chairG.add(shin);
    // Chaussure
    const shoe = new THREE.Mesh(new THREE.BoxGeometry(0.12, 0.08, 0.22),
      new THREE.MeshLambertMaterial({ color: 0x111111 }));
    shoe.position.set(lx, 3.30, 0.30); chairG.add(shoe);
  });
  // Tête
  const arbHead = new THREE.Mesh(new THREE.SphereGeometry(0.118, 10, 8), matArbSkin);
  arbHead.position.set(0, 4.68, 0); chairG.add(arbHead);
  // Oreilles
  [-0.118, 0.118].forEach(ex => {
    const ear = new THREE.Mesh(new THREE.SphereGeometry(0.028, 6, 5), matArbSkin);
    ear.position.set(ex, 4.67, 0); chairG.add(ear);
  });
  // Yeux
  [-0.04, 0.04].forEach(ex => {
    const eye = new THREE.Mesh(new THREE.SphereGeometry(0.018, 5, 4),
      new THREE.MeshLambertMaterial({ color: 0x1A1008 }));
    eye.position.set(ex, 4.70, 0.108); chairG.add(eye);
  });
  // Casquette RG (brim + dôme)
  const capDome = new THREE.Mesh(new THREE.SphereGeometry(0.135, 10, 6, 0, Math.PI*2, 0, Math.PI*0.55), matArbCap);
  capDome.position.set(0, 4.76, 0); chairG.add(capDome);
  const capBrim = new THREE.Mesh(new THREE.CylinderGeometry(0.175, 0.175, 0.018, 12), matArbCap);
  capBrim.position.set(0, 4.75, 0.015); chairG.add(capBrim);
  // Visière avant plus saillante
  const visor = new THREE.Mesh(new THREE.BoxGeometry(0.22, 0.018, 0.10), matArbCap);
  visor.position.set(0, 4.75, 0.125); chairG.add(visor);
  // Micro (tige + boule)
  const micArm = new THREE.Mesh(new THREE.CylinderGeometry(0.012, 0.012, 0.22, 5), matArbMic);
  micArm.rotation.z = 0.5; micArm.position.set(-0.14, 4.64, 0.10); chairG.add(micArm);
  const micBall = new THREE.Mesh(new THREE.SphereGeometry(0.028, 6, 5), matArbMic);
  micBall.position.set(-0.22, 4.60, 0.12); chairG.add(micBall);
  // Logo RG sur la veste (patch)
  const patchCv = document.createElement('canvas'); patchCv.width=64; patchCv.height=64;
  const patchCtx = patchCv.getContext('2d');
  patchCtx.fillStyle='#C4622D'; patchCtx.beginPath(); patchCtx.arc(32,32,28,0,Math.PI*2); patchCtx.fill();
  patchCtx.font='bold 26px Arial'; patchCtx.fillStyle='#FFF'; patchCtx.textAlign='center'; patchCtx.textBaseline='middle';
  patchCtx.fillText('RG', 32, 32);
  const patch = new THREE.Mesh(new THREE.PlaneGeometry(0.09, 0.09),
    new THREE.MeshLambertMaterial({ map: new THREE.CanvasTexture(patchCv), side: THREE.DoubleSide }));
  patch.position.set(0.14, 4.35, 0.115); chairG.add(patch);

  // ─ Panneau "CHATRIER" sur le côté de la chaise
  const panelCv = document.createElement('canvas'); panelCv.width=256; panelCv.height=64;
  const panelCtx = panelCv.getContext('2d');
  panelCtx.fillStyle='#1A3A6A'; panelCtx.fillRect(0,0,256,64);
  panelCtx.font='bold 32px Arial,sans-serif'; panelCtx.fillStyle='#FFFFFF';
  panelCtx.textAlign='center'; panelCtx.textBaseline='middle';
  panelCtx.fillText('CHATRIER', 128, 32);
  const panel = new THREE.Mesh(new THREE.PlaneGeometry(0.65, 0.18),
    new THREE.MeshLambertMaterial({ map: new THREE.CanvasTexture(panelCv), side: THREE.DoubleSide }));
  panel.position.set(0, 2.4, -0.31); chairG.add(panel);

  // ═══════════════════════
  //  BANCS JOUEURS (aux 2 bouts du court, fond de court)
  // ═══════════════════════
  function addBench(x, z, ry=0) {
    const g = new THREE.Group(); scene.add(g);
    g.position.set(x, 0, z); g.rotation.y = ry;

    // Plateau assis
    const seat = new THREE.Mesh(new THREE.BoxGeometry(2.2, 0.07, 0.55), matWood);
    seat.position.set(0, 0.45, 0); seat.castShadow = true; g.add(seat);

    // Dossier
    const back = new THREE.Mesh(new THREE.BoxGeometry(2.2, 0.42, 0.05), matWood);
    back.position.set(0, 0.69, -0.24); g.add(back);

    // 4 pieds
    [[-0.9,-0.2],[0.9,-0.2],[-0.9,0.2],[0.9,0.2]].forEach(([bx,bz]) => {
      const p = new THREE.Mesh(new THREE.CylinderGeometry(0.03,0.03,0.45,6), matDarkGray);
      p.position.set(bx, 0.22, bz); g.add(p);
    });

    // Serviette sur le banc
    const towel = new THREE.Mesh(new THREE.BoxGeometry(0.55, 0.03, 0.38), new THREE.MeshLambertMaterial({ color: 0xF5F0E8 }));
    towel.position.set(-0.5, 0.50, 0); g.add(towel);

    // Sac de sport
    const bag = new THREE.Mesh(new THREE.CylinderGeometry(0.12,0.14,0.55,8), matChair);
    bag.rotation.z = Math.PI/2;
    bag.position.set(0.6, 0.25, 0.1); g.add(bag);
  }

  addBench(-9.5, -5, Math.PI/2);  // côté gauche, joueur 1
  addBench(-9.5,  5, Math.PI/2);  // côté gauche, joueur 2

  // ═══════════════════════
  //  PARASOLS (aux 2 bouts du court, près des bancs)
  // ═══════════════════════
  function addParasol(x, z, ry=0) {
    const g = new THREE.Group(); scene.add(g);
    g.position.set(x, 0, z); g.rotation.y = ry;

    // Mât
    const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.03,0.03, 2.6, 8), matDarkGray);
    pole.position.set(0, 1.3, 0); pole.castShadow = true; g.add(pole);

    // Toile — alternance bleu/blanc en segments coniques
    const SEGS = 8;
    for(let i=0; i<SEGS; i++) {
      const a0 = (i / SEGS) * Math.PI * 2;
      const a1 = ((i+1) / SEGS) * Math.PI * 2;
      const mat = i % 2 === 0 ? matUmbrella : matUmbrella2;
      // Triangle segment
      const shape = new THREE.Shape();
      shape.moveTo(0, 0);
      shape.lineTo(Math.cos(a0)*1.3, Math.sin(a0)*1.3);
      shape.lineTo(Math.cos(a1)*1.3, Math.sin(a1)*1.3);
      shape.closePath();
      const geo = new THREE.ShapeGeometry(shape);
      const seg = new THREE.Mesh(geo, mat);
      seg.rotation.x = -Math.PI/2 + 0.28; // légère inclinaison vers le bas
      seg.position.set(0, 2.6, 0);
      g.add(seg);
    }

    // Bouton central
    const top = new THREE.Mesh(new THREE.SphereGeometry(0.06,8,6), matDarkGray);
    top.position.set(0, 2.62, 0); g.add(top);

    // Base lestée
    const base = new THREE.Mesh(new THREE.CylinderGeometry(0.18,0.22,0.18,8), matGray);
    base.position.set(0, 0.09, 0); g.add(base);
  }

  // 2 parasols — côté gauche, un par banc
  addParasol(-9.5, -3.5, Math.PI/2);
  addParasol(-9.5,  3.5, Math.PI/2);
}

// ═══════════════════════════════════════════════════════
//  TEXTURE GRADINS — une seule texture par tribune, très haute résolution
//  Rendu sur PlaneGeometry face avant uniquement → pas de distorsion
// ═══════════════════════════════════════════════════════

// ── Textures sponsors
function makeSchweppesTex() {
  const W=1024, H=256;
  const cv=document.createElement('canvas'); cv.width=W; cv.height=H; const cx=cv.getContext('2d');
  const g=cx.createLinearGradient(0,0,0,H); g.addColorStop(0,'#FFD700'); g.addColorStop(.5,'#FFC200'); g.addColorStop(1,'#FFD700');
  cx.fillStyle=g; cx.fillRect(0,0,W,H);
  // Ajuster la taille de police pour que le texte tienne dans la largeur
  let fontSize = 148;
  cx.font='bold '+fontSize+'px Georgia,serif';
  while(cx.measureText('Schweppes').width > W*0.88 && fontSize > 40) { fontSize -= 4; cx.font='bold '+fontSize+'px Georgia,serif'; }
  cx.fillStyle='#1A1A1A'; cx.textAlign='center'; cx.textBaseline='middle';
  cx.shadowColor='rgba(0,0,0,0.25)'; cx.shadowBlur=6; cx.shadowOffsetY=3;
  cx.fillText('Schweppes', W/2, H/2);
  cx.shadowColor='transparent';
  return new THREE.CanvasTexture(cv);
}
const schwTex    = makeSchweppesTex();

// ═══════════════════════════════════════════════════════
//  GRADINS INSTANCED — spectateurs réalistes grande taille
// ═══════════════════════════════════════════════════════
const SEAT_W = 1.05;   // largeur siège (était 0.62) — plus réaliste
const SEAT_D = 0.90;   // profondeur
const SEAT_H_BACK = 0.92;  // hauteur dossier
const SEAT_H_BASE = 0.12;  // épaisseur assise

const concMat      = new THREE.MeshLambertMaterial({ color:0x7A6E60 });
const stepFaceMat  = new THREE.MeshLambertMaterial({ color:0x625850 });
const legMatShared = new THREE.MeshLambertMaterial({ color:0x2A2018 });

// Couleurs Roland-Garros : dominante terre cuite / rouge orangé + quelques bleus, verts, beiges
const SEAT_COLORS = [
  0xC84820, 0xB83A18, 0xD05028, 0xA83818, 0xC04520,
  0xBB4218, 0xD25230, 0xBC4820, 0xA03010,
  0x3A4880, 0x2E3C72, 0x445AAA,   // bleus (rares)
  0x286040, 0x2A7045,              // verts (rares)
  0xD8C8A0,                        // beige (siège vide)
];

// ── Géométries SIÈGE détaillé
// Dossier avec légère courbure (multi-segment)
const geoDossier   = new THREE.BoxGeometry(SEAT_W-0.06, SEAT_H_BACK, 0.10);
const geoDossierTop= new THREE.BoxGeometry(SEAT_W-0.06, 0.08, 0.14); // rebord haut dossier
// Assise
const geoAssise    = new THREE.BoxGeometry(SEAT_W-0.06, SEAT_H_BASE, SEAT_D-0.08);
const geoAssiseFront= new THREE.BoxGeometry(SEAT_W-0.06, 0.10, 0.06); // nez avant arrondi
// Accoudoirs latéraux
const geoAccoudoir = new THREE.BoxGeometry(0.08, 0.06, SEAT_D*0.85);
const geoAccoudoirV= new THREE.BoxGeometry(0.07, 0.42, 0.07); // montant vertical accoudoir
// Structure/piétement siège
const geoStructL   = new THREE.BoxGeometry(0.06, 0.55, 0.06);  // pied latéral
const geoStructH   = new THREE.BoxGeometry(SEAT_W*0.82, 0.06, 0.06); // traverse horizontale

// ── Géométries SPECTATEUR — proportions humaines 1:1
// Torse (légèrement trapézoïdal épaules>hanches)
const geoTorse     = new THREE.BoxGeometry(0.46, 0.60, 0.28);
const geoTorsoBot  = new THREE.BoxGeometry(0.38, 0.24, 0.26); // bas de torse/hanches
// Épaules larges
const geoEpaules   = new THREE.BoxGeometry(0.62, 0.12, 0.28);
// Bras haut (humérus)
const geoHumerusG  = new THREE.BoxGeometry(0.13, 0.38, 0.13);
const geoHumerusD  = new THREE.BoxGeometry(0.13, 0.38, 0.13);
// Avant-bras + main
const geoAvantBras = new THREE.BoxGeometry(0.11, 0.32, 0.11);
const geoMain      = new THREE.BoxGeometry(0.10, 0.14, 0.08);
// Bras tendu horizontal (posé genoux)
const geoBras      = new THREE.BoxGeometry(0.11, 0.11, 0.48);
const geoBrasHaut  = new THREE.BoxGeometry(0.12, 0.38, 0.12);
// Tête — sphère + mâchoire aplatie
const geoTete      = new THREE.SphereGeometry(0.148, 12, 9);
const geoMachoire  = new THREE.BoxGeometry(0.20, 0.12, 0.22);
// Cou
const geoNeck      = new THREE.CylinderGeometry(0.058, 0.072, 0.20, 8);
// Oreilles
const geoOreille   = new THREE.SphereGeometry(0.040, 6, 5);
// Nez
const geoNez       = new THREE.BoxGeometry(0.055, 0.065, 0.058);
// Casquette dôme + visière
const geoCasqDome  = new THREE.SphereGeometry(0.162, 12, 7, 0, Math.PI*2, 0, Math.PI*0.52);
const geoCasqVisi  = new THREE.BoxGeometry(0.28, 0.028, 0.18);
// Cheveux
const geoHairShort = new THREE.SphereGeometry(0.156, 10, 7, 0, Math.PI*2, 0, Math.PI*0.62);
const geoHairLong  = new THREE.BoxGeometry(0.26, 0.32, 0.10);
const geoHairBun   = new THREE.SphereGeometry(0.082, 7, 6);
// Lunettes de soleil
const geoLunettes  = new THREE.BoxGeometry(0.28, 0.06, 0.04);
// Jambes — cuisse + genou + mollet + pied
const geoCuisse    = new THREE.BoxGeometry(0.17, 0.46, 0.20);
const geoGenou     = new THREE.SphereGeometry(0.085, 7, 6);
const geoMollet    = new THREE.BoxGeometry(0.14, 0.40, 0.16);
const geoPied      = new THREE.BoxGeometry(0.15, 0.09, 0.30);
// Accessoires
const geoPhone     = new THREE.BoxGeometry(0.07, 0.14, 0.016);
const geoJumelles  = new THREE.BoxGeometry(0.22, 0.10, 0.13);
const geoProgram   = new THREE.BoxGeometry(0.24, 0.32, 0.014);
const geoStruct    = new THREE.BoxGeometry(SEAT_W-0.06, 0.40, 0.08); // ancienne compat

// Matériaux instanced
const matDossier   = new THREE.MeshLambertMaterial();
const matAssise    = new THREE.MeshLambertMaterial();
const matTorse     = new THREE.MeshLambertMaterial();
const matEpaules   = new THREE.MeshLambertMaterial();
const matBras      = new THREE.MeshLambertMaterial();
const matTete      = new THREE.MeshLambertMaterial();
const matNeck      = new THREE.MeshLambertMaterial();
const matCasqDome  = new THREE.MeshLambertMaterial();
const matCasqVisi  = new THREE.MeshLambertMaterial();
const matHair      = new THREE.MeshLambertMaterial();
const matLegs      = new THREE.MeshLambertMaterial();
const matShoes     = new THREE.MeshLambertMaterial({ color: 0x1A1614 });
const matPhone     = new THREE.MeshLambertMaterial({ color: 0x0A0A0A });
const matAccessory = new THREE.MeshLambertMaterial();
const matSkin2     = new THREE.MeshLambertMaterial(); // oreilles/mains/nez
const matSeatMetal = new THREE.MeshLambertMaterial({ color: 0x282018 });
const matSeatTop   = new THREE.MeshLambertMaterial(); // rebord haut dossier
const matLunettes  = new THREE.MeshLambertMaterial({ color: 0x111111 });

const _dummy = new THREE.Object3D();
const _col   = new THREE.Color();

function buildStand(group, nRows, rowHeight, rowDepth, totalW, zStart, zDir, seedBase) {
  const srng = n => { const x = Math.sin(n*seedBase*0.0137+1.618)*31415.9; return x-Math.floor(x); };
  const seatSpacing = SEAT_W + 0.30;  // espacement plus large
  const nSeats = Math.floor(totalW / seatSpacing);
  const rowOffset = (totalW - nSeats * seatSpacing) * 0.5;
  const total = nRows * nSeats;

  const mk = (geo, mat) => {
    const im = new THREE.InstancedMesh(geo, mat, total);
    im.instanceColor = new THREE.InstancedBufferAttribute(new Float32Array(total*3), 3);
    return im;
  };

  // ── SIÈGES — géométries détaillées
  const imDossier    = mk(geoDossier,    matDossier);
  const imDossierTop = mk(geoDossierTop, matSeatTop);
  const imAssise     = mk(geoAssise,     matAssise);
  const imAssiseFront= mk(geoAssiseFront,matAssise);
  const imAccG       = mk(geoAccoudoir,  matSeatMetal);
  const imAccD       = mk(geoAccoudoir,  matSeatMetal);
  const imAccVG      = mk(geoAccoudoirV, matSeatMetal);
  const imAccVD      = mk(geoAccoudoirV, matSeatMetal);
  const imStructL1   = mk(geoStructL,    legMatShared);
  const imStructL2   = mk(geoStructL,    legMatShared);
  const imStructH    = mk(geoStructH,    legMatShared);

  // ── SPECTATEURS
  const imTorse      = mk(geoTorse,      matTorse);
  const imTorsoBot   = mk(geoTorsoBot,   matTorse);
  const imEpaules    = mk(geoEpaules,    matEpaules);
  const imHumerusG   = mk(geoHumerusG,   matBras);
  const imHumerusD   = mk(geoHumerusD,   matBras);
  const imAvantBrasG = mk(geoAvantBras,  matBras);
  const imAvantBrasD = mk(geoAvantBras,  matBras);
  const imMainG      = mk(geoMain,       matSkin2);
  const imMainD      = mk(geoMain,       matSkin2);
  const imTete       = mk(geoTete,       matTete);
  const imMachoire   = mk(geoMachoire,   matTete);
  const imNeck       = mk(geoNeck,       matNeck);
  const imOreillG    = mk(geoOreille,    matSkin2);
  const imOreillD    = mk(geoOreille,    matSkin2);
  const imNez        = mk(geoNez,        matSkin2);
  const imCasqD      = mk(geoCasqDome,   matCasqDome);
  const imCasqV      = mk(geoCasqVisi,   matCasqVisi);
  const imHairShort  = mk(geoHairShort,  matHair);
  const imHairLong   = mk(geoHairLong,   matHair);
  const imHairBun    = mk(geoHairBun,    matHair);
  const imLunettes   = mk(geoLunettes,   matLunettes);
  // Jambes
  const imCuisseG    = mk(geoCuisse,     matLegs);
  const imCuisseD    = mk(geoCuisse,     matLegs);
  const imGenouG     = mk(geoGenou,      matLegs);
  const imGenouD     = mk(geoGenou,      matLegs);
  const imMolletG    = mk(geoMollet,     matLegs);
  const imMolletD    = mk(geoMollet,     matLegs);
  const imPiedG      = new THREE.InstancedMesh(geoPied, matShoes, total);
  const imPiedD      = new THREE.InstancedMesh(geoPied, matShoes, total);
  // Accessoires
  const imPhone      = mk(geoPhone,      matPhone);
  const imJumelles   = mk(geoJumelles,   matAccessory);
  const imProgram    = mk(geoProgram,    matAccessory);
  const imBrasLeve   = mk(geoBrasHaut,   matBras);
  const imABLeve     = mk(geoAvantBras,  matBras);

  const HIDDEN = new THREE.Matrix4().makeScale(0,0,0);
  const allIM = [imDossier,imDossierTop,imAssise,imAssiseFront,imAccG,imAccD,imAccVG,imAccVD,
                 imStructL1,imStructL2,imStructH,
                 imTorse,imTorsoBot,imEpaules,imHumerusG,imHumerusD,imAvantBrasG,imAvantBrasD,
                 imMainG,imMainD,imTete,imMachoire,imNeck,imOreillG,imOreillD,imNez,
                 imCasqD,imCasqV,imHairShort,imHairLong,imHairBun,imLunettes,
                 imCuisseG,imCuisseD,imGenouG,imGenouD,imMolletG,imMolletD,imPiedG,imPiedD,
                 imPhone,imJumelles,imProgram,imBrasLeve,imABLeve];
  for(let i=0;i<total;i++) allIM.forEach(im=>im.setMatrixAt(i,HIDDEN));

  let idx = 0;
  for(let r = 0; r < nRows; r++) {
    const y  = r * rowHeight;
    const z  = zStart + zDir * r * rowDepth;

    // ── Contremarche béton
    const faceH = rowHeight * 0.55;
    const face = new THREE.Mesh(new THREE.BoxGeometry(totalW, faceH, 0.20), stepFaceMat);
    face.position.set(0, y + faceH*0.5, z);
    face.receiveShadow = true; group.add(face);

    // ── Palier béton
    const palier = new THREE.Mesh(new THREE.PlaneGeometry(totalW, rowDepth), concMat);
    palier.rotation.x = -Math.PI/2;
    palier.position.set(0, y+rowHeight, z + zDir*rowDepth*0.5);
    palier.receiveShadow = true; group.add(palier);

    const seatY = y + rowHeight;
    const sz    = z + zDir * rowDepth * 0.35;

    for(let s = 0; s < nSeats; s++, idx++) {
      const sx = -totalW*0.5 + rowOffset + (s+0.5)*seatSpacing;
      const seatColIdx = Math.floor(srng(r*97+s*13) * SEAT_COLORS.length);
      const seatCol  = new THREE.Color(SEAT_COLORS[seatColIdx]);
      const seatDark = seatCol.clone().multiplyScalar(0.65);
      const seatMid  = seatCol.clone().multiplyScalar(0.82);

      // ── DOSSIER
      _dummy.position.set(sx, seatY+0.52+SEAT_H_BACK*0.5, sz+zDir*0.12);
      _dummy.rotation.set(0.14*zDir, 0, 0); _dummy.scale.set(1,1,1);
      _dummy.updateMatrix();
      imDossier.setMatrixAt(idx, _dummy.matrix); imDossier.setColorAt(idx, seatCol);

      // Rebord supérieur dossier (détail chromé/noir)
      _dummy.position.set(sx, seatY+0.52+SEAT_H_BACK+0.04, sz+zDir*0.12);
      _dummy.rotation.set(0.14*zDir, 0, 0); _dummy.updateMatrix();
      imDossierTop.setMatrixAt(idx, _dummy.matrix);
      _col.setHSL(0,0,0.15); imDossierTop.setColorAt(idx, _col);

      // ── ASSISE
      _dummy.position.set(sx, seatY+SEAT_H_BASE*0.5+0.01, sz+zDir*0.05);
      _dummy.rotation.set(0, 0, 0); _dummy.updateMatrix();
      imAssise.setMatrixAt(idx, _dummy.matrix); imAssise.setColorAt(idx, seatDark);

      // Nez avant assise (arrondi)
      _dummy.position.set(sx, seatY+SEAT_H_BASE*0.5-0.02, sz-zDir*(SEAT_D*0.5-0.02));
      _dummy.rotation.set(0.20*zDir, 0, 0); _dummy.updateMatrix();
      imAssiseFront.setMatrixAt(idx, _dummy.matrix); imAssiseFront.setColorAt(idx, seatMid);

      // ── ACCOUDOIRS — gris aluminium clair
      const accY = seatY + 0.38;
      _dummy.position.set(sx - SEAT_W*0.5 + 0.04, accY, sz+zDir*0.02);
      _dummy.rotation.set(0,0,0); _dummy.updateMatrix();
      imAccG.setMatrixAt(idx, _dummy.matrix); _col.setHSL(0.08,0.06,0.72); imAccG.setColorAt(idx,_col);
      _dummy.position.set(sx + SEAT_W*0.5 - 0.04, accY, sz+zDir*0.02);
      _dummy.updateMatrix();
      imAccD.setMatrixAt(idx, _dummy.matrix); imAccD.setColorAt(idx,_col);

      // Montants verticaux accoudoirs — légèrement plus sombres
      _dummy.position.set(sx-SEAT_W*0.5+0.04, seatY+0.22, sz+zDir*0.02);
      _dummy.rotation.set(0,0,0); _dummy.updateMatrix();
      imAccVG.setMatrixAt(idx, _dummy.matrix); _col.setHSL(0.08,0.05,0.58); imAccVG.setColorAt(idx,_col);
      _dummy.position.set(sx+SEAT_W*0.5-0.04, seatY+0.22, sz+zDir*0.02);
      _dummy.updateMatrix();
      imAccVD.setMatrixAt(idx, _dummy.matrix); imAccVD.setColorAt(idx,_col);

      // ── PIEDS (2 pieds avant, traverse)
      _col.setHSL(0,0,0.14);
      _dummy.position.set(sx-SEAT_W*0.35, seatY-0.28, sz+zDir*0.10);
      _dummy.rotation.set(0,0,0); _dummy.updateMatrix();
      imStructL1.setMatrixAt(idx,_dummy.matrix); imStructL1.setColorAt(idx,_col);
      _dummy.position.set(sx+SEAT_W*0.35, seatY-0.28, sz+zDir*0.10);
      _dummy.updateMatrix();
      imStructL2.setMatrixAt(idx,_dummy.matrix); imStructL2.setColorAt(idx,_col);
      _dummy.position.set(sx, seatY-0.54, sz+zDir*0.10);
      _dummy.updateMatrix();
      imStructH.setMatrixAt(idx,_dummy.matrix); imStructH.setColorAt(idx,_col);

      // ── SPECTATEUR
      const isEmpty = (r > nRows*0.90 && (s < nSeats*0.06 || s > nSeats*0.94) && srng(idx+8888)<0.45)
                   || srng(idx+9999) < 0.15;
      if(!isEmpty) {
        // ── Tenue
        const cg = srng(r*312+s*19+7777);
        let ch, cs, cl;
        if      (cg<0.14){ch=0.06;cs=0.88;cl=0.44+srng(idx)*0.18;}
        else if (cg<0.26){ch=0.60;cs=0.72;cl=0.36+srng(idx)*0.22;}
        else if (cg<0.37){ch=0.95;cs=0.82;cl=0.48+srng(idx)*0.18;}
        else if (cg<0.47){ch=0.75;cs=0.68;cl=0.34+srng(idx)*0.22;}
        else if (cg<0.57){ch=0.33;cs=0.70;cl=0.30+srng(idx)*0.22;}
        else if (cg<0.69){ch=0.08;cs=0.06;cl=0.88+srng(idx)*0.10;}
        else if (cg<0.79){ch=0.05;cs=0.90;cl=0.24+srng(idx)*0.14;}
        else if (cg<0.87){ch=0.10;cs=0.80;cl=0.52+srng(idx)*0.18;}
        else if (cg<0.93){ch=0.58;cs=0.62;cl=0.54+srng(idx)*0.14;}
        else              {ch=0.55;cs=0.20;cl=0.16+srng(idx)*0.14;}

        // Bas (pantalon/jupe)
        const pg = srng(r*541+s*37+4321);
        let ph, ps, pl;
        if      (pg<0.28){ph=0.60;ps=0.48;pl=0.20+srng(idx+10)*0.12;}
        else if (pg<0.50){ph=0.08;ps=0.06;pl=0.12+srng(idx+10)*0.10;}
        else if (pg<0.68){ph=0.07;ps=0.28;pl=0.46+srng(idx+10)*0.14;}
        else if (pg<0.82){ph=0.06;ps=0.70;pl=0.30+srng(idx+10)*0.12;}
        else              {ph=0.33;ps=0.48;pl=0.26+srng(idx+10)*0.12;}

        // Peau
        const sk = srng(r*503+s*71+1111);
        let sh, ss2, sl;
        if      (sk<0.25){sh=0.07;ss2=0.32;sl=0.68+srng(idx+1)*0.12;}
        else if (sk<0.50){sh=0.07;ss2=0.42;sl=0.52+srng(idx+1)*0.10;}
        else if (sk<0.72){sh=0.06;ss2=0.48;sl=0.38+srng(idx+1)*0.10;}
        else              {sh=0.06;ss2=0.36;sl=0.22+srng(idx+1)*0.08;}
        const skinCol = new THREE.Color().setHSL(sh,ss2,sl);

        // Cheveux
        const hg = srng(r*677+s*43+8888);
        let hh,hs,hl;
        if      (hg<0.28){hh=0.06;hs=0.52;hl=0.10+srng(idx+5)*0.08;}
        else if (hg<0.48){hh=0.07;hs=0.48;hl=0.20+srng(idx+5)*0.10;}
        else if (hg<0.64){hh=0.09;hs=0.70;hl=0.54+srng(idx+5)*0.14;}
        else if (hg<0.76){hh=0.00;hs=0.00;hl=0.04+srng(idx+5)*0.05;}
        else if (hg<0.87){hh=0.00;hs=0.00;hl=0.76+srng(idx+5)*0.18;}
        else              {hh=0.02;hs=0.82;hl=0.40+srng(idx+5)*0.12;}

        // Posture
        const postR = srng(r*1301+s*83+5555);
        const posture = postR<0.50?0:postR<0.74?1:postR<0.90?2:3;
        const leanBase = posture===1?0.26*zDir:posture===2?0.05*zDir:posture===3?0.00:0.12*zDir;
        const headTilt = (srng(r*811+s*37)-0.5)*0.32;
        const jX = (srng(r*711+s+22)-0.5)*0.06;
        const rise = posture===3 ? 0.40 : 0.0;

        // ── POSTURE ASSISE — anatomie réaliste
        // La hanche est sur le siège (seatY), cuisse horizontale, genou plié à ~90°,
        // mollet pendant vers le bas, pied au sol devant.
        // "rise" = offset pour les rares debout

        // Lean selon posture : assis droit / légèrement penché / redressé / debout
        // Pour assis : inclinaison du torse vers le court (zDir négatif = vers nous)
        const spineAngle = posture===1 ? 0.18*zDir : posture===2 ? 0.04*zDir : posture===3 ? 0.0 : 0.12*zDir;

        // ── CUISSES — horizontales posées sur l'assise, légèrement vers l'avant/bas
        _col.setHSL(ph,ps,pl);
        const thighPitch = posture===3 ? -0.30 : 0.15;  // quasi-horizontal en position assise
        _dummy.position.set(sx-0.13, seatY+0.22+rise*0.1, sz+zDir*0.08);
        _dummy.rotation.set(thighPitch*zDir, 0, 0.05); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
        imCuisseG.setMatrixAt(idx,_dummy.matrix); imCuisseG.setColorAt(idx,_col);
        _dummy.position.set(sx+0.13, seatY+0.22+rise*0.1, sz+zDir*0.08);
        _dummy.rotation.set(thighPitch*zDir, 0, -0.05); _dummy.updateMatrix();
        imCuisseD.setMatrixAt(idx,_dummy.matrix); imCuisseD.setColorAt(idx,_col);

        // ── GENOUX — au bord de l'assise, légèrement surélevés
        _col.setHSL(ph,ps,pl*0.85);
        const kneeZ = sz - zDir*0.34;   // genou en avant du siège
        _dummy.position.set(sx-0.13, seatY+0.10+rise*0.05, kneeZ);
        _dummy.rotation.set(0,0,0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
        imGenouG.setMatrixAt(idx,_dummy.matrix); imGenouG.setColorAt(idx,_col);
        _dummy.position.set(sx+0.13, seatY+0.10+rise*0.05, kneeZ);
        _dummy.updateMatrix();
        imGenouD.setMatrixAt(idx,_dummy.matrix); imGenouD.setColorAt(idx,_col);

        // ── MOLLETS — tombant vers le bas depuis le genou (verticaux)
        _col.setHSL(ph,ps,pl);
        _dummy.position.set(sx-0.12, seatY-0.20+rise*0.02, kneeZ - zDir*0.04);
        _dummy.rotation.set(0.08, 0, 0.02); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
        imMolletG.setMatrixAt(idx,_dummy.matrix); imMolletG.setColorAt(idx,_col);
        _dummy.position.set(sx+0.12, seatY-0.20+rise*0.02, kneeZ - zDir*0.04);
        _dummy.rotation.set(0.08, 0, -0.02); _dummy.updateMatrix();
        imMolletD.setMatrixAt(idx,_dummy.matrix); imMolletD.setColorAt(idx,_col);

        // ── PIEDS — posés à plat devant les mollets
        _dummy.position.set(sx-0.12, seatY-0.44, kneeZ - zDir*0.08);
        _dummy.rotation.set(0,0,0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
        imPiedG.setMatrixAt(idx,_dummy.matrix);
        _dummy.position.set(sx+0.12, seatY-0.44, kneeZ - zDir*0.08);
        _dummy.updateMatrix();
        imPiedD.setMatrixAt(idx,_dummy.matrix);

        // ── TORSE — centré sur le siège, légèrement incliné vers le court
        _col.setHSL(ch,cs,cl*0.80);
        _dummy.position.set(sx+jX, seatY+0.52+rise, sz+zDir*0.10);
        _dummy.rotation.set(spineAngle,0,0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
        imTorsoBot.setMatrixAt(idx,_dummy.matrix); imTorsoBot.setColorAt(idx,_col);

        _col.setHSL(ch,cs,cl);
        _dummy.position.set(sx+jX, seatY+0.82+rise, sz+zDir*0.06);
        _dummy.rotation.set(spineAngle,0,0); _dummy.updateMatrix();
        imTorse.setMatrixAt(idx,_dummy.matrix); imTorse.setColorAt(idx,_col);

        // ── ÉPAULES
        _dummy.position.set(sx+jX, seatY+1.08+rise, sz+zDir*0.03);
        _dummy.rotation.set(spineAngle,0,0); _dummy.updateMatrix();
        imEpaules.setMatrixAt(idx,_dummy.matrix); imEpaules.setColorAt(idx,_col);

        // ── BRAS — 4 poses selon type
        // accoudoir gauche X = sx - SEAT_W*0.5 + 0.04, Y = seatY+0.38
        const accXG = sx - SEAT_W*0.5 + 0.10;
        const accXD = sx + SEAT_W*0.5 - 0.10;
        const accYArm = seatY + 0.38 + rise;
        const armR = srng(r*1789+s*113+2222);
        _col.setHSL(ch,cs,cl*0.83);

        if(armR < 0.42) {
          // Bras posés sur accoudoirs — humérus oblique vers le bas, avant-bras horizontal
          // Humérus G : épaule → coude (descend vers l'accoudoir)
          _dummy.position.set(accXG+0.04, seatY+0.80+rise, sz+zDir*0.03);
          _dummy.rotation.set(0.55*zDir, 0, 0.30); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imHumerusG.setMatrixAt(idx,_dummy.matrix); imHumerusG.setColorAt(idx,_col);
          // Avant-bras G : coude → poignet (horizontal sur l'accoudoir, vers l'avant)
          _dummy.position.set(accXG+0.02, accYArm+0.06, sz+zDir*0.04);
          _dummy.rotation.set(0.12*zDir, 0, 0.12); _dummy.updateMatrix();
          imAvantBrasG.setMatrixAt(idx,_dummy.matrix); imAvantBrasG.setColorAt(idx,_col);
          // Main G sur l'accoudoir
          _col.setHSL(sh,ss2,sl);
          _dummy.position.set(accXG-0.02, accYArm+0.04, sz-zDir*0.10);
          _dummy.rotation.set(0,0,0.10); _dummy.updateMatrix();
          imMainG.setMatrixAt(idx,_dummy.matrix); imMainG.setColorAt(idx,_col);
          _col.setHSL(ch,cs,cl*0.83);

          // Humérus D
          _dummy.position.set(accXD-0.04, seatY+0.80+rise, sz+zDir*0.03);
          _dummy.rotation.set(0.55*zDir, 0, -0.30); _dummy.updateMatrix();
          imHumerusD.setMatrixAt(idx,_dummy.matrix); imHumerusD.setColorAt(idx,_col);
          // Avant-bras D
          _dummy.position.set(accXD-0.02, accYArm+0.06, sz+zDir*0.04);
          _dummy.rotation.set(0.12*zDir, 0, -0.12); _dummy.updateMatrix();
          imAvantBrasD.setMatrixAt(idx,_dummy.matrix); imAvantBrasD.setColorAt(idx,_col);
          // Main D
          _col.setHSL(sh,ss2,sl);
          _dummy.position.set(accXD+0.02, accYArm+0.04, sz-zDir*0.10);
          _dummy.rotation.set(0,-0.10,0); _dummy.updateMatrix();
          imMainD.setMatrixAt(idx,_dummy.matrix); imMainD.setColorAt(idx,_col);
          _col.setHSL(ch,cs,cl*0.83);

          imBrasLeve.setMatrixAt(idx,HIDDEN); imABLeve.setMatrixAt(idx,HIDDEN);

        } else if(armR < 0.66) {
          // Bras croisés sur la poitrine
          _dummy.position.set(sx-0.14+jX, seatY+0.82+rise, sz+zDir*0.00);
          _dummy.rotation.set(0.28*zDir, 0.30, 0.42); _dummy.updateMatrix();
          imHumerusG.setMatrixAt(idx,_dummy.matrix); imHumerusG.setColorAt(idx,_col);
          _dummy.position.set(sx-0.06+jX, seatY+0.82+rise, sz-zDir*0.06);
          _dummy.rotation.set(0.18*zDir, -0.22, 0.20); _dummy.updateMatrix();
          imAvantBrasG.setMatrixAt(idx,_dummy.matrix); imAvantBrasG.setColorAt(idx,_col);
          _dummy.position.set(sx+0.14+jX, seatY+0.82+rise, sz+zDir*0.00);
          _dummy.rotation.set(0.28*zDir, -0.30, -0.42); _dummy.updateMatrix();
          imHumerusD.setMatrixAt(idx,_dummy.matrix); imHumerusD.setColorAt(idx,_col);
          _dummy.position.set(sx+0.06+jX, seatY+0.82+rise, sz-zDir*0.06);
          _dummy.rotation.set(0.18*zDir, 0.22, -0.20); _dummy.updateMatrix();
          imAvantBrasD.setMatrixAt(idx,_dummy.matrix); imAvantBrasD.setColorAt(idx,_col);
          imMainG.setMatrixAt(idx,HIDDEN); imMainD.setMatrixAt(idx,HIDDEN);
          imBrasLeve.setMatrixAt(idx,HIDDEN); imABLeve.setMatrixAt(idx,HIDDEN);

        } else if(armR < 0.82) {
          // Un bras levé (téléphone / applaudissement)
          const side = srng(r*2231+s*117)<0.5 ? -1 : 1;
          // Bras levé
          _dummy.position.set(sx+side*0.30+jX, seatY+1.14+rise, sz+zDir*0.00);
          _dummy.rotation.set(-0.25, 0, side*(-0.22)); _dummy.updateMatrix();
          imBrasLeve.setMatrixAt(idx,_dummy.matrix); imBrasLeve.setColorAt(idx,_col);
          _dummy.position.set(sx+side*0.24+jX, seatY+1.46+rise, sz-zDir*0.04);
          _dummy.rotation.set(-0.12, 0, side*(-0.10)); _dummy.updateMatrix();
          imABLeve.setMatrixAt(idx,_dummy.matrix); imABLeve.setColorAt(idx,_col);
          // Autre bras sur accoudoir
          const ax = side > 0 ? accXG : accXD;
          _dummy.position.set(ax+(side>0?0.04:-0.04), seatY+0.80+rise, sz+zDir*0.03);
          _dummy.rotation.set(0.50*zDir, 0, side*0.28); _dummy.updateMatrix();
          if(side>0){imHumerusG.setMatrixAt(idx,_dummy.matrix);imHumerusG.setColorAt(idx,_col);}
          else      {imHumerusD.setMatrixAt(idx,_dummy.matrix);imHumerusD.setColorAt(idx,_col);}
          imAvantBrasG.setMatrixAt(idx,HIDDEN); imAvantBrasD.setMatrixAt(idx,HIDDEN);
          imMainG.setMatrixAt(idx,HIDDEN); imMainD.setMatrixAt(idx,HIDDEN);

        } else {
          // Coudes sur les genoux, penché légèrement (spectateur concentré)
          _dummy.position.set(sx-0.20+jX, seatY+0.60+rise, sz-zDir*0.18);
          _dummy.rotation.set(0.70*zDir, 0, 0.28); _dummy.updateMatrix();
          imHumerusG.setMatrixAt(idx,_dummy.matrix); imHumerusG.setColorAt(idx,_col);
          _dummy.position.set(sx-0.16+jX, seatY+0.46+rise, sz-zDir*0.26);
          _dummy.rotation.set(0.20*zDir, 0, 0.14); _dummy.updateMatrix();
          imAvantBrasG.setMatrixAt(idx,_dummy.matrix); imAvantBrasG.setColorAt(idx,_col);
          _dummy.position.set(sx+0.20+jX, seatY+0.60+rise, sz-zDir*0.18);
          _dummy.rotation.set(0.70*zDir, 0, -0.28); _dummy.updateMatrix();
          imHumerusD.setMatrixAt(idx,_dummy.matrix); imHumerusD.setColorAt(idx,_col);
          _dummy.position.set(sx+0.16+jX, seatY+0.46+rise, sz-zDir*0.26);
          _dummy.rotation.set(0.20*zDir, 0, -0.14); _dummy.updateMatrix();
          imAvantBrasD.setMatrixAt(idx,_dummy.matrix); imAvantBrasD.setColorAt(idx,_col);
          imMainG.setMatrixAt(idx,HIDDEN); imMainD.setMatrixAt(idx,HIDDEN);
          imBrasLeve.setMatrixAt(idx,HIDDEN); imABLeve.setMatrixAt(idx,HIDDEN);
        }

        // ── COU
        _col.setHSL(sh,ss2,sl);
        _dummy.position.set(sx+jX, seatY+1.18+rise, sz+zDir*0.01);
        _dummy.rotation.set(spineAngle*0.5, 0, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
        imNeck.setMatrixAt(idx,_dummy.matrix); imNeck.setColorAt(idx,_col);

        // ── TÊTE
        const headPitch = posture===3?0.20*zDir:posture===1?0.08*zDir:spineAngle*0.45;
        _dummy.position.set(sx+jX, seatY+1.40+rise, sz+zDir*0.02);
        _dummy.rotation.set(headPitch, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
        imTete.setMatrixAt(idx,_dummy.matrix); imTete.setColorAt(idx, skinCol);

        // Mâchoire
        _dummy.position.set(sx+jX, seatY+1.28+rise, sz+zDir*0.02-zDir*0.06);
        _dummy.rotation.set(headPitch, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
        imMachoire.setMatrixAt(idx,_dummy.matrix); imMachoire.setColorAt(idx, skinCol);

        // Oreilles
        _dummy.position.set(sx+jX-0.152*Math.cos(headTilt), seatY+1.40+rise, sz+zDir*0.02);
        _dummy.rotation.set(0,0,0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
        imOreillG.setMatrixAt(idx,_dummy.matrix); imOreillG.setColorAt(idx,skinCol);
        _dummy.position.set(sx+jX+0.152*Math.cos(headTilt), seatY+1.40+rise, sz+zDir*0.02);
        _dummy.updateMatrix();
        imOreillD.setMatrixAt(idx,_dummy.matrix); imOreillD.setColorAt(idx,skinCol);

        // Nez
        _dummy.position.set(sx+jX+Math.sin(headTilt)*0.04, seatY+1.40+rise, sz+zDir*0.02-zDir*0.148);
        _dummy.rotation.set(headPitch+0.05, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
        imNez.setMatrixAt(idx,_dummy.matrix); imNez.setColorAt(idx,skinCol);

        // ── COIFFURE / CASQUETTE
        const headAccR = srng(r*1023+s*57+333);
        if(headAccR < 0.28) {
          const capH = srng(r*901+s*61);
          _col.setHSL(capH, 0.70, 0.28+capH*0.28);
          _dummy.position.set(sx+jX, seatY+1.48+rise, sz+zDir*0.02);
          _dummy.rotation.set(headPitch, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imCasqD.setMatrixAt(idx,_dummy.matrix); imCasqD.setColorAt(idx,_col);
          _col.setHSL(capH, 0.62, 0.22+capH*0.22);
          _dummy.position.set(sx+jX+Math.sin(headTilt)*0.08, seatY+1.475+rise, sz+zDir*0.02-zDir*0.130);
          _dummy.rotation.set(headPitch+0.06, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imCasqV.setMatrixAt(idx,_dummy.matrix); imCasqV.setColorAt(idx,_col);
          imHairShort.setMatrixAt(idx,HIDDEN); imHairLong.setMatrixAt(idx,HIDDEN); imHairBun.setMatrixAt(idx,HIDDEN);
        } else if(headAccR < 0.50) {
          _col.setHSL(hh,hs,hl);
          _dummy.position.set(sx+jX, seatY+1.40+rise, sz+zDir*0.02);
          _dummy.rotation.set(headPitch, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imHairShort.setMatrixAt(idx,_dummy.matrix); imHairShort.setColorAt(idx,_col);
          imCasqD.setMatrixAt(idx,HIDDEN); imCasqV.setMatrixAt(idx,HIDDEN);
          imHairLong.setMatrixAt(idx,HIDDEN); imHairBun.setMatrixAt(idx,HIDDEN);
        } else if(headAccR < 0.72) {
          _col.setHSL(hh,hs,hl);
          _dummy.position.set(sx+jX, seatY+1.40+rise, sz+zDir*0.02);
          _dummy.rotation.set(headPitch, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imHairShort.setMatrixAt(idx,_dummy.matrix); imHairShort.setColorAt(idx,_col);
          _col.setHSL(hh,hs,hl*0.86);
          _dummy.position.set(sx+jX, seatY+1.22+rise, sz+zDir*0.14);
          _dummy.rotation.set(-0.08, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imHairLong.setMatrixAt(idx,_dummy.matrix); imHairLong.setColorAt(idx,_col);
          imCasqD.setMatrixAt(idx,HIDDEN); imCasqV.setMatrixAt(idx,HIDDEN); imHairBun.setMatrixAt(idx,HIDDEN);
        } else {
          _col.setHSL(hh,hs,hl);
          _dummy.position.set(sx+jX, seatY+1.40+rise, sz+zDir*0.02);
          _dummy.rotation.set(headPitch, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imHairShort.setMatrixAt(idx,_dummy.matrix); imHairShort.setColorAt(idx,_col);
          _col.setHSL(hh,hs,hl*0.78);
          _dummy.position.set(sx+jX, seatY+1.48+rise, sz+zDir*0.10);
          _dummy.rotation.set(headPitch, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imHairBun.setMatrixAt(idx,_dummy.matrix); imHairBun.setColorAt(idx,_col);
          imCasqD.setMatrixAt(idx,HIDDEN); imCasqV.setMatrixAt(idx,HIDDEN); imHairLong.setMatrixAt(idx,HIDDEN);
        }

        // ── LUNETTES DE SOLEIL (~18%)
        if(srng(r*4421+s*183+7777) < 0.18) {
          _dummy.position.set(sx+jX, seatY+1.40+rise, sz+zDir*0.02-zDir*0.142);
          _dummy.rotation.set(headPitch, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imLunettes.setMatrixAt(idx,_dummy.matrix); _col.setHSL(0,0,0.08); imLunettes.setColorAt(idx,_col);
        } else { imLunettes.setMatrixAt(idx,HIDDEN); }

        // ── ACCESSOIRES
        const accR2 = srng(r*3317+s*211+6666);
        if(accR2 < 0.09) {
          _dummy.position.set(sx+jX+0.12, seatY+1.56+rise, sz+zDir*0.02-zDir*0.18);
          _dummy.rotation.set(0.15, headTilt+0.18, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imPhone.setMatrixAt(idx,_dummy.matrix); _col.setHSL(0,0,0.08); imPhone.setColorAt(idx,_col);
          imJumelles.setMatrixAt(idx,HIDDEN); imProgram.setMatrixAt(idx,HIDDEN);
        } else if(accR2 < 0.17) {
          _dummy.position.set(sx+jX, seatY+1.38+rise, sz+zDir*0.02-zDir*0.18);
          _dummy.rotation.set(0.06, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imJumelles.setMatrixAt(idx,_dummy.matrix); _col.setHSL(0.60,0.28,0.20); imJumelles.setColorAt(idx,_col);
          imPhone.setMatrixAt(idx,HIDDEN); imProgram.setMatrixAt(idx,HIDDEN);
        } else if(accR2 < 0.24) {
          _dummy.position.set(sx+jX, seatY+0.95+rise, sz+zDir*0.00);
          _dummy.rotation.set(0.48*zDir, headTilt, 0); _dummy.scale.set(1,1,1); _dummy.updateMatrix();
          imProgram.setMatrixAt(idx,_dummy.matrix); _col.setHSL(0.10,0.04,0.90); imProgram.setColorAt(idx,_col);
          imPhone.setMatrixAt(idx,HIDDEN); imJumelles.setMatrixAt(idx,HIDDEN);
        } else {
          imPhone.setMatrixAt(idx,HIDDEN); imJumelles.setMatrixAt(idx,HIDDEN); imProgram.setMatrixAt(idx,HIDDEN);
        }
      } // end !isEmpty
    } // end seats loop
  } // end rows loop


  // Mettre à jour les buffers
  [imDossier,imDossierTop,imAssise,imAssiseFront,imAccG,imAccD,imAccVG,imAccVD,
   imStructL1,imStructL2,imStructH,
   imTorse,imTorsoBot,imEpaules,imHumerusG,imHumerusD,imAvantBrasG,imAvantBrasD,
   imMainG,imMainD,imTete,imMachoire,imNeck,imOreillG,imOreillD,imNez,
   imCasqD,imCasqV,imHairShort,imHairLong,imHairBun,imLunettes,
   imCuisseG,imCuisseD,imGenouG,imGenouD,imMolletG,imMolletD,imPiedG,imPiedD,
   imPhone,imJumelles,imProgram,imBrasLeve,imABLeve].forEach(im => {
    im.instanceMatrix.needsUpdate = true;
    if(im.instanceColor) im.instanceColor.needsUpdate = true;
    group.add(im);
  });

  // ── Mur de fond
  const backZ = zStart + zDir * nRows * rowDepth;
  const backH = nRows * rowHeight;

  // ── Texture brandée pour la tribune principale (standBack uniquement, seedBase===1)
  if(seedBase === 1) {
    // Fond structurel sombre
    const backWall = new THREE.Mesh(new THREE.PlaneGeometry(totalW, backH),
      new THREE.MeshLambertMaterial({ color: 0x0E0C0A }));
    backWall.position.set(0, backH * 0.5, backZ);
    if(zDir < 0) backWall.rotation.y = Math.PI;
    group.add(backWall);

    // ── Grande bannière centrale Roland-Garros + Schweppes
    const BW = 48, BH = 10;
    const banCv = document.createElement('canvas'); banCv.width = 2048; banCv.height = 430;
    const bx = banCv.getContext('2d');

    // Fond dégradé sombre élégant
    const bgGrad = bx.createLinearGradient(0, 0, 0, 430);
    bgGrad.addColorStop(0, '#0A0806');
    bgGrad.addColorStop(0.5, '#110E0A');
    bgGrad.addColorStop(1, '#0A0806');
    bx.fillStyle = bgGrad; bx.fillRect(0, 0, 2048, 430);

    // Liseré orange Roland-Garros en haut et en bas
    const rgOrange = '#C4622D';
    bx.fillStyle = rgOrange; bx.fillRect(0, 0, 2048, 8);
    bx.fillStyle = rgOrange; bx.fillRect(0, 422, 2048, 8);

    // ── PANNEAU ROLAND-GARROS (gauche, ~55% de la largeur)
    const rgPanW = 1120;
    // Fond légèrement différencié
    const rgPanGrad = bx.createLinearGradient(0, 0, rgPanW, 0);
    rgPanGrad.addColorStop(0, 'rgba(196,98,45,0.08)');
    rgPanGrad.addColorStop(1, 'rgba(196,98,45,0.01)');
    bx.fillStyle = rgPanGrad; bx.fillRect(0, 8, rgPanW, 414);

    // Séparateur vertical
    bx.strokeStyle = 'rgba(196,98,45,0.5)'; bx.lineWidth = 3;
    bx.beginPath(); bx.moveTo(rgPanW, 30); bx.lineTo(rgPanW, 400); bx.stroke();

    // Cercle RG (logo simplifié)
    const cx_rg = 120, cy_rg = 215, cr = 72;
    bx.beginPath(); bx.arc(cx_rg, cy_rg, cr, 0, Math.PI*2);
    bx.fillStyle = rgOrange; bx.fill();
    bx.strokeStyle = 'rgba(245,240,232,0.3)'; bx.lineWidth = 3;
    bx.stroke();
    // Lettres RG dans le cercle
    bx.font = 'bold 64px "Arial Black", Arial, sans-serif';
    bx.fillStyle = '#F5F0E8';
    bx.textAlign = 'center'; bx.textBaseline = 'middle';
    bx.shadowColor = 'rgba(0,0,0,0.6)'; bx.shadowBlur = 8;
    bx.fillText('RG', cx_rg, cy_rg);
    bx.shadowBlur = 0;

    // Texte ROLAND-GARROS
    bx.font = 'bold 110px "Arial Black", Arial, sans-serif';
    bx.fillStyle = '#F5F0E8';
    bx.textAlign = 'left';
    bx.shadowColor = 'rgba(196,98,45,0.6)'; bx.shadowBlur = 22;
    bx.fillText('ROLAND-GARROS', 222, 195);
    bx.shadowBlur = 0;

    // Sous-titre
    bx.font = 'italic 46px Georgia, serif';
    bx.fillStyle = 'rgba(245,240,232,0.55)';
    bx.fillText('Paris · Stade Roland Garros', 222, 280);

    // Année
    bx.font = 'bold 38px "Arial", sans-serif';
    bx.fillStyle = rgOrange;
    bx.fillText('2025', 222, 345);

    // ── PANNEAU SCHWEPPES (droite, ~45% restant)
    const swPanX = rgPanW + 30;
    // Fond jaune doré caractéristique Schweppes
    const swGrad = bx.createLinearGradient(swPanX, 0, 2048, 0);
    swGrad.addColorStop(0, '#1A1500');
    swGrad.addColorStop(0.3, '#2A2200');
    swGrad.addColorStop(1, '#1A1500');
    bx.fillStyle = swGrad; bx.fillRect(swPanX, 8, 2048-swPanX, 414);

    // Grande bande Schweppes jaune
    const swBandY = 100, swBandH = 230;
    const swBandGrad = bx.createLinearGradient(swPanX, swBandY, swPanX, swBandY+swBandH);
    swBandGrad.addColorStop(0, '#FFD700');
    swBandGrad.addColorStop(0.45, '#FFC200');
    swBandGrad.addColorStop(0.55, '#FFB800');
    swBandGrad.addColorStop(1, '#FFD700');
    bx.fillStyle = swBandGrad;
    bx.fillRect(swPanX, swBandY, 2048-swPanX, swBandH);

    // Ombre sous la bande
    const swShadow = bx.createLinearGradient(swPanX, swBandY+swBandH, swPanX, swBandY+swBandH+18);
    swShadow.addColorStop(0, 'rgba(0,0,0,0.45)');
    swShadow.addColorStop(1, 'transparent');
    bx.fillStyle = swShadow; bx.fillRect(swPanX, swBandY+swBandH, 2048-swPanX, 18);

    // Texte Schweppes
    const swCx = (swPanX + 2048) / 2;
    bx.font = 'bold 148px Georgia, serif';
    bx.fillStyle = '#1A1A1A';
    bx.textAlign = 'center'; bx.textBaseline = 'middle';
    bx.shadowColor = 'rgba(0,0,0,0.20)'; bx.shadowBlur = 6; bx.shadowOffsetY = 4;
    bx.fillText('Schweppes', swCx, swBandY + swBandH/2);
    bx.shadowBlur = 0; bx.shadowOffsetY = 0;

    // Texte "Official Partner" en dessous
    bx.font = 'italic 34px Georgia, serif';
    bx.fillStyle = 'rgba(245,240,232,0.45)';
    bx.fillText('Official Partner · Roland-Garros', swCx, 375);

    // Petits points décoratifs Schweppes (bulles)
    bx.fillStyle = 'rgba(255,215,0,0.18)';
    for(let i=0; i<18; i++) {
      const bx2 = swPanX + 40 + (i*52)%(2048-swPanX-80);
      const by = 30 + (i*37)%60;
      const br = 4 + (i%3)*3;
      bx.beginPath(); bx.arc(bx2, by, br, 0, Math.PI*2); bx.fill();
      bx.beginPath(); bx.arc(bx2+20, by+390, br*0.7, 0, Math.PI*2); bx.fill();
    }

    const banTex = new THREE.CanvasTexture(banCv);
    banTex.anisotropy = renderer.capabilities.getMaxAnisotropy();
    const banner = new THREE.Mesh(
      new THREE.PlaneGeometry(BW, BH),
      new THREE.MeshLambertMaterial({ map: banTex, side: THREE.FrontSide })
    );
    banner.position.set(0, backH * 0.30, backZ + (zDir < 0 ? 0.05 : -0.05));
    if(zDir < 0) banner.rotation.y = Math.PI;
    group.add(banner);

    // Encadrement métal de la bannière
    const frameTop = new THREE.Mesh(new THREE.BoxGeometry(BW+0.4, 0.18, 0.12), new THREE.MeshLambertMaterial({ color: 0x2A2420 }));
    frameTop.position.set(0, backH*0.30 + BH/2 + 0.09, backZ + (zDir<0?0.06:-0.06));
    if(zDir<0) frameTop.rotation.y=Math.PI;
    group.add(frameTop);
    const frameBot = frameTop.clone();
    frameBot.position.set(0, backH*0.30 - BH/2 - 0.09, backZ + (zDir<0?0.06:-0.06));
    group.add(frameBot);

  } else {
    // Tribunes latérales — mur neutre
    const back = new THREE.Mesh(new THREE.PlaneGeometry(totalW, backH),
      new THREE.MeshLambertMaterial({ color: 0x181010 }));
    back.position.set(0, backH * 0.5, backZ);
    if(zDir < 0) back.rotation.y = Math.PI;
    group.add(back);
  }
}

// ── 4 tribunes — rangées plus espacées pour les grands sièges
const ROW_H = 1.55;   // hauteur rangée (augmentée)
const ROW_D = 1.45;   // profondeur rangée (augmentée)

const standBack = new THREE.Group(); scene.add(standBack);
buildStand(standBack, 14, ROW_H, ROW_D, 60, -14.0, -1, 1);

const standLeft = new THREE.Group(); scene.add(standLeft);
standLeft.rotation.y = Math.PI/2;
buildStand(standLeft, 10, ROW_H, ROW_D, 50, -14.0, -1, 5);

const standRight = new THREE.Group(); scene.add(standRight);
standRight.rotation.y = -Math.PI/2;
buildStand(standRight, 10, ROW_H, ROW_D, 50, -14.0, -1, 13);

// ═══════════════════════════════════════════════════════
//  BALUSTRADES SCHWEPPES — grandes banderoles jaunes devant
//  chaque tribune, comme sur les vrais courts Roland Garros
// ═══════════════════════════════════════════════════════
function makeSchweppesBalustradeTex(W2=2048, H2=256) {
  const cv2 = document.createElement('canvas'); cv2.width=W2; cv2.height=H2;
  const cx2 = cv2.getContext('2d');
  // Fond jaune vif
  const g2 = cx2.createLinearGradient(0,0,0,H2);
  g2.addColorStop(0,'#FFE020'); g2.addColorStop(0.4,'#FFD000'); g2.addColorStop(0.6,'#FFC800'); g2.addColorStop(1,'#FFE020');
  cx2.fillStyle=g2; cx2.fillRect(0,0,W2,H2);
  // Liseré noir haut/bas
  cx2.fillStyle='#1A1A1A'; cx2.fillRect(0,0,W2,12); cx2.fillRect(0,H2-12,W2,12);
  // Texte "Schweppes" répété
  cx2.font='bold 168px Georgia,serif';
  cx2.fillStyle='#1A1A1A';
  cx2.textBaseline='middle';
  cx2.shadowColor='rgba(0,0,0,0.18)'; cx2.shadowBlur=4; cx2.shadowOffsetY=3;
  const text='Schweppes';
  const tw = cx2.measureText(text).width;
  const gap = tw + 80;
  for(let x2=-gap; x2<W2+gap; x2+=gap) {
    cx2.fillText(text, x2, H2/2);
  }
  cx2.shadowBlur=0;
  const t2 = new THREE.CanvasTexture(cv2);
  t2.anisotropy = renderer.capabilities.getMaxAnisotropy();
  t2.wrapS = THREE.RepeatWrapping;
  return t2;
}
const schwBalTex = makeSchweppesBalustradeTex(1024, 256);

// Hauteur du premier gradin (bas des tribunes) ≈ row 0
const BAL_Y = 1.15;  // hauteur balustrade depuis le sol du couloir
const BAL_H = 1.20;  // hauteur de la banderole
const BAL_THICK = 0.18;

function addBalustrade(x, z, w, rotY=0) {
  const mat = new THREE.MeshLambertMaterial({ map: schwBalTex, side: THREE.DoubleSide });
  const m = new THREE.Mesh(new THREE.BoxGeometry(w, BAL_H, BAL_THICK), mat);
  m.position.set(x, BAL_Y, z); m.rotation.y = rotY;
  scene.add(m);
  // Support béton derrière
  const conc = new THREE.Mesh(new THREE.BoxGeometry(w, BAL_H+0.1, 0.25),
    new THREE.MeshLambertMaterial({color:0x7A6E60}));
  conc.position.set(x, BAL_Y, z); conc.rotation.y = rotY;
  scene.add(conc);
}

// Tribune fond (standBack est à z = -14, rotation none)
addBalustrade(0, -14.1, 62, 0);
// Tribune gauche (standLeft rotation.y=PI/2, donc elle s'étend en Z)
addBalustrade(-14.1, 0, 58, Math.PI/2);
// Tribune droite
addBalustrade(14.1, 0, 58, Math.PI/2);

// ── ENCEINTE DU STADE — grand cylindre intérieur qui ferme tous les trous
// Visible uniquement depuis l'intérieur (BackSide), remplace le ciel bleu par du béton sombre

// Cylindre principal (murs arrondis du stade)
const bowl = new THREE.Mesh(
  new THREE.CylinderGeometry(52, 52, 50, 12, 1, true),
  new THREE.MeshLambertMaterial({ color: 0x252018, side: THREE.BackSide })
);
bowl.position.set(0, 22, -4);
scene.add(bowl);

// Disque de plafond (ferme le haut)
const roof = new THREE.Mesh(
  new THREE.CircleGeometry(52, 20),
  new THREE.MeshLambertMaterial({ color: 0x1A1510, side: THREE.BackSide })
);
roof.rotation.x = Math.PI / 2;
roof.position.set(0, 47, -4);
scene.add(roof);

// ── Toits
function addRoof(posX, y, cz, w, d, ry=0) {
  const grp = new THREE.Group(); grp.rotation.y = ry; scene.add(grp);
  const roofMesh = new THREE.Mesh(new THREE.BoxGeometry(w, 0.7, d),
    new THREE.MeshLambertMaterial({ color: 0x7A8888 }));
  roofMesh.position.set(posX, y, cz); grp.add(roofMesh);
  // Visière translucide avant
  const visor = new THREE.Mesh(new THREE.BoxGeometry(w, 0.12, d*0.45),
    new THREE.MeshLambertMaterial({ color: 0xAABBBB, transparent:true, opacity:0.6 }));
  visor.position.set(posX, y-0.32, cz - d*0.72); grp.add(visor);
  // Poutres
  for(let i = -w/2+5; i <= w/2-5; i+=10) {
    const beam = new THREE.Mesh(new THREE.BoxGeometry(0.22,4,0.22),
      new THREE.MeshLambertMaterial({color:0x666666}));
    beam.position.set(i+posX, y-2.2, cz+d/2); grp.add(beam);
  }
}
addRoof(0, 26+ROW_H, -14-15*ROW_D-1, 64, 10, 0);    // fond opposé
addRoof(0, 24+ROW_H, -14-11*ROW_D-1, 60, 10, Math.PI/2); // gauche
addRoof(0, 24+ROW_H,  14+11*ROW_D+1, 60, 10, Math.PI/2); // droite

// ── Murs de clôture bas du court (latéraux + fond uniquement, pas côté caméra)
const fMat = new THREE.MeshLambertMaterial({color:0x28201A});
[[-15.5, 0, 34, 1, Math.PI/2],[15.5, 0, 34, 1, Math.PI/2],
].forEach(([x,z,w,d,ry]) => {
  const f = new THREE.Mesh(new THREE.BoxGeometry(w, 1.25, d), fMat);
  f.position.set(x, 0.62, z); f.rotation.y = ry; f.castShadow = true; scene.add(f);
});

// ═══════════════════════════════════════════════════════
//  BANNIERES SPONSORS — UNIQUEMENT NIVEAU SOL (balustrade)
// ═══════════════════════════════════════════════════════

function addBanner(tex, x, y, z, rotY=0, w=4.8, h=1.15) {
  // Panneau
  const m = new THREE.Mesh(new THREE.PlaneGeometry(w, h),
    new THREE.MeshLambertMaterial({map:tex, side:THREE.DoubleSide}));
  m.position.set(x, y, z); m.rotation.y = rotY; scene.add(m);
}

// ── SPONSORS NIVEAU 2 — haut du 1er gradin (~4.5m) - banderoles Schweppes
// Fond + côtés, répétées comme sur le vrai court
for(let i=0;i<5;i++) addBanner(schwTex, -10+i*5, 4.5, -14.4, 0, 5.2, 1.3);
for(let i=0;i<5;i++) addBanner(schwTex, -14.4, 4.5, -10+i*5, Math.PI/2, 5.2, 1.3);
for(let i=0;i<5;i++) addBanner(schwTex,  14.4, 4.5, -10+i*5, -Math.PI/2, 5.2, 1.3);

// ── Scoreboard LED 3D — mis à jour dynamiquement
const scoreboardCanvas = document.createElement('canvas');
scoreboardCanvas.width = 1024; scoreboardCanvas.height = 512;

function getVerdictColor(speed) {
  if(speed >= 200) return '#FFD040';
  if(speed >= 170) return '#FFA030';
  if(speed >= 140) return '#3EC86A';
  if(speed >= 110) return '#60D0FF';
  return '#F0ECE4';
}

function getVerdictMsg(speed) {
  if(speed >= 210) return 'BOMBE ! 💥';
  if(speed >= 190) return 'FULGURANT !';
  if(speed >= 175) return 'EXPLOSIF !';
  if(speed >= 160) return 'EXCELLENT !';
  if(speed >= 145) return 'TRÈS BIEN !';
  if(speed >= 130) return 'BIEN JOUÉ !';
  if(speed >= 110) return 'EN PUISSANCE';
  return 'BONNE BALLE !';
}

function drawScoreboard(cv, speedVal, verdictVal) {
  const cx = cv.getContext('2d');
  const W = cv.width, H = cv.height;
  cx.clearRect(0,0,W,H);

  // Fond noir profond
  cx.fillStyle = '#050302'; cx.fillRect(0,0,W,H);

  // Grille LED subtile
  cx.fillStyle = 'rgba(255,255,255,0.018)';
  for(let px=0; px<W; px+=8) for(let py=0; py<H; py+=8)
    cx.fillRect(px+3, py+3, 3, 3);

  // Cadre orange
  cx.strokeStyle = '#C4622D'; cx.lineWidth = 14; cx.strokeRect(7,7,W-14,H-14);
  cx.strokeStyle = 'rgba(196,98,45,0.3)'; cx.lineWidth = 3; cx.strokeRect(22,22,W-44,H-44);

  // ── Header RG (bandeau haut)
  const hg = cx.createLinearGradient(0,26,0,110);
  hg.addColorStop(0,'#C04A18'); hg.addColorStop(1,'#7A2808');
  cx.fillStyle=hg; cx.fillRect(22,22,W-44,88);
  cx.shadowColor='#FF5010'; cx.shadowBlur=20;
  cx.font='bold 64px "Bebas Neue",Arial Narrow,sans-serif';
  cx.fillStyle='#FFE8D0'; cx.textAlign='center'; cx.textBaseline='middle';
  cx.fillText('ROLAND-GARROS', W/2, 66);
  cx.shadowBlur=0;

  // Sous-titre court
  cx.fillStyle='rgba(20,8,2,0.85)'; cx.fillRect(22,110,W-44,34);
  cx.font='500 22px "Barlow Condensed",monospace';
  cx.fillStyle='rgba(245,200,160,0.5)'; cx.textBaseline='middle';
  cx.fillText('COURT PHILIPPE-CHATRIER', W/2, 127);

  // Séparateur pointillé
  for(let px=30; px<W-30; px+=10) {
    cx.fillStyle=px%20===0 ? 'rgba(196,98,45,0.7)' : 'rgba(196,98,45,0.2)';
    cx.fillRect(px, 150, 6, 4);
  }

  // ── Label VITESSE centré
  cx.font='bold 28px monospace';
  cx.fillStyle='rgba(245,200,140,0.38)'; cx.textBaseline='middle'; cx.textAlign='center';
  cx.fillText('VITESSE', W/2, 190);

  // ── Valeur LED géante centrée
  cx.font='bold 190px "Bebas Neue",monospace';
  cx.textAlign='center'; cx.textBaseline='middle';
  if(speedVal !== '--') {
    cx.fillStyle='#FFD060'; cx.shadowColor='#FFA010'; cx.shadowBlur=40;
    cx.fillText(speedVal, W/2, 285);
    cx.shadowBlur=20; cx.fillText(speedVal, W/2, 285);
  } else {
    cx.fillStyle='rgba(245,200,140,0.15)';
    cx.fillText('--', W/2, 285);
  }
  cx.shadowBlur=0;

  // Unité km/h centrée
  cx.font='bold 28px monospace'; cx.fillStyle='rgba(245,200,140,0.32)';
  cx.textBaseline='middle'; cx.textAlign='center';
  cx.fillText('km/h', W/2, 390);

  // Séparateur bas
  for(let px=30; px<W-30; px+=10) {
    cx.fillStyle=px%20===0 ? 'rgba(196,98,45,0.6)' : 'rgba(196,98,45,0.18)';
    cx.fillRect(px, 412, 6, 4);
  }

  // ── Message / footer
  if(verdictVal && verdictVal !== '') {
    const msgColor = speedVal !== '--' ? getVerdictColor(parseInt(speedVal)) : '#F0ECE4';
    cx.font='bold 58px "Bebas Neue",Arial Narrow,sans-serif';
    cx.textAlign='center'; cx.textBaseline='middle';
    cx.fillStyle=msgColor; cx.shadowColor=msgColor; cx.shadowBlur=28;
    cx.fillText(verdictVal, W/2, 464);
    cx.shadowBlur=0;
  } else {
    cx.font='italic 24px monospace';
    cx.fillStyle='rgba(245,200,140,0.22)'; cx.textAlign='center'; cx.textBaseline='middle';
    cx.fillText(speedVal==='--' ? '▶  PRÊT À SERVIR  ◀' : '◀  PROCHAIN SERVICE  ▶', W/2, 464);
  }
}

drawScoreboard(scoreboardCanvas, '--', '');
const scoreboardTex3D = new THREE.CanvasTexture(scoreboardCanvas);

const scoreboardMesh = new THREE.Mesh(
  new THREE.BoxGeometry(22, 11, 0.3),
  new THREE.MeshBasicMaterial({ map: scoreboardTex3D })
);
scoreboardMesh.position.set(0, 13, -16.0); scene.add(scoreboardMesh);
// Cadre métal
const sbFrame = new THREE.Mesh(new THREE.BoxGeometry(23.4, 12.4, 0.25),
  new THREE.MeshLambertMaterial({ color: 0x0A0806 }));
sbFrame.position.set(0, 13, -16.2); scene.add(sbFrame);
// Pieds du scoreboard
[-9.5, 9.5].forEach(x => {
  const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.18,0.22,5,8),
    new THREE.MeshLambertMaterial({color:0x222018}));
  leg.position.set(x, 6.5, -16.0); scene.add(leg);
});

// Fonction de mise à jour du scoreboard 3D
function updateScoreboard3D(speed, verdict) {
  drawScoreboard(scoreboardCanvas, speed, verdict);
  scoreboardTex3D.needsUpdate = true;
}

// ── Sol terre battue marron foncé — un seul grand plan couvrant tout l'espace visible
const surroundMat = new THREE.MeshPhongMaterial({
  color: 0x381408,
  emissive: 0x0A0402,
  specular: 0x0A0402,
  shininess: 3
});
const groundFull = new THREE.Mesh(new THREE.PlaneGeometry(120, 120), surroundMat);
groundFull.rotation.x = -Math.PI/2;
groundFull.position.set(0, 0.001, 0);
groundFull.receiveShadow = true;
scene.add(groundFull);

// ═══════════════════════════════════════════════════════
//  NADAL 3D — morphologie, tenue, détails signature
// ═══════════════════════════════════════════════════════
const playerGroup = new THREE.Group();
// Joueur derrière la ligne de fond (baseline z=13.5), position service réaliste
playerGroup.position.set(-1.2, 0, 14.7);
playerGroup.rotation.y = 0.10;
playerGroup.scale.setScalar(1.32);
scene.add(playerGroup);

// ── MATÉRIAUX NADAL — MeshPhongMaterial pour réalisme peau/vêtements
// Peau bronzée méditerranéenne avec subsurface scattering simulé
const skinMat   = new THREE.MeshPhongMaterial({color:0xC8906A, specular:0x3A1808, shininess:28, emissive:0x1A0800});
const skinDarkMat= new THREE.MeshPhongMaterial({color:0xA87050, specular:0x220C04, shininess:15, emissive:0x0D0400});
// Maillot Nike sans manches bleu marine avec micro-brillance tissu
const shirtMat  = new THREE.MeshPhongMaterial({color:0x08183A, specular:0x101830, shininess:12, emissive:0x020610});
const shirtAccMat= new THREE.MeshPhongMaterial({color:0x1A3A6A, specular:0x0A1A38, shininess:10});
// Short blanc avec légère brillance polyester
const shortMat  = new THREE.MeshPhongMaterial({color:0xEEECE4, specular:0x303028, shininess:22, emissive:0x050504});
const shortBandMat= new THREE.MeshPhongMaterial({color:0x0A1A3A, specular:0x080C18, shininess:8});
// Bandeau blanc signature Nadal
const bandMat   = new THREE.MeshPhongMaterial({color:0xF0EEE8, specular:0x282820, shininess:18});
// Cheveux noirs légèrement brillants
const hairMat   = new THREE.MeshPhongMaterial({color:0x100C06, specular:0x1A1208, shininess:45, emissive:0x040200});
// Raquette Babolat Pure Aero (jaune/noir signature Nadal)
const racketFrameMat = new THREE.MeshPhongMaterial({color:0xC8A800, specular:0x605000, shininess:60});
const racketFrameDarkMat = new THREE.MeshPhongMaterial({color:0x181818, specular:0x404040, shininess:35});
const stringMat = new THREE.MeshLambertMaterial({color:0xF0F080, transparent:true, opacity:0.85});
const gripMat   = new THREE.MeshPhongMaterial({color:0xF0EEE6, specular:0x181816, shininess:8});
// Chaussures Nike — matériaux améliorés (déclarés ici pour cohérence)
const shoeMat = new THREE.MeshPhongMaterial({color:0xF0EEE6, specular:0x998877, shininess:55});
const soleMat = new THREE.MeshPhongMaterial({color:0xC85020, specular:0x442010, shininess:30});

function cyl(rt,rb,h,mat,s=10){ const m=new THREE.Mesh(new THREE.CylinderGeometry(rt,rb,h,s,1),mat); m.castShadow=true; return m; }
function sph(r,mat,sw=10,sh=8){ const m=new THREE.Mesh(new THREE.SphereGeometry(r,sw,sh),mat); m.castShadow=true; return m; }

// Crée un cylindre organique avec renflement central (muscle)
function muscle(rtop,rmid,rbot,h,mat,s=10){
  const geo=new THREE.CylinderGeometry(rtop,rbot,h,s,2);
  const pos=geo.attributes.position;
  for(let i=0;i<pos.count;i++){
    const y=pos.getY(i)/h*2; // [-1,1]
    const bulge=1+0.18*Math.max(0,1-y*y*4); // renflement au centre
    pos.setX(i,pos.getX(i)*bulge);
    pos.setZ(i,pos.getZ(i)*bulge);
  }
  pos.needsUpdate=true; geo.computeVertexNormals();
  const m=new THREE.Mesh(geo,mat); m.castShadow=true; return m;
}

// ── HIÉRARCHIE COMPLÈTE : bassin → cuisse → genou → tibia → cheville → pied
// Bassin (pivot central des hanches)
const hipsG = new THREE.Group(); hipsG.position.set(0,0.96,0); playerGroup.add(hipsG);

// Jambe gauche — pivot hanche
const hipLG = new THREE.Group(); hipLG.position.set(-0.13,-0.02,0); hipsG.add(hipLG);
const thighL=muscle(0.100,0.110,0.082,0.40,skinMat,14); thighL.position.set(0,-0.20,0); hipLG.add(thighL);
const kneeL=sph(0.086,skinMat,12,10); kneeL.position.set(0,-0.41,0); hipLG.add(kneeL);
// Pivot genou gauche
const kneeLG=new THREE.Group(); kneeLG.position.set(0,-0.41,0); hipLG.add(kneeLG);
const shinL=muscle(0.082,0.076,0.055,0.36,skinMat,14); shinL.position.set(0,-0.18,0); kneeLG.add(shinL);
const ankleL=sph(0.055,skinMat,10,8); ankleL.position.set(0,-0.37,0); kneeLG.add(ankleL);
// Pivot cheville gauche → pied
const ankleLG=new THREE.Group(); ankleLG.position.set(0,-0.37,0); kneeLG.add(ankleLG);

// Jambe droite — pivot hanche
const hipRG = new THREE.Group(); hipRG.position.set(0.13,-0.02,0.04); hipsG.add(hipRG);
const thighR=muscle(0.100,0.110,0.082,0.40,skinMat,14); thighR.position.set(0,-0.20,0); hipRG.add(thighR);
const kneeR=sph(0.086,skinMat,12,10); kneeR.position.set(0,-0.41,0); hipRG.add(kneeR);
// Pivot genou droit
const kneeRG=new THREE.Group(); kneeRG.position.set(0,-0.41,0); hipRG.add(kneeRG);
const shinR=muscle(0.082,0.076,0.055,0.36,skinMat,14); shinR.position.set(0,-0.18,0); kneeRG.add(shinR);
const ankleR=sph(0.055,skinMat,10,8); ankleR.position.set(0,-0.37,0); kneeRG.add(ankleR);
const ankleRG=new THREE.Group(); ankleRG.position.set(0,-0.37,0); kneeRG.add(ankleRG);

// Chaussettes blanches hautes (caractéristique Nadal)
const sockL=cyl(0.072,0.066,0.28,new THREE.MeshLambertMaterial({color:0xF5F5F5}),12); sockL.position.set(0,-0.06,0); kneeLG.add(sockL);
const sockR=cyl(0.072,0.066,0.28,new THREE.MeshLambertMaterial({color:0xF5F5F5}),12); sockR.position.set(0,-0.06,0); kneeRG.add(sockR);

// Chaussures Nike — forme réaliste

function makeShoe(mirrorX) {
  const grp = new THREE.Group();
  const mx = mirrorX ? -1 : 1;
  // Corps principal allongé
  const body=new THREE.Mesh(new THREE.SphereGeometry(1,14,10),shoeMat);
  body.scale.set(0.095,0.055,0.175); body.position.set(mx*0.005,-0.01,0.08); grp.add(body);
  // Bout arrondi légèrement relevé
  const toe=new THREE.Mesh(new THREE.SphereGeometry(1,12,8),shoeMat);
  toe.scale.set(0.078,0.048,0.088); toe.position.set(mx*0.004,0.006,0.185); grp.add(toe);
  // Talon
  const heel=new THREE.Mesh(new THREE.SphereGeometry(1,10,8),shoeMat);
  heel.scale.set(0.082,0.052,0.078); heel.position.set(0,-0.004,-0.055); grp.add(heel);
  // Semelle plate
  const sole=new THREE.Mesh(new THREE.BoxGeometry(0.170,0.024,0.340),soleMat);
  sole.position.set(0,-0.056,0.065); grp.add(sole);
  // Arrondi avant semelle
  const stoe=new THREE.Mesh(new THREE.SphereGeometry(1,10,6),soleMat);
  stoe.scale.set(0.082,0.022,0.088); stoe.position.set(0,-0.056,0.205); grp.add(stoe);
  // Swoosh Nike simplifié
  const sw=new THREE.Mesh(new THREE.PlaneGeometry(0.10,0.034),new THREE.MeshLambertMaterial({color:0xCC3300,side:THREE.DoubleSide}));
  sw.position.set(mx*0.097,0.008,0.10); sw.rotation.y=mx*Math.PI/2; sw.rotation.z=0.25; grp.add(sw);
  return grp;
}
const shoeL=makeShoe(false); shoeL.position.set(0,-0.365,0); ankleLG.add(shoeL);
const shoeR=makeShoe(true);  shoeR.position.set(0,-0.365,0); ankleRG.add(shoeR);

// ── SHORT — cylindre organique
const shortGeo = new THREE.CylinderGeometry(0.215,0.240,0.31,16,4);
const shortPos=shortGeo.attributes.position;
for(let i=0;i<shortPos.count;i++) shortPos.setZ(i,shortPos.getZ(i)*0.78);
shortPos.needsUpdate=true; shortGeo.computeVertexNormals();
const short=new THREE.Mesh(shortGeo,shortMat); short.position.set(0,0,0); short.castShadow=true; hipsG.add(short);
const bandShortL=cyl(0.007,0.007,0.32,shortBandMat,6); bandShortL.position.set(-0.215,0,0); hipsG.add(bandShortL);
const bandShortR=cyl(0.007,0.007,0.32,shortBandMat,6); bandShortR.position.set( 0.215,0,0); hipsG.add(bandShortR);

// ── TORSE avec pivot séparé pour rotation indépendante hanche/épaules
const torsoG=new THREE.Group(); torsoG.position.set(0,0.34,0); hipsG.add(torsoG);
// Corps du torse
const torsoGeo=new THREE.CylinderGeometry(0.21,0.255,0.62,16,6);
const tPos=torsoGeo.attributes.position;
for(let i=0;i<tPos.count;i++) tPos.setZ(i,tPos.getZ(i)*0.60);
tPos.needsUpdate=true; torsoGeo.computeVertexNormals();
const torso=new THREE.Mesh(torsoGeo,shirtMat); torso.castShadow=true; torsoG.add(torso);
// Cage thoracique (renflement pectoraux)
const pecL=sph(0.115,shirtMat,10,8); pecL.scale.set(1.0,0.65,0.5); pecL.position.set(-0.11,0.12,-0.11); torsoG.add(pecL);
const pecR=sph(0.115,shirtMat,10,8); pecR.scale.set(1.0,0.65,0.5); pecR.position.set( 0.11,0.12,-0.11); torsoG.add(pecR);
// Bandes latérales shirt
const tBandL=cyl(0.008,0.008,0.63,shirtAccMat,6); tBandL.position.set(-0.218,0,0); torsoG.add(tBandL);
const tBandR=cyl(0.008,0.008,0.63,shirtAccMat,6); tBandR.position.set( 0.218,0,0); torsoG.add(tBandR);
// Col V
const collar=new THREE.Mesh(new THREE.TorusGeometry(0.072,0.016,8,16,Math.PI*1.1),
  new THREE.MeshLambertMaterial({color:0x1A3A6A}));
collar.position.set(0,0.22,-0.10); collar.rotation.x=-0.3; torsoG.add(collar);
// Logo Nike
const nikeLogo=new THREE.Mesh(new THREE.PlaneGeometry(0.09,0.038),
  new THREE.MeshLambertMaterial({color:0xF5F0E8,side:THREE.DoubleSide}));
nikeLogo.position.set(-0.12,0.10,-0.148); torsoG.add(nikeLogo);

// Épaules arrondies
const shoulPadMat=new THREE.MeshLambertMaterial({color:0x0A1A3A});
const sholL=sph(0.115,shoulPadMat,12,10); sholL.scale.set(1.15,0.72,0.82); sholL.position.set(-0.265,0.22,0); torsoG.add(sholL);
const sholR=sph(0.115,shoulPadMat,12,10); sholR.scale.set(1.15,0.72,0.82); sholR.position.set( 0.265,0.22,0); torsoG.add(sholR);

// ── COU
const neckG=new THREE.Group(); neckG.position.set(0,0.32,0); torsoG.add(neckG);
const neck=cyl(0.066,0.078,0.18,skinMat,12); neck.position.set(0,0.09,0); neckG.add(neck);
// Trapèzes musclés
const trapL=new THREE.Mesh(new THREE.SphereGeometry(0.085,10,8),shirtMat); trapL.scale.set(1.0,0.5,0.7); trapL.position.set(-0.11,0.04,0); neckG.add(trapL);
const trapR=new THREE.Mesh(new THREE.SphereGeometry(0.085,10,8),shirtMat); trapR.scale.set(1.0,0.5,0.7); trapR.position.set( 0.11,0.04,0); neckG.add(trapR);

// ── TÊTE
const headG=new THREE.Group(); headG.position.set(0,0.20,0); neckG.add(headG);
const head=sph(0.185,skinMat,20,18); head.scale.set(1.0,1.06,0.93); head.position.set(0,0.01,0); headG.add(head);
// Mâchoire arrondie
const jaw=new THREE.Mesh(new THREE.CylinderGeometry(0.118,0.098,0.12,14,3),skinMat); jaw.position.set(0,-0.13,-0.01); jaw.castShadow=true; headG.add(jaw);
const chin=sph(0.090,skinMat,10,8); chin.scale.set(1.1,0.52,0.78); chin.position.set(0,-0.19,-0.05); headG.add(chin);

// ── CHEVEUX
const hairBack=sph(0.172,hairMat,14,12); hairBack.scale.set(1.05,0.62,0.66); hairBack.position.set(0,0.10,0.09); headG.add(hairBack);
const hairTop=sph(0.178,hairMat,14,10); hairTop.scale.set(1.0,0.50,0.92); hairTop.position.set(0,0.15,0.02); headG.add(hairTop);
const hairSideL=sph(0.074,hairMat,10,8); hairSideL.scale.set(0.48,0.92,0.84); hairSideL.position.set(-0.182,0.07,0.01); headG.add(hairSideL);
const hairSideR=sph(0.074,hairMat,10,8); hairSideR.scale.set(0.48,0.92,0.84); hairSideR.position.set( 0.182,0.07,0.01); headG.add(hairSideR);
const nape=sph(0.10,hairMat,10,8); nape.scale.set(1.28,0.54,0.58); nape.position.set(0,-0.10,0.14); headG.add(nape);

// ── BANDEAU BLANC
const headband=cyl(0.190,0.190,0.068,bandMat,22); headband.rotation.x=Math.PI/2; headband.position.set(0,0.04,-0.01); headG.add(headband);
const bandKnot=sph(0.038,bandMat,8,6); bandKnot.position.set(0,0.04,0.21); headG.add(bandKnot);
const bandTailL=cyl(0.011,0.009,0.13,bandMat,6); bandTailL.position.set(-0.038,0.022,0.265); bandTailL.rotation.x=0.38; headG.add(bandTailL);
const bandTailR=cyl(0.011,0.009,0.13,bandMat,6); bandTailR.position.set( 0.038,0.022,0.265); bandTailR.rotation.x=-0.12; headG.add(bandTailR);

// ── OREILLES
const earL=sph(0.038,skinMat,8,6); earL.scale.set(0.50,0.80,0.38); earL.position.set(-0.195,-0.02,0.03); headG.add(earL);
const earR=sph(0.038,skinMat,8,6); earR.scale.set(0.50,0.80,0.38); earR.position.set( 0.195,-0.02,0.03); headG.add(earR);

// ── VISAGE — détails réalistes
// Sourcils noirs prononcés
const browMat = new THREE.MeshPhongMaterial({color:0x0E0A06, shininess:5});
const browL = new THREE.Mesh(new THREE.BoxGeometry(0.072,0.014,0.025), browMat);
browL.position.set(-0.068, 0.095, -0.158); browL.rotation.z = 0.10; headG.add(browL);
const browR = new THREE.Mesh(new THREE.BoxGeometry(0.072,0.014,0.025), browMat);
browR.position.set( 0.068, 0.095, -0.158); browR.rotation.z = -0.10; headG.add(browR);

// Rides frontales (légères)
const rideMat = new THREE.MeshLambertMaterial({color:0xB07050, transparent:true, opacity:0.3});
for(let i=0;i<3;i++) {
  const ride=new THREE.Mesh(new THREE.BoxGeometry(0.10+i*0.02, 0.006, 0.008), rideMat);
  ride.position.set(0, 0.12+(i*0.018), -0.162); headG.add(ride);
}

// Yeux — blancs, iris, pupilles
const eyeWhiteMat = new THREE.MeshPhongMaterial({color:0xF0EEE8, specular:0xFFFFFF, shininess:60});
const irisMat = new THREE.MeshPhongMaterial({color:0x3A2808, specular:0x202020, shininess:40});
const pupilMat = new THREE.MeshLambertMaterial({color:0x030200});
const eyeShine = new THREE.MeshPhongMaterial({color:0xFFFFFF, emissive:0xFFFFFF, specular:0xFFFFFF, shininess:100, transparent:true, opacity:0.85});

function makeEye(side) {
  const g = new THREE.Group();
  const white = new THREE.Mesh(new THREE.SphereGeometry(0.030, 12, 10), eyeWhiteMat);
  white.scale.set(1, 0.82, 0.55); g.add(white);
  const iris = new THREE.Mesh(new THREE.CircleGeometry(0.018, 12), irisMat);
  iris.position.z = -0.016; g.add(iris);
  const pupil = new THREE.Mesh(new THREE.CircleGeometry(0.010, 10), pupilMat);
  pupil.position.z = -0.017; g.add(pupil);
  const shine = new THREE.Mesh(new THREE.CircleGeometry(0.005, 8), eyeShine);
  shine.position.set(0.006, 0.006, -0.018); g.add(shine);
  // Paupière supérieure
  const lid = new THREE.Mesh(new THREE.SphereGeometry(0.031,10,6,0,Math.PI*2,0,Math.PI/2), skinMat);
  lid.scale.set(1, 0.55, 0.55); lid.position.y=0.002; g.add(lid);
  return g;
}
const eyeL = makeEye(-1); eyeL.position.set(-0.070, 0.040, -0.162); eyeL.rotation.y=-0.12; headG.add(eyeL);
const eyeR = makeEye( 1); eyeR.position.set( 0.070, 0.040, -0.162); eyeR.rotation.y= 0.12; headG.add(eyeR);

// Nez — forme réaliste
const noseBridge = new THREE.Mesh(new THREE.SphereGeometry(0.018,8,6), new THREE.MeshPhongMaterial({color:0xBE8560, specular:0x1A0A04, shininess:12}));
noseBridge.scale.set(0.6, 1.8, 0.7); noseBridge.position.set(0, 0.002, -0.168); headG.add(noseBridge);
const noseTip = new THREE.Mesh(new THREE.SphereGeometry(0.026,10,8), skinMat);
noseTip.scale.set(1.1, 0.7, 0.85); noseTip.position.set(0, -0.028, -0.175); headG.add(noseTip);
const nostrilL = new THREE.Mesh(new THREE.SphereGeometry(0.014,8,6), skinDarkMat);
nostrilL.scale.set(0.6,0.55,0.70); nostrilL.position.set(-0.022,-0.035,-0.168); headG.add(nostrilL);
const nostrilR = new THREE.Mesh(new THREE.SphereGeometry(0.014,8,6), skinDarkMat);
nostrilR.scale.set(0.6,0.55,0.70); nostrilR.position.set( 0.022,-0.035,-0.168); headG.add(nostrilR);

// Bouche — lèvres
const lipMat = new THREE.MeshPhongMaterial({color:0xA06045, specular:0x1A0A06, shininess:18});
const upperLip = new THREE.Mesh(new THREE.SphereGeometry(0.042,10,6), lipMat);
upperLip.scale.set(1.1, 0.35, 0.55); upperLip.position.set(0,-0.060,-0.162); headG.add(upperLip);
const lowerLip = new THREE.Mesh(new THREE.SphereGeometry(0.042,10,6), lipMat);
lowerLip.scale.set(1.0, 0.40, 0.55); lowerLip.position.set(0,-0.082,-0.158); headG.add(lowerLip);
// Sillon philtrum
const philtrum = new THREE.Mesh(new THREE.SphereGeometry(0.012,6,4), skinDarkMat);
philtrum.scale.set(0.6,1.5,0.4); philtrum.position.set(0,-0.045,-0.170); headG.add(philtrum);

// Barbe de 3 jours — zone joues/menton avec matériau semi-transparent
const stubble = new THREE.Mesh(new THREE.SphereGeometry(0.178,16,12), new THREE.MeshLambertMaterial({color:0x1A1208, transparent:true, opacity:0.22}));
stubble.scale.set(1.0, 0.55, 0.85); stubble.position.set(0,-0.07,0); headG.add(stubble);

// ── BRAS GAUCHE (lanceur de balle) — hiérarchie épaule→bras→coude→avant-bras→poignet
const shoulderPivotL=new THREE.Group(); shoulderPivotL.position.set(-0.265,0.22,0); torsoG.add(shoulderPivotL);
const armLG=new THREE.Group(); shoulderPivotL.add(armLG);
const shoulderBallL=sph(0.098,skinMat,12,10); shoulderBallL.scale.set(1.0,0.86,0.84); armLG.add(shoulderBallL);
const bicepL=muscle(0.088,0.096,0.072,0.36,skinMat,14); bicepL.position.set(0,-0.20,0); armLG.add(bicepL);
const elbowLG=new THREE.Group(); elbowLG.position.set(0,-0.40,0); armLG.add(elbowLG);
const elbowBumpL=sph(0.066,skinMat,10,8); elbowBumpL.position.set(0,0,0); elbowLG.add(elbowBumpL);
const forearmLG=new THREE.Group(); forearmLG.position.set(0,-0.01,0); elbowLG.add(forearmLG);
const forearmL=muscle(0.075,0.068,0.054,0.31,skinMat,14); forearmL.position.set(0,-0.155,0); forearmLG.add(forearmL);
const wristLG=new THREE.Group(); wristLG.position.set(0,-0.32,0); forearmLG.add(wristLG);
const handL=sph(0.058,skinMat,10,8); handL.scale.set(1.08,0.82,0.68); wristLG.add(handL);
// Tatouage
const tattoo1=new THREE.Mesh(new THREE.CylinderGeometry(0.076,0.076,0.05,14,2), new THREE.MeshLambertMaterial({color:0x2A3A5A, transparent:true, opacity:0.7})); tattoo1.position.set(0,-0.12,0); elbowLG.add(tattoo1);

// ── BRAS DROIT (bras raquette) — même hiérarchie + poignet
const shoulderPivotR=new THREE.Group(); shoulderPivotR.position.set(0.265,0.22,0); torsoG.add(shoulderPivotR);
const armRG=new THREE.Group(); shoulderPivotR.add(armRG);
const shoulderBallR=sph(0.094,skinMat,12,10); shoulderBallR.scale.set(1.0,0.86,0.84); armRG.add(shoulderBallR);
const bicepR=muscle(0.082,0.090,0.066,0.34,skinMat,14); bicepR.position.set(0,-0.19,0); armRG.add(bicepR);
const elbowRG=new THREE.Group(); elbowRG.position.set(0,-0.37,0); armRG.add(elbowRG);
const elbowBumpR=sph(0.060,skinMat,10,8); elbowBumpR.position.set(0,0,0); elbowRG.add(elbowBumpR);
const forearmRG=new THREE.Group(); forearmRG.position.set(0,-0.01,0); elbowRG.add(forearmRG);
const forearmR=muscle(0.068,0.060,0.050,0.28,skinMat,14); forearmR.position.set(0,-0.14,0); forearmRG.add(forearmR);
const wristRG=new THREE.Group(); wristRG.position.set(0,-0.29,0); forearmRG.add(wristRG);
const handR=sph(0.054,skinMat,10,8); handR.scale.set(1.08,0.82,0.68); wristRG.add(handR);

// ── RAQUETTE BABOLAT PURE AERO — attachée au poignet
const raqG=new THREE.Group(); raqG.position.set(0,-0.12,0); wristRG.add(raqG);

// Grip blanc Babolat
const grip=cyl(0.030,0.026,0.26,gripMat,10); grip.position.set(0,-0.13,0); raqG.add(grip);
const gripEnd=cyl(0.037,0.030,0.038,racketFrameDarkMat,8); gripEnd.position.set(0,-0.27,0); raqG.add(gripEnd);
// Manche jaune
const shaft=cyl(0.022,0.022,0.16,racketFrameMat,8); shaft.position.set(0,-0.38,0); raqG.add(shaft);
// Gorge en V
const throatL=new THREE.Mesh(new THREE.BoxGeometry(0.046,0.16,0.022),racketFrameMat); throatL.position.set(-0.058,-0.49,0); throatL.rotation.z=-0.32; raqG.add(throatL);
const throatR=new THREE.Mesh(new THREE.BoxGeometry(0.046,0.16,0.022),racketFrameMat); throatR.position.set( 0.058,-0.49,0); throatR.rotation.z= 0.32; raqG.add(throatR);
// Cadre ovale
const frame=new THREE.Mesh(new THREE.TorusGeometry(0.200,0.024,7,28),racketFrameMat);
frame.position.set(0,-0.670,0); frame.scale.set(1,1.30,0.28); raqG.add(frame);
const frameDark=new THREE.Mesh(new THREE.TorusGeometry(0.200,0.012,5,28),racketFrameDarkMat);
frameDark.position.set(0,-0.670,0.009); frameDark.scale.set(1,1.30,0.18); raqG.add(frameDark);
// Cordes
for(let i=-4;i<=4;i++){
  const sc=cyl(0.005,0.005,0.48,stringMat,4); sc.position.set(i*0.044,-0.670,0); raqG.add(sc);
  const sh=cyl(0.005,0.005,0.36,stringMat,4); sh.rotation.z=Math.PI/2; sh.position.set(0,-0.670+i*0.054,0); raqG.add(sh);
}

// ── BALLE avec matériau amélioré
const ballMesh=new THREE.Mesh(new THREE.SphereGeometry(0.065,22,18), new THREE.MeshPhongMaterial({
  color:0xBFDE5A, specular:0x446600, shininess:55, emissive:0x101800
}));
ballMesh.castShadow=true; ballMesh.visible=false; scene.add(ballMesh);

// Ombre portée de la balle (disc sous la balle)
const ballShadow=new THREE.Mesh(new THREE.CircleGeometry(0.12,16), new THREE.MeshLambertMaterial({color:0x000000,transparent:true,opacity:0.35,depthWrite:false}));
ballShadow.rotation.x=-Math.PI/2; ballShadow.position.y=0.004; ballShadow.visible=false; scene.add(ballShadow);

// Trail amélioré — plus large, dégradé lumineux
const TRAIL=8;
const trailM=[];
for(let i=0;i<TRAIL;i++){
  const tm=new THREE.Mesh(new THREE.SphereGeometry(0.042,7,6),
    new THREE.MeshLambertMaterial({color:0xE0F060,transparent:true,opacity:0,emissive:0x202800}));
  tm.visible=false; scene.add(tm); trailM.push(tm);
}
const trailPos=[];

// Poussière — pool pré-alloué
const DUST_POOL_SIZE = 28;
const _dustGeo = new THREE.SphereGeometry(0.055, 4, 3);
const dustPool = [];
const dustMeshes = [];
for(let i = 0; i < DUST_POOL_SIZE; i++) {
  const m = new THREE.Mesh(_dustGeo, new THREE.MeshLambertMaterial({color:new THREE.Color().setHSL(0.05,0.60,0.40),transparent:true,opacity:0}));
  m.visible = false; scene.add(m); dustPool.push(m);
}
function spawnDust(pos, count=24, spread=1.0) {
  let spawned = 0;
  for(let i = 0; i < dustPool.length && spawned < count; i++) {
    const m = dustPool[i]; if(m.visible) continue;
    m.visible = true; m.position.copy(pos);
    m.position.x += (Math.random()-.5)*0.15; m.position.z += (Math.random()-.5)*0.15;
    m.scale.setScalar(0.4 + Math.random() * 0.9); m.material.opacity = 0.85;
    const a = Math.random()*Math.PI*2, sp = (0.4+Math.random()*2.2)*spread;
    dustMeshes.push({mesh:m, vx:Math.cos(a)*sp*0.06, vy:(0.5+Math.random()*1.8)*0.06, vz:Math.sin(a)*sp*0.06, life:1});
    spawned++;
  }
}

// ═══════════════════════════════════════════════════════
//  ANIMATION — cinématique inverse + physique balle
// ═══════════════════════════════════════════════════════
let phase='idle', phaseT=0;
const PDUR={wind:.61,toss:.51,swing:.40,ballfly:.91,bounce:.57,done:999};
const PORD=['idle','wind','toss','swing','ballfly','bounce','done'];

let force=62;
let bounceDone=false, scoreShown=false, flashPow=0;

// ── POINTS DE TRAJECTOIRE (world space, joueur en -1.2,0,14.8 scale 1.32)
const TP={
  tossStart: new THREE.Vector3(-2.75, 1.90, 13.90),
  tossPeak:  new THREE.Vector3(-2.55, 3.70, 13.55),
  serveHit:  new THREE.Vector3(-2.00, 3.55, 13.20),
  netCross:  new THREE.Vector3(-1.00, 1.05, 0.05),
  bounce:    new THREE.Vector3( 2.60, 0.065,-10.40),
  rest:      new THREE.Vector3( 2.85, 0.065,-11.10),
};

function bez3(p0,p1,p2,t){
  const v=new THREE.Vector3;
  const a=v.clone().lerpVectors(p0,p1,t), b=v.clone().lerpVectors(p1,p2,t);
  return v.lerpVectors(a,b,t);
}
function eOut(t){ return 1-Math.pow(1-t,3); }
function eOut2(t){ return 1-Math.pow(1-t,2); }
function eIn(t){ return t*t*t; }
function eInQ(t){ return t*t; }
function clamp(v,a,b){ return Math.max(a,Math.min(b,v)); }
function sMix(a,b,t){ const c=clamp(t,0,1); const e=c<.5?2*c*c:-1+(4-2*c)*c; return a+(b-a)*e; }

function updatePlayer(){
  const eo=eOut(clamp(phaseT,0,1));

  // Angles articulaires — convention cinématique
  let hipsRotY=0.15;    // rotation bassin
  let torsoTwist=0;     // rotation torse sur bassin (Y)
  let torsoLean=0;      // inclinaison torse (Z)
  let torsoArch=0;      // extension/flexion torse (X)
  let hipLiftR=0; // levée hanche droite (follow-through)
  let kneeLx=0, kneeRx=0; // flexion genoux
  let tiptoe=0;
  let headLookUp=0;      // tête regarde la balle vers le haut
  let headTurnY=0;       // tête tourne pour regarder la balle/filet

  // Bras gauche (lanceur)
  let aLx=0, aLz=0, eLx=0, wLx=0;
  // Bras droit (raquette) — angles en monde local torso
  let aRx=0, aRz=0, eRx=0, fRx=0, wRx=0, wRz=0;

  if(phase==='idle'){
    const t=Date.now()*0.001;
    hipsRotY=0.15+Math.sin(t*0.3)*0.018;
    torsoLean=Math.sin(t*0.28)*0.018;
    torsoArch=Math.sin(t*0.22)*0.008; // légère respiration
    aRx=Math.sin(t*0.5)*0.05;
    aLx=Math.sin(t*0.45+0.5)*0.04;
    headTurnY=-0.08; // regarde vers le filet
    headLookUp=-0.04;

  } else if(phase==='wind'){
    // Préparation : rotation coté dominant, transfert de poids, genoux fléchis
    hipsRotY=sMix(0.15,0.55,phaseT);
    torsoTwist=sMix(0,0.20,phaseT);
    torsoLean=sMix(0,-0.26,phaseT);
    torsoArch=sMix(0,-0.10,phaseT);
    kneeLx=sMix(0,-0.28,phaseT); // genou gauche fléchi (transfert poids)
    kneeRx=sMix(0, 0.15,phaseT);
    // Bras droit monte derrière (position trophée early)
    aRx=sMix(0,-0.70,phaseT); aRz=sMix(0,-0.42,phaseT); eRx=sMix(0,0.90,phaseT);
    // Bras gauche se prépare, balle tenue devant
    aLx=sMix(0, 0.25,phaseT); aLz=sMix(0, 0.18,phaseT);
    headTurnY=sMix(-0.08,0.10,phaseT); // regarde la zone de service
    headLookUp=sMix(-0.04,-0.08,phaseT);

  } else if(phase==='toss'){
    hipsRotY=sMix(0.55,0.65,phaseT);
    torsoTwist=sMix(0.20,0.26,phaseT);
    torsoLean=sMix(-0.26,-0.12,phaseT);
    torsoArch=sMix(-0.10,-0.16,phaseT);
    tiptoe=sMix(0,0.22,phaseT); // montée sur la pointe des pieds
    kneeLx=sMix(-0.28,-0.08,phaseT); // extension pour monter
    // Bras gauche monte : lancer de balle vers le haut
    aLx=sMix(0.25,2.10,phaseT); aLz=sMix(0.18,-0.06,phaseT);
    eLx=sMix(0,-0.45,phaseT); wLx=sMix(0,0.25,phaseT);
    // Bras droit continue à monter derrière la tête (trophée complet)
    aRx=sMix(-0.70,-1.05,phaseT); aRz=sMix(-0.42,-0.55,phaseT); eRx=sMix(0.90,1.45,phaseT);
    // Regard suit la balle qui monte
    headLookUp=sMix(-0.08, 0.55,phaseT); // regarde vers le haut
    headTurnY=sMix(0.10, 0.05,phaseT);

  } else if(phase==='swing'){
    const p=phaseT;
    if(p<0.22){
      // APEX — position service maximale, dos arqué, regard sur la balle au sommet
      const s=eOut2(p/0.22);
      hipsRotY=sMix(0.65,0.78,s);
      torsoTwist=sMix(0.26,0.38,s);
      torsoLean=sMix(-0.12, 0.05,s);
      torsoArch=sMix(-0.16,-0.26,s);  // cambré en arrière maximal
      tiptoe=sMix(0.22,0.30,s); // sur la pointe des deux pieds
      kneeLx=sMix(-0.08,-0.04,s);
      kneeRx=sMix(0.15,0.22,s); // légère flexion pour sauter
      // Bras droit : position trophée complète — coude haut, raquette en arrière
      aRx=sMix(-1.05,-1.40,s); aRz=sMix(-0.55,-0.48,s); eRx=sMix(1.45,1.62,s);
      fRx=sMix(0,-0.35,s); wRx=sMix(0,-0.7,s); // poignet en extension max
      aLx=sMix(2.10,1.85,s);
      headLookUp=sMix(0.55, 0.62,s); // regarde la balle au sommet du lancer

    } else if(p<0.60){
      // DÉCHARGE EXPLOSIVE — chaîne cinétique complète
      const s=(p-0.22)/0.38; const se=eIn(s); const so=eOut(s);
      // 1. Hanches ouvrent EN PREMIER (lag musculaire)
      hipsRotY=sMix(0.78,-0.32,eIn(Math.min(s*1.5,1)));
      // 2. Torse suit avec délai plus marqué
      torsoTwist=sMix(0.38,-0.62,eIn(Math.min(s*1.25,1)));
      torsoLean=sMix(0.05,0.58,so);
      torsoArch=sMix(-0.26,0.10,so); // dos se redresse brutalement
      tiptoe=sMix(0.30,0.04,s); // descend pendant l'extension
      kneeLx=sMix(-0.04,0.30,so); // extension/poussée jambe gauche
      kneeRx=sMix(0.22,-0.15,so); // jambe droite décolle
      // 3. Bras fouette de derrière tête → impact en avant/haut — mouvement supination
      aRx=sMix(-1.40, 2.95,se); aRz=sMix(-0.48, 0.62,so); eRx=sMix(1.62,-0.72,se);
      fRx=sMix(-0.35, 0.45,so); wRx=sMix(-0.7, 0.90,so); // claquage poignet = vitesse balle
      aLx=sMix(1.85, 0.95,so);
      // Regard vers le point d'impact (haut devant)
      headLookUp=sMix(0.62, 0.25,so);
      headTurnY=sMix(0.05,-0.05,so);
      if(s>0.50) flashPow=1;

    } else {
      // FOLLOW-THROUGH — élan naturel vers bas/gauche, pied droit décolle
      const s=(p-0.60)/0.40; const so=eOut(s);
      hipsRotY=sMix(-0.32,0.06,so);
      torsoTwist=sMix(-0.62,-0.12,so);
      torsoLean=sMix(0.58,0.32,so);
      torsoArch=sMix(0.10,0.02,so);
      // Bras continue son élan naturel vers hanche opposée
      aRx=sMix(2.95, 4.50,eInQ(s)); aRz=sMix(0.62,-0.22,so); eRx=sMix(-0.72,-0.32,so);
      wRx=sMix(0.90,-0.25,so); wRz=sMix(0,-0.35,so);
      aLx=sMix(0.95,0.28,so);
      tiptoe=sMix(0.04,0,so);
      kneeLx=sMix(0.30,0.05,so);
      // Pied droit décolle — caractéristique naturel du service
      hipLiftR=sMix(0,-0.28,so);
      kneeRx=sMix(-0.15,-0.35,so); // pied droit se lève derrière
      // Regard suit vers la zone d'impact/rebond
      headLookUp=sMix(0.25,-0.12,so);
      headTurnY=sMix(-0.05,-0.18,so);
    }

  } else if(phase==='ballfly'){
    // Joueur reprend position ready après le service — regarde la balle voler
    hipsRotY=sMix(0.06,0.15,eo);
    torsoTwist=sMix(-0.12,0,eo); torsoLean=sMix(0.32,0.05,eo);
    aRx=sMix(4.50,0.75,eo); aRz=sMix(-0.22,0,eo);
    aLx=sMix(0.28,0,eo);
    hipLiftR=sMix(-0.28,0,eo);
    kneeRx=sMix(-0.35,0,eo);
    headLookUp=sMix(-0.12,-0.18,eo); // suit la balle vers le fond adverse
    headTurnY=sMix(-0.18,-0.12,eo);

  } else if(phase==='bounce'||phase==='done'){
    hipsRotY=0.15; torsoTwist=0; torsoLean=0;
    aRx=sMix(0.75,0,eo); aLx=0;
    headLookUp=sMix(-0.18,-0.05,eo);
    headTurnY=-0.08;
  }

  // ── Application des rotations à la hiérarchie
  playerGroup.rotation.y=hipsRotY;
  hipsG.rotation.y=torsoTwist*0.45;
  torsoG.rotation.y=torsoTwist;
  torsoG.rotation.z=torsoLean;
  torsoG.rotation.x=torsoArch;
  neckG.rotation.z=torsoLean*0.35;
  neckG.rotation.x=headLookUp*0.3;
  headG.rotation.z=torsoLean*0.20;
  headG.rotation.y=torsoTwist*0.12 + headTurnY;
  headG.rotation.x=headLookUp; // tête se penche pour suivre la balle

  // Jambes
  hipLG.rotation.x=kneeLx*0.5;
  kneeLG.rotation.x=-Math.abs(kneeLx)*0.8;
  hipRG.rotation.x=kneeRx*0.3+hipLiftR;
  kneeRG.rotation.x=Math.max(0,-hipLiftR)*0.6;

  // Pointe des pieds via déplacement Y des groupes de jambe
  hipLG.position.y=-0.02+tiptoe*0.1;
  hipRG.position.y=-0.02;
  // Chaussures pivotent légèrement sur la pointe
  ankleLG.rotation.x=-tiptoe*0.8;
  ankleRG.rotation.x=-tiptoe*0.5;

  // Bras gauche
  armLG.rotation.x=aLx; armLG.rotation.z=aLz;
  elbowLG.rotation.x=eLx;
  forearmLG.rotation.x=0;
  wristLG.rotation.x=wLx;

  // Bras droit (raquette)
  armRG.rotation.x=aRx; armRG.rotation.z=aRz;
  elbowRG.rotation.x=eRx;
  forearmRG.rotation.x=fRx;
  wristRG.rotation.x=wRx; wristRG.rotation.z=wRz;
}

// ── PHYSIQUE DE LA BALLE ─────────────────────────────────────
// Simule une trajectoire balistique réelle avec gravité, topspin/flat
// Service flat : vitesse ~200 km/h → 55.5 m/s ; durée vol ~0.55s
// Court = 23.77m, hauteur filet au centre = 0.91m, impact à ~2.9m
// Trajectoire en 3 segments : impact → filet → rebond → arrêt

function ballTrajectory(t) {
  // t in [0,1] sur la durée totale PDUR.ballfly
  // Point d'impact : serveHit, passage filet : netCross, bounce : bounce
  
  const timeToNet = TP.serveHit.distanceTo(TP.netCross) / TP.serveHit.distanceTo(TP.bounce); // ~0.45

  if(t < timeToNet) {
    // Segment 1 : impact → franchissement du filet
    // Courbe bézier quadratique avec point de contrôle sous le sommet (balle descend légèrement)
    const s = t / timeToNet;
    const ctrl = new THREE.Vector3(
      (TP.serveHit.x + TP.netCross.x)*0.5 - 0.15,
      // Trajectoire légèrement descendante — la balle sort haute mais chute rapidement
      TP.serveHit.y * (1-s*0.5) + TP.netCross.y * s*0.5 + 0.12*(1-(2*s-1)*(2*s-1)),
      (TP.serveHit.z + TP.netCross.z)*0.5
    );
    // Bezier quadratique précise
    const ss = eOut2(s);
    const p = bez3(TP.serveHit, ctrl, TP.netCross, ss);
    // Ajouter la gravité parabolique (g = 9.81 m/s²)
    const vY = (TP.netCross.y - TP.serveHit.y) / timeToNet;
    p.y = TP.serveHit.y + vY*ss*(timeToNet) - 4.9*(ss*timeToNet)*(ss*timeToNet)*0.28;
    return p;
  } else {
    // Segment 2 : passage filet → rebond
    const s = (t - timeToNet) / (1 - timeToNet);
    // Arc parabolique naturel : monte légèrement puis chute vers le rebond
    const ctrlMid = new THREE.Vector3(
      (TP.netCross.x + TP.bounce.x)*0.5 + 0.4,
      TP.netCross.y * (1-s) + 0.55 + 0.22*(1-(2*s-1)*(2*s-1)), // petit arc
      (TP.netCross.z + TP.bounce.z)*0.5
    );
    const ss = eIn(s) * 0.3 + s * 0.7; // vitesse croissante à l'approche
    const p = bez3(TP.netCross, ctrlMid, TP.bounce, ss);
    // Gravité accentuée
    p.y = Math.max(0.065, p.y - 0.8 * s * s);
    return p;
  }
}

function updateBall(){
  ballMesh.visible=false;
  ballShadow.visible=false;
  trailM.forEach(m=>m.visible=false);

  if(phase==='toss'){
    ballMesh.visible=true;
    // Physique du lancer : décélération réaliste (v₀ - g*t)
    const t=1-Math.pow(1-phaseT,2.2);
    ballMesh.position.lerpVectors(TP.tossStart,TP.tossPeak,t);
    // Légère rotation
    ballMesh.rotation.z+=0.08; ballMesh.rotation.x+=0.05;
    // Ombre portée
    ballShadow.visible=true;
    ballShadow.position.x=ballMesh.position.x;
    ballShadow.position.z=ballMesh.position.z;
    ballShadow.material.opacity=0.35*Math.max(0,1-ballMesh.position.y*0.3);

  } else if(phase==='swing'){
    // Balle reste au sommet, chute doucement (gravité)
    ballMesh.visible=(phaseT<0.75);
    ballMesh.position.copy(TP.tossPeak);
    ballMesh.position.y-=phaseT*phaseT*0.55;
    ballMesh.rotation.z+=0.06;

  } else if(phase==='ballfly'){
    ballMesh.visible=true;
    const pos=ballTrajectory(phaseT);
    ballMesh.position.copy(pos);
    
    // Rotation rapide topspin
    ballMesh.rotation.x+=0.22;
    ballMesh.rotation.z+=0.08;

    // Trail
    trailPos.push(pos.clone());
    if(trailPos.length>TRAIL) trailPos.shift();
    trailM.forEach((m,i)=>{
      const idx=trailPos.length-1-i;
      if(idx>=0){
        m.visible=true;
        m.position.copy(trailPos[idx]);
        const fade=1-(i/trailPos.length);
        m.material.opacity=fade*fade*0.55;
        m.scale.setScalar(0.25+fade*0.75);
      }
    });

    // Ombre dynamique : s'étale et s'assombrit à l'approche du sol
    ballShadow.visible=true;
    const h=Math.max(0.01,pos.y-0.004);
    ballShadow.position.set(pos.x,0.005,pos.z);
    ballShadow.scale.setScalar(0.3+h*0.35);
    ballShadow.material.opacity=0.45*Math.max(0,1-h*0.45);

  } else if(phase==='bounce'){
    ballMesh.visible=true;
    const t=phaseT;
    // Rebond physique : compression à l'impact, rebond amorti
    if(t<0.12){
      // Compression — balle s'écrase légèrement
      const s=t/0.12;
      ballMesh.position.lerpVectors(TP.bounce,
        new THREE.Vector3(TP.bounce.x,0.04,TP.bounce.z), s);
      ballMesh.scale.setScalar(1+s*0.25); // compression latérale
    } else {
      // Rebond amorti : montée puis re-chute
      const s=(t-0.12)/0.88;
      const bounceH=0.42*(1-s); // hauteur qui décroît
      const x=Math.abs(Math.sin(s*Math.PI));
      ballMesh.position.set(
        TP.bounce.x + (TP.rest.x-TP.bounce.x)*s,
        0.065 + x * bounceH,
        TP.bounce.z + (TP.rest.z-TP.bounce.z)*s
      );
      ballMesh.scale.setScalar(1); // retour forme normale
    }
    ballMesh.rotation.x+=0.12;
    trailPos.length=0;

    // Ombre du rebond
    ballShadow.visible=true;
    const bh=Math.max(0.01,ballMesh.position.y);
    ballShadow.position.set(ballMesh.position.x,0.005,ballMesh.position.z);
    ballShadow.scale.setScalar(0.25+bh*0.5);
    ballShadow.material.opacity=0.4*Math.max(0,1-bh*0.6);

  } else if(phase==='done'){
    ballMesh.visible=true; ballMesh.position.copy(TP.rest); ballMesh.scale.setScalar(1);
    ballShadow.visible=true;
    ballShadow.position.set(TP.rest.x,0.005,TP.rest.z);
    ballShadow.scale.setScalar(0.28);
    ballShadow.material.opacity=0.25;
  }
}

function updateDust(){
  for(let i=dustMeshes.length-1;i>=0;i--){
    const p=dustMeshes[i];
    p.mesh.position.x+=p.vx; p.mesh.position.y+=p.vy; p.mesh.position.z+=p.vz;
    p.vy-=0.0025; p.life-=0.028;
    p.mesh.material.opacity=p.life*p.life*0.70;
    p.mesh.scale.setScalar(Math.max(0.01,p.life*1.2));
    if(p.life<=0){ p.mesh.visible=false; dustMeshes.splice(i,1); }
  }
}

let lastT=null;
function nextPhase(){
  const idx=PORD.indexOf(phase);
  if(idx<PORD.length-1){
    phase=PORD[idx+1]; phaseT=0;
    if(phase==='bounce'&&!bounceDone){ spawnDust(TP.bounce.clone(),36,1.2); bounceDone=true; }
    if(phase==='done'){ showScore(_pendingSensorSpeed); }
  }
}

let _idleFrame=0;
function animate(ts){
  requestAnimationFrame(animate);
  const dt=lastT?Math.min((ts-lastT)/1000,.05):.016; lastT=ts;
  // En idle/done : ne render que 1 frame sur 3 (économie CPU/GPU)
  if(phase==='idle'||phase==='done'){ _idleFrame++; if(_idleFrame<3){return;} _idleFrame=0; } else { _idleFrame=0; }

  if(phase!=='idle'&&phase!=='done'){
    phaseT=clamp(phaseT+dt/PDUR[phase],0,1);
    if(phaseT>=1) nextPhase();
  }

  if(flashPow>0){ flash.intensity=flashPow*6; flashPow=Math.max(0,flashPow-dt*9); }
  else flash.intensity=0;

  updatePlayer(); updateBall(); updateDust();

  renderer.render(scene,camera);
}

function showScore(sensorSpeed){
  if(scoreShown) return; scoreShown=true;
  const speed = sensorSpeed !== undefined ? Math.round(sensorSpeed) : Math.round(85+force*1.35+(Math.random()*20-10));
  DOM.hSpeed.textContent=speed; DOM.hSpeed.classList.add('lit');
  DOM.pFill.style.width=force+'%';

  const msg = getVerdictMsg(speed);

  // Scoreboard 3D : vitesse d'abord, message après
  updateScoreboard3D(speed+'', '');

  setTimeout(()=>{
    updateScoreboard3D(speed+'', msg);
  }, 600);

  // ── Overlay résultat ──
  setTimeout(()=>{
    DOM.resSpeed.textContent = speed;
    DOM.resVerdict.textContent = msg;
    DOM.resVerdict.className = 'result-verdict spd-' + (speed >= 190 ? 'gold' : speed >= 150 ? 'green' : speed >= 110 ? 'blue' : 'white');

    _overlayShowing = true;
    DOM.overlay.classList.add('show');
    requestAnimationFrame(()=>{ DOM.resBar.style.width = force + '%'; });
    setTimeout(()=>{
      DOM.overlay.classList.remove('show');
      _overlayShowing = false; // pop-up fermée → nouveau swing autorisé
    }, 3000);
  }, 900);
  // ── Enregistrer au leaderboard si un nom de joueur est connu
  _saveScoreToLeaderboard(speed);
}

// ── Références DOM cachées
const DOM = {
  hSpeed:    document.getElementById('hSpeed'),
  hBest:     document.getElementById('hBest'),
  pFill:     document.getElementById('pFill'),
  overlay:   document.getElementById('resultOverlay'),
  resSpeed:  document.getElementById('resSpeed'),
  resVerdict:document.getElementById('resVerdict'),
  resBar:    document.getElementById('resBar'),
};

// ── API CAPTEUR ÉLECTRONIQUE
// Appelée par le système externe : onSwingDetected(true, 185.3)
let _pendingSensorSpeed = undefined;
let _overlayShowing = false; // verrou : bloque tout nouveau swing tant que la pop-up est visible

function triggerSwing(speedKmh) {
  if(_overlayShowing) return;                // pop-up score encore affichée → on attend
  if(phase!=='idle'&&phase!=='done') return; // swing déjà en cours
  _pendingSensorSpeed = speedKmh;
  // force proportionnelle à la vitesse (normalisée 0–100 pour barre visuelle)
  force = Math.min(100, Math.max(10, Math.round((speedKmh / 230) * 100)));
  phase='wind'; phaseT=0; bounceDone=false; scoreShown=false; flashPow=0;
  trailPos.length=0; trailM.forEach(m=>m.visible=false);
  dustMeshes.length=0; dustPool.forEach(m=>{m.visible=false;});
  DOM.hSpeed.classList.remove('lit');
  DOM.overlay.classList.remove('show');
  DOM.pFill.style.width='0%';
  DOM.hSpeed.textContent='--';
  updateScoreboard3D('--','');
}

// Point d'entrée exposé au système électronique
window.onSwingDetected = function(swingBool, speedKmh) {
  if(swingBool === true) {
    triggerSwing(speedKmh);
  }
};

// ── RESIZE — robuste avec debounce
let _resizeTimer = null;
function onResize(){
  clearTimeout(_resizeTimer);
  _resizeTimer = setTimeout(() => {
    const w = window.innerWidth, h = window.innerHeight;
    renderer.setPixelRatio(Math.min(devicePixelRatio, 1.5));
    renderer.setSize(w, h, true);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  }, 50);
}
window.addEventListener('resize', onResize, { passive: true });

animate(0);

// ══════════════════════════════════════════════════════════════════════════
//  SÉLECTEUR DE PICO — peuple le <select> depuis l'API et gère le choix
// ══════════════════════════════════════════════════════════════════════════
let _selectedPicoId = 0;  // 0 = "Tous" (pic le plus récent parmi tous les Picos)

const picoSelectEl  = document.getElementById('picoSelect');
const picoSelectorDot = document.getElementById('picoSelectorDot');

async function refreshPicoSelect() {
  try {
    const r = await fetch('?picos=1&_=' + Date.now());
    const picos = await r.json();
    // Conserver la sélection courante
    const cur = picoSelectEl.value;
    // Vider sauf "Tous"
    while (picoSelectEl.options.length > 1) picoSelectEl.remove(1);
    picos.forEach(p => {
      const opt = document.createElement('option');
      opt.value = p.pico_id;
      opt.textContent = (p.nom || ('Pico ' + p.pico_id)) + '  [P' + p.pico_id + ']';
      picoSelectEl.appendChild(opt);
    });
    // Restaurer la sélection si elle existe encore
    if ([...picoSelectEl.options].some(o => o.value == cur)) {
      picoSelectEl.value = cur;
    }
    _selectedPicoId = parseInt(picoSelectEl.value) || 0;
  } catch(e) {}
}

picoSelectEl.addEventListener('change', () => {
  _selectedPicoId = parseInt(picoSelectEl.value) || 0;
  picoSelectorDot.classList.remove('live');
});

// Charger la liste au démarrage puis la rafraîchir toutes les 10s
refreshPicoSelect();
setInterval(refreshPicoSelect, 10000);

// ── POLLING CAPTEUR ─────────────────────────────────────────────────────────
// Stratégie : polling rapide (150ms) pour ne pas rater le pic.
// Le PHP accumule le maximum pendant PEAK_TTL secondes (modèle TTL).
// TOUS les clients voient le même pic sans "consommation" destructive :
//   → plusieurs navigateurs sur le même Pico reçoivent tous l'event.
// ────────────────────────────────────────────────────────────────────────────
let _polling = false;
let _sessionBestKmh = 0;
let _lastTriggeredAt = 0;   // timestamp ms du dernier swing déclenché (dédoublonnage côté JS)

setInterval(async () => {
  if (_polling) return;
  _polling = true;
  try {
    // Toujours inclure le pico_id sélectionné (0 = tous)
    const url = '?api=1&pico_id=' + _selectedPicoId + '&_=' + Date.now();
    const r = await fetch(url);
    if (!r.ok) return;
    const data = await r.json();

    if (data && data.swing_detecte && data.vitesse_kmh > 0) {
      // Dédoublonnage JS : le TTL fait que le serveur renvoie le même pic
      // pendant plusieurs secondes. On ne déclenche l'animation qu'une seule
      // fois par pic, identifié par (pico_id, expires_at).
      const fingerprint = (data.pico_id || 0) + '_' + (data.expires_at || 0);
      if (fingerprint !== _lastTriggeredAt) {
        _lastTriggeredAt = fingerprint;
        const kmh = parseFloat(data.vitesse_kmh);
        if (kmh > _sessionBestKmh) { _sessionBestKmh = kmh; DOM.hBest.textContent = Math.round(_sessionBestKmh); }
        _currentPicoId = data.pico_id || _selectedPicoId;
        // Récupérer le nom du joueur depuis le sélecteur
        const selOpt = [...picoSelectEl.options].find(o => o.value == _currentPicoId);
        if (selOpt) _currentPlayerName = selOpt.textContent.replace(/\s*\[P\d+\]$/, '').trim();
        // Indicateur visuel dans la topbar
        picoSelectorDot.classList.add('live');
        setTimeout(() => picoSelectorDot.classList.remove('live'), 3500);
        window.onSwingDetected(true, kmh);
      }
    }
  } catch(e) {
    // silencieux
  } finally {
    _polling = false;
  }
}, 150);

// ════════════════════════════════════════════════════════
//  MENU BALLE DE TENNIS + LEADERBOARD + PICO MANAGER
// ════════════════════════════════════════════════════════

// ── Nom joueur courant (pour le leaderboard) ──
let _currentPlayerName = 'Joueur';
let _currentPicoId = 0;

async function _saveScoreToLeaderboard(speed) {
  try {
    await fetch('?add_score=1', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ nom: _currentPlayerName, pico_id: _currentPicoId, vitesse: speed })
    });
  } catch(e) {}
}

// ── Helpers UI ──
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Fermer en cliquant sur le fond
document.querySelectorAll('.modal-bg').forEach(bg => {
  bg.addEventListener('click', e => { if(e.target === bg) bg.classList.remove('open'); });
});

// ── Bouton balle menu ──
const menuBtn   = document.getElementById('menuBtn');
const menuPanel = document.getElementById('menuPanel');
let menuOpen = false;

function toggleMenu() {
  menuOpen = !menuOpen;
  menuPanel.classList.toggle('open', menuOpen);
}
menuBtn.addEventListener('click', e => { e.stopPropagation(); toggleMenu(); });
document.addEventListener('click', () => { if(menuOpen){ menuOpen=false; menuPanel.classList.remove('open'); } });
menuPanel.addEventListener('click', e => e.stopPropagation());

// ── Item : Leaderboard ──
document.getElementById('menuLeaderboard').addEventListener('click', () => {
  toggleMenu();
  openModal('lbModal');
  loadLeaderboard();
});
document.getElementById('lbClose').addEventListener('click', () => closeModal('lbModal'));
document.getElementById('lbClear').addEventListener('click', async () => {
  if(!confirm('Effacer tout le classement ?')) return;
  await fetch('?clear_leaderboard=1', { method: 'POST' });
  loadLeaderboard();
});

async function loadLeaderboard() {
  const body = document.getElementById('lbBody');
  body.innerHTML = '<div class="lb-empty">Chargement…</div>';
  try {
    const r = await fetch('?leaderboard=1&_='+Date.now());
    const lb = await r.json();
    if (!lb.length) { body.innerHTML = '<div class="lb-empty">Aucun score enregistré</div>'; return; }
    const medals = ['🥇','🥈','🥉'];
    const rows = lb.map((e, i) => {
      const spd = Math.round(e.vitesse);
      const spdClass = spd >= 190 ? 'spd-gold' : spd >= 150 ? '' : '';
      const medal = medals[i] || '';
      const date = new Date(e.ts * 1000).toLocaleDateString('fr-FR', { day:'2-digit', month:'2-digit' });
      return `<tr>
        <td class="lb-rank">${medal || (i+1)}</td>
        <td>${e.nom || 'Joueur'}</td>
        <td style="color:rgba(196,98,45,0.45);font-size:11px;letter-spacing:1px">P${e.pico_id||'?'}</td>
        <td style="font-size:10px;color:rgba(240,236,228,0.2)">${date}</td>
        <td class="lb-speed">${spd} <span style="font-size:11px;color:rgba(240,236,228,0.3)">km/h</span></td>
      </tr>`;
    }).join('');
    body.innerHTML = `<table class="lb-table">
      <thead><tr>
        <th>#</th><th>Joueur</th><th>Pico</th><th>Date</th><th style="text-align:right">Vitesse</th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
  } catch(e) {
    body.innerHTML = '<div class="lb-empty">Erreur de chargement</div>';
  }
}

// ── Item : Pico Manager ──
document.getElementById('menuPicos').addEventListener('click', () => {
  toggleMenu();
  openModal('picoModal');
  loadPicos();
});
document.getElementById('picoClose').addEventListener('click', () => closeModal('picoModal'));

async function loadPicos() {
  const grid = document.getElementById('picoGrid');
  grid.innerHTML = '<div class="lb-empty">Chargement…</div>';
  try {
    const r = await fetch('?picos=1&_='+Date.now());
    const picos = await r.json();
    document.getElementById('menuNotif').classList.toggle('show', picos.length === 0);
    if (!picos.length) {
      grid.innerHTML = '<div class="lb-empty">Aucun Pico enregistré. Ajoutez-en ci-dessus.</div>';
      return;
    }
    const now = Math.floor(Date.now()/1000);
    grid.innerHTML = picos.map(p => {
      const online = (now - (p.last_seen||0)) < 30; // actif si vu il y a < 30s
      const activeClass = p.active ? 'active-card' : '';
      const dotClass = online ? 'on' : '';
      const toggleLabel = p.active ? 'Désactiver' : 'Activer';
      const toggleClass = p.active ? 'toggle-on' : '';
      return `<div class="pico-card ${activeClass}" data-id="${p.pico_id}">
        <div class="pico-card-top">
          <span class="pico-id-badge">Pico ${p.pico_id}</span>
          <span class="pico-status-dot ${dotClass}" title="${online?'En ligne':'Hors ligne'}"></span>
        </div>
        <div class="pico-name-row">
          <input class="pico-name-input" type="text" value="${p.nom||''}" placeholder="Nom du joueur" data-orig="${p.nom||''}">
        </div>
        <div class="pico-ip">IP : ${p.ip||'—'} · Vu : ${online?'maintenant':_timeAgo(p.last_seen)}</div>
        <div class="pico-actions">
          <button class="btn-sm ${toggleClass}" onclick="togglePico(${p.pico_id}, ${!p.active})">${toggleLabel}</button>
          <button class="btn-sm" onclick="savePicoName(${p.pico_id}, this)">Sauver</button>
          <button class="btn-sm danger" onclick="deletePico(${p.pico_id})">Suppr</button>
        </div>
      </div>`;
    }).join('');
  } catch(e) {
    grid.innerHTML = '<div class="lb-empty">Erreur de chargement</div>';
  }
}

function _timeAgo(ts) {
  if(!ts) return '—';
  const s = Math.floor(Date.now()/1000) - ts;
  if(s < 60) return s+'s';
  if(s < 3600) return Math.floor(s/60)+'min';
  return Math.floor(s/3600)+'h';
}

async function savePicoName(id, btn) {
  const card = btn.closest('.pico-card');
  const nom = card.querySelector('.pico-name-input').value.trim() || 'Pico '+id;
  await fetch('?register_pico=1', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ pico_id: id, nom })
  });
  loadPicos();
  // Si c'est le Pico actif courant, on met à jour le nom
  if(id === _currentPicoId) _currentPlayerName = nom;
}

async function togglePico(id, active) {
  await fetch('?toggle_pico=1', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ pico_id: id, active })
  });
  loadPicos();
}

async function deletePico(id) {
  if(!confirm('Supprimer Pico '+id+' ?')) return;
  await fetch('?delete_pico=1', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ pico_id: id })
  });
  loadPicos();
}

// ── Ajout rapide d'un Pico ──
document.getElementById('addPicoBtn').addEventListener('click', async () => {
  const idEl  = document.getElementById('addPicoId');
  const nomEl = document.getElementById('addPicoNom');
  const id    = parseInt(idEl.value);
  const nom   = nomEl.value.trim() || 'Pico '+id;
  if(!id || id < 1 || id > 20) { idEl.style.borderColor='#E05030'; return; }
  idEl.style.borderColor='';
  await fetch('?register_pico=1', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ pico_id: id, nom })
  });
  idEl.value = ''; nomEl.value = '';
  loadPicos();           // recharge le modal
  refreshPicoSelect();   // recharge le sélecteur topbar
});

// ── Ajout rapide : rafraîchir aussi le sélecteur topbar après ajout/suppression ──
// (loadPicos est appelé par les boutons existants ; on accroche refreshPicoSelect dessus)
const _origLoadPicos = loadPicos;
window.loadPicos = async function() {
  await _origLoadPicos();
  await refreshPicoSelect();
};

</script>
</body>
</html>
