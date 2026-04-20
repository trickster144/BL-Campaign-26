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

// Fetch towns for this faction
$towns = [];
$tRes = $conn->query("SELECT id, name FROM towns WHERE side = '$selectedFaction' ORDER BY name");
if ($tRes) { while ($t = $tRes->fetch_assoc()) $towns[] = $t; }

// Fetch resources
$resources = [];
$rRes = $conn->query("SELECT id, resource_name, resource_type FROM world_prices ORDER BY resource_type, resource_name");
if ($rRes) { while ($r = $rRes->fetch_assoc()) $resources[] = $r; }

// Fetch existing rules
$rules = [];
$rlRes = $conn->query("
    SELECT at.*,
           t1.name as from_town_name,
           t2.name as to_town_name,
           wp.resource_name, wp.resource_type
    FROM auto_transport at
    JOIN towns t1 ON at.from_town_id = t1.id
    JOIN towns t2 ON at.to_town_id = t2.id
    JOIN world_prices wp ON at.resource_id = wp.id
    WHERE at.faction = '$selectedFaction'
    ORDER BY at.enabled DESC, t1.name, wp.resource_name
");
if ($rlRes) { while ($rl = $rlRes->fetch_assoc()) $rules[] = $rl; }

// Fetch recent dispatch log
$logs = [];
$lgRes = $conn->query("
    SELECT atl.*, at.faction
    FROM auto_transport_log atl
    JOIN auto_transport at ON atl.auto_transport_id = at.id
    WHERE at.faction = '$selectedFaction'
    ORDER BY atl.dispatched_at DESC LIMIT 25
");
if ($lgRes) { while ($lg = $lgRes->fetch_assoc()) $logs[] = $lg; }

// Build adjacency for destination filtering (towns connected by road/rail)
$connections = [];
$cRes = $conn->query("
    SELECT td.town_id_1, td.town_id_2
    FROM town_distances td
    JOIN towns t1 ON td.town_id_1 = t1.id
    JOIN towns t2 ON td.town_id_2 = t2.id
    WHERE t1.side = '$selectedFaction' AND t2.side = '$selectedFaction'
");
if ($cRes) {
    while ($c = $cRes->fetch_assoc()) {
        $connections[(int)$c['town_id_1']][] = (int)$c['town_id_2'];
        $connections[(int)$c['town_id_2']][] = (int)$c['town_id_1'];
    }
}

$msg = $_GET['msg'] ?? '';
$msgMap = [
    'created' => ['success', 'Auto-transport rule created.'],
    'deleted' => ['success', 'Rule deleted.'],
    'toggled' => ['success', 'Rule toggled.'],
    'updated' => ['success', 'Rule updated.'],
    'duplicate' => ['warning', 'A rule for this route/resource already exists.'],
    'invalid_towns' => ['danger', 'Invalid town selection.'],
    'same_town' => ['danger', 'Origin and destination must be different.'],
    'no_route' => ['danger', 'No road/rail connection between those towns.'],
    'invalid_resource' => ['danger', 'Invalid resource.'],
    'denied' => ['danger', 'Access denied.'],
];

include "files/header.php";
include "files/sidebar.php";
?>

<div id="content" class="app-content">
  <h1 class="page-header">Auto Transport <small class="text-muted">— Automated Resource Dispatch</small></h1>

  <?php if (isset($msgMap[$msg])): ?>
  <div class="alert alert-<?= $msgMap[$msg][0] ?> alert-dismissible fade show">
    <?= $msgMap[$msg][1] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <?php if (count($allowedFactions) > 1): ?>
  <ul class="nav nav-tabs mb-3">
    <?php foreach ($allowedFactions as $f): ?>
    <li class="nav-item">
      <a class="nav-link <?= $f === $selectedFaction ? 'active' : '' ?>" href="?faction=<?= $f ?>">
        <span class="text-<?= $f === 'blue' ? 'primary' : ($f === 'red' ? 'danger' : 'success') ?> fw-bold text-capitalize"><?= $f ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <div class="row">
    <!-- New Rule Form -->
    <div class="col-lg-5 mb-4">
      <div class="card">
        <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
          <i class="bi bi-plus-circle"></i> <strong>New Auto-Transport Rule</strong>
        </div>
        <div class="card-body">
          <form action="auto_transport_action.php" method="POST" id="ruleForm">
            <input type="hidden" name="action" value="create_rule">
            <input type="hidden" name="faction" value="<?= htmlspecialchars($selectedFaction) ?>">

            <div class="mb-3">
              <label class="form-label fw-bold">From Town</label>
              <select name="from_town_id" id="fromTown" class="form-select" required>
                <option value="">Select origin...</option>
                <?php foreach ($towns as $t): ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">To Town</label>
              <select name="to_town_id" id="toTown" class="form-select" required>
                <option value="">Select origin first...</option>
              </select>
              <div class="form-text">Only connected towns are shown.</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Resource</label>
              <select name="resource_id" class="form-select" required>
                <option value="">Select resource...</option>
                <?php
                $lastType = '';
                foreach ($resources as $r):
                    if ($r['resource_type'] !== $lastType):
                        if ($lastType) echo '</optgroup>';
                        echo '<optgroup label="' . htmlspecialchars($r['resource_type']) . '">';
                        $lastType = $r['resource_type'];
                    endif;
                ?>
                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['resource_name']) ?></option>
                <?php endforeach; ?>
                <?php if ($lastType) echo '</optgroup>'; ?>
              </select>
            </div>

            <div class="row mb-3">
              <div class="col-6">
                <label class="form-label fw-bold">Trigger When ≥</label>
                <div class="input-group">
                  <input type="number" name="threshold" class="form-control" value="100" min="1" step="1" required>
                  <span class="input-group-text">tons</span>
                </div>
                <div class="form-text">Stock level that triggers dispatch.</div>
              </div>
              <div class="col-6">
                <label class="form-label fw-bold">Send Amount</label>
                <div class="input-group">
                  <input type="number" name="send_amount" class="form-control" value="50" min="1" step="1" required>
                  <span class="input-group-text">tons</span>
                </div>
                <div class="form-text">Amount to load per dispatch.</div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Transport Type</label>
              <div class="d-flex gap-3">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="transport_type" id="typeTruck" value="truck" checked>
                  <label class="form-check-label" for="typeTruck"><i class="bi bi-truck"></i> Truck</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="transport_type" id="typeTrain" value="train">
                  <label class="form-check-label" for="typeTrain"><i class="bi bi-train-front"></i> Train</label>
                </div>
              </div>
            </div>

            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="return_empty" id="returnEmpty" checked>
              <label class="form-check-label" for="returnEmpty">Return vehicle empty after delivery</label>
            </div>

            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Create Rule</button>
          </form>
        </div>
      </div>

      <!-- How It Works -->
      <div class="card mt-3">
        <div class="card-header bg-dark text-white"><i class="bi bi-info-circle"></i> How It Works</div>
        <div class="card-body small">
          <ol class="mb-0">
            <li>Each game tick, the system checks your rules.</li>
            <li>If the resource stock in the origin town ≥ your threshold, it looks for an <strong>idle</strong> vehicle/train.</li>
            <li>It loads up to the <strong>send amount</strong> (capped by vehicle capacity and actual stock).</li>
            <li>Fuel is deducted from the origin town automatically.</li>
            <li>The vehicle travels the route and delivers at the destination.</li>
          </ol>
          <hr>
          <p class="mb-0 text-muted"><i class="bi bi-exclamation-triangle"></i> Requires an idle truck/train of the correct cargo class in the origin town, plus sufficient fuel.</p>
        </div>
      </div>
    </div>

    <!-- Active Rules -->
    <div class="col-lg-7">
      <div class="card mb-4">
        <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
          <i class="bi bi-arrow-repeat"></i> <strong>Active Rules</strong>
          <span class="badge bg-secondary ms-auto"><?= count($rules) ?></span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($rules)): ?>
          <div class="p-4 text-center text-muted">
            <i class="bi bi-inbox" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0">No auto-transport rules yet. Create one to get started.</p>
          </div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th>Status</th>
                  <th>Route</th>
                  <th>Resource</th>
                  <th>Trigger ≥</th>
                  <th>Send</th>
                  <th>Type</th>
                  <th>Last Sent</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rules as $rule): ?>
                <tr class="<?= $rule['enabled'] ? '' : 'table-secondary opacity-50' ?>">
                  <td>
                    <form action="auto_transport_action.php" method="POST" class="d-inline">
                      <input type="hidden" name="action" value="toggle_rule">
                      <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                      <button type="submit" class="btn btn-sm <?= $rule['enabled'] ? 'btn-success' : 'btn-outline-secondary' ?>" title="<?= $rule['enabled'] ? 'Active — click to pause' : 'Paused — click to activate' ?>">
                        <i class="bi <?= $rule['enabled'] ? 'bi-check-circle-fill' : 'bi-pause-circle' ?>"></i>
                      </button>
                    </form>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars($rule['from_town_name']) ?></strong>
                    <i class="bi bi-arrow-right text-muted mx-1"></i>
                    <strong><?= htmlspecialchars($rule['to_town_name']) ?></strong>
                    <?php if ($rule['return_empty']): ?><span class="badge bg-info text-dark ms-1" title="Returns empty">↩</span><?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($rule['resource_name']) ?></td>
                  <td>
                    <span class="badge bg-warning text-dark"><?= number_format($rule['threshold']) ?>t</span>
                  </td>
                  <td>
                    <span class="badge bg-primary"><?= number_format($rule['send_amount']) ?>t</span>
                  </td>
                  <td>
                    <i class="bi <?= $rule['transport_type'] === 'train' ? 'bi-train-front' : 'bi-truck' ?>"></i>
                    <?= ucfirst($rule['transport_type']) ?>
                  </td>
                  <td class="text-muted small">
                    <?= $rule['last_dispatched_at'] ? date('M j, H:i', strtotime($rule['last_dispatched_at'])) : '—' ?>
                  </td>
                  <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary" onclick="editRule(<?= $rule['id'] ?>, <?= $rule['threshold'] ?>, <?= $rule['send_amount'] ?>)" title="Edit amounts"><i class="bi bi-pencil"></i></button>
                    <form action="auto_transport_action.php" method="POST" class="d-inline" onsubmit="return confirm('Delete this rule?')">
                      <input type="hidden" name="action" value="delete_rule">
                      <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
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

      <!-- Recent Dispatch Log -->
      <div class="card">
        <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
          <i class="bi bi-clock-history"></i> <strong>Recent Auto-Dispatches</strong>
        </div>
        <div class="card-body p-0">
          <?php if (empty($logs)): ?>
          <div class="p-4 text-center text-muted">No dispatches yet.</div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light">
                <tr><th>Time</th><th>Route</th><th>Resource</th><th>Amount</th><th>Vehicle</th></tr>
              </thead>
              <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                  <td class="text-muted small"><?= date('M j, H:i', strtotime($log['dispatched_at'])) ?></td>
                  <td><?= htmlspecialchars($log['from_town']) ?> → <?= htmlspecialchars($log['to_town']) ?></td>
                  <td><?= htmlspecialchars($log['resource_name']) ?></td>
                  <td><strong><?= number_format($log['amount_sent'], 1) ?>t</strong></td>
                  <td><?= $log['vehicle_id'] ? "Truck #" . $log['vehicle_id'] : "Train #" . $log['train_id'] ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Edit Rule Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <form action="auto_transport_action.php" method="POST" class="modal-content">
      <input type="hidden" name="action" value="update_rule">
      <input type="hidden" name="rule_id" id="editRuleId">
      <div class="modal-header">
        <h5 class="modal-title">Edit Rule</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-bold">Trigger When ≥</label>
          <div class="input-group">
            <input type="number" name="threshold" id="editThreshold" class="form-control" min="1" step="1" required>
            <span class="input-group-text">tons</span>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Send Amount</label>
          <div class="input-group">
            <input type="number" name="send_amount" id="editSendAmount" class="form-control" min="1" step="1" required>
            <span class="input-group-text">tons</span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary w-100">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
// Town connections for dynamic destination filtering
const connections = <?= json_encode($connections) ?>;
const towns = <?= json_encode($towns) ?>;
const townMap = {};
towns.forEach(t => townMap[t.id] = t.name);

document.getElementById('fromTown').addEventListener('change', function(){
    const fromId = parseInt(this.value);
    const toSelect = document.getElementById('toTown');
    toSelect.innerHTML = '<option value="">Select destination...</option>';
    if (!fromId || !connections[fromId]) return;
    connections[fromId].forEach(tid => {
        if (townMap[tid]) {
            const opt = document.createElement('option');
            opt.value = tid;
            opt.textContent = townMap[tid];
            toSelect.appendChild(opt);
        }
    });
});

function editRule(id, threshold, sendAmount) {
    document.getElementById('editRuleId').value = id;
    document.getElementById('editThreshold').value = threshold;
    document.getElementById('editSendAmount').value = sendAmount;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php include "files/scripts.php"; ?>
