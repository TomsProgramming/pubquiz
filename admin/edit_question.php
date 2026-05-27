<?php
require_once 'auth.php';

requireAdmin();

include '../database_connect.php';
include 'upload_helper.php';
require_once 'edit_question_model.php';

$message      = '';
$message_type = '';

$question_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($question_id <= 0) {
    header('Location: questions.php');
    exit;
}

$question = get_question($question_id);
if (!$question) {
    header('Location: questions.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    csrf_validate();
    $result       = handle_update_question($question_id, $question, $_POST, $_FILES);
    $message      = $result['message'];
    $message_type = $result['message_type'];
    if ($result['message_type'] === 'success') {
        $question = get_question($question_id);
    }
}

$options     = $question['question_type'] === 'multiple_choice' ? get_question_options($question_id) : [];
$type_icons  = ['text' => '📝', 'multiple_choice' => '☑️', 'video' => '🎬', 'audio' => '🎵', 'photo' => '📷'];
$type_labels = ['text' => 'Tekst', 'multiple_choice' => 'Multiple Choice', 'video' => 'Video', 'audio' => 'Audio', 'photo' => 'Foto'];
$q    = $question;
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
                <div class="alert alert-<?= $message_type; ?>">
                    <?= htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <p><a href="questions.php?week=<?= intval($q['week']); ?>" class="btn btn-secondary">← Terug naar vragen</a></p>

            <div class="card">
                <h2><?= $type_icons[$type] ?? ''; ?> Vraag #<?= intval($q['question_number']); ?> bewerken
                    <span class="label-hint">(type: <?= htmlspecialchars($type_labels[$type] ?? $type); ?> — niet wijzigbaar)</span>
                </h2>

                <form method="POST" class="form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="week">Week</label>
                            <input type="number" id="week" name="week" value="<?= intval($q['week']); ?>" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="question_number">Vraagnummer</label>
                            <input type="number" id="question_number" name="question_number" value="<?= intval($q['question_number']); ?>" min="1" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="question">Vraag</label>
                        <input type="text" id="question" name="question" required value="<?= htmlspecialchars($q['question']); ?>">
                    </div>

                    <?php if ($type === 'photo'): ?>
                        <div class="form-group">
                            <label>Huidige foto</label>
                            <?php if ($q['media_path']): ?>
                                <div class="media-preview"><img src="../<?= htmlspecialchars($q['media_path']); ?>" alt="" style="max-width: 300px; border: 1px solid #000;"></div>
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
                                <div class="media-preview"><audio controls src="../<?= htmlspecialchars($q['media_path']); ?>"></audio></div>
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
                                <div class="media-preview"><iframe width="320" height="180" src="https://www.youtube.com/embed/<?= htmlspecialchars($q['video_youtube_id']); ?>" frameborder="0" allowfullscreen></iframe></div>
                            <?php elseif ($q['video_source'] === 'upload' && $q['media_path']): ?>
                                <div class="media-preview"><video controls style="max-width: 320px;" src="../<?= htmlspecialchars($q['media_path']); ?>"></video></div>
                            <?php else: ?>
                                <p class="form-hint">Geen video opgeslagen.</p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>Video Bron</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="video_source" value="upload" <?= $q['video_source'] === 'upload' ? 'checked' : ''; ?> onchange="updateVideoSourceFields()"> Upload
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="video_source" value="youtube" <?= $q['video_source'] === 'youtube' ? 'checked' : ''; ?> onchange="updateVideoSourceFields()"> YouTube
                                </label>
                            </div>
                        </div>

                        <div class="form-group video-source-upload" <?= $q['video_source'] === 'upload' ? '' : 'style="display:none;"'; ?>>
                            <label for="video_file">Vervang video <span class="label-hint">(optioneel als al opgeslagen — anders verplicht)</span></label>
                            <input type="file" id="video_file" name="video_file" accept="video/mp4,video/webm">
                        </div>

                        <div class="form-group video-source-youtube" <?= $q['video_source'] === 'youtube' ? '' : 'style="display:none;"'; ?>>
                            <label for="video_youtube_url">YouTube URL of ID</label>
                            <input type="text" id="video_youtube_url" name="video_youtube_url"
                                value="<?= htmlspecialchars($q['video_youtube_id'] ?? ''); ?>"
                                placeholder="https://www.youtube.com/watch?v=...">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="video_start">Start <span class="label-hint">(seconden)</span></label>
                                <input type="number" id="video_start" name="video_start" min="0" value="<?= $q['video_start'] !== null ? intval($q['video_start']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="video_end">Eind <span class="label-hint">(seconden, leeg = tot einde)</span></label>
                                <input type="number" id="video_end" name="video_end" min="0" value="<?= $q['video_end'] !== null ? intval($q['video_end']) : ''; ?>">
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($type === 'multiple_choice'): ?>
                        <div class="form-group">
                            <label>Antwoord Opties <span class="label-hint">(vink "correct" aan — meerdere mogelijk)</span></label>
                            <div class="mc-builder" id="mc-options-container">
                                <?php foreach ($options as $idx => $opt): ?>
                                    <div class="mc-option-row" data-idx="<?= $idx; ?>">
                                        <span class="mc-letter-badge"><?= chr(65 + $idx); ?></span>
                                        <input type="hidden" name="option_id[<?= $idx; ?>]" value="<?= intval($opt['id']); ?>">
                                        <input type="text" name="option_text[<?= $idx; ?>]" placeholder="Tekst" value="<?= htmlspecialchars($opt['option_text'] ?? ''); ?>">
                                        <input type="file" name="option_image[<?= $idx; ?>]" accept="image/*">
                                        <label class="mc-correct-label">
                                            <input type="checkbox" name="option_correct[<?= $idx; ?>]" value="1" <?= $opt['is_correct'] ? 'checked' : ''; ?>> Correct
                                        </label>
                                        <button type="button" class="mc-remove-btn" onclick="removeOption(this)" title="Verwijderen">✕</button>
                                        <?php if ($opt['option_image_path']): ?>
                                            <div class="mc-existing-image" style="grid-column: 1 / -1; display: flex; align-items: center; gap: 12px; margin-top: 4px;">
                                                <img src="../<?= htmlspecialchars($opt['option_image_path']); ?>" alt="" style="max-height: 60px; border: 1px solid #000;">
                                                <label class="mc-correct-label">
                                                    <input type="checkbox" name="option_delete_image[<?= $idx; ?>]" value="1"> Verwijder afbeelding
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
                            <label for="answer">Antwoord <?= $type === 'text' ? '' : '<span class="label-hint">(optioneel)</span>'; ?></label>
                            <input type="text" id="answer" name="answer" value="<?= htmlspecialchars($q['answer'] ?? ''); ?>">
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="category">Categorie</label>
                        <input type="text" id="category" name="category" value="<?= htmlspecialchars($q['category'] ?? 'Algemeen'); ?>">
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
        let optionIdx = <?= count($options); ?>;

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
