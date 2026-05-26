<?php
require_once 'auth.php';

requireAdmin();

include '../database_connect.php';
include 'upload_helper.php';
require_once 'questions_model.php';

$message = '';
$message_type = '';

$form = [];

$current_week = isset($_GET['week']) && is_numeric($_GET['week']) ? intval($_GET['week']) : 1;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add') {
            $week = intval($_POST['week']);
            $question_number_input = trim($_POST['question_number'] ?? '');
            $question_number = $question_number_input === '' ? next_question_number($week) : intval($question_number_input);
            $question = trim($_POST['question']);
            $category = trim($_POST['category'] ?? 'Algemeen');
            $question_type = $_POST['question_type'] ?? 'text';

            $allowed_types = ['text', 'multiple_choice', 'video', 'audio', 'photo'];
            if (!in_array($question_type, $allowed_types, true)) {
                $question_type = 'text';
            }

            // Voor text en multiple_choice is "answer" een tekstveld; voor media types is het optioneel (uitleg/beschrijving)
            $answer = trim($_POST['answer'] ?? '');

            if (empty($question)) {
                $message = "Vraag mag niet leeg zijn";
                $message_type = 'error';
                $form = $_POST;
            } elseif ($question_type === 'text' && empty($answer)) {
                $message = "Antwoord mag niet leeg zijn bij tekst-vraag";
                $message_type = 'error';
                $form = $_POST;
            } else {
                $media_path = null;
                $video_source = null;
                $video_youtube_id = null;
                $video_start = null;
                $video_end = null;
                $upload_error = null;

                // Per type upload/validatie afhandelen
                if ($question_type === 'photo') {
                    $result = handle_upload($_FILES['photo_file'] ?? null, UPLOAD_TYPE_PHOTO);
                    if (!$result['ok']) $upload_error = $result['error'];
                    else $media_path = $result['path'];
                } elseif ($question_type === 'audio') {
                    $result = handle_upload($_FILES['audio_file'] ?? null, UPLOAD_TYPE_AUDIO);
                    if (!$result['ok']) $upload_error = $result['error'];
                    else $media_path = $result['path'];
                } elseif ($question_type === 'video') {
                    $video_source = ($_POST['video_source'] ?? 'upload') === 'youtube' ? 'youtube' : 'upload';
                    $video_start = isset($_POST['video_start']) && $_POST['video_start'] !== '' ? intval($_POST['video_start']) : null;
                    $video_end = isset($_POST['video_end']) && $_POST['video_end'] !== '' ? intval($_POST['video_end']) : null;

                    if ($video_source === 'youtube') {
                        $youtube_id = extract_youtube_id($_POST['video_youtube_url'] ?? '');
                        if (!$youtube_id) {
                            $upload_error = 'Ongeldige YouTube URL of ID';
                        } else {
                            $video_youtube_id = $youtube_id;
                        }
                    } else {
                        $result = handle_upload($_FILES['video_file'] ?? null, UPLOAD_TYPE_VIDEO);
                        if (!$result['ok']) $upload_error = $result['error'];
                        else $media_path = $result['path'];
                    }
                }

                if ($upload_error) {
                    $message = "Fout: " . $upload_error;
                    $message_type = 'error';
                    $form = $_POST;
                } else {
                    $display_order = next_display_order($week);

                    $stmt = $conn->prepare("INSERT INTO questions (week, question_number, question, answer, category, points, question_type, display_order, media_path, video_source, video_youtube_id, video_start, video_end) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)");
                    // Types: i i s s s s i s s s i i  (12 parameters)
                    $stmt->bind_param("iissssisssii", $week, $question_number, $question, $answer, $category, $question_type, $display_order, $media_path, $video_source, $video_youtube_id, $video_start, $video_end);

                    if ($stmt->execute()) {
                        $new_question_id = $conn->insert_id;

                        // Multiple choice opties verwerken
                        if ($question_type === 'multiple_choice' && isset($_POST['option_text']) && is_array($_POST['option_text'])) {
                            $option_texts = $_POST['option_text'];
                            $option_correct = $_POST['option_correct'] ?? [];
                            $option_files = $_FILES['option_image'] ?? null;

                            $opt_stmt = $conn->prepare("INSERT INTO answer_options (question_id, option_text, option_image_path, is_correct, display_order) VALUES (?, ?, ?, ?, ?)");

                            foreach ($option_texts as $idx => $opt_text) {
                                $opt_text = trim($opt_text);
                                $opt_image_path = null;

                                // Upload optionele afbeelding
                                if ($option_files && isset($option_files['error'][$idx]) && $option_files['error'][$idx] !== UPLOAD_ERR_NO_FILE) {
                                    $file_arr = [
                                        'name' => $option_files['name'][$idx],
                                        'type' => $option_files['type'][$idx],
                                        'tmp_name' => $option_files['tmp_name'][$idx],
                                        'error' => $option_files['error'][$idx],
                                        'size' => $option_files['size'][$idx],
                                    ];
                                    $opt_result = handle_upload($file_arr, UPLOAD_TYPE_OPTION);
                                    if ($opt_result['ok']) {
                                        $opt_image_path = $opt_result['path'];
                                    }
                                }

                                // Skip lege opties (geen tekst EN geen afbeelding)
                                if ($opt_text === '' && !$opt_image_path) continue;

                                $is_correct = isset($option_correct[$idx]) ? 1 : 0;
                                $opt_order = $idx + 1;
                                $opt_stmt->bind_param("issii", $new_question_id, $opt_text, $opt_image_path, $is_correct, $opt_order);
                                $opt_stmt->execute();
                            }
                        }

                        $message = "Vraag succesvol toegevoegd";
                        $message_type = 'success';
                    } else {
                        $message = "Fout: " . $conn->error;
                        $message_type = 'error';
                        $form = $_POST;
                    }
                }
            }
        } elseif ($_POST['action'] == 'delete') {
            $question_id = intval($_POST['question_id']);

            // Eerst media paths ophalen om bestanden op te ruimen
            $stmt = $conn->prepare("SELECT media_path FROM questions WHERE id = ?");
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $media_row = $stmt->get_result()->fetch_assoc();

            $opt_stmt = $conn->prepare("SELECT option_image_path FROM answer_options WHERE question_id = ?");
            $opt_stmt->bind_param("i", $question_id);
            $opt_stmt->execute();
            $opt_result = $opt_stmt->get_result();
            $opt_files = [];
            while ($r = $opt_result->fetch_assoc()) {
                if ($r['option_image_path']) $opt_files[] = $r['option_image_path'];
            }

            $del_stmt = $conn->prepare("DELETE FROM questions WHERE id = ?");
            $del_stmt->bind_param("i", $question_id);

            if ($del_stmt->execute()) {
                // ON DELETE CASCADE verwijdert answer_options automatisch; nu nog bestanden opruimen
                if ($media_row && $media_row['media_path']) delete_upload($media_row['media_path']);
                foreach ($opt_files as $p) delete_upload($p);

                $message = "Vraag verwijderd";
                $message_type = 'success';
            } else {
                $message = "Fout: " . $conn->error;
                $message_type = 'error';
            }
        } elseif ($_POST['action'] == 'add_bulk') {
            $week = intval($_POST['week']);
            $bulk_text = $_POST['bulk_text'];
            $default_category = trim($_POST['category'] ?? 'Algemeen');

            // Parse bulk questions (format: 1. Question | Answer or 1. Question | Answer | Category)
            $lines = explode("\n", $bulk_text);
            $added = 0;
            $failed = 0;

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $category = $default_category;

                if (preg_match('/^(\d+)\.\s*(.+?)\s*\|\s*(.+?)\s*(?:\|\s*(.+))?$/', $line, $matches)) {
                    $question_number = intval($matches[1]);
                    $question = trim($matches[2]);
                    $answer = trim($matches[3]);
                    if (!empty($matches[4])) {
                        $category = trim($matches[4]);
                    }
                } elseif (strpos($line, '|') !== false) {
                    $parts = explode('|', $line);
                    $question = trim($parts[0]);
                    $answer = trim($parts[1]);
                    if (isset($parts[2])) {
                        $category = trim($parts[2]);
                    }
                    // Pak het eerstvolgende vrije nummer uit de DB (voorkomt botsing met UNIQUE(week, question_number))
                    $question_number = next_question_number($week);
                } else {
                    continue;
                }

                if (!empty($question) && !empty($answer)) {
                    $display_order = next_display_order($week);
                    $question_type = 'text';
                    $stmt = $conn->prepare("INSERT INTO questions (week, question_number, question, answer, category, points, question_type, display_order) VALUES (?, ?, ?, ?, ?, 1, ?, ?)");
                    $stmt->bind_param("iissssi", $week, $question_number, $question, $answer, $category, $question_type, $display_order);

                    if ($stmt->execute()) {
                        $added++;
                    } else {
                        $failed++;
                    }
                }
            }

            if ($failed > 0) {
                $message = $added . " vragen toegevoegd, " . $failed . " mislukt (vraagnummer bestaat al?)";
                $message_type = $added > 0 ? 'success' : 'error';
            } else {
                $message = $added . " vragen succesvol toegevoegd";
                $message_type = 'success';
            }
        }
    }
}

// Get max week
$week_result = $conn->query("SELECT MAX(week) as max_week FROM questions");
$week_row = $week_result->fetch_assoc();
$max_week = $week_row['max_week'] ?? 0;
$next_week = $max_week + 1;

// Get all categories
$categories_result = $conn->query("SELECT DISTINCT category FROM questions ORDER BY category ASC");
$categories = [];
if ($categories_result) {
    while ($row = $categories_result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
}

// Type-iconen
$type_icons = [
    'text' => '📝',
    'multiple_choice' => '☑️',
    'video' => '🎬',
    'audio' => '🎵',
    'photo' => '📷',
];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vragen Beheren - PubQuiz Admin</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 PubQuiz Admin - Vragen Beheren</h1>
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

            <!-- Week selector -->
            <div class="card">
                <h2>Week Selecteren</h2>
                <form method="GET" class="week-selector-inline">
                    <div class="form-group">
                        <label for="week">Huidige week</label>
                        <input type="number" id="week" name="week" value="<?php echo $current_week; ?>" min="1">
                    </div>
                    <button type="submit" class="btn btn-secondary">Ga naar week</button>
                </form>
                <p class="week-hint">Volgende week beschikbaar: <strong><?php echo $next_week; ?></strong></p>
            </div>

            <?php
            // Helper: form value met fallback
            $fv = function($key, $default = '') use ($form) {
                return htmlspecialchars((string)($form[$key] ?? $default));
            };
            $selected_type = $form['question_type'] ?? 'text';
            $selected_video_source = $form['video_source'] ?? 'upload';
            $form_week = isset($form['week']) ? intval($form['week']) : $current_week;
            $had_error = !empty($form);
            // Bestaande MC opties uit POST (bij error)
            $posted_options = [];
            if ($had_error && isset($form['option_text']) && is_array($form['option_text'])) {
                foreach ($form['option_text'] as $idx => $txt) {
                    $posted_options[] = [
                        'text' => (string)$txt,
                        'correct' => isset($form['option_correct'][$idx]),
                    ];
                }
            }
            ?>

            <!-- Add single question -->
            <div class="card">
                <h2>Voeg Vraag Toe</h2>
                <form method="POST" class="form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="q_week">Week</label>
                            <input type="number" id="q_week" name="week" value="<?php echo $form_week; ?>" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="question_number">Vraagnummer <span class="label-hint">(optioneel)</span></label>
                            <input type="number" id="question_number" name="question_number" min="1" placeholder="Leeg = automatisch achteraan" value="<?php echo $fv('question_number'); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="question_type">Type Vraag</label>
                        <select id="question_type" name="question_type" onchange="updateQuestionTypeFields()">
                            <option value="text" <?php echo $selected_type === 'text' ? 'selected' : ''; ?>>Tekst</option>
                            <option value="multiple_choice" <?php echo $selected_type === 'multiple_choice' ? 'selected' : ''; ?>>Multiple Choice</option>
                            <option value="photo" <?php echo $selected_type === 'photo' ? 'selected' : ''; ?>>Foto</option>
                            <option value="audio" <?php echo $selected_type === 'audio' ? 'selected' : ''; ?>>Audio</option>
                            <option value="video" <?php echo $selected_type === 'video' ? 'selected' : ''; ?>>Video</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="question">Vraag</label>
                        <input type="text" id="question" name="question" required placeholder="Voer de vraag in" value="<?php echo $fv('question'); ?>">
                    </div>

                    <!-- Foto upload -->
                    <div class="form-group type-field type-photo" style="display: none;">
                        <label for="photo_file">Foto <span class="label-hint">(JPG/PNG/WEBP/GIF, max 5 MB)</span></label>
                        <input type="file" id="photo_file" name="photo_file" accept="image/jpeg,image/png,image/webp,image/gif">
                        <?php if ($had_error && $selected_type === 'photo'): ?><p class="field-warning">⚠️ Selecteer het bestand opnieuw (browser bewaart geen file-uploads).</p><?php endif; ?>
                    </div>

                    <!-- Audio upload -->
                    <div class="form-group type-field type-audio" style="display: none;">
                        <label for="audio_file">Audio <span class="label-hint">(MP3/WAV/OGG/M4A, max 20 MB)</span></label>
                        <input type="file" id="audio_file" name="audio_file" accept="audio/*">
                        <?php if ($had_error && $selected_type === 'audio'): ?><p class="field-warning">⚠️ Selecteer het bestand opnieuw (browser bewaart geen file-uploads).</p><?php endif; ?>
                    </div>

                    <!-- Video -->
                    <div class="type-field type-video" style="display: none;">
                        <div class="form-group">
                            <label>Video Bron</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="video_source" value="upload" <?php echo $selected_video_source === 'upload' ? 'checked' : ''; ?> onchange="updateVideoSourceFields()"> Upload
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="video_source" value="youtube" <?php echo $selected_video_source === 'youtube' ? 'checked' : ''; ?> onchange="updateVideoSourceFields()"> YouTube
                                </label>
                            </div>
                        </div>
                        <div class="form-group video-source-upload" <?php echo $selected_video_source === 'upload' ? '' : 'style="display:none;"'; ?>>
                            <label for="video_file">Video Bestand <span class="label-hint">(MP4/WEBM, max 100 MB)</span></label>
                            <input type="file" id="video_file" name="video_file" accept="video/mp4,video/webm">
                            <?php if ($had_error && $selected_type === 'video' && $selected_video_source === 'upload'): ?><p class="field-warning">⚠️ Selecteer het bestand opnieuw (browser bewaart geen file-uploads).</p><?php endif; ?>
                        </div>
                        <div class="form-group video-source-youtube" <?php echo $selected_video_source === 'youtube' ? '' : 'style="display:none;"'; ?>>
                            <label for="video_youtube_url">YouTube URL of ID</label>
                            <input type="text" id="video_youtube_url" name="video_youtube_url" placeholder="https://www.youtube.com/watch?v=..." value="<?php echo $fv('video_youtube_url'); ?>">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="video_start">Start <span class="label-hint">(seconden)</span></label>
                                <input type="number" id="video_start" name="video_start" min="0" placeholder="0" value="<?php echo $fv('video_start'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="video_end">Eind <span class="label-hint">(seconden, leeg = tot einde)</span></label>
                                <input type="number" id="video_end" name="video_end" min="0" placeholder="" value="<?php echo $fv('video_end'); ?>">
                            </div>
                        </div>
                        <p class="form-hint">Tip: video probeert met geluid te starten — browsers kunnen dit blokkeren, klik dan op play.</p>
                    </div>

                    <!-- Multiple choice opties -->
                    <div class="type-field type-multiple_choice" style="display: none;">
                        <div class="form-group">
                            <label>Antwoord Opties <span class="label-hint">(vink "correct" aan — meerdere mogelijk)</span></label>
                            <div class="mc-builder" id="mc-options-container"></div>
                            <button type="button" class="btn btn-secondary mc-add-btn" onclick="addOption()">+ Optie Toevoegen</button>
                            <script type="application/json" id="mc-prefill-data"><?php echo json_encode($posted_options); ?></script>
                        </div>
                    </div>

                    <!-- Antwoord (text type + optioneel voor media) -->
                    <div class="form-group type-field type-text type-photo type-audio type-video">
                        <label for="answer">Antwoord</label>
                        <input type="text" id="answer" name="answer" placeholder="Voer het antwoord in" value="<?php echo $fv('answer'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="category">Categorie</label>
                        <input type="text" id="category" name="category" placeholder="bijv. Geografie, Sport, Cultuur" value="<?php echo $fv('category', 'Algemeen'); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Vraag Toevoegen</button>
                </form>
            </div>

            <!-- Add bulk questions -->
            <div class="card">
                <h2>Bulk Vragen Toevoegen <span class="label-hint">(alleen tekst)</span></h2>
                <form method="POST" class="form">
                    <input type="hidden" name="action" value="add_bulk">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="bulk_week">Week</label>
                            <input type="number" id="bulk_week" name="week" value="<?php echo $current_week; ?>" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="bulk_category">Standaard Categorie</label>
                            <input type="text" id="bulk_category" name="category" placeholder="bijv. Geografie" value="Algemeen">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="bulk_text">Vragen <span class="label-hint">(1 per regel — "Vraag | Antwoord | Categorie")</span></label>
                        <textarea id="bulk_text" name="bulk_text" rows="10" placeholder="1. Wat is de hoofdstad van Frankrijk? | Parijs | Geografie&#10;2. Hoeveel zijden heeft een driehoek? | 3 | Wiskunde"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Bulk Toevoegen</button>
                </form>
            </div>

            <!-- List questions for current week -->
            <div class="card">
                <h2>Vragen Week <?php echo $current_week; ?> <span class="label-hint">— sleep om volgorde aan te passen</span></h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 30px;"></th>
                            <th style="width: 50px;">#</th>
                            <th style="width: 60px;">Type</th>
                            <th>Vraag</th>
                            <th>Categorie</th>
                            <th>Antwoord</th>
                            <th style="width: 180px;">Acties</th>
                        </tr>
                    </thead>
                    <tbody id="questions-sortable">
                        <?php
                        $stmt = $conn->prepare("SELECT * FROM questions WHERE week = ? ORDER BY display_order ASC, question_number ASC");
                        $stmt->bind_param("i", $current_week);
                        $stmt->execute();
                        $result = $stmt->get_result();

                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                $icon = $type_icons[$row['question_type']] ?? '📝';
                                $answer_preview = $row['answer'] ?: '—';
                                echo "<tr data-id='" . intval($row['id']) . "'>
                                    <td class='drag-handle' title='Sleep om te verplaatsen'>≡</td>
                                    <td>" . intval($row['question_number']) . "</td>
                                    <td class='type-icon-cell' title='" . htmlspecialchars($row['question_type']) . "'>" . $icon . "</td>
                                    <td>" . htmlspecialchars(mb_substr($row['question'], 0, 60)) . (mb_strlen($row['question']) > 60 ? '…' : '') . "</td>
                                    <td>" . htmlspecialchars($row['category']) . "</td>
                                    <td>" . htmlspecialchars(mb_substr($answer_preview, 0, 40)) . "</td>
                                    <td>
                                        <a href='edit_question.php?id=" . intval($row['id']) . "' class='btn btn-small btn-secondary'>Bewerken</a>
                                        <form method='POST' class='inline-form' onsubmit='return confirm(\"Zeker weten?\");'>
                                            <input type='hidden' name='action' value='delete'>
                                            <input type='hidden' name='question_id' value='" . intval($row['id']) . "'>
                                            <button type='submit' class='btn btn-small btn-danger'>Verwijderen</button>
                                        </form>
                                    </td>
                                </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' class='empty'>Geen vragen voor week " . intval($current_week) . "</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- SortableJS voor drag-drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script>
        // Toon/verberg velden per vraag type
        function updateQuestionTypeFields() {
            const type = document.getElementById('question_type').value;
            document.querySelectorAll('.type-field').forEach(el => {
                el.style.display = 'none';
            });
            document.querySelectorAll('.type-' + type).forEach(el => {
                el.style.display = '';
            });
        }

        function updateVideoSourceFields() {
            const source = document.querySelector('input[name="video_source"]:checked').value;
            document.querySelector('.video-source-upload').style.display = source === 'upload' ? '' : 'none';
            document.querySelector('.video-source-youtube').style.display = source === 'youtube' ? '' : 'none';
        }

        // Multiple choice opties
        let optionIdx = 0;
        function addOption(prefillText, prefillCorrect) {
            const container = document.getElementById('mc-options-container');
            const div = document.createElement('div');
            div.className = 'mc-option-row';
            const i = optionIdx;
            const textVal = (prefillText || '').toString()
                .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            div.innerHTML = `
                <span class="mc-letter-badge">${String.fromCharCode(65 + i)}</span>
                <input type="text" name="option_text[${i}]" placeholder="Tekst" value="${textVal}">
                <input type="file" name="option_image[${i}]" accept="image/*">
                <label class="mc-correct-label">
                    <input type="checkbox" name="option_correct[${i}]" value="1" ${prefillCorrect ? 'checked' : ''}> Correct
                </label>
                <button type="button" class="mc-remove-btn" onclick="removeOption(this)" title="Verwijderen">✕</button>
            `;
            container.appendChild(div);
            optionIdx++;
        }

        function removeOption(btn) {
            btn.parentElement.remove();
            renumberOptions();
        }

        // Letters + name-indexen opnieuw genereren zodat A, B, C, ... aaneengesloten blijven
        function renumberOptions() {
            const rows = document.querySelectorAll('#mc-options-container .mc-option-row');
            rows.forEach((row, i) => {
                const badge = row.querySelector('.mc-letter-badge');
                if (badge) badge.textContent = String.fromCharCode(65 + i);
                const textInput = row.querySelector('input[type="text"]');
                if (textInput) textInput.name = `option_text[${i}]`;
                const fileInput = row.querySelector('input[type="file"]');
                if (fileInput) fileInput.name = `option_image[${i}]`;
                const checkbox = row.querySelector('input[type="checkbox"]');
                if (checkbox) checkbox.name = `option_correct[${i}]`;
            });
            optionIdx = rows.length;
        }

        // Drag-drop voor vragenlijst
        const sortable = document.getElementById('questions-sortable');
        if (sortable && sortable.querySelectorAll('tr[data-id]').length > 1) {
            Sortable.create(sortable, {
                handle: '.drag-handle',
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function() {
                    const ids = Array.from(sortable.querySelectorAll('tr[data-id]')).map(tr => parseInt(tr.dataset.id));
                    fetch('reorder_questions.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({order: ids}),
                    }).then(r => r.json()).then(data => {
                        if (!data.ok) alert('Fout bij opslaan volgorde: ' + (data.error || 'onbekend'));
                    }).catch(err => alert('Netwerkfout: ' + err));
                }
            });
        }

        // Initialiseer
        updateQuestionTypeFields();
        // MC-opties: pre-fill bij error, anders 4 lege defaults
        (function() {
            const prefillNode = document.getElementById('mc-prefill-data');
            let prefilled = [];
            if (prefillNode && prefillNode.textContent.trim() !== '') {
                try { prefilled = JSON.parse(prefillNode.textContent) || []; } catch (e) {}
            }
            if (prefilled.length > 0) {
                prefilled.forEach(opt => addOption(opt.text, opt.correct));
            } else {
                for (let i = 0; i < 4; i++) addOption();
            }
        })();
    </script>
</body>
</html>
