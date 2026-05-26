<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

include '../database_connect.php';

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add') {
            $team_name = trim($_POST['team_name']);
            
            if (empty($team_name)) {
                $message = "Teamnaam mag niet leeg zijn";
                $message_type = 'error';
            } else {
                $stmt = $conn->prepare("INSERT INTO teams (name, score) VALUES (?, 0)");
                $stmt->bind_param("s", $team_name);
                
                if ($stmt->execute()) {
                    $message = "Team '" . htmlspecialchars($team_name) . "' succesvol toegevoegd";
                    $message_type = 'success';
                } else {
                    $message = "Fout: " . $conn->error;
                    $message_type = 'error';
                }
            }
        } elseif ($_POST['action'] == 'delete') {
            $team_id = intval($_POST['team_id']);
            
            $stmt = $conn->prepare("DELETE FROM teams WHERE id = ?");
            $stmt->bind_param("i", $team_id);
            
            if ($stmt->execute()) {
                $message = "Team verwijderd";
                $message_type = 'success';
            } else {
                $message = "Fout: " . $conn->error;
                $message_type = 'error';
            }
        } elseif ($_POST['action'] == 'update_name') {
            $team_id = intval($_POST['team_id']);
            $team_name = trim($_POST['team_name']);
            
            if (empty($team_name)) {
                $message = "Teamnaam mag niet leeg zijn";
                $message_type = 'error';
            } else {
                $stmt = $conn->prepare("UPDATE teams SET name = ? WHERE id = ?");
                $stmt->bind_param("si", $team_name, $team_id);
                
                if ($stmt->execute()) {
                    $message = "Teamnaam bijgewerkt naar '" . htmlspecialchars($team_name) . "'";
                    $message_type = 'success';
                } else {
                    $message = "Fout: " . $conn->error;
                    $message_type = 'error';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teams Beheren - PubQuiz Admin</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 PubQuiz Admin - Teams Beheren</h1>
            <nav class="nav">
                <a href="index.php" class="nav-link">Dashboard</a>
                <a href="questions.php" class="nav-link">Vragen</a>
                <a href="teams.php" class="nav-link active">Teams</a>
                <a href="scores.php" class="nav-link">Scores</a>
                <a href="../quiz.php" class="nav-link nav-quiz">▶ Quiz Starten</a>
                <a href="logout.php" class="nav-link logout">Uitloggen</a>
            </nav>
        </div>

        <div class="content">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <h2>Voeg Team Toe</h2>
                <form method="POST" class="form">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label for="team_name">Teamnaam</label>
                        <input type="text" id="team_name" name="team_name" required placeholder="Voer teamnaam in">
                    </div>
                    <button type="submit" class="btn btn-primary">Team Toevoegen</button>
                </form>
            </div>

            <div class="card">
                <h2>Teams Beheren <span class="label-hint">— scores beheer je via <a href="scores.php" style="text-decoration: underline;">Scores</a></span></h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Teamnaam</th>
                            <th class="col-total">Totaal Score</th>
                            <th style="width: 140px;">Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $result = $conn->query("SELECT * FROM teams ORDER BY name ASC");

                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                echo "<tr>
                                    <td>
                                        <form method='POST' class='team-name-form'>
                                            <input type='hidden' name='action' value='update_name'>
                                            <input type='hidden' name='team_id' value='" . intval($row['id']) . "'>
                                            <input type='text' name='team_name' value='" . htmlspecialchars($row['name']) . "' required>
                                            <button type='submit' class='btn btn-small btn-secondary'>Wijzig</button>
                                        </form>
                                    </td>
                                    <td class='col-total'>
                                        <span class='score-total'>" . intval($row['score']) . "</span>
                                    </td>
                                    <td>
                                        <form method='POST' class='inline-form' onsubmit='return confirm(\"Zeker weten?\");'>
                                            <input type='hidden' name='action' value='delete'>
                                            <input type='hidden' name='team_id' value='" . intval($row['id']) . "'>
                                            <button type='submit' class='btn btn-small btn-danger'>Verwijderen</button>
                                        </form>
                                    </td>
                                </tr>";
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
