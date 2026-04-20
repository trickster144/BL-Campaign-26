<?php
// setup_map_connections.php — Spread towns apart & create neighbor-only connections
// Run once via browser: http://localhost/.../setup/setup_map_connections.php
// Then delete this file
include __DIR__ . "/../files/config.php";

$messages = [];

// 1. Fetch all towns
$all = [];
$res = $conn->query("SELECT id, name, side FROM towns ORDER BY id");
while ($r = $res->fetch_assoc()) $all[(int)$r['id']] = $r;

$groups = ['blue' => [], 'red' => [], 'blue_customs' => [], 'red_customs' => []];
foreach ($all as $id => $t) {
    $customs = stripos($t['name'], 'Customs') !== false;
    $key = $t['side'] . ($customs ? '_customs' : '');
    $groups[$key][] = $id;
}

$nb = count($groups['blue']);
$nr = count($groups['red']);
$messages[] = "Found: {$nb} blue towns, {$nr} red towns, " .
              count($groups['blue_customs']) . " blue customs, " .
              count($groups['red_customs']) . " red customs";

// 2. Spread towns across their territory on a hex-offset grid
function distribute($ids, $xMin, $xMax, $yMin, $yMax) {
    $n = count($ids);
    if (!$n) return [];
    $w = $xMax - $xMin;
    $h = $yMax - $yMin;
    $cell = sqrt($w * $h / $n);
    $cols = max(1, round($w / $cell));
    $rows = max(1, ceil($n / $cols));
    $xs = $w / max(1, $cols);
    $ys = $h / max(1, $rows);
    $pos = [];
    $i = 0;
    for ($r = 0; $r < $rows && $i < $n; $r++) {
        for ($c = 0; $c < $cols && $i < $n; $c++) {
            $x = $xMin + ($c + 0.5) * $xs + ($r % 2 ? $xs * 0.4 : 0);
            $y = $yMin + ($r + 0.5) * $ys;
            $id = $ids[$i];
            // Seeded jitter for organic feel
            $jx = sin($id * 7.3) * $xs * 0.22;
            $jy = cos($id * 11.1) * $ys * 0.22;
            $pos[$id] = [round($x + $jx, 1), round($y + $jy, 1)];
            $i++;
        }
    }
    return $pos;
}

// Blue territory: western half of island
$bluePos = distribute($groups['blue'], -58, 42, -25, 95);
// Red territory: eastern half of island
$redPos  = distribute($groups['red'],  82, 235, -25, 95);

// 3. Write new positions to DB
$upd = $conn->prepare("UPDATE towns SET x_coord = ?, y_coord = ? WHERE id = ?");
foreach (array_merge($bluePos, $redPos) as $id => $p) {
    $upd->bind_param("ddi", $p[0], $p[1], $id);
    $upd->execute();
}
foreach ($groups['blue_customs'] as $id) {
    $x = -72.0; $y = 35.0;
    $upd->bind_param("ddi", $x, $y, $id);
    $upd->execute();
}
foreach ($groups['red_customs'] as $id) {
    $x = 255.0; $y = 35.0;
    $upd->bind_param("ddi", $x, $y, $id);
    $upd->execute();
}
$upd->close();
$messages[] = "✅ All town positions updated";

// 4. Clear old fully-connected network
$conn->query("DELETE FROM town_distances");
$conn->query("DELETE FROM roads");
$conn->query("DELETE FROM rail_lines");
$messages[] = "✅ Cleared old connections (town_distances, roads, rail_lines)";

// 5. Reload positions
$pos = [];
$res = $conn->query("SELECT id, side, x_coord AS x, y_coord AS y FROM towns");
while ($r = $res->fetch_assoc()) $pos[(int)$r['id']] = $r;

// 6. Build sparse neighbor-only connections (K-nearest same-side)
$edges = [];
foreach ($pos as $id => $t) {
    $dists = [];
    foreach ($pos as $oid => $o) {
        if ($oid == $id || $o['side'] !== $t['side']) continue;
        $dx = $o['x'] - $t['x'];
        $dy = $o['y'] - $t['y'];
        $dists[$oid] = sqrt($dx * $dx + $dy * $dy);
    }
    asort($dists);
    $k = min(3, count($dists)); // each town connects to 3 nearest same-side neighbors
    $nearest = array_slice($dists, 0, $k, true);
    foreach ($nearest as $nid => $d) {
        $key = min($id, $nid) . '-' . max($id, $nid);
        if (!isset($edges[$key])) {
            $edges[$key] = [(int)min($id, $nid), (int)max($id, $nid), round($d, 2)];
        }
    }
}

// Insert connections (both directions, as existing code expects)
$ins = $conn->prepare("INSERT INTO town_distances (town_id_1, town_id_2, distance_km) VALUES (?, ?, ?)");
foreach ($edges as $e) {
    $ins->bind_param("iid", $e[0], $e[1], $e[2]);
    $ins->execute();
    $ins->bind_param("iid", $e[1], $e[0], $e[2]);
    $ins->execute();
}
$ins->close();
$messages[] = "✅ Created " . count($edges) . " neighbor-only connections (sparse network)";

// 7. Rebuild roads and rail lines from new connections
$conn->query("INSERT IGNORE INTO roads (town_id_1, town_id_2, road_type, speed_limit)
    SELECT town_id_1, town_id_2, 'mud', 30 FROM town_distances WHERE town_id_1 < town_id_2");
$rc = $conn->affected_rows;
$messages[] = "✅ Initialized $rc road segments (mud, 30 km/h)";

$conn->query("INSERT IGNORE INTO rail_lines (town_id_1, town_id_2, rail_type, speed_limit)
    SELECT town_id_1, town_id_2, 'basic', 60 FROM town_distances WHERE town_id_1 < town_id_2");
$rlc = $conn->affected_rows;
$messages[] = "✅ Initialized $rlc rail line segments (basic, 60 km/h)";

// 8. Show new network summary
$connPerTown = [];
$cRes = $conn->query("SELECT town_id_1 AS tid, COUNT(*) AS cnt FROM town_distances GROUP BY town_id_1");
while ($r = $cRes->fetch_assoc()) $connPerTown[(int)$r['tid']] = (int)$r['cnt'];
$minC = $connPerTown ? min($connPerTown) : 0;
$maxC = $connPerTown ? max($connPerTown) : 0;
$avgC = $connPerTown ? round(array_sum($connPerTown) / count($connPerTown), 1) : 0;
$messages[] = "ℹ️ Connections per town: min=$minC, max=$maxC, avg=$avgC";

$conn->close();
?>
<!DOCTYPE html><html><head><title>Setup Map Connections</title>
<style>
body{background:#1a1a2e;color:#eee;font-family:monospace;padding:40px;max-width:700px;margin:auto}
.box{background:#16213e;border:1px solid #0f3460;padding:20px;border-radius:8px;margin:20px 0}
h2{color:#4fc3f7}a{color:#4fc3f7}
.warn{color:#e94560;margin-top:20px}
</style></head><body>
<h2>🗺️ Map Connection Setup</h2>
<div class="box">
<?php foreach ($messages as $m) echo "<p>$m</p>"; ?>
</div>
<p class="warn"><strong>⚠️ Delete this file after running!</strong></p>
<p><a href="<?= BASE_URL ?>map.php">→ View Map</a> | <a href="<?= BASE_URL ?>towns.php">→ Towns</a></p>
</body></html>
