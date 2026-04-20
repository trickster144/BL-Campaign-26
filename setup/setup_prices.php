<?php
// setup_prices.php — Set realistic world market prices based on production chain complexity
// Run once via browser, then delete this file
// Sell price = buy price × 0.95 (5% spread)
include __DIR__ . "/../files/config.php";

// Pricing rationale (cost buildup + margin for power/workers):
//
// Tier 0 (Raw): base extraction value
// Tier 1 (Basic Processed): input cost + 40-60% margin
// Tier 2 (Advanced Processed): input cost + 25-35% margin
//
// Rebalanced recipes for Chemicals/Plastics/Electronics to flatten the price curve

$prices = [
    // === RAW RESOURCES (Tier 0) ===
    // 1 MW power, 100 workers, 1t/hr/level
    'Stone'     => 10,   // Most abundant
    'Wood'      => 15,   // Moderate demand
    'Coal'      => 20,   // High demand (bricks, steel, prefab, power)
    'Iron'      => 25,   // Steel production
    'Bauxite'   => 30,   // Aluminium production
    'Oil'       => 35,   // High demand (fuel, chemicals, plastics)
    'Uranium'   => 5,    // Low per-tonne (10t → 1t nuclear fuel)

    // === BASIC PROCESSED (Tier 1) ===
    // Margin includes power/worker overhead (15-60% above input cost)
    // Bricks: 2t Coal(40), 1MW 100w → 65
    'Bricks'          => 65,
    // Wooden boards: 5t Wood(75), 1MW 50w → 110
    'Wooden boards'   => 110,
    // Prefab panels: 1t Coal(20) + 5t Stone(50) = 70, 1MW 75w → 105
    'Prefab panels'   => 105,
    // Aluminium: 1t Bauxite(30), 5MW 300w → 90 (high overhead from heavy power/workers)
    'Aluminium'       => 90,
    // Steel: 3t Coal(60) + 3t Iron(75) = 135, 3MW 200w → 250
    'Steel'           => 250,
    // Fuel: 5t Oil(175), 5MW 500w → 280
    'Fuel'            => 280,
    // Nuclear fuel: 10t Uranium(50), 10MW 350w → 150 (strategic: powers 100 MW-hrs)
    'Nuclear fuel'    => 150,

    // === ADVANCED PROCESSED (Tier 2) ===
    // Chemicals: 3t Stone(30) + 3t Wood(45) + 10t Oil(350) = 425, 8MW 500w → 550
    'Chemicals'               => 550,
    // Plastics: 5t Oil(175) + 2t Chemicals(1100) = 1275, 10MW 100w → 1500
    'Plastics'                => 1500,
    // Mechanical components: 10t Steel(2500) = 2500, 15MW 200w → 3200
    'Mechanical components'   => 3200,
    // Explosives: 4t Chemicals(2200) + 4t Wood(60) + 10t Stone(100) = 2360, 20MW 150w → 2800
    'Explosives'              => 2800,
    // Electronic components: 3t Plastics(4500) + 2t Chemicals(1100) = 5600, 10MW 200w → 6500
    'Electronic components'   => 6500,
];

$updated = 0;
foreach ($prices as $resource => $buyPrice) {
    $sellPrice = round($buyPrice * 0.95, 2);
    $stmt = $conn->prepare("UPDATE world_prices SET buy_price = ?, sell_price = ? WHERE resource_name = ?");
    $stmt->bind_param("dds", $buyPrice, $sellPrice, $resource);
    $stmt->execute();
    $updated += $stmt->affected_rows;
    $stmt->close();
}

echo "<h2>World Prices Updated</h2>";
echo "<p>Updated $updated resource prices.</p>";
echo "<table border='1' cellpadding='6' style='border-collapse:collapse; font-family:monospace;'>";
echo "<tr><th>Resource</th><th>Buy/t</th><th>Sell/t</th><th>Input Cost</th><th>Tier</th></tr>";

$details = [
    ['Stone',10,0,0], ['Wood',15,0,0], ['Coal',20,0,0], ['Iron',25,0,0],
    ['Bauxite',30,0,0], ['Oil',35,0,0], ['Uranium',5,0,0],
    ['Bricks',65,40,1], ['Wooden boards',110,75,1], ['Prefab panels',105,70,1],
    ['Aluminium',90,30,1], ['Steel',250,135,1], ['Fuel',280,175,1], ['Nuclear fuel',150,50,1],
    ['Chemicals',550,425,2], ['Plastics',1500,1275,2], ['Mechanical components',3200,2500,2],
    ['Explosives',2800,2360,2], ['Electronic components',6500,5600,2],
];
foreach ($details as $d) {
    $sell = round($d[1] * 0.95, 2);
    $input = $d[2] > 0 ? $d[2] : '-';
    echo "<tr><td>{$d[0]}</td><td>{$d[1]}</td><td>$sell</td><td>$input</td><td>Tier {$d[3]}</td></tr>";
}
echo "</table>";
echo "<br><p><strong>Price range:</strong> 5 (Uranium) to 6,500 (Electronics) — ratio 1:1,300</p>";
echo "<p><a href='" . BASE_URL . "index.php'>← View World Market</a></p>";
?>
