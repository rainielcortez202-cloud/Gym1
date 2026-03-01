<?php
require '../auth.php';
require '../connection.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if ($_SESSION['role'] !== 'member') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// --- FETCH DATA BASED ON STEPS ---
$groups = $pdo->query("SELECT * FROM muscle_groups ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$group_id  = intval($_GET['group'] ?? 0);
$muscle_id = intval($_GET['muscle'] ?? 0);

$muscles = [];
if ($group_id) {
    $stmt = $pdo->prepare("SELECT * FROM muscles WHERE muscle_group_id=? ORDER BY id ASC");
    $stmt->execute([$group_id]);
    $muscles = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$exercises = [];
if ($muscle_id) {
    $stmt = $pdo->prepare("SELECT * FROM exercises WHERE muscle_id = ? ORDER BY created_at DESC");
    $stmt->execute([$muscle_id]);
    $exercises = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exercise Library | Arts Gym</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary-red: #e63946;
            --dark-red: #9d0208;
            --bg-body: #f8f9fa;
            --bg-card: #ffffff;
            --text-main: #212529;
            --text-muted: #6c757d;
            --sidebar-width: 260px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --card-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        body.dark-mode-active {
            --bg-body: #0a0a0a;
            --bg-card: #161616;
            --text-main: #f8f9fa;
            --text-muted: #a0a0a0;
            --card-shadow: 0 4px 15px rgba(0,0,0,0.4);
        }

        body { 
            font-family: 'Poppins', sans-serif; 
            background-color: var(--bg-body); 
            color: var(--text-main);
            transition: var(--transition);
        }

        h1, h2, h3, h4, h5 { font-family: 'Oswald', sans-serif; text-transform: uppercase; letter-spacing: 0.5px; }

        /* Main Content Layout */
        #main {
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        #main.expanded { margin-left: 80px; }

        @media (max-width: 991px) {
            #main { margin-left: 0 !important; }
        }

        /* Top Header */
        .top-header {
            background: var(--bg-card);
            padding: 0.75rem 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 999;
        }

        /* Minimized Container */
        .content-container {
            padding: 1.5rem;
            max-width: 1140px; /* Narrower width for better focus */
            margin: 0 auto;
            width: 100%;
        }

        /* Tighter Exercise Grid */
        .exercise-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); 
            gap: 1.25rem; 
        }

        /* Compact Card Styling */
        .card-box { 
            background: var(--bg-card); 
            border-radius: 12px; 
            padding: 1rem; 
            box-shadow: var(--card-shadow); 
            border: 1px solid rgba(0,0,0,0.05);
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .card-box:hover { 
            transform: translateY(-5px); 
            border-color: var(--primary-red);
        }

        /* Smaller Card Images */
        .img-wrapper {
            width: 100%;
            height: 160px; /* Reduced height */
            overflow: hidden;
            border-radius: 8px;
            margin-bottom: 0.75rem;
            background: #222;
        }

        .img-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Compact Typography */
        .exercise-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem; }
        .exercise-desc { 
            font-size: 0.85rem; 
            line-height: 1.4; 
            color: var(--text-muted);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Smaller Action Buttons */
        .btn-primary-gym { 
            background: var(--primary-red); 
            color: white; 
            border: none; 
            padding: 8px; 
            border-radius: 8px; 
            font-weight: 600; 
            text-transform: uppercase; 
            text-decoration: none; 
            display: block; 
            text-align: center; 
            margin-top: auto;
            font-family: 'Oswald', sans-serif;
            font-size: 0.9rem;
        }

        .btn-video {
            background: #212529;
            color: white !important;
            padding: 8px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            font-family: 'Oswald', sans-serif;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        body.dark-mode-active .btn-video { background: #333; }

        .quick-log-btn {
            padding: 8px;
            font-size: 0.85rem;
            border-radius: 8px;
            font-family: 'Oswald';
        }

        .step-tag {
            font-size: 0.7rem;
            letter-spacing: 1.5px;
            color: var(--primary-red);
            font-weight: 800;
        }
    </style>
</head>
<body class="<?= isset($_COOKIE['theme']) && $_COOKIE['theme'] == 'dark' ? 'dark-mode-active' : '' ?>">

    <?php include __DIR__ . '/_sidebar.php'; ?>

    <div id="main">
        <header class="top-header">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-lg-none me-3" onclick="toggleSidebar()">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <h5 class="mb-0 fw-bold">Training Library</h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php include '../global_clock.php'; ?>
            </div>
        </header>

        <div class="content-container">
            <!-- Tighter Header Section -->
            <div class="mb-4">
                <span class="step-tag text-uppercase">
                    <?php 
                        if ($muscle_id) echo "Step 3: Exercises";
                        elseif ($group_id) echo "Step 2: Muscles";
                        else echo "Step 1: Areas";
                    ?>
                </span>
                <h3 class="fw-bold mt-1 mb-0">Workout Guide</h3>
            </div>

            <!-- Step 1: Muscle Groups -->
            <?php if(!$group_id): ?>
                <div class="exercise-grid">
                    <?php foreach($groups as $g): ?>
                        <div class="card-box">
                            <div class="img-wrapper">
                                <img src="<?= str_replace('../', '', htmlspecialchars($g['image'])) ?>" 
                                     alt="<?= htmlspecialchars($g['name']) ?>"
                                     onerror="this.src='https://via.placeholder.com/400x300?text=Gym+Focus';">
                            </div>
                            <h5 class="exercise-title"><?= htmlspecialchars($g['name']) ?></h5>
                            <a href="?group=<?= $g['id'] ?>" class="btn btn-primary-gym">Select Area</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Step 2: Muscles -->
            <?php if($group_id && !$muscle_id): ?>
                <div class="back-nav mb-3">
                    <a href="exercises.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i> Back
                    </a>
                </div>
                <?php if (empty($muscles)): ?>
                    <div class="card-box">
                        <h5 class="exercise-title mb-1">No muscles found</h5>
                        <p class="exercise-desc mb-0">The exercise library is not seeded yet. Please ask an admin to seed or add muscles and exercises.</p>
                    </div>
                <?php endif; ?>
                <div class="exercise-grid">
                    <?php foreach($muscles as $m): ?>
                        <div class="card-box">
                            <div class="img-wrapper">
                                <img src="<?= str_replace('../', '', htmlspecialchars($m['image'])) ?>" 
                                     alt="<?= htmlspecialchars($m['name']) ?>"
                                     onerror="this.src='https://via.placeholder.com/400x300?text=Muscle';">
                            </div>
                            <h5 class="exercise-title"><?= htmlspecialchars($m['name']) ?></h5>
                            <a href="?group=<?= $group_id ?>&muscle=<?= $m['id'] ?>" class="btn btn-primary-gym">View Exercises</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Step 3: Specific Exercises -->
            <?php if($muscle_id): ?>
                <div class="back-nav mb-3">
                    <a href="exercises.php?group=<?= $group_id ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i> Back
                    </a>
                </div>
                <?php if (empty($exercises)): ?>
                    <div class="card-box">
                        <h5 class="exercise-title mb-1">No exercises found</h5>
                        <p class="exercise-desc mb-0">This muscle has no exercises yet. Please ask an admin to add exercises.</p>
                    </div>
                <?php endif; ?>
                <div class="exercise-grid">
                    <?php foreach($exercises as $e): ?>
                        <div class="card-box">
                            <div class="img-wrapper">
                                <img src="<?= htmlspecialchars($e['image_url']) ?: 'https://via.placeholder.com/400x300?text=Exercise' ?>" 
                                     alt="<?= htmlspecialchars($e['name']) ?>">
                            </div>
                            <h5 class="exercise-title"><?= htmlspecialchars($e['name']) ?></h5>
                            
                            <p class="exercise-desc">
                                <?= htmlspecialchars($e['description']) ?>
                            </p>
                            
                            <div class="d-flex flex-column gap-2 mt-2">
                                <?php if(!empty($e['video_url'])): ?>
                                    <a href="<?= htmlspecialchars($e['video_url']) ?>" target="_blank" class="btn-video">
                                        <i class="bi bi-play-fill"></i> Guide
                                    </a>
                                <?php endif; ?>

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>


        function toggleDarkMode() {
            const isDark = !document.body.classList.contains('dark-mode-active');
            document.cookie = "theme=" + (isDark ? "dark" : "light") + ";path=/;max-age=" + (30*24*60*60);
            location.reload(); 
        }
    </script>
</body>
</html>
