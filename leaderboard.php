<?php
session_start();
include 'database_connect.php';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PubQuiz - Leaderboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 PubQuiz</h1>
            <nav class="nav">
                <a href="index.php" class="nav-link">Home</a>
            </nav>
        </div>

        <div class="content">
            <div class="card full-width">
                <h2>Leaderboard</h2>
                
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th class="rank">#</th>
                            <th class="team-name">Teamnaam</th>
                            <th class="score">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $result = $conn->query("SELECT * FROM teams ORDER BY score DESC");
                        $rank = 1;
                        
                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                $medal = '';
                                if ($rank == 1) {
                                    $medal = '🥇';
                                } elseif ($rank == 2) {
                                    $medal = '🥈';
                                } elseif ($rank == 3) {
                                    $medal = '🥉';
                                }
                                
                                echo "<tr class='rank-" . $rank . "'>
                                    <td class='rank'>" . $medal . " " . $rank . "</td>
                                    <td class='team-name'>" . htmlspecialchars($row['name']) . "</td>
                                    <td class='score'><strong>" . $row['score'] . "</strong></td>
                                </tr>";
                                $rank++;
                            }
                        } else {
                            echo "<tr><td colspan='3' class='empty'>Geen teams beschikbaar</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
