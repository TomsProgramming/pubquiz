<?php
require_once 'auth.php';
requireAdmin();
include '../database_connect.php';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - PubQuiz</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 PubQuiz Admin Dashboard</h1>
            <nav class="nav">
                <a href="index.php" class="nav-link active">Dashboard</a>
                <a href="questions.php" class="nav-link">Vragen</a>
                <a href="teams.php" class="nav-link">Teams</a>
                <a href="scores.php" class="nav-link">Scores</a>
                <a href="../quiz.php" class="nav-link nav-quiz">▶ Quiz Starten</a>
                <a href="logout.php" class="nav-link logout">Uitloggen</a>
            </nav>
        </div>

        <div class="content">
            <div class="dashboard-grid">
                <!-- Statistieken -->
                <div class="card">
                    <h2>Statistieken</h2>
                    <?php
                    $total_teams = 0;
                    $stmt = $conn->prepare("SELECT COUNT(`id`) as total_teams FROM teams");
                    if ($stmt && $stmt->execute()) {
                        $total_teams = $stmt->get_result()->fetch_assoc()['total_teams'] ?? 0;
                    }

                    $total_questions = 0;
                    $stmt = $conn->prepare("SELECT COUNT(`id`) as total_questions FROM questions");
                    if ($stmt && $stmt->execute()) {
                        $total_questions = $stmt->get_result()->fetch_assoc()['total_questions'] ?? 0;
                    }

                    $current_week = 0;
                    $stmt = $conn->prepare("SELECT MAX(`week`) as max_week FROM questions");
                    if ($stmt && $stmt->execute()) {
                        $current_week = $stmt->get_result()->fetch_assoc()['max_week'] ?? 0;
                    }
                    ?>
                    <div class="stats">
                        <div class="stat">
                            <span class="stat-value"><?= $total_teams; ?></span>
                            <span class="stat-label">Teams</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value"><?= $total_questions; ?></span>
                            <span class="stat-label">Vragen</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value"><?= $current_week; ?></span>
                            <span class="stat-label">Huidige Week</span>
                        </div>
                    </div>
                </div>

                <!-- Top 5 Teams -->
                <div class="card">
                    <h2>Top 5 Teams</h2>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Team</th>
                                <th>Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $conn->prepare("SELECT `name`, `score` FROM teams ORDER BY score DESC LIMIT 5");
                            $rank = 1;
                            if ($stmt && $stmt->execute()) {
                                $result = $stmt->get_result();
                                while ($row = $result->fetch_assoc()) {
                                    echo "<tr>
                                        <td>" . $rank . "</td>
                                        <td>" . htmlspecialchars($row['name']) . "</td>
                                        <td><strong>" . $row['score'] . "</strong></td>
                                    </tr>";
                                    $rank++;
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <h2>Snelle Acties</h2>
                    <div class="button-group">
                        <a href="questions.php" class="btn btn-primary">Voeg vragen toe</a>
                        <a href="teams.php" class="btn btn-secondary">Beheer teams</a>
                        <a href="../index.php" class="btn btn-tertiary">Bekijk leaderboard</a>
                    </div>
                </div>

                <!-- Recent Questions -->
                <div class="card">
                    <h2>Recente Vragen (Week <?= $current_week; ?>)</h2>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Vraag</th>
                                <th>Categorie</th>
                                <th>Antwoord</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $recent_result = null;
                            $recent = $conn->prepare("SELECT `question_number`, `question`, `category`, `answer` FROM questions WHERE week = ? ORDER BY question_number LIMIT 10");
                            if ($recent && $recent->bind_param("i", $current_week) && $recent->execute()) {
                                $recent_result = $recent->get_result();
                            }
                            if ($recent_result && $recent_result->num_rows > 0) {
                                while ($row = $recent_result->fetch_assoc()) {
                                    ?>
                                    <tr>
                                        <td><?= $row['question_number'] ?></td>
                                        <td><?= htmlspecialchars(substr($row['question'], 0, 50)) . (strlen($row['question']) > 50 ? '...' : '') ?></td>
                                        <td><?= htmlspecialchars($row['category']) ?></td>
                                        <td><?= htmlspecialchars($row['answer']) ?></td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                ?>
                                <tr>
                                    <td colspan="4" class="empty">Geen vragen voor deze week</td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
