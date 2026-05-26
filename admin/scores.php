<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

include '../database_connect.php';

$message = '';
$message_type = '';

/**
 * Herbereken teams.score = SUM(team_week_scores.points) per team.
 * Houdt de cached totaal-kolom in sync met de per-week tabel.
 */
function recalculate_team_totals($conn) {
    $conn->query("
        UPDATE teams t
        LEFT JOIN (
            SELECT team_id, COALESCE(SUM(points), 0) AS total
            FROM team_week_scores
            GROUP BY team_id
        ) s ON s.team_id = t.id
        SET t.score = COALESCE(s.total, 0)
    ");
}

// Bepaal beschikbare weken: alle weken uit questions + alle weken uit team_week_scores
$weeks_result = $conn->query("
    SELECT week FROM (
        SELECT DISTINCT week FROM questions
        UNION
        SELECT DISTINCT week FROM team_week_scores
    ) AS all_weeks
    ORDER BY week ASC
");
$weeks = [];
if ($weeks_result) {
    while ($row = $weeks_result->fetch_assoc()) {
        $weeks[] = intval($row['week']);
    }
}

$max_existing_week = count($weeks) > 0 ? max($weeks) : 0;
$default_week = $max_existing_week > 0 ? $max_existing_week : 1;
$current_week = isset($_GET['week']) ? intval($_GET['week']) : $default_week;
if ($current_week < 1) $current_week = 1;

// Zorg dat huidige week in de lijst staat (ook als er nog geen data is)
if (!in_array($current_week, $weeks, true)) {
    $weeks[] = $current_week;
    sort($weeks);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_week_scores') {
        $week = intval($_POST['week']);
        $points_input = $_POST['points'] ?? [];

        if ($week < 1) {
            $message = "Ongeldige week";
            $message_type = 'error';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO team_week_scores (team_id, week, points)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE points = VALUES(points)
            ");

            $saved = 0;
            $failed = 0;
            $last_error = '';
            foreach ($points_input as $team_id => $points) {
                $team_id = intval($team_id);
                $points = intval($points);
                if ($team_id <= 0) continue;
                $stmt->bind_param("iii", $team_id, $week, $points);
                if ($stmt->execute()) {
                    $saved++;
                } else {
                    $failed++;
                    $last_error = $stmt->error;
                }
            }

            recalculate_team_totals($conn);
            $current_week = $week;

            if ($failed > 0) {
                $message = "Week " . $week . ": " . $saved . " teams opgeslagen, " . $failed . " mislukt (" . $last_error . ")";
                $message_type = $saved > 0 ? 'success' : 'error';
            } else {
                $message = "Punten voor week " . $week . " opgeslagen (" . $saved . " teams)";
                $message_type = 'success';
            }
        }
    } elseif ($_POST['action'] === 'delete_week_scores') {
        $week = intval($_POST['week']);
        $stmt = $conn->prepare("DELETE FROM team_week_scores WHERE week = ?");
        $stmt->bind_param("i", $week);
        if ($stmt->execute()) {
            recalculate_team_totals($conn);
            $message = "Alle scores voor week " . $week . " verwijderd";
            $message_type = 'success';
        } else {
            $message = "Fout: " . $conn->error;
            $message_type = 'error';
        }
    }
}

// Teams ophalen (gesorteerd op naam voor stabiele volgorde in formulier)
$teams_result = $conn->query("SELECT id, name, score FROM teams ORDER BY name ASC");
$teams = [];
if ($teams_result) {
    while ($row = $teams_result->fetch_assoc()) {
        $teams[] = $row;
    }
}

// Punten voor huidige week
$current_points = [];
if ($current_week > 0) {
    $stmt = $conn->prepare("SELECT team_id, points FROM team_week_scores WHERE week = ?");
    $stmt->bind_param("i", $current_week);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $current_points[intval($row['team_id'])] = intval($row['points']);
    }
}

// Matrix data: alle weken × alle teams
$matrix_weeks = $weeks;
$matrix_data = []; // [team_id][week] = points
$matrix_result = $conn->query("SELECT team_id, week, points FROM team_week_scores");
if ($matrix_result) {
    while ($row = $matrix_result->fetch_assoc()) {
        $matrix_data[intval($row['team_id'])][intval($row['week'])] = intval($row['points']);
    }
}

// Volgende beschikbare week (voor "+ Nieuwe week" knop)
$next_week = $max_existing_week + 1;
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scores Beheren - PubQuiz Admin</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 PubQuiz Admin - Scores Beheren</h1>
            <nav class="nav">
                <a href="index.php" class="nav-link">Dashboard</a>
                <a href="questions.php" class="nav-link">Vragen</a>
                <a href="teams.php" class="nav-link">Teams</a>
                <a href="scores.php" class="nav-link active">Scores</a>
                <a href="../quiz.php" class="nav-link nav-quiz">▶ Quiz Starten</a>
                <a href="logout.php" class="nav-link logout">Uitloggen</a>
            </nav>
        </div>

        <div class="content">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($teams)): ?>
                <div class="card">
                    <h2>Geen Teams</h2>
                    <p class="week-hint">Voeg eerst teams toe via <a href="teams.php" style="text-decoration: underline;">Teams Beheren</a>.</p>
                </div>
            <?php else: ?>

            <!-- Week selector -->
            <div class="card">
                <h2>Week Selecteren</h2>
                <form method="GET" class="week-selector-inline">
                    <div class="form-group">
                        <label for="week">Huidige week</label>
                        <select id="week" name="week">
                            <?php foreach ($weeks as $w): ?>
                                <option value="<?php echo $w; ?>" <?php echo $w === $current_week ? 'selected' : ''; ?>>Week <?php echo $w; ?></option>
                            <?php endforeach; ?>
                            <?php if (!in_array($next_week, $weeks, true)): ?>
                                <option value="<?php echo $next_week; ?>">Week <?php echo $next_week; ?> (nieuw)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary">Ga Naar Week</button>
                </form>
            </div>

            <!-- Scores invoeren -->
            <div class="card">
                <h2>Punten Week <?php echo $current_week; ?> Invoeren</h2>
                <form method="POST" class="form">
                    <input type="hidden" name="action" value="save_week_scores">
                    <input type="hidden" name="week" value="<?php echo $current_week; ?>">

                    <table class="table scores-input-table">
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th class="col-points">Punten Week <?php echo $current_week; ?></th>
                                <th class="col-total">Huidig Totaal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teams as $team): ?>
                                <?php
                                $tid = intval($team['id']);
                                $existing = $current_points[$tid] ?? '';
                                ?>
                                <tr>
                                    <td class="team-name-cell"><?php echo htmlspecialchars($team['name']); ?></td>
                                    <td class="col-points">
                                        <input
                                            type="number"
                                            name="points[<?php echo $tid; ?>]"
                                            value="<?php echo htmlspecialchars((string)$existing); ?>"
                                            min="0"
                                            class="score-input"
                                            placeholder="0"
                                        >
                                    </td>
                                    <td class="col-total">
                                        <span class="score-total"><?php echo intval($team['score']); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="scores-actions">
                        <button type="submit" class="btn btn-primary">Opslaan</button>
                    </div>
                </form>

                <?php if (!empty($current_points)): ?>
                    <form method="POST" class="inline-form scores-delete-form" onsubmit="return confirm('Alle punten voor week <?php echo $current_week; ?> verwijderen?');">
                        <input type="hidden" name="action" value="delete_week_scores">
                        <input type="hidden" name="week" value="<?php echo $current_week; ?>">
                        <button type="submit" class="btn btn-secondary">Week Wissen</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Overzicht matrix -->
            <?php if (count($matrix_weeks) > 0 && !empty($matrix_data)): ?>
            <div class="card">
                <h2>Overzicht <span class="label-hint">— punten per team per week</span></h2>
                <div class="matrix-scroll">
                    <table class="table scores-matrix">
                        <thead>
                            <tr>
                                <th class="matrix-team-col">Team</th>
                                <?php foreach ($matrix_weeks as $w): ?>
                                    <th class="matrix-week-col <?php echo $w === $current_week ? 'matrix-col-active' : ''; ?>">
                                        W<?php echo $w; ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="matrix-total-col">Totaal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Sorteer een kopie op totaal (aflopend) voor rangschikking — laat $teams in originele (naam-)volgorde
                            $teams_by_score = $teams;
                            usort($teams_by_score, function($a, $b) {
                                return intval($b['score']) - intval($a['score']);
                            });
                            foreach ($teams_by_score as $rank => $team):
                                $tid = intval($team['id']);
                            ?>
                                <tr>
                                    <td class="matrix-team-col">
                                        <span class="matrix-rank"><?php echo $rank + 1; ?>.</span>
                                        <?php echo htmlspecialchars($team['name']); ?>
                                    </td>
                                    <?php foreach ($matrix_weeks as $w):
                                        $pts = $matrix_data[$tid][$w] ?? null;
                                    ?>
                                        <td class="matrix-week-col <?php echo $w === $current_week ? 'matrix-col-active' : ''; ?>">
                                            <?php echo $pts !== null ? $pts : '<span class="matrix-empty">—</span>'; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="matrix-total-col">
                                        <strong><?php echo intval($team['score']); ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; // teams check ?>
        </div>
    </div>

    <script>
        // Enter in score-invoer gaat naar volgende rij (niet form submit)
        document.querySelectorAll('.score-input').forEach((input, idx, arr) => {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const next = arr[idx + 1];
                    if (next) next.focus();
                    else input.blur();
                }
            });
        });
    </script>
</body>
</html>
