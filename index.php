<?php
session_start();
include 'database_connect.php';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PubQuiz</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 PubQuiz</h1>
        </div>

        <div class="content">
            <div class="podium-section">
                <h2 style="text-align: center; margin-bottom: 50px;">Ranglijst</h2>
                
                <?php
                $top_teams = [];
                $stmt = $conn->prepare("SELECT `name`, `score` FROM teams ORDER BY score DESC LIMIT 3");
                if ($stmt && $stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $top_teams[] = $row;
                        }
                    }
                }

                if (count($top_teams) >= 1) {
                    $first = $top_teams[0] ?? null;
                    $second = $top_teams[1] ?? null;
                    $third = $top_teams[2] ?? null;
                    
                    $podium_teams = [$second, $first, $third];
                    ?>
                    <div class="podium-container">
                    <?php
                    
                    foreach ($podium_teams as $team) {
                        if ($team){
                            $place = $team === $first ? '1' : ($team === $second ? '2' : '3');
                            $medal = $place === '1' ? '🥇' : ($place === '2' ? '🥈' : '🥉');
                            $podium_class = $place === '1' ? 'gold' : ($place === '2' ? 'silver' : 'bronze');

                            ?>
                            <div class="podium-step podium-<?= $podium_class ?>">
                            <div class="podium-medal"><?= $medal ?></div>
                            <div class="podium-rank"><?= $place ?></div>
                            <div class="podium-name"><?= htmlspecialchars($team['name']) ?></div>
                            <div class="podium-score"><?= $team['score'] ?> pts</div>
                            </div>
                         <?php
                        }
                    }
                    
                    ?>
                    </div>
                    <?php
                }
                ?>
            </div>

            <div class="card full-width" style="margin-top: 80px;">
                <h2>Alle Teams</h2>
                
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
                        $result = null;
                        $stmt = $conn->prepare("SELECT `name`, `score` FROM teams ORDER BY score DESC");
                        if ($stmt && $stmt->execute()) {
                            $result = $stmt->get_result();
                        }
                        $rank = 1;

                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                $medal = $rank == 1 ? '🥇' : ($rank == 2 ? '🥈' : ($rank == 3 ? '🥉' : ''));
                                
                                ?>
                                <tr class='rank-<?= $rank ?>'>
                                    <td class='rank'><?= $medal ?> <?= $rank ?></td>
                                    <td class='team-name'><?= htmlspecialchars($row['name']) ?></td>
                                    <td class='score'><strong><?= $row['score'] ?></strong></td>
                                </tr>
                                <?php
                                $rank++;
                            }
                        } else {
                            ?>
                            <tr>
                                <td colspan='3' class='empty'>Geen teams beschikbaar</td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
