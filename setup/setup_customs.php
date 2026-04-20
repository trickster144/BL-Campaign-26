<?php
// Run this file ONCE to add customs houses, then delete it.
include __DIR__ . "/../files/config.php";

// Customs houses as coastal port towns at the far edges of each territory
// West port: on the western coast (x=-75, y=25)
// East port: on the eastern coast (x=258, y=25)

$customs = [
    ['Customs House West', 'blue', -75, 25, 0],
    ['Customs House East', 'red',  258, 25, 0],
];

// Check if customs houses already exist (re-runnable)
$existing = $conn->query("SELECT id, name FROM towns WHERE name LIKE 'Customs House%'");
$existingMap = [];
if ($existing && $existing->num_rows > 0) {
    while ($r = $existing->fetch_assoc()) $existingMap[$r['name']] = (int)$r['id'];
}

$newIds = [];
if (count($existingMap) > 0) {
    // UPDATE existing customs houses to new border positions
    $stmt = $conn->prepare("UPDATE towns SET x_coord = ?, y_coord = ? WHERE id = ?");
    foreach ($customs as $c) {
        if (isset($existingMap[$c[0]])) {
            $id = $existingMap[$c[0]];
            $stmt->bind_param("ddi", $c[2], $c[3], $id);
            $stmt->execute();
            $newIds[] = $id;
            echo "Updated {$c[0]} (ID: $id) to border position ({$c[2]}, {$c[3]})<br>";
        }
    }
    $stmt->close();
    // Delete old distances for customs houses so we can recalculate
    foreach ($newIds as $nid) {
        $conn->query("DELETE FROM town_distances WHERE town_id_1 = $nid OR town_id_2 = $nid");
    }
    echo "Cleared old distance entries for recalculation.<br>";
} else {
    // INSERT new customs houses
    $stmt = $conn->prepare("INSERT INTO towns (name, side, x_coord, y_coord, population) VALUES (?, ?, ?, ?, ?)");
    foreach ($customs as $c) {
        $stmt->bind_param("ssddi", $c[0], $c[1], $c[2], $c[3], $c[4]);
        $stmt->execute();
        $newIds[] = $conn->insert_id;
        echo "Inserted {$c[0]} (ID: {$conn->insert_id})<br>";
    }
    $stmt->close();
}

// Calculate distances between customs houses and ALL towns (including each other)
$allTowns = [];
$res = $conn->query("SELECT id, x_coord, y_coord FROM towns ORDER BY id");
while ($r = $res->fetch_assoc()) {
    $allTowns[$r['id']] = $r;
}

$stmt = $conn->prepare("INSERT IGNORE INTO town_distances (town_id_1, town_id_2, distance_km) VALUES (?, ?, ?)");
$count = 0;

foreach ($newIds as $newId) {
    foreach ($allTowns as $tid => $t) {
        if ($tid == $newId) continue;
        $dx = $allTowns[$newId]['x_coord'] - $t['x_coord'];
        $dy = $allTowns[$newId]['y_coord'] - $t['y_coord'];
        $dist = round(sqrt($dx * $dx + $dy * $dy), 2);
        // Insert both directions
        $stmt->bind_param("iid", $newId, $tid, $dist);
        $stmt->execute();
        $stmt->bind_param("iid", $tid, $newId, $dist);
        $stmt->execute();
        $count++;
    }
}
$stmt->close();

echo "$count distance pairs added (both directions).<br>";
echo "<br><strong>All done! Delete this file (setup_customs.php) now.</strong>";
$conn->close();
?>
