<?php
require_once 'includes/config.php';
requireAdmin();

$pageTitle = 'Rewards Management';
$db = Database::getInstance();
$success = '';
$error = '';

function rewardsDefaultMap(): array {
    return [
        'purchase_7kg_points' => '60',
        'refill_7kg_points' => '50',
        'purchase_11kg_points' => '100',
        'refill_11kg_points' => '90',
        'purchase_22kg_points' => '210',
        'refill_22kg_points' => '200',
        'purchase_above_22kg_points' => '250',
        'refill_above_22kg_points' => '220',
        'bronze_threshold' => '0',
        'silver_threshold' => '1800',
        'gold_threshold' => '3300',
        'platinum_threshold' => '7000',
        'bronze_discount_pct' => '0',
        'silver_discount_pct' => '2',
        'gold_discount_pct' => '3',
        'platinum_discount_pct' => '4',
        'bronze_redemption_points' => '500',
        'bronze_redemption_value' => '40',
        'silver_redemption_points' => '1000',
        'silver_redemption_value' => '90',
        'gold_redemption_points' => '1500',
        'gold_redemption_value' => '140',
        'platinum_redemption_points' => '2000',
        'platinum_redemption_value' => '190',
        'cluster_1_free_credits' => '3',
        'cluster_2_free_credits' => '5',
        'cluster_3_free_credits' => '10',
        'lpg_free_credit_value' => '1',
        'refill_free_credit_value' => '0.5',
        'points_enabled' => '1',
        'rewards_enabled' => '1',
        'tier_discount_stacks_with_redemption' => '1',
        'one_redemption_per_order' => '1',
    ];
}

function ensureRewardSettings(Database $db): void {
    $existingRows = $db->select('rewards_settings');
    $existingMap = [];
    if (is_array($existingRows)) {
        foreach ($existingRows as $row) {
            if (is_array($row) && isset($row['setting_key'])) {
                $existingMap[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        }
    }
    foreach (rewardsDefaultMap() as $key => $value) {
        if (!array_key_exists($key, $existingMap)) {
            $db->insert('rewards_settings', [
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_at' => date('c')
            ]);
        }
    }
}

function loadRewardSettings(Database $db): array {
    $rows = $db->select('rewards_settings');
    $settings = rewardsDefaultMap();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['setting_key'])) {
                $settings[$row['setting_key']] = (string)($row['setting_value'] ?? '');
            }
        }
    }
    return $settings;
}

function tierFromLifetimePoints(int $lifetimePoints, array $settings): string {
    if ($lifetimePoints >= (int)$settings['platinum_threshold']) return 'Platinum';
    if ($lifetimePoints >= (int)$settings['gold_threshold']) return 'Gold';
    if ($lifetimePoints >= (int)$settings['silver_threshold']) return 'Silver';
    return 'Bronze';
}

function tierColorClass(string $tier): string {
    return match ($tier) {
        'Silver' => 'tier-silver',
        'Gold' => 'tier-gold',
        'Platinum' => 'tier-platinum',
        default => 'tier-bronze',
    };
}

ensureRewardSettings($db);

function saveRewardSettings(Database $db, array $input): array {
    $errors = [];
    foreach (rewardsDefaultMap() as $key => $defaultValue) {
        $raw = $input[$key] ?? $defaultValue;
        $value = trim((string)$raw);
        $existing = $db->select('rewards_settings', ['setting_key' => $key]);
        if (is_array($existing) && !empty($existing)) {
            $updated = $db->update('rewards_settings', [
                'setting_value' => $value,
                'updated_at' => date('c')
            ], ['setting_key' => $key]);
            if ($updated === [] && $db->getLastError()) {
                $errors[] = $key . ': ' . $db->getLastError();
            }
        } else {
            $inserted = $db->insert('rewards_settings', [
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_at' => date('c')
            ]);
            if ($inserted === [] && $db->getLastError()) {
                $errors[] = $key . ': ' . $db->getLastError();
            }
        }
    }
    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings']) && isMasterAdmin()) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $saveErrors = saveRewardSettings($db, $_POST);
            if (!empty($saveErrors)) {
                $error = 'Failed to update some rewards settings. ' . htmlspecialchars(implode(' | ', array_slice($saveErrors, 0, 3)));
            } else {
                $success = 'Rewards settings updated successfully.';
                logActivity('update', 'rewards', 'Updated rewards settings');
            }
        } catch (Throwable $e) {
            $error = 'Failed to update rewards settings: ' . $e->getMessage();
        }
    }
}

$settings = loadRewardSettings($db);
$users = $db->select('users', ['role' => 'customer']);
$walletRows = $db->select('user_rewards');
$walletMap = [];
if (is_array($walletRows)) {
    foreach ($walletRows as $wallet) {
        if (is_array($wallet) && isset($wallet['user_id'])) {
            $walletMap[(int)$wallet['user_id']] = $wallet;
        }
    }
}

$members = [];
$totalMembers = 0;
$totalCurrentPoints = 0;
$totalLifetimePoints = 0;
$totalRedeemed = 0;
$tierCounts = ['Bronze' => 0, 'Silver' => 0, 'Gold' => 0, 'Platinum' => 0];

if (is_array($users)) {
    foreach ($users as $user) {
        if (!is_array($user) || (($user['role'] ?? '') !== 'customer')) {
            continue;
        }
        $totalMembers++;
        $userId = (int)($user['user_id'] ?? 0);
        $wallet = $walletMap[$userId] ?? [];
        $currentPoints = (int)($wallet['total_points'] ?? 0);
        $redeemedPoints = (int)($wallet['redeemed_points'] ?? 0);
        $lifetimePoints = (int)($wallet['lifetime_points'] ?? $currentPoints);
        $tier = tierFromLifetimePoints($lifetimePoints, $settings);
        if (($wallet['tier'] ?? null) !== $tier && $userId > 0) {
            $db->update('user_rewards', ['tier' => $tier, 'updated_at' => date('c')], ['user_id' => $userId]);
        }
        $availablePoints = max(0, $currentPoints - $redeemedPoints);
        $totalCurrentPoints += $currentPoints;
        $totalLifetimePoints += $lifetimePoints;
        $totalRedeemed += $redeemedPoints;
        $tierCounts[$tier]++;
        $members[] = [
            'user_id' => $userId,
            'full_name' => (string)($user['full_name'] ?? 'Unknown Customer'),
            'email' => (string)($user['email'] ?? ''),
            'phone' => (string)($user['phone'] ?? ''),
            'current_points' => $currentPoints,
            'redeemed_points' => $redeemedPoints,
            'available_points' => $availablePoints,
            'lifetime_points' => $lifetimePoints,
            'tier' => $tier,
            'created_at' => (string)($user['created_at'] ?? ''),
        ];
    }
}

$search = trim((string)($_GET['search'] ?? ''));
if ($search !== '') {
    $needle = strtolower($search);
    $members = array_values(array_filter($members, function (array $member) use ($needle) {
        return str_contains(strtolower($member['full_name']), $needle)
            || str_contains(strtolower($member['email']), $needle)
            || str_contains(strtolower($member['phone']), $needle)
            || str_contains(strtolower($member['tier']), $needle);
    }));
}

usort($members, function (array $a, array $b) {
    if ($a['lifetime_points'] === $b['lifetime_points']) {
        return strcmp($a['full_name'], $b['full_name']);
    }
    return $b['lifetime_points'] <=> $a['lifetime_points'];
});

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$totalRecords = count($members);
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$membersPage = array_slice($members, $offset, $perPage);

require_once 'includes/header.php';
?>
<style>
.rewards-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap;background:linear-gradient(135deg,#ffffff 0%,#f8fbff 100%);border:1px solid #e2e8f0;border-radius:20px;padding:22px 24px;box-shadow:0 12px 32px rgba(15,23,42,.04);margin-bottom:20px}
.rewards-hero h1{margin:0;font-size:30px;color:#0f172a}
.rewards-hero p{margin:8px 0 0;color:#64748b;max-width:760px}
.hero-pill{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;background:#eef2ff;color:#4f46e5;font-weight:700;font-size:13px}
.icon-chip{width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center}
.icon-chip svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.tier-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:18px 0 24px}
.tier-box{border-radius:18px;padding:18px;color:#fff;min-height:110px;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 10px 22px rgba(15,23,42,.08)}
.tier-box strong{font-size:18px}
.tier-box div{font-size:13px}
.tier-bronze{background:linear-gradient(135deg,#d97706,#92400e)}
.tier-silver{background:linear-gradient(135deg,#94a3b8,#475569)}
.tier-gold{background:linear-gradient(135deg,#f59e0b,#d97706)}
.tier-platinum{background:linear-gradient(135deg,#8b5cf6,#5b21b6)}
.section-card{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:22px;box-shadow:0 10px 30px rgba(15,23,42,.04);margin-bottom:22px}
.section-title{margin:0 0 8px;font-size:26px;color:#0f172a}
.section-subtitle{margin:0 0 18px;color:#64748b;font-size:14px}
.settings-groups{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px}
.settings-group{border:1px solid #e2e8f0;border-radius:18px;padding:18px;background:linear-gradient(180deg,#ffffff,#fafcff)}
.settings-group h3{margin:0 0 14px;font-size:16px;color:#0f172a}
.settings-group p{margin:-4px 0 12px;color:#64748b;font-size:13px}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
.field label{display:block;margin-bottom:8px;color:#334155;font-size:13px;font-weight:700}
.field input{width:100%;padding:12px 14px;border:1px solid #cbd5e1;border-radius:12px;font-size:14px;background:#fff;transition:.2s}
.field input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12)}
.actions{display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap}
.search-box{display:flex;gap:10px;flex-wrap:wrap}
.search-box input{min-width:280px;padding:12px 14px;border:1px solid #cbd5e1;border-radius:12px}
.btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;border-radius:12px;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;font-weight:700;padding:12px 18px;cursor:pointer;box-shadow:0 8px 18px rgba(79,70,229,.2)}
.table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:16px}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:14px 16px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:14px}
.table th{background:#f8fafc;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.06em}
.badge{display:inline-flex;align-items:center;padding:7px 10px;border-radius:999px;font-size:12px;font-weight:800}
.badge.tier-bronze{background:#ffedd5;color:#9a3412}.badge.tier-silver{background:#e2e8f0;color:#475569}.badge.tier-gold{background:#fef3c7;color:#b45309}.badge.tier-platinum{background:#ede9fe;color:#6d28d9}
.muted{color:#64748b;font-size:13px}.pagination{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;margin-top:16px}.pagination a,.pagination span{min-width:38px;height:38px;padding:0 12px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #cbd5e1;color:#334155;text-decoration:none}.pagination .active{background:#4f46e5;border-color:#4f46e5;color:#fff}
.alert{border-radius:16px;padding:14px 16px;margin-bottom:18px;font-weight:600}.alert.success{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}.alert.error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.save-row{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:22px;flex-wrap:wrap;padding-top:18px;border-top:1px solid #e5e7eb}
.save-note{color:#64748b;font-size:13px;max-width:640px}
@media (max-width:768px){.rewards-hero h1,.section-title{font-size:24px}.search-box input{min-width:100%}}
</style>

<div class="rewards-hero">
    <div>
        <div class="hero-pill"><span class="icon-chip"><svg viewBox="0 0 24 24"><path d="M12 3l7 4v5c0 5-3.5 7.5-7 9-3.5-1.5-7-4-7-9V7z"/><path d="M9 12l2 2 4-4"/></svg></span>Rewards Management</div>
        <h1>Rewards & Loyalty Settings</h1>
        <p>Manage lifetime points, automatic tier discounts, redemption rules, and cluster-based Platinum free delivery in one place.</p>
    </div>
    <div class="hero-pill"><span class="icon-chip"><svg viewBox="0 0 24 24"><path d="M12 2v20"/><path d="M17 5H9a3 3 0 0 0 0 6h6a3 3 0 0 1 0 6H6"/></svg></span>Cluster-based rewards active</div>
</div>

<?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="tier-list">
    <div class="tier-box tier-bronze"><strong>Bronze</strong><div><?= number_format($tierCounts['Bronze']) ?> member(s)</div><div>Points only</div></div>
    <div class="tier-box tier-silver"><strong>Silver</strong><div><?= number_format($tierCounts['Silver']) ?> member(s)</div><div>2% automatic discount</div></div>
    <div class="tier-box tier-gold"><strong>Gold</strong><div><?= number_format($tierCounts['Gold']) ?> member(s)</div><div>3% automatic discount</div></div>
    <div class="tier-box tier-platinum"><strong>Platinum</strong><div><?= number_format($tierCounts['Platinum']) ?> member(s)</div><div>4% + free delivery rule</div></div>
</div>

<div class="section-card">
    <h2 class="section-title">Rewards Settings</h2>
    <p class="section-subtitle">All rewards logic below is used by checkout and customer tier progression. Update values carefully, then save once.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken()) ?>">
        <input type="hidden" name="update_settings" value="1">

        <div class="settings-groups">
        <section class="settings-group">
            <h3>Base Points</h3>
            <p>Points earned per product type and size.</p>
            <div class="form-grid">
            <?php foreach ([
                'purchase_7kg_points' => '7kg LPG points',
                'refill_7kg_points' => '7kg refill points',
                'purchase_11kg_points' => '11kg LPG points',
                'refill_11kg_points' => '11kg refill points',
                'purchase_22kg_points' => '22kg LPG points',
                'refill_22kg_points' => '22kg refill points',
                'purchase_above_22kg_points' => 'Above 22kg LPG points',
                'refill_above_22kg_points' => 'Above 22kg refill points',
            ] as $key => $label): ?>
                <div class="field">
                    <label for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                    <input id="<?= $key ?>" name="<?= $key ?>" type="number" min="0" value="<?= htmlspecialchars($settings[$key]) ?>">
                </div>
            <?php endforeach; ?>
            </div>
        </section>

        <section class="settings-group">
            <h3>Tier Unlock Criteria</h3>
            <p>Lifetime points required to unlock each tier.</p>
            <div class="form-grid">
            <?php foreach ([
                'bronze_threshold' => 'Bronze lifetime points',
                'silver_threshold' => 'Silver lifetime points',
                'gold_threshold' => 'Gold lifetime points',
                'platinum_threshold' => 'Platinum lifetime points',
            ] as $key => $label): ?>
                <div class="field">
                    <label for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                    <input id="<?= $key ?>" name="<?= $key ?>" type="number" min="0" value="<?= htmlspecialchars($settings[$key]) ?>">
                </div>
            <?php endforeach; ?>
            </div>
        </section>

        <section class="settings-group">
            <h3>Automatic Tier Discounts</h3>
            <p>Applied automatically during checkout based on current tier.</p>
            <div class="form-grid">
            <?php foreach ([
                'bronze_discount_pct' => 'Bronze discount %',
                'silver_discount_pct' => 'Silver discount %',
                'gold_discount_pct' => 'Gold discount %',
                'platinum_discount_pct' => 'Platinum discount %',
            ] as $key => $label): ?>
                <div class="field">
                    <label for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                    <input id="<?= $key ?>" name="<?= $key ?>" type="number" step="0.1" min="0" value="<?= htmlspecialchars($settings[$key]) ?>">
                </div>
            <?php endforeach; ?>
            </div>
        </section>

        <section class="settings-group">
            <h3>Tier Redemption Rules</h3>
            <p>Only one redemption option may be used per order.</p>
            <div class="form-grid">
            <?php foreach ([
                'bronze_redemption_points' => 'Bronze points required',
                'bronze_redemption_value' => 'Bronze discount value',
                'silver_redemption_points' => 'Silver points required',
                'silver_redemption_value' => 'Silver discount value',
                'gold_redemption_points' => 'Gold points required',
                'gold_redemption_value' => 'Gold discount value',
                'platinum_redemption_points' => 'Platinum points required',
                'platinum_redemption_value' => 'Platinum discount value',
            ] as $key => $label): ?>
                <div class="field">
                    <label for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                    <input id="<?= $key ?>" name="<?= $key ?>" type="number" min="0" value="<?= htmlspecialchars($settings[$key]) ?>">
                </div>
            <?php endforeach; ?>
            </div>
        </section>

        <section class="settings-group">
            <h3>Platinum Free Delivery Rules</h3>
            <p>Cluster requirements are checked per order only.</p>
            <div class="form-grid">
            <?php foreach ([
                'cluster_1_free_credits' => 'Cluster 1 free credits',
                'cluster_2_free_credits' => 'Cluster 2 free credits',
                'cluster_3_free_credits' => 'Cluster 3 free credits',
                'lpg_free_credit_value' => 'LPG free credit value',
                'refill_free_credit_value' => 'Refill free credit value',
            ] as $key => $label): ?>
                <div class="field">
                    <label for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                    <input id="<?= $key ?>" name="<?= $key ?>" type="number" step="0.1" min="0" value="<?= htmlspecialchars($settings[$key]) ?>">
                </div>
            <?php endforeach; ?>
            </div>
        </section>

        <section class="settings-group">
            <h3>Controls</h3>
            <p>Feature switches used by the rewards and checkout APIs.</p>
            <div class="form-grid">
            <?php foreach ([
                'points_enabled' => 'Points enabled (1/0)',
                'rewards_enabled' => 'Rewards enabled (1/0)',
                'tier_discount_stacks_with_redemption' => 'Allow tier discount + redemption (1/0)',
                'one_redemption_per_order' => 'One redemption per order (1/0)',
            ] as $key => $label): ?>
                <div class="field">
                    <label for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                    <input id="<?= $key ?>" name="<?= $key ?>" type="number" min="0" max="1" value="<?= htmlspecialchars($settings[$key]) ?>">
                </div>
            <?php endforeach; ?>
            </div>
        </section>
        </div>

        <div class="save-row">
            <div class="save-note">Tip: cluster free-credit requirements should match your delivery tier policy in Settings so Platinum free delivery stays sustainable.</div>
            <button class="btn-primary" type="submit">Save Rewards Settings</button>
        </div>
    </form>
</div>

<div class="section-card">
    <div class="actions">
        <h2 class="section-title" style="margin:0;">Customer Rewards Overview</h2>
        <form method="get" class="search-box">
            <input type="text" name="search" placeholder="Search customer, email, phone, or tier" value="<?= htmlspecialchars($search) ?>">
            <button class="btn-primary" type="submit">Search</button>
        </form>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Tier</th>
                    <th>Current Points</th>
                    <th>Redeemed</th>
                    <th>Available</th>
                    <th>Lifetime Points</th>
                    <th>Joined</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($membersPage)): ?>
                <tr><td colspan="7" style="text-align:center;color:#64748b;">No customer rewards data found.</td></tr>
            <?php else: ?>
                <?php foreach ($membersPage as $member): ?>
                    <tr>
                        <td>
                            <div style="font-weight:700;"><?= htmlspecialchars($member['full_name']) ?></div>
                            <div class="muted"><?= htmlspecialchars($member['email']) ?><?= $member['phone'] ? ' • ' . htmlspecialchars($member['phone']) : '' ?></div>
                        </td>
                        <td><span class="badge <?= tierColorClass($member['tier']) ?>"><?= htmlspecialchars($member['tier']) ?></span></td>
                        <td><?= number_format($member['current_points']) ?></td>
                        <td><?= number_format($member['redeemed_points']) ?></td>
                        <td><?= number_format($member['available_points']) ?></td>
                        <td><strong><?= number_format($member['lifetime_points']) ?></strong></td>
                        <td><?= htmlspecialchars($member['created_at'] ? date('M d, Y', strtotime($member['created_at'])) : '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="active"><?= $i ?></span>
            <?php else: ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
