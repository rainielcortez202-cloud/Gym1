<?php
session_start();
require '../auth.php';
require '../connection.php';
require '../includes/status_sync.php';

if ($_SESSION['role'] !== 'admin') { header("Location: ../login.php"); exit; }

$current = basename($_SERVER['PHP_SELF']);
$filter = $_GET['filter'] ?? 'all';

// --- BULK SYNC STATUS WITH DATABASE ---
bulkSyncMembers($pdo);

// --- PAGINATION (Max 10 per request) ---
$m_page = isset($_GET['m_page']) ? max(1, (int)$_GET['m_page']) : 1;
$s_page = isset($_GET['s_page']) ? max(1, (int)$_GET['s_page']) : 1;
$limit = 10;
$m_offset = ($m_page - 1) * $limit;
$s_offset = ($s_page - 1) * $limit;

// --- ROBUST POSTGRESQL MEMBER QUERY ---
if ($filter === 'expiring') {
    $m_sql = "
        WITH LatestSales AS (
            SELECT user_id, MAX(expires_at) as latest_expiry 
            FROM sales 
            WHERE user_id IS NOT NULL
            GROUP BY user_id
        )
        SELECT u.*, ls.latest_expiry 
        FROM users u 
        JOIN LatestSales ls ON u.id = ls.user_id 
        WHERE u.role = 'member' 
        AND u.status = 'active'
        AND ls.latest_expiry >= CURRENT_TIMESTAMP 
        AND ls.latest_expiry <= (CURRENT_TIMESTAMP + INTERVAL '7 days')
        ORDER BY ls.latest_expiry ASC
    ";
    $m_total = $pdo->query("
        WITH LatestSales AS (SELECT user_id, MAX(expires_at) as latest_expiry FROM sales WHERE user_id IS NOT NULL GROUP BY user_id)
        SELECT COUNT(*) FROM users u JOIN LatestSales ls ON u.id = ls.user_id 
        WHERE u.role = 'member' AND u.status = 'active'
        AND ls.latest_expiry >= CURRENT_TIMESTAMP AND ls.latest_expiry <= (CURRENT_TIMESTAMP + INTERVAL '7 days')
    ")->fetchColumn();
} else {
    $m_sql = "
        SELECT u.*, s.latest_expiry FROM users u 
        LEFT JOIN (SELECT user_id, MAX(expires_at) as latest_expiry FROM sales GROUP BY user_id) s ON u.id = s.user_id 
        WHERE u.role = 'member' 
        ORDER BY u.id DESC
    ";
    $m_total = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
}

$s_total = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'staff'")->fetchColumn();

$m_total_pages = ceil($m_total / $limit);
$s_total_pages = ceil($s_total / $limit);

$m_sql .= " LIMIT $limit OFFSET $m_offset";
$members = $pdo->query($m_sql)->fetchAll(PDO::FETCH_ASSOC);
$staffs  = $pdo->query("SELECT * FROM users WHERE role='staff' ORDER BY id DESC LIMIT $limit OFFSET $s_offset")->fetchAll(PDO::FETCH_ASSOC);

function maskEmailPHP($email) {
    if (!$email) return 'N/A';
    $parts = explode("@", $email);
    if (count($parts) < 2) return $email;
    $name = $parts[0];
    $len = strlen($name);
    if ($len <= 4) { 
        $maskedName = substr($name, 0, 1) . str_repeat('*', max(3, $len - 1)); 
    } else { 
        $maskedName = substr($name, 0, 2) . str_repeat('*', $len - 3) . substr($name, -1); 
    }
    return $maskedName . "@" . $parts[1];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Users | Arts Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Supabase SDK -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
    <script src="../assets/js/supabase-config.php"></script>
    
    <style>
        :root {
            --primary-red: #e63946; --bg-body: #f8f9fa; --bg-card: #ffffff;
            --text-main: #1a1a1a; --text-muted: #8e8e93; --border-color: #f1f1f1;
            --sidebar-width: 260px; --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body.dark-mode-active {
            --bg-body: #0a0a0a; --bg-card: #121212; --text-main: #f5f5f7;
            --text-muted: #86868b; --border-color: #1c1c1e; --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        body { 
            font-family: 'Inter', sans-serif; background-color: var(--bg-body); 
            color: var(--text-main); transition: var(--transition); letter-spacing: -0.01em;
        }

        h1, h2, h3, h4, h5 { font-family: 'Oswald', sans-serif; text-transform: uppercase; }

        #sidebar { width: var(--sidebar-width); height: 100vh; position: fixed; left: 0; top: 0; z-index: 1100; transition: var(--transition); }
        #main { margin-left: var(--sidebar-width); transition: var(--transition); min-height: 100vh; padding: 2rem; }
        #main.expanded { margin-left: 80px; }
        #sidebar.collapsed { width: 80px; }

        @media (max-width: 991.98px) {
            #main { margin-left: 0 !important; padding: 1.5rem; }
            #sidebar { left: calc(var(--sidebar-width) * -1); }
            #sidebar.show { left: 0; box-shadow: 10px 0 30px rgba(0,0,0,0.1); }
            .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1090; }
            .sidebar-overlay.show { display: block; }
            #main.expanded { margin-left: var(--sidebar-width) !important; }
        }

        .card-table { background: var(--bg-card); border-radius: 20px; padding: 24px; box-shadow: var(--card-shadow); border: none; margin-bottom: 2rem; }
        .table thead th { background: var(--bg-card); color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; border-bottom: 1px solid var(--border-color); padding: 15px; white-space: nowrap; }
        .table tbody td { padding: 15px; color: var(black); border-bottom: 1px solid var(--border-color); }
        
        .col-qr { width: 80px; text-align: center; }

        .btn-primary-gym { background: var(--primary-red); color: white; border: none; border-radius: 10px; font-weight: 600; padding: 10px 20px; }
        .btn-primary-gym:hover { background: #d62839; color: white; }
        .top-header { background: transparent; padding: 0 0 2rem 0; display: flex; align-items: center; justify-content: space-between; }
        
        .table-responsive { max-height: 450px; overflow-y: auto; }
        .table thead th { position: sticky; top: 0; z-index: 5; }
    </style>
</head>
<body class="<?= isset($_COOKIE['theme']) && $_COOKIE['theme'] == 'dark' ? 'dark-mode-active' : '' ?>">

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<?php include '_sidebar.php'; ?>

<div id="main">
    <header class="top-header">
        <div>
            <button class="btn btn-light d-lg-none me-2" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
            <h4 class="mb-0 fw-bold">User Management</h4>
            <p class="text-muted small mb-0">ADMIN PANEL</p>
             
        </div>
        
        <div class="d-flex align-items-center gap-3">
           <?php include '../global_clock.php'; ?>  
        </div>
    </header>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div class="btn-group bg-white p-1 rounded-3 shadow-sm border">
            <a href="?filter=all" class="btn btn-sm <?= $filter=='all'?'btn-danger active':'btn-light' ?> px-4 fw-bold" style="border-radius: 8px;">All Members</a>
            <a href="?filter=expiring" class="btn btn-sm <?= $filter=='expiring'?'btn-danger active':'btn-light' ?> px-4 fw-bold" style="border-radius: 8px;">Expiring Soon</a>
        </div>
        <button class="btn btn-dark btn-sm fw-bold" onclick="openAddModal('member')">
            Add Member
        </button>
    </div>

    <!-- MEMBERS TABLE -->
    <div class="card-table">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h6 class="fw-bold mb-0">Members Directory</h6>
            <input type="text" id="mSearch" class="form-control bg-light border-0" style="width: 250px;" placeholder="Search name...">
        </div>
        <div class="table-responsive">
            <table class="table align-middle" id="mTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th class="col-qr">QR Pass</th>
                        <th>Status</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($members as $m): 
                        $latest = $m['latest_expiry'];
                        $is_active = ($m['status'] === 'active');
                        $qr_data = $m['qr_code'] ?: $m['id'];
                    ?>
                    <tr>
                        <td class="fw-bold name-cell"><?= htmlspecialchars($m['full_name']) ?></td>
                        <td style="font-family: monospace; color: #666;"><?= maskEmailPHP($m['email']) ?></td>
                        <!-- QR BUTTON -->
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-dark border-0" onclick="viewQR('<?= $qr_data ?>','<?= addslashes($m['full_name']) ?>')">
                                <i class="bi bi-qr-code fs-5"></i>
                            </button>
                        </td>
                        <td><span class="badge <?= $is_active?'bg-success-subtle text-success':'bg-danger-subtle text-danger' ?> border px-3"><?= $is_active?'Active':'Inactive' ?></span></td>
                        <td>
                            <?php if (!$is_active): ?>
                                <div class="d-flex gap-2">
                                    <select class="form-select form-select-sm rate-select" style="width: 130px;">
                                        <option value="400">Student (400)</option><option value="500" selected>Regular (500)</option>
                                    </select>
                                    <button class="btn btn-dark btn-sm fw-bold" onclick="pay(<?= $m['id'] ?>, this)">PAY</button>
                                </div>
                            <?php else: ?>
                                <small class="fw-bold text-success text-uppercase">Until: <?= date('M d, Y', strtotime($m['latest_expiry'])) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination Members -->
        <?php if ($m_total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <li class="page-item <?= ($m_page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?filter=<?= $filter ?>&m_page=<?= $m_page-1 ?>&s_page=<?= $s_page ?>">Previous</a>
                </li>
                <?php for($i=1; $i<=$m_total_pages; $i++): ?>
                    <li class="page-item <?= ($i == $m_page) ? 'active' : '' ?>">
                        <a class="page-link" href="?filter=<?= $filter ?>&m_page=<?= $i ?>&s_page=<?= $s_page ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= ($m_page >= $m_total_pages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?filter=<?= $filter ?>&m_page=<?= $m_page+1 ?>&s_page=<?= $s_page ?>">Next</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>

    <!-- STAFF TABLE -->
    <div class="card-table">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h6 class="fw-bold mb-0">Staff Management</h6>
            <div class="d-flex gap-2">
                <input type="text" id="sSearch" class="form-control bg-light border-0" style="width: 200px;" placeholder="Search staff...">
                <button class="btn btn-dark btn-sm fw-bold" onclick="openAddModal('staff')">Add Staff</button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle" id="sTable">
                <thead><tr><th>Name</th><th>Email</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    <?php foreach($staffs as $s): ?>
                    <tr>
                        <td class="fw-bold name-cell"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td style="font-family: monospace; color: #666;"><?= maskEmailPHP($s['email']) ?></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary border-0 me-2" onclick="editUser(<?= $s['id'] ?>,'<?= addslashes($s['full_name']) ?>','<?= addslashes($s['email']) ?>','staff','<?= $s['status'] ?>')"><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-outline-danger border-0" onclick="delUser(<?= $s['id'] ?>)"><i class="bi bi-trash3"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Staff -->
        <?php if ($s_total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <li class="page-item <?= ($s_page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?filter=<?= $filter ?>&m_page=<?= $m_page ?>&s_page=<?= $s_page-1 ?>">Previous</a>
                </li>
                <?php for($i=1; $i<=$s_total_pages; $i++): ?>
                    <li class="page-item <?= ($i == $s_page) ? 'active' : '' ?>">
                        <a class="page-link" href="?filter=<?= $filter ?>&m_page=<?= $m_page ?>&s_page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= ($s_page >= $s_total_pages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?filter=<?= $filter ?>&m_page=<?= $m_page ?>&s_page=<?= $s_page+1 ?>">Next</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modals -->
<div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 p-4 shadow">
    <h5 class="fw-bold mb-4 text-center" id="modalTitle"></h5>
    <input type="hidden" id="uRole"><input type="hidden" id="uId">
    <div class="mb-3"><label class="small fw-bold">Full Name *</label><input type="text" id="uName" class="form-control"></div>
    <div class="mb-3"><label class="small fw-bold">Email *</label><input type="email" id="uEmail" class="form-control"></div>
    <div class="mb-3 d-none" id="sGrp"><label class="small fw-bold">Status</label><select id="uStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    <div class="mb-4" id="pGrp">
        <label class="small fw-bold">Password *</label>
        <input type="password" id="uPass" class="form-control" placeholder="Min 8 chars, 1 Upper, 1 Lower, 1 Symbol">
    </div>
    <button class="btn btn-danger w-100 fw-bold py-3 shadow-sm" id="saveUserBtn">Save Account</button>
</div></div></div>

<!-- QR Modal -->
<div class="modal fade" id="qrModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content text-center p-3">
    <h6 id="qrName" class="fw-bold mb-3"></h6>
    <img id="qrImg" src="" class="img-fluid rounded shadow-sm">
</div></div></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <?= csrf_script(); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const uM = new bootstrap.Modal('#userModal'), qM = new bootstrap.Modal('#qrModal');
    
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const main = document.getElementById('main');
        const overlay = document.getElementById('sidebarOverlay');
        const isMobile = window.innerWidth <= 991.98;
        
        if (isMobile) {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        } else {
            sidebar.classList.toggle('collapsed');
            main.classList.toggle('expanded');
        }
    }
    
    // Updated viewQR to use LOCAL generator
    function viewQR(c, n) { 
        $('#qrName').text(n); 
        $('#qrImg').attr('src', `../generate_qr.php?data=${encodeURIComponent(c)}`); 
        qM.show(); 
    }

    function openAddModal(r) { 
        $('#uId').val(''); $('#uRole').val(r); $('#uName,#uEmail,#uPass').val('');
        $('#pGrp').show(); $('#sGrp').hide(); $('#modalTitle').text('New ' + r.toUpperCase());
        $('#saveUserBtn').off().click(saveCreate); uM.show(); 
    }
    
    function editUser(id,n,e,r,s) { 
        $('#uId').val(id); $('#uRole').val(r); $('#uName').val(n); $('#uEmail').val(e); $('#uStatus').val(s);
        $('#pGrp').hide(); $('#sGrp').show(); 
        $('#modalTitle').text('Edit ' + r.toUpperCase());
        $('#saveUserBtn').off().click(saveUpdate); uM.show(); 
    }

    function saveCreate() {
        $.post('admin_user_actions.php', { action:'create', full_name:$('#uName').val(), email:$('#uEmail').val(), password:$('#uPass').val(), role:$('#uRole').val() }, (res) => { if(res.status==='success') location.reload(); else alert(res.message); }, 'json');
    }

    function saveUpdate() {
        $.post('admin_user_actions.php', { action:'update', id:$('#uId').val(), full_name:$('#uName').val(), email:$('#uEmail').val(), status:$('#uStatus').val() }, (res) => { if(res.status==='success') location.reload(); else alert(res.message); }, 'json');
    }

    function pay(id, btn) { 
        const amount = $(btn).siblings('.rate-select').val();
        const name = $(btn).closest('tr').find('.name-cell').text();
        
        if (!confirm(`Are you sure you want to mark ${name} as PAID (Amount: ${amount})?`)) {
            return;
        }

        $(btn).prop('disabled', true).text('...'); 
        $.post('register_payment.php', { user_id: id, amount: amount, duration: 1 }, (res) => {
            if(res.status==='success') location.reload(); else { alert(res.message); $(btn).prop('disabled', false).text('PAY'); }
        }, 'json'); 
    }

    function delUser(id) { if(confirm('Delete user?')) $.post('admin_user_actions.php', { action: 'delete', id: id }, () => location.reload()); }
    
    $('#mSearch').on('keyup', async function() { 
        let v = $(this).val().toLowerCase(); 
        if (v.length > 2 && window.supabaseClient) {
            // Use Supabase Full-Text Search for more accurate results
            const { data, error } = await window.supabaseClient.rpc('search_users', { query: v });
            if (!error && data) {
                const foundIds = data.map(u => u.id);
                $('#mTable tbody tr').each(function() {
                    const rowId = $(this).find('button[onclick^="pay"], button[onclick^="editUser"]').attr('onclick').match(/\d+/)[0];
                    $(this).toggle(foundIds.includes(parseInt(rowId)));
                });
                return;
            }
        }
        // Fallback to simple client-side filter
        $('#mTable tbody tr').filter(function() { $(this).toggle($(this).find('.name-cell').text().toLowerCase().indexOf(v) > -1); }); 
    });
    $('#sSearch').on('keyup', function() { let v = $(this).val().toLowerCase(); $('#sTable tbody tr').filter(function() { $(this).toggle($(this).find('.name-cell').text().toLowerCase().indexOf(v) > -1); }); });

    (function() { if (localStorage.getItem('arts-gym-theme') === 'dark') document.body.classList.add('dark-mode-active'); })();
</script>
</body>
</html>