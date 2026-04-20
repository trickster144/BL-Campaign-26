<!-- Recent Auto-Trade/Resupply Log -->
<div class="card">
  <div class="card-header bg-dark text-white"><i class="bi bi-clock-history"></i> <strong>Recent Activity</strong></div>
  <div class="card-body p-0">
    <?php if (empty($logs)): ?>
    <div class="p-4 text-center text-muted">No activity yet.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light">
          <tr><th>Time</th><th>Type</th><th>Resource</th><th>Action</th><th>Amount</th><th>Points</th></tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
          <tr>
            <td class="text-muted small"><?= date('M j, H:i', strtotime($log['created_at'])) ?></td>
            <td><span class="badge <?= $log['log_type'] === 'trade' ? 'bg-info text-dark' : 'bg-purple' ?> bg-opacity-75"><?= ucfirst($log['log_type']) ?></span></td>
            <td><?= htmlspecialchars($log['resource_name']) ?></td>
            <td class="small"><?= htmlspecialchars($log['action_taken']) ?></td>
            <td><strong><?= number_format($log['amount'], 1) ?>t</strong></td>
            <td class="text-muted"><?= $log['points_spent'] != 0 ? number_format($log['points_spent'], 2) . ' pts' : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
