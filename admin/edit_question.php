<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

include '../database_connect.php';
include 'upload_helper.php';

$message = '';
$message_type = '';

$question_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($question_id <= 0) {
    header('Location: questions.php');
    exit;
}

/**
 * Laad vraag + bestaande opties uit DB.
 */
function load_question($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM questions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function load_options($conn, $question_id) {
    $stmt = $conn->prepare("SELECT * FROM answer_options WHERE question_id = ? ORDER BY display_order ASC, id ASC");
    $stmt->bind_param("i", $question_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

$question = load_question($conn, $question_id);
if (!$question) {
    header('Location: questions.php');
    exit;
}

// ---------- POST handler ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $week = intval($_POST['week']);
    $question_number = intval($_POST['question_number']);
    $question_text = trim($_POST['question'] ?? '');
    $category = trim($_POST['category'] ?? 'Algemeen');
    $answer = trim($_POST['answer'] ?? '');
    $type = $question['question_type']; // type wordt niet gewijzigd via edit

    // Begin met huidige media-velden
    $media_path = $question['media_path'];
    $video_source = $question['video_source'];
    $video_youtube_id = $question['video_youtube_id'];
    $video_start = isset($_POST['video_start']) && $_POST['video_start'] !== '' ? intval($_POST['video_start']) : null;
    $video_end = isset($_POST['video_end']) && $_POST['video_end'] !== '' ? intval($_POST['video_end']) : null;
    $upload_error = null;

    if ($question_text === '') {
        $upload_error = 'Vraag mag niet leeg zijn';
    } elseif ($type === 'text' && $answer === '') {
        $upload_error = 'Antwoord mag niet leeg zijn bij tekst-vraag';
    }

    // --- Media replacement per type ---
    if (!$upload_error && $type === 'photo') {
        if (!empty($_FILES['photo_file']['name'])) {
            $res = handle_upload($_FILES['photo_file'], UPLOAD_TYPE_PHOTO);
            if (!$res['ok']) {
                $upload_error = $res['error'];
            } else {
                if ($question['media_path']) delete_upload($question['media_path']);
                $media_path = $res['path'];
            }
        }
    } elseif (!$upload_error && $type === 'audio') {
        if (!empty($_FILES['audio_file']['name'])) {
            $res = handle_upload($_FILES['audio_file'], UPLOAD_TYPE_AUDIO);
            if (!$res['ok']) {
                $upload_error = $res['error'];
            } else {
                if ($question['media_path']) delete_upload($question['media_path']);
                $media_path = $res['path'];
            }
        }
    } elseif (!$upload_error && $type === 'video') {
        $new_source = ($_POST['video_source'] ?? $video_source) === 'youtube' ? 'youtube' : 'upload';

        if ($new_source === 'youtube') {
            $yt_id = extract_youtube_id($_POST['video_youtube_url'] ?? '');
            if (!$yt_id) {
                $upload_error = 'Ongeldige YouTube URL of ID';
            } else {
                // Als we van upload naar youtube wisselen: oude videobestand opruimen
                if ($video_source === 'upload' && $media_path) {
                    delete_upload($media_path);
                    $media_path = null;
                }
                $video_source = 'youtube';
                $video_youtube_id = $yt_id;
            }
        } else {
            // upload-mode: nieuw bestand optioneel; anders bestaande bestand behouden
            if (!empty($_FILES['video_file']['name'])) {
                $res = handle_upload($_FILES['video_file'], UPLOAD_TYPE_VIDEO);
                if (!$res['ok']) {
                    $upload_error = $res['error'];
                } else {
                    if ($video_source === 'upload' && $question['media_path']) {
                        delete_upload($question['media_path']);
                    }
                    $media_path = $res['path'];
                    $video_source = 'upload';
                    $video_youtube_id = null;
                }
            } elseif ($video_source !== 'upload') {
                // Wissel naar upload zonder bestand → niet toegestaan
                $upload_error = 'Selecteer een videobestand om naar upload-modus te wisselen';
            }
        }
    }

    if ($upload_error) {
        $message = 'Fout: ' . $upload_error;
        $message_type = 'error';
    } else {
        $stmt = $conn->prepare("
            UPDATE questions
            SET week = ?, question_number = ?, question = ?, answer = ?, category = ?,
                media_path = ?, video_source = ?, video_youtube_id = ?, video_start = ?, video_end = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            "iissssssiii",
            $week, $question_number, $question_text, $answer, $category,
            $media_path, $video_source, $video_youtube_id, $video_start, $video_end,
            $question_id
        );

        if ($stmt->execute()) {
            // --- Multiple choice opties verwerken ---
            if ($type === 'multiple_choice') {
                $existing_options = load_options($conn, $question_id);
                $existing_by_id = [];
                foreach ($existing_options as $o) $existing_by_id[intval($o['id'])] = $o;

                $posted_ids = $_POST['option_id'] ?? [];
                $posted_texts = $_POST['option_text'] ?? [];
                $posted_correct = $_POST['option_correct'] ?? [];
                $posted_delete_image = $_POST['option_delete_image'] ?? [];
                $option_files = $_FILES['option_image'] ?? null;

                // Welke bestaande IDs blijven? Rest wordt verwijderd.
                $kept_ids = [];

                $update_stmt = $conn->prepare("UPDATE answer_options SET option_text = ?, option_image_path = ?, is_correct = ?, display_order = ? WHERE id = ?");
                $insert_stmt = $conn->prepare("INSERT INTO answer_options (question_id, option_text, option_image_path, is_correct, display_order) VALUES (?, ?, ?, ?, ?)");
                $delete_stmt = $conn->prepare("DELETE FROM answer_options WHERE id = ?");

                foreach ($posted_texts as $idx => $text) {
                    $text = trim($text);
                    $existing_id = isset($posted_ids[$idx]) ? intval($posted_ids[$idx]) : 0;
                    $is_correct = isset($posted_correct[$idx]) ? 1 : 0;
                    $delete_image = isset($posted_delete_image[$idx]);
                    $display_order = $idx + 1;

                    // Bepaal image path (nieuw, gewist, of behoud bestaand)
                    $image_path = null;
                    $existing_option = $existing_id > 0 ? ($existing_by_id[$existing_id] ?? null) : null;
                    if ($existing_option) $image_path = $existing_option['option_image_path'];

                    // Nieuwe image upload?
                    $new_image_uploaded = false;
                    if ($option_files && isset($option_files['error'][$idx]) && $option_files['error'][$idx] !== UPLOAD_ERR_NO_FILE) {
                        $file_arr = [
                            'name' => $option_files['name'][$idx],
                            'type' => $option_files['type'][$idx],
                            'tmp_name' => $option_files['tmp_name'][$idx],
                            'error' => $option_files['error'][$idx],
                            'size' => $option_files['size'][$idx],
                        ];
                        $up = handle_upload($file_arr, UPLOAD_TYPE_OPTION);
                        if ($up['ok']) {
                            if ($image_path) delete_upload($image_path);
                            $image_path = $up['path'];
                            $new_image_uploaded = true;
                        }
                    }
                    // Image expliciet verwijderen (geen vervanging)
                    if (!$new_image_uploaded && $delete_image && $image_path) {
                        delete_upload($image_path);
                        $image_path = null;
                    }

                    // Skip lege opties (geen tekst EN geen image)
                    if ($text === '' && !$image_path) {
                        if ($existing_id > 0) {
                            // Was bestaand maar nu leeg → verwijder
                            $delete_stmt->bind_param("i", $existing_id);
                            $delete_stmt->execute();
                        }
                        continue;
                    }

                    if ($existing_id > 0 && isset($existing_by_id[$existing_id])) {
                        $update_stmt->bind_param("ssiii", $text, $image_path, $is_correct, $display_order, $existing_id);
                        $update_stmt->execute();
                        $kept_ids[] = $existing_id;
                    } else {
                        $insert_stmt->bind_param("issii", $question_id, $text, $image_path, $is_correct, $display_order);
                        $insert_stmt->execute();
                    }
                }

                // Verwijder opties die niet meer in submission staan
                foreach ($existing_by_id as $id => $opt) {
                    if (!in_array($id, $kept_ids, true)) {
                        if ($opt['option_image_path']) delete_upload($opt['option_image_path']);
                        $delete_stmt->bind_param("i", $id);
                        $delete_stmt->execute();
                    }
                }
            }

            $message = 'Vraag bijgewerkt';
            $message_type = 'success';
            // Herlaad de vraag zodat het form de nieuwe waarden toont
            $question = load_question($conn, $question_id);
        } else {
            $message = 'Fout: ' . $conn->error;
            $message_type = 'error';
        }
    }
}

$options = $question['question_type'] === 'multiple_choice' ? load_options($conn, $question_id) : [];
$type_icons = [
    'text' => '📝', 'multiple_choice' => '☑️', 'video' => '🎬', 'audio' => '🎵', 'photo' => '📷',
];
$type_labels = [
    'text' => 'Tekst', 'multiple_choice' => 'Multiple Choice', 'video' => 'Video', 'audio' => 'Audio', 'photo' => 'Foto',
];
$q = $question;
$type = $q['question_type'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vraag Bewerken - PubQuiz Admin</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 PubQuiz Admin - Vraag Bewerken</h1>
            <nav class="nav">
                <a href="index.php" class="nav-link">Dashboard</a>
                <a href="questions.php" class="nav-link active">Vragen</a>
                <a href="teams.php" class="nav-link">Teams</a>
                <a href="scores.php" class="nav-link">Scores</a>
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

            <p><a href="questions.php?week=<?php echo intval($q['week']); ?>" class="btn btn-secondary">← Terug naar vragen</a></p>

            <div class="card">
                <h2><?php echo $type_icons[$type] ?? ''; ?> Vraag #<?php echo intval($q['question_number']); ?> bewerken
                    <span class="label-hint">(type: <?php echo htmlspecialchars($type_labels[$type] ?? $type); ?> — niet wijzigbaar)</span>
                </h2>

                <form method="POST" class="form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="week">Week</label>
                            <input type="number" id="week" name="week" value="<?php echo intval($q['week']); ?>" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="question_number">Vraagnummer</label>
                            <input type="number" id="question_number" name="question_number" value="<?php echo intval($q['question_number']); ?>" min="1" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="question">Vraag</label>
                        <input type="text" id="question" name="question" required value="<?php echo htmlspecialchars($q['question']); ?>">
                    </div>

                    <?php if ($type === 'photo'): ?>
                        <div class="form-group">
                            <label>Huidige foto</label>
                            <?php if ($q['media_path']): ?>
                                <div class="media-preview"><img src="../<?php echo htmlspecialchars($q['media_path']); ?>" alt="" style="max-width: 300px; border: 1px solid #000;"></div>
                            <?php else: ?>
                                <p class="form-hint">Geen foto opgeslagen.</p>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="photo_file">Vervang foto <span class="label-hint">(optioneel — leeg = behoud huidige)</span></label>
                            <input type="file" id="photo_file" name="photo_file" accept="image/jpeg,image/png,image/webp,image/gif">
                        </div>
                    <?php endif; ?>

                    <?php if ($type === 'audio'): ?>
                        <div class="form-group">
                            <label>Huidige audio</label>
                            <?php if ($q['media_path']): ?>
                                <div class="media-preview"><audio controls src="../<?php echo htmlspecialchars($q['media_path']); ?>"></audio></div>
                            <?php else: ?>
                                <p class="form-hint">Geen audio opgeslagen.</p>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="audio_file">Vervang audio <span class="label-hint">(optioneel — leeg = behoud huidige)</span></label>
                            <input type="file" id="audio_file" name="audio_file" accept="audio/*">
                        </div>
                    <?php endif; ?>

                    <?php if ($type === 'video'): ?>
                        <div class="form-group">
                            <label>Huidige video</label>
                            <?php if ($q['video_source'] === 'youtube' && $q['video_youtube_id']): ?>
                                <div class="media-preview"><iframe width="320" height="180" src="https://www.youtube.com/embed/<?php echo htmlspecialchars($q['video_youtube_id']); ?>" frameborder="0" allowfullscreen></iframe></div>
                            <?php elseif ($q['video_source'] === 'upload' && $q['media_path']): ?>
                                <div class="media-preview"><video controls style="max-width: 320px;" src="../<?php echo htmlspecialchars($q['media_path']); ?>"></video></div>
                            <?php else: ?>
                                <p class="form-hint">Geen video opgeslagen.</p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>Video Bron</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="video_source" value="upload" <?php echo $q['video_source'] === 'upload' ? 'checked' : ''; ?> onchange="updateVideoSourceFields()"> Upload
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="video_source" value="youtube" <?php echo $q['video_source'] === 'youtube' ? 'checked' : ''; ?> onchange="updateVideoSourceFields()"> YouTube
                                </label>
                            </div>
                        </div>

                        <div class="form-group video-source-upload" <?php echo $q['video_source'] === 'upload' ? '' : 'style="display:none;"'; ?>>
                            <label for="video_file">Vervang video <span class="label-hint">(optioneel als al opgeslagen — anders verplicht)</span></label>
                            <input type="file" id="video_file" name="video_file" accept="video/mp4,video/webm">
                        </div>

                        <div class="form-group video-source-youtube" <?php echo $q['video_source'] === 'youtube' ? '' : 'style="display:none;"'; ?>>
                            <label for="video_youtube_url">YouTube URL of ID</label>
                            <input type="text" id="video_youtube_url" name="video_youtube_url"
                                value="<?php echo htmlspecialchars($q['video_youtube_id'] ?? ''); ?>"
                                placeholder="https://www.youtube.com/watch?v=...">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="video_start">Start <span class="label-hint">(seconden)</span></label>
                                <input type="number" id="video_start" name="video_start" min="0" value="<?php echo $q['video_start'] !== null ? intval($q['video_start']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="video_end">Eind <span class="label-hint">(seconden, leeg = tot einde)</span></label>
                                <input type="number" id="video_end" name="video_end" min="0" value="<?php echo $q['video_end'] !== null ? intval($q['video_end']) : ''; ?>">
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($type === 'multiple_choice'): ?>
                        <div class="form-group">
                            <label>Antwoord Opties <span class="label-hint">(vink "correct" aan — meerdere mogelijk)</span></label>
                            <div class="mc-builder" id="mc-options-container">
                                <?php foreach ($options as $idx => $opt): ?>
                                    <div class="mc-option-row" data-idx="<?php echo $idx; ?>">
                                        <span class="mc-letter-badge"><?php echo chr(65 + $idx); ?></span>
                                        <input type="hidden" name="option_id[<?php echo $idx; ?>]" value="<?php echo intval($opt['id']); ?>">
                                        <input type="text" name="option_text[<?php echo $idx; ?>]" placeholder="Tekst" value="<?php echo htmlspecialchars($opt['option_text'] ?? ''); ?>">
                                        <input type="file" name="option_image[<?php echo $idx; ?>]" accept="image/*">
                                        <label class="mc-correct-label">
                                            <input type="checkbox" name="option_correct[<?php echo $idx; ?>]" value="1" <?php echo $opt['is_correct'] ? 'checked' : ''; ?>> Correct
                                        </label>
                                        <button type="button" class="mc-remove-btn" onclick="removeOption(this)" title="Verwijderen">✕</button>
                                        <?php if ($opt['option_image_path']): ?>
                                            <div class="mc-existing-image" style="grid-column: 1 / -1; display: flex; align-items: center; gap: 12px; margin-top: 4px;">
                                                <img src="../<?php echo htmlspecialchars($opt['option_image_path']); ?>" alt="" style="max-height: 60px; border: 1px solid #000;">
                                                <label class="mc-correct-label">
                                                    <input type="checkbox" name="option_delete_image[<?php echo $idx; ?>]" value="1"> Verwijder afbeelding
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-secondary mc-add-btn" onclick="addOption()">+ Optie Toevoegen</button>
                        </div>
                    <?php endif; ?>

                    <?php if ($type !== 'multiple_choice'): ?>
                        <div class="form-group">
                            <label for="answer">Antwoord <?php echo $type === 'text' ? '' : '<span class="label-hint">(optioneel)</span>'; ?></label>
                            <input type="text" id="answer" name="answer" value="<?php echo htmlspecialchars($q['answer'] ?? ''); ?>">
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="category">Categorie</label>
                        <input type="text" id="category" name="category" value="<?php echo htmlspecialchars($q['category'] ?? 'Algemeen'); ?>">
                    </div>

                    <button type="submit" class="btn btn-primary">Wijzigingen Opslaan</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function updateVideoSourceFields() {
            const source = document.querySelector('input[name="video_source"]:checked').value;
            const upDiv = document.querySelector('.video-source-upload');
            const ytDiv = document.querySelector('.video-source-youtube');
            if (upDiv) upDiv.style.display = source === 'upload' ? '' : 'none';
            if (ytDiv) ytDiv.style.display = source === 'youtube' ? '' : 'none';
        }

        // MC: index begint na bestaande opties zodat new option_text[N] niet botst
        let optionIdx = <?php echo count($options); ?>;

        function addOption() {
            const container = document.getElementById('mc-options-container');
            const div = document.createElement('div');
            div.className = 'mc-option-row';
            const i = optionIdx;
            div.innerHTML = `
                <span class="mc-letter-badge">${String.fromCharCode(65 + i)}</span>
                <input type="hidden" name="option_id[${i}]" value="">
                <input type="text" name="option_text[${i}]" placeholder="Tekst">
                <input type="file" name="option_image[${i}]" accept="image/*">
                <label class="mc-correct-label">
                    <input type="checkbox" name="option_correct[${i}]" value="1"> Correct
                </label>
                <button type="button" class="mc-remove-btn" onclick="removeOption(this)" title="Verwijderen">✕</button>
            `;
            container.appendChild(div);
            optionIdx++;
        }

        function removeOption(btn) {
            btn.closest('.mc-option-row').remove();
            renumberOptionLetters();
        }

        // Alleen letters opnieuw nummeren; name-indexen blijven gekoppeld aan option_id zodat de server-side mapping intact blijft
        function renumberOptionLetters() {
            const rows = document.querySelectorAll('#mc-options-container .mc-option-row');
            rows.forEach((row, i) => {
                const badge = row.querySelector('.mc-letter-badge');
                if (badge) badge.textContent = String.fromCharCode(65 + i);
            });
        }
    </script>
</body>
</html>
