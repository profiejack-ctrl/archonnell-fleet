<section class="panel">
    <div class="panel-header">
        <div>
            <p class="eyebrow">Drivers</p>
            <h3>Driver Performance</h3>
        </div>
        <?php if ($dashboard['isAdmin']): ?><button class="pill-button">Add Driver</button><?php endif; ?>
    </div>
    <div class="list-stack">
        <?php foreach ($dashboard['drivers'] as $driver): ?>
            <div class="list-item">
                <div class="avatar avatar-dark"><?= htmlspecialchars(strtoupper(substr($driver['name'] ?? 'DR', 0, 2))) ?></div>
                <div>
                    <h4><?= htmlspecialchars($driver['name']) ?></h4>
                    <p><?= htmlspecialchars($driver['role'] ?? '—') ?> • <?= htmlspecialchars($driver['dispatches'] ?? '0 dispatches') ?></p>
                </div>
                <?php if (!empty($driver['score'])): ?>
                    <div class="status-pill status-approved"><?= htmlspecialchars($driver['score']) ?> / 10</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (count($dashboard['drivers'] ?? []) === 0): ?>
            <div style="padding:24px;text-align:center;color:#64748b;">
                <?= !empty($dashboard['backendUnavailable']) ? '⚠️ Centralized backend is offline. Unable to load driver roster.' : 'No drivers registered in centralized backend.' ?>
            </div>
        <?php endif; ?>
    </div>
</section>
