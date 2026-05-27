<?php
require_once 'auth.php';

requireAdmin();

include '../database_connect.php';
include 'upload_helper.php';
require_once 'questions_model.php';

$message = '';
$message_type = '';

$form = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['week']) && is_numeric($_POST['week'])) {
    $current_week = intval($_POST['week']);
} else {
    $current_week = isset($_GET['week']) && is_numeric($_GET['week']) ? intval($_GET['week']) : 1;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_validate();
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $result = handle_add_question($_POST, $_FILES);
            $message = $result['message'];
            $message_type = $result['message_type'];
            $form = $result['form'];
        } elseif ($_POST['action'] === 'delete') {
            $result = handle_delete_question(intval($_POST['question_id']));
            $message = $result['message'];
            $message_type = $result['message_type'];
        } elseif ($_POST['action'] === 'add_bulk') {
            $result = handle_add_bulk(intval($_POST['week']), $_POST['bulk_text'], trim($_POST['category'] ?? 'Algemeen'));
            $message = $result['message'];
            $message_type = $result['message_type'];
        }
    }
}

$next_week = get_max_week() + 1;
$categories = get_all_categories();

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
                <div class="alert alert-<?= $message_type ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Week selector -->
            <div class="card">
                <h2>Week Selecteren</h2>
                <form method="GET" class="week-selector-inline">
                    <div class="form-group">
                        <label for="week">Huidige week</label>
                        <input type="number" id="week" name="week" value="<?= $current_week ?>" min="1">
                    </div>
                    <button type="submit" class="btn btn-secondary">Ga naar week</button>
                </form>
                <p class="week-hint">Volgende week beschikbaar: <strong><?= $next_week ?></strong></p>
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
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

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
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
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
                        $week_questions = get_questions_for_week($current_week);
                        if ($week_questions) {
                            foreach ($week_questions as $row) {
                                $icon = $type_icons[$row['question_type']] ?? '📝';
                                $answer_preview = $row['answer'] ?: '—';
                                ?>
                                <tr data-id='<?= intval($row['id']) ?>'>
                                    <td class='drag-handle' title='Sleep om te verplaatsen'>≡</td>
                                    <td><?= intval($row['question_number']) ?></td>
                                    <td class='type-icon-cell' title='<?= htmlspecialchars($row['question_type']) ?>'><?= $icon ?></td>
                                    <td><?= htmlspecialchars(mb_substr($row['question'], 0, 60)) . (mb_strlen($row['question']) > 60 ? '…' : '') ?></td>
                                    <td><?= htmlspecialchars($row['category']) ?></td>
                                    <td><?= htmlspecialchars(mb_substr($answer_preview, 0, 40)) ?></td>
                                    <td>
                                        <a href='edit_question.php?id=<?= intval($row['id']) ?>' class='btn btn-small btn-secondary'>Bewerken</a>
                                        <form method='POST' class='inline-form' onsubmit='return confirm(\"Zeker weten?\");'>
                                            <input type='hidden' name='action' value='delete'>
                                            <input type='hidden' name='csrf_token' value='<?= csrf_token() ?>'>
                                            <input type='hidden' name='question_id' value='<?= intval($row['id']) ?>'>
                                            <input type='hidden' name='week' value='<?= $current_week ?>'>
                                            <button type='submit' class='btn btn-small btn-danger'>Verwijderen</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php
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
    <script>const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
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
                        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN},
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
