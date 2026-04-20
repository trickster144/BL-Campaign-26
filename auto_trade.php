<?php
session_start();
include "files/config.php";
include "files/auth.php";
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
requireTeamAssignment($user);

$allowedFactions = viewableFactions($user);
$selectedFaction = $_GET['faction'] ?? $allowedFactions[0] ?? 'blue';
if (!in_array($selectedFaction, $allowedFactions)) $selectedFaction = $allowedFactions[0];
$activeTab = $_GET['tab'] ?? 'trade';

// Faction balance
$balRow = $conn->query("SELECT points FROM faction_balance WHERE faction = '$selectedFaction'")->fetch_assoc();
$factionBalance = $balRow ? (float)$balRow['points'] : 0;

// Customs house for this faction
$customsName = $selectedFaction === 'blue' ? 'Customs House West' : 'Customs House East';
$csRow = $conn->query("SELECT id FROM towns WHERE name = '" . $conn->real_escape_string($customsName) . "'")->fetch_assoc();
$customsTownId = $csRow ? (int)$csRow['id'] : 0;

// Resources
$resources = [];
$rRes = $conn->query("SELECT id, resource_name, resource_type, buy_price, sell_price FROM world_prices ORDER BY resource_type, resource_name");
if ($rRes) { while ($r = $rRes->fetch_assoc()) $resources[] = $r; }
$resourceMap = [];
foreach ($resources as $r) $resourceMap[$r['id']] = $r;

// Towns for this faction (non-customs)
$towns = [];
$tRes = $conn->query("SELECT id, name FROM towns WHERE side = '$selectedFaction' ORDER BY name");
if ($tRes) { while ($t = $tRes->fetch_assoc()) $towns[] = $t; }

// Auto-trade rules
$tradeRules = [];
$trRes = $conn->query("
    SELECT at.*, wp.resource_name, wp.resource_type, wp.buy_price, wp.sell_price
    FROM auto_trade at
    JOIN world_prices wp ON at.resource_id = wp.id
    WHERE at.faction = '$selectedFaction'
    ORDER BY at.enabled DESC, at.trade_action, wp.resource_name
");
if ($trRes) { while ($tr = $trRes->fetch_assoc()) $tradeRules[] = $tr; }

// Auto-resupply rules
$resupplyRules = [];
$rsRes = $conn->query("
    SELECT ar.*, wp.resource_name, wp.resource_type, wp.buy_price,
           t.name as town_name
    FROM auto_resupply ar
    JOIN world_prices wp ON ar.resource_id = wp.id
    JOIN towns t ON ar.town_id = t.id
    WHERE ar.faction = '$selectedFaction'
    ORDER BY ar.enabled DESC, t.name, wp.resource_name
");
if ($rsRes) { while ($rs = $rsRes->fetch_assoc()) $resupplyRules[] = $rs; }

// Current customs stock
$customsStock = [];
if ($customsTownId) {
    $csRes = $conn->query("SELECT resource_id, stock FROM town_resources WHERE town_id = $customsTownId");
    if ($csRes) { while ($cs = $csRes->fetch_assoc()) $customsStock[(int)$cs['resource_id']] = (float)$cs['stock']; }
}

// Recent log
$logs = [];
$lgRes = $conn->query("
    SELECT * FROM auto_trade_log
    WHERE faction = '$selectedFaction'
    ORDER BY created_at DESC LIMIT 30
");
if ($lgRes) { while ($lg = $lgRes->fetch_assoc()) $logs[] = $lg; }

$msg = $_GET['msg'] ?? '';
$msgMap = [
    'created' => ['success', 'Rule created successfully.'],
    'deleted' => ['success', 'Rule deleted.'],
    'toggled' => ['success', 'Rule toggled.'],
    'updated' => ['success', 'Rule updated.'],
    'duplicate' => ['warning', 'A matching rule already exists.'],
    'invalid_resource' => ['danger', 'Invalid resource.'],
    'invalid_town' => ['danger', 'Invalid town selection.'],
    'denied' => ['danger', 'Access denied.'],
];

include "files/header.php";
include "files/sidebar.php";
?>
<div id="content" class="app-content">
  <div class="d-flex align-items-center mb-3">
    <h1 class="page-header mb-0">Auto Trade & Resupply</h1>
    <span class="badge bg-<?= $selectedFaction === 'blue' ? 'primary' : ($selectedFaction === 'red' ? 'danger' : 'success') ?> ms-3 fs-6 text-capitalize"><?= $selectedFaction ?></span>
    <span class="ms-auto text-muted">Balance: <strong class="text-warning"><?= number_format($factionBalance, 2) ?> pts</strong></span>
  </div>

  <?php if (isset($msgMap[$msg])): ?>
  <div class="alert alert-<?= $msgMap[$msg][0] ?> alert-dismissible fade show">
    <?= $msgMap[$msg][1] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <?php if (count($allowedFactions) > 1): ?>
  <ul class="nav nav-pills mb-3">
    <?php foreach ($allowedFactions as $f): ?>
    <li class="nav-item">
      <a class="nav-link <?= $f === $selectedFaction ? 'active' : '' ?>" href="?faction=<?= $f ?>&tab=<?= $activeTab ?>">
        <span class="text-capitalize"><?= $f ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <!-- Tab navigation -->
  <ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
      <a class="nav-link <?= $activeTab !== 'resupply' ? 'active' : '' ?>" href="?faction=<?= $selectedFaction ?>&tab=trade">
        <i class="bi bi-shop"></i> Auto Buy/Sell Orders
        <span class="badge bg-secondary ms-1"><?= count($tradeRules) ?></span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $activeTab === 'resupply' ? 'active' : '' ?>" href="?faction=<?= $selectedFaction ?>&tab=resupply">
        <i class="bi bi-box-seam"></i> Auto Resupply
        <span class="badge bg-secondary ms-1"><?= count($resupplyRules) ?></span>
      </a>
    </li>
  </ul>

  <!-- ═══════════════════════════════════════════ -->
  <!-- TAB 1: AUTO TRADE (BUY/SELL)               -->
  <!-- ═══════════════════════════════════════════ -->
  <?php if ($activeTab !== 'resupply'): ?>
  <div class="row">
    <div class="col-lg-5 mb-4">
      <div class="card">
        <div class="card-header bg-dark text-white"><i class="bi bi-plus-circle"></i> <strong>New Auto Trade Rule</strong></div>
        <div class="card-body">
          <form action="auto_trade_action.php" method="POST">
            <input type="hidden" name="action" value="create_trade">
            <input type="hidden" name="faction" value="<?= htmlspecialchars($selectedFaction) ?>">

            <div class="mb-3">
              <label class="form-label fw-bold">Action</label>
              <div class="d-flex gap-3">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="trade_action" id="actBuy" value="buy" checked onchange="toggleTradeForm()">
                  <label class="form-check-label" for="actBuy"><i class="bi bi-cart-plus text-success"></i> Auto Buy</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="trade_action" id="actSell" value="sell" onchange="toggleTradeForm()">
                  <label class="form-check-label" for="actSell"><i class="bi bi-cash-coin text-warning"></i> Auto Sell</label>
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Resource</label>
              <select name="resource_id" class="form-select" required>
                <option value="">Select...</option>
                <?php
                $lastType = '';
                foreach ($resources as $r):
                    if ($r['resource_type'] !== $lastType):
                        if ($lastType) echo '</optgroup>';
                        echo '<optgroup label="' . htmlspecialchars($r['resource_type']) . '">';
                        $lastType = $r['resource_type'];
                    endif;
                ?>
                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['resource_name']) ?> (buy: <?= number_format($r['buy_price'],2) ?> / sell: <?= number_format($r['sell_price'],2) ?>)</option>
                <?php endforeach; ?>
                <?php if ($lastType) echo '</optgroup>'; ?>
              </select>
            </div>

            <div class="mb-3" id="thresholdGroup">
              <label class="form-label fw-bold" id="thresholdLabel">Buy when customs stock &lt;</label>
              <div class="input-group">
                <input type="number" name="threshold" class="form-control" value="100" min="1" step="1" required>
                <span class="input-group-text">tons</span>
              </div>
              <div class="form-text" id="thresholdHelp">Triggers when customs stock is below this level.</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Amount per order</label>
              <div class="input-group">
                <input type="number" name="order_amount" class="form-control" value="50" min="1" step="1" required>
                <span class="input-group-text">tons</span>
              </div>
            </div>

            <div class="mb-3" id="minBalGroup">
              <label class="form-label fw-bold">Minimum balance to keep</label>
              <div class="input-group">
                <input type="number" name="min_balance" class="form-control" value="0" min="0" step="0.01">
                <span class="input-group-text">pts</span>
              </div>
              <div class="form-text">Won't buy if it would drop balance below this.</div>
            </div>

            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Create Rule</button>
          </form>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header bg-dark text-white"><i class="bi bi-info-circle"></i> How Auto Trade Works</div>
        <div class="card-body small">
          <p class="mb-2"><strong>Auto Buy:</strong> Each tick, if customs house stock of the resource is below your threshold, it buys the order amount using faction points at current market price.</p>
          <p class="mb-2"><strong>Auto Sell:</strong> Each tick, if customs house stock of the resource is at or above your threshold, it sells the order amount for faction points at current market price.</p>
          <p class="mb-0 text-muted"><i class="bi bi-exclamation-triangle"></i> Prices fluctuate with volume. Large auto-orders will move the market!</p>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-list-check"></i> <strong>Active Trade Rules</strong></div>
        <div class="card-body p-0">
          <?php if (empty($tradeRules)): ?>
          <div class="p-4 text-center text-muted"><i class="bi bi-inbox" style="font-size:2rem;"></i><p class="mt-2 mb-0">No auto-trade rules. Create one to get started.</p></div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
              <thead class="table-light">
                <tr><th>Status</th><th>Action</th><th>Resource</th><th>Trigger</th><th>Amount</th><th>Min Bal.</th><th>Customs Stock</th><th>Last Run</th><th class="text-end">Actions</th></tr>
              </thead>
              <tbody>
                <?php foreach ($tradeRules as $tr): $cStock = $customsStock[$tr['resource_id']] ?? 0; ?>
                <tr class="<?= $tr['enabled'] ? '' : 'table-secondary opacity-50' ?>">
                  <td>
                    <form action="auto_trade_action.php" method="POST" class="d-inline">
                      <input type="hidden" name="action" value="toggle_trade"><input type="hidden" name="rule_id" value="<?= $tr['id'] ?>">
                      <button type="submit" class="btn btn-sm <?= $tr['enabled'] ? 'btn-success' : 'btn-outline-secondary' ?>"><i class="bi <?= $tr['enabled'] ? 'bi-check-circle-fill' : 'bi-pause-circle' ?>"></i></button>
                    </form>
                  </td>
                  <td><span class="badge <?= $tr['trade_action'] === 'buy' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= strtoupper($tr['trade_action']) ?></span></td>
                  <td><?= htmlspecialchars($tr['resource_name']) ?></td>
                  <td>
                    <?php if ($tr['trade_action'] === 'buy'): ?>
                      Stock &lt; <strong><?= number_format($tr['threshold']) ?>t</strong>
                      <?php if ($cStock < $tr['threshold']): ?><i class="bi bi-exclamation-circle text-danger ms-1" title="Currently triggered"></i><?php endif; ?>
                    <?php else: ?>
                      Stock ≥ <strong><?= number_format($tr['threshold']) ?>t</strong>
                      <?php if ($cStock >= $tr['threshold']): ?><i class="bi bi-exclamation-circle text-warning ms-1" title="Currently triggered"></i><?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td><strong><?= number_format($tr['order_amount']) ?>t</strong></td>
                  <td><?= $tr['min_balance'] > 0 ? number_format($tr['min_balance'],2) . ' pts' : '—' ?></td>
                  <td><span class="<?= $cStock < ($tr['trade_action'] === 'buy' ? $tr['threshold'] : 0) ? 'text-danger fw-bold' : '' ?>"><?= number_format($cStock, 1) ?>t</span></td>
                  <td class="text-muted small"><?= $tr['last_executed_at'] ? date('M j, H:i', strtotime($tr['last_executed_at'])) : '—' ?></td>
                  <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" onclick="editTrade(<?= $tr['id'] ?>,<?= $tr['threshold'] ?>,<?= $tr['order_amount'] ?>,<?= $tr['min_balance'] ?>)"><i class="bi bi-pencil"></i></button>
                    <form action="auto_trade_action.php" method="POST" class="d-inline" onsubmit="return confirm('Delete this rule?')">
                      <input type="hidden" name="action" value="delete_trade"><input type="hidden" name="rule_id" value="<?= $tr['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php include '_auto_trade_log_partial.php'; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════ -->
  <!-- TAB 2: AUTO RESUPPLY                        -->
  <!-- ═══════════════════════════════════════════ -->
  <?php if ($activeTab === 'resupply'): ?>
  <div class="row">
    <div class="col-lg-5 mb-4">
      <div class="card">
        <div class="card-header bg-dark text-white"><i class="bi bi-plus-circle"></i> <strong>New Resupply Rule</strong></div>
        <div class="card-body">
          <form action="auto_trade_action.php" method="POST">
            <input type="hidden" name="action" value="create_resupply">
            <input type="hidden" name="faction" value="<?= htmlspecialchars($selectedFaction) ?>">

            <div class="mb-3">
              <label class="form-label fw-bold">Town to Keep Supplied</label>
              <select name="town_id" class="form-select" required>
                <option value="">Select town...</option>
                <?php foreach ($towns as $t): ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Resource</label>
              <select name="resource_id" class="form-select" required>
                <option value="">Select...</option>
                <?php
                $lastType = '';
                foreach ($resources as $r):
                    if ($r['resource_type'] !== $lastType):
                        if ($lastType) echo '</optgroup>';
                        echo '<optgroup label="' . htmlspecialchars($r['resource_type']) . '">';
                        $lastType = $r['resource_type'];
                    endif;
                ?>
                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['resource_name']) ?> (<?= number_format($r['buy_price'],2) ?> pts/t)</option>
                <?php endforeach; ?>
                <?php if ($lastType) echo '</optgroup>'; ?>
              </select>
            </div>

            <div class="row mb-3">
              <div class="col-6">
                <label class="form-label fw-bold">Trigger when below</label>
                <div class="input-group">
                  <input type="number" name="low_threshold" class="form-control" value="50" min="1" step="1" required>
                  <span class="input-group-text">tons</span>
                </div>
              </div>
              <div class="col-6">
                <label class="form-label fw-bold">Order amount</label>
                <div class="input-group">
                  <input type="number" name="order_amount" class="form-control" value="100" min="1" step="1" required>
                  <span class="input-group-text">tons</span>
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Min. balance to keep</label>
              <div class="input-group">
                <input type="number" name="min_balance" class="form-control" value="0" min="0" step="0.01">
                <span class="input-group-text">pts</span>
              </div>
              <div class="form-text">Won't buy if it would drop balance below this.</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Transport Type</label>
              <div class="d-flex gap-3">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="transport_type" id="resTruck" value="truck" checked>
                  <label class="form-check-label" for="resTruck"><i class="bi bi-truck"></i> Truck</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="transport_type" id="resTrain" value="train">
                  <label class="form-check-label" for="resTrain"><i class="bi bi-train-front"></i> Train</label>
                </div>
              </div>
            </div>

            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Create Resupply Rule</button>
          </form>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header bg-dark text-white"><i class="bi bi-info-circle"></i> How Resupply Works</div>
        <div class="card-body small">
          <ol class="mb-2">
            <li>Each tick, checks if the town's resource stock is below your threshold.</li>
            <li>Looks for an <strong>idle vehicle</strong> at the faction's <strong>Customs House</strong> that can carry the resource.</li>
            <li>Auto-buys the order amount from the world market (spends faction points).</li>
            <li>Loads and dispatches the vehicle to the requesting town.</li>
          </ol>
          <p class="mb-0 text-muted"><i class="bi bi-exclamation-triangle"></i> Requires an idle truck/train <em>at the Customs House</em>. Station vehicles there to enable resupply.</p>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-box-seam"></i> <strong>Active Resupply Rules</strong></div>
        <div class="card-body p-0">
          <?php if (empty($resupplyRules)): ?>
          <div class="p-4 text-center text-muted"><i class="bi bi-inbox" style="font-size:2rem;"></i><p class="mt-2 mb-0">No resupply rules yet.</p></div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
              <thead class="table-light">
                <tr><th>Status</th><th>Town</th><th>Resource</th><th>Below</th><th>Order</th><th>Min Bal.</th><th>Type</th><th>Last Run</th><th class="text-end">Actions</th></tr>
              </thead>
              <tbody>
                <?php foreach ($resupplyRules as $rs): ?>
                <tr class="<?= $rs['enabled'] ? '' : 'table-secondary opacity-50' ?>">
                  <td>
                    <form action="auto_trade_action.php" method="POST" class="d-inline">
                      <input type="hidden" name="action" value="toggle_resupply"><input type="hidden" name="rule_id" value="<?= $rs['id'] ?>">
                      <button type="submit" class="btn btn-sm <?= $rs['enabled'] ? 'btn-success' : 'btn-outline-secondary' ?>"><i class="bi <?= $rs['enabled'] ? 'bi-check-circle-fill' : 'bi-pause-circle' ?>"></i></button>
                    </form>
                  </td>
                  <td><strong><?= htmlspecialchars($rs['town_name']) ?></strong></td>
                  <td><?= htmlspecialchars($rs['resource_name']) ?></td>
                  <td><span class="badge bg-danger">&lt; <?= number_format($rs['low_threshold']) ?>t</span></td>
                  <td><span class="badge bg-primary"><?= number_format($rs['order_amount']) ?>t</span></td>
                  <td><?= $rs['min_balance'] > 0 ? number_format($rs['min_balance'],2) . ' pts' : '—' ?></td>
                  <td><i class="bi <?= $rs['transport_type'] === 'train' ? 'bi-train-front' : 'bi-truck' ?>"></i></td>
                  <td class="text-muted small"><?= $rs['last_executed_at'] ? date('M j, H:i', strtotime($rs['last_executed_at'])) : '—' ?></td>
                  <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" onclick="editResupply(<?= $rs['id'] ?>,<?= $rs['low_threshold'] ?>,<?= $rs['order_amount'] ?>,<?= $rs['min_balance'] ?>)"><i class="bi bi-pencil"></i></button>
                    <form action="auto_trade_action.php" method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                      <input type="hidden" name="action" value="delete_resupply"><input type="hidden" name="rule_id" value="<?= $rs['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php include '_auto_trade_log_partial.php'; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Edit Trade Rule Modal -->
<div class="modal fade" id="editTradeModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <form action="auto_trade_action.php" method="POST" class="modal-content">
      <input type="hidden" name="action" value="update_trade"><input type="hidden" name="rule_id" id="etRuleId">
      <div class="modal-header"><h5 class="modal-title">Edit Trade Rule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-bold">Threshold</label><div class="input-group"><input type="number" name="threshold" id="etThreshold" class="form-control" min="1" required><span class="input-group-text">tons</span></div></div>
        <div class="mb-3"><label class="form-label fw-bold">Order Amount</label><div class="input-group"><input type="number" name="order_amount" id="etAmount" class="form-control" min="1" required><span class="input-group-text">tons</span></div></div>
        <div class="mb-3"><label class="form-label fw-bold">Min Balance</label><div class="input-group"><input type="number" name="min_balance" id="etMinBal" class="form-control" min="0" step="0.01"><span class="input-group-text">pts</span></div></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Save</button></div>
    </form>
  </div>
</div>

<!-- Edit Resupply Rule Modal -->
<div class="modal fade" id="editResupplyModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <form action="auto_trade_action.php" method="POST" class="modal-content">
      <input type="hidden" name="action" value="update_resupply"><input type="hidden" name="rule_id" id="erRuleId">
      <div class="modal-header"><h5 class="modal-title">Edit Resupply Rule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-bold">Low Threshold</label><div class="input-group"><input type="number" name="low_threshold" id="erThreshold" class="form-control" min="1" required><span class="input-group-text">tons</span></div></div>
        <div class="mb-3"><label class="form-label fw-bold">Order Amount</label><div class="input-group"><input type="number" name="order_amount" id="erAmount" class="form-control" min="1" required><span class="input-group-text">tons</span></div></div>
        <div class="mb-3"><label class="form-label fw-bold">Min Balance</label><div class="input-group"><input type="number" name="min_balance" id="erMinBal" class="form-control" min="0" step="0.01"><span class="input-group-text">pts</span></div></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Save</button></div>
    </form>
  </div>
</div>

<script>
function toggleTradeForm(){
  const isBuy = document.getElementById('actBuy').checked;
  document.getElementById('thresholdLabel').textContent = isBuy ? 'Buy when customs stock <' : 'Sell when customs stock ≥';
  document.getElementById('thresholdHelp').textContent = isBuy ? 'Triggers when customs stock is below this level.' : 'Triggers when customs stock reaches this level.';
  document.getElementById('minBalGroup').style.display = isBuy ? '' : 'none';
}
function editTrade(id,th,amt,mb){
  document.getElementById('etRuleId').value=id;
  document.getElementById('etThreshold').value=th;
  document.getElementById('etAmount').value=amt;
  document.getElementById('etMinBal').value=mb;
  new bootstrap.Modal(document.getElementById('editTradeModal')).show();
}
function editResupply(id,th,amt,mb){
  document.getElementById('erRuleId').value=id;
  document.getElementById('erThreshold').value=th;
  document.getElementById('erAmount').value=amt;
  document.getElementById('erMinBal').value=mb;
  new bootstrap.Modal(document.getElementById('editResupplyModal')).show();
}
</script>

<?php include "files/scripts.php"; ?>
