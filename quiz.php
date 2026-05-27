<?php
session_start();

// Quiz is alleen toegankelijk voor ingelogde admins
// (zodat deelnemers de vragen niet vooraf kunnen zien)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin/login.php');
    exit;
}

include 'database_connect.php';

// Get the current week or use the max week
$current_week = isset($_GET['week']) ? intval($_GET['week']) : null;

if (!$current_week) {
    $week_result = $conn->query("SELECT MAX(week) as max_week FROM questions");
    $week_row = $week_result->fetch_assoc();
    $current_week = $week_row['max_week'] ?? 1;
}

// Get all questions for this week (prepared statement, gesorteerd op display_order)
$stmt = $conn->prepare("SELECT * FROM questions WHERE week = ? ORDER BY display_order ASC, question_number ASC");
$stmt->bind_param("i", $current_week);
$stmt->execute();
$questions_result = $stmt->get_result();
$questions = [];
if ($questions_result && $questions_result->num_rows > 0) {
    while ($row = $questions_result->fetch_assoc()) {
        $questions[] = $row;
    }
}

// Get all available weeks
$weeks_result = $conn->query("SELECT DISTINCT week FROM questions ORDER BY week DESC");
$weeks = [];
if ($weeks_result && $weeks_result->num_rows > 0) {
    while ($row = $weeks_result->fetch_assoc()) {
        $weeks[] = $row['week'];
    }
}

// Get current question index
$current_index = isset($_GET['q']) ? intval($_GET['q']) : 0;
if ($current_index < 0) $current_index = 0;
if ($current_index >= count($questions)) $current_index = count($questions) - 1;

// Get current question
$current_question = isset($questions[$current_index]) ? $questions[$current_index] : null;

// Check if all questions have been shown
$all_questions_shown = isset($_GET['all_shown']) && $_GET['all_shown'] === '1';

// Only show answers if all questions have been viewed
$show_answers = $all_questions_shown && isset($_GET['answers']) && $_GET['answers'] === '1';

// Voor multiple choice vragen: opties ophalen
$current_options = [];
if ($current_question && $current_question['question_type'] === 'multiple_choice') {
    $opt_stmt = $conn->prepare("SELECT * FROM answer_options WHERE question_id = ? ORDER BY display_order ASC, id ASC");
    $opt_stmt->bind_param("i", $current_question['id']);
    $opt_stmt->execute();
    $opt_result = $opt_stmt->get_result();
    while ($r = $opt_result->fetch_assoc()) {
        $current_options[] = $r;
    }
}

/**
 * Render de media/content van een vraag op basis van question_type.
 */
function render_question_media($q) {
    $type = $q['question_type'] ?? 'text';
    if ($type === 'photo' && $q['media_path']) {
        echo '<div class="question-media-wrapper"><img src="' . htmlspecialchars($q['media_path']) . '" alt="" class="question-media"></div>';
    } elseif ($type === 'audio' && $q['media_path']) {
        echo '<div class="question-media-wrapper"><audio autoplay controls src="' . htmlspecialchars($q['media_path']) . '" class="question-media question-audio"></audio></div>';
    } elseif ($type === 'video') {
        $start = $q['video_start'] !== null ? intval($q['video_start']) : 0;
        $end_raw = $q['video_end'] !== null ? intval($q['video_end']) : '';

        if ($q['video_source'] === 'youtube' && $q['video_youtube_id']) {
            $yt_id = $q['video_youtube_id'];
            // enablejsapi=1 + iframe-id zodat we de loop via de YouTube IFrame API kunnen besturen
            $params = ['autoplay=1', 'controls=1', 'rel=0', 'playsinline=1', 'enablejsapi=1'];
            if ($start > 0) $params[] = 'start=' . $start;
            if ($end_raw !== '') $params[] = 'end=' . intval($end_raw);
            $src = 'https://www.youtube.com/embed/' . htmlspecialchars($yt_id) . '?' . implode('&', $params);
            $iframe_id = 'yt-player-' . intval($q['id']);
            echo '<div class="question-media-wrapper video-embed-wrapper">'
                . '<iframe id="' . $iframe_id . '" class="question-youtube"'
                . ' data-start="' . $start . '" data-end="' . htmlspecialchars((string)$end_raw) . '"'
                . ' src="' . $src . '" frameborder="0"'
                . ' allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>'
                . '</div>';
        } elseif ($q['video_source'] === 'upload' && $q['media_path']) {
            echo '<div class="question-media-wrapper"><video autoplay controls playsinline'
                . ' class="question-media question-video"'
                . ' data-start="' . $start . '" data-end="' . htmlspecialchars((string)$end_raw) . '"'
                . ' src="' . htmlspecialchars($q['media_path']) . '"></video></div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PubQuiz - Week <?php echo $current_week; ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="content content-quiz">
            <?php
            $quiz_started = isset($_GET['start']) && $_GET['start'] === '1';

            if (!$quiz_started): ?>
                <!-- START SCREEN WITH WEEK SELECTOR -->
                <div class="quiz-start">
                    <h2 class="quiz-start-title">Quiz</h2>

                    <div class="quiz-start-field">
                        <label for="week-select-start">Selecteer Week</label>
                        <select id="week-select-start">
                            <?php foreach ($weeks as $week): ?>
                                <option value="<?php echo $week; ?>" <?php echo $week == $current_week ? 'selected' : ''; ?>>Week <?php echo $week; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button onclick="startQuiz()" class="btn btn-primary">Start →</button>

                    <div class="quiz-start-back">
                        <a href="admin/index.php" class="btn btn-secondary">← Terug naar Admin</a>
                    </div>
                </div>
            <?php else: ?>
                <!-- QUIZ CONTENT -->
                <div class="quiz-back-bar">
                    <a href="quiz.php" class="btn btn-secondary">← Startscherm</a>
                    <a href="admin/index.php" class="btn btn-secondary">← Admin Dashboard</a>
                </div>
                <?php if (count($questions) > 0 && $current_question): ?>
                    <div class="question-container">
                        <div class="question-display">
                            <div class="question-number-display">
                                Vraag <?php echo intval($current_question['question_number']); ?>
                            </div>
                            <div class="question-category-display">
                                <?php echo htmlspecialchars($current_question['category']); ?>
                            </div>
                            <div class="question-text-display">
                                <?php echo htmlspecialchars($current_question['question']); ?>
                            </div>

                            <?php render_question_media($current_question); ?>

                            <?php if ($current_question['question_type'] === 'multiple_choice' && !empty($current_options)): ?>
                                <ul class="mc-options">
                                    <?php foreach ($current_options as $idx => $opt): ?>
                                        <?php
                                        $letter = chr(65 + $idx);
                                        $is_correct = $show_answers && $opt['is_correct'];
                                        ?>
                                        <li class="mc-option <?php echo $is_correct ? 'option-correct' : ''; ?>">
                                            <span class="mc-letter"><?php echo $letter; ?></span>
                                            <?php if ($opt['option_image_path']): ?>
                                                <img src="<?php echo htmlspecialchars($opt['option_image_path']); ?>" alt="" class="mc-image">
                                            <?php endif; ?>
                                            <?php if (!empty($opt['option_text'])): ?>
                                                <span class="mc-text"><?php echo htmlspecialchars($opt['option_text']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($is_correct): ?>
                                                <span class="mc-correct-badge">✓</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ($all_questions_shown && $show_answers && $current_question['question_type'] !== 'multiple_choice' && !empty($current_question['answer'])): ?>
                                <div class="question-answer-display">
                                    <div class="question-answer-label">Antwoord</div>
                                    <div class="question-answer-text">
                                        <?php echo htmlspecialchars($current_question['answer']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="question-nav">
                            <div class="question-progress">
                                Vraag <?php echo ($current_index + 1); ?> van <?php echo count($questions); ?>
                            </div>

                            <div class="question-nav-buttons">
                                <?php if ($current_index > 0): ?>
                                    <a href="quiz.php?start=1&week=<?php echo $current_week; ?>&q=<?php echo ($current_index - 1); ?>&all_shown=<?php echo $all_questions_shown ? 1 : 0; ?>&answers=<?php echo ($all_questions_shown && $show_answers) ? 1 : 0; ?>" class="btn btn-secondary">← Vorige</a>
                                <?php else: ?>
                                    <button class="btn btn-secondary btn-disabled" disabled>← Vorige</button>
                                <?php endif; ?>

                                <?php if ($current_index < count($questions) - 1): ?>
                                    <a href="quiz.php?start=1&week=<?php echo $current_week; ?>&q=<?php echo ($current_index + 1); ?>&all_shown=<?php echo $all_questions_shown ? 1 : 0; ?>&answers=<?php echo ($all_questions_shown && $show_answers) ? 1 : 0; ?>" class="btn btn-primary">Volgende →</a>
                                <?php else: ?>
                                    <?php if (!$all_questions_shown): ?>
                                        <a href="quiz.php?start=1&week=<?php echo $current_week; ?>&q=<?php echo $current_index; ?>&all_shown=1&answers=0" class="btn btn-primary">Alle Vragen Bekeken →</a>
                                    <?php else: ?>
                                        <a href="quiz.php?start=1&week=<?php echo $current_week; ?>&q=0&all_shown=1&answers=<?php echo $show_answers ? 1 : 0; ?>" class="btn btn-primary">Opnieuw →</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($all_questions_shown): ?>
                            <div class="toggle-wrapper">
                                <div class="toggle-view">
                                    <a href="quiz.php?start=1&week=<?php echo $current_week; ?>&q=<?php echo $current_index; ?>&all_shown=1&answers=0" class="<?php echo !$show_answers ? 'active' : ''; ?>">Vragen</a>
                                    <a href="quiz.php?start=1&week=<?php echo $current_week; ?>&q=<?php echo $current_index; ?>&all_shown=1&answers=1" class="<?php echo $show_answers ? 'active' : ''; ?>">Antwoorden</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="quiz-empty">
                        <p>Geen vragen beschikbaar voor week <?php echo $current_week; ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function startQuiz() {
            const week = document.getElementById('week-select-start').value;
            window.location.href = 'quiz.php?start=1&week=' + week + '&q=0&all_shown=0&answers=0';
        }

        // Uploaded video's: autoplay (via attribuut) + loop tussen start en end
        document.querySelectorAll('video.question-video').forEach(function(video) {
            const start = parseFloat(video.dataset.start) || 0;
            const endRaw = video.dataset.end;
            const end = endRaw !== '' ? parseFloat(endRaw) : null;

            const seekToStart = function() {
                try { video.currentTime = start; } catch (e) {}
                const p = video.play();
                if (p && typeof p.catch === 'function') p.catch(function() {});
            };

            video.addEventListener('loadedmetadata', function() {
                if (start > 0) {
                    try { video.currentTime = start; } catch (e) {}
                }
            });

            if (end !== null && end > 0) {
                video.addEventListener('timeupdate', function() {
                    if (video.currentTime >= end) seekToStart();
                });
            }

            // Natuurlijke einde (geen expliciete end opgegeven, of end == lengte)
            video.addEventListener('ended', seekToStart);
        });

        // YouTube embeds: autoplay (via URL) + loop via IFrame API
        (function() {
            const ytIframes = document.querySelectorAll('iframe.question-youtube');
            if (ytIframes.length === 0) return;

            function initPlayers() {
                ytIframes.forEach(function(iframe) {
                    const start = parseFloat(iframe.dataset.start) || 0;
                    const endRaw = iframe.dataset.end;
                    const end = endRaw !== '' ? parseFloat(endRaw) : null;
                    let endChecker = null;

                    const player = new YT.Player(iframe, {
                        events: {
                            onReady: function(e) {
                                if (e.target.unMute) e.target.unMute();
                                e.target.playVideo();
                                if (end !== null && end > 0) {
                                    endChecker = setInterval(function() {
                                        const t = e.target.getCurrentTime ? e.target.getCurrentTime() : 0;
                                        if (t >= end) {
                                            e.target.seekTo(start, true);
                                            e.target.playVideo();
                                        }
                                    }, 250);
                                }
                            },
                            onStateChange: function(e) {
                                if (e.data === YT.PlayerState.ENDED) {
                                    e.target.seekTo(start, true);
                                    e.target.playVideo();
                                }
                            }
                        }
                    });
                });
            }

            if (window.YT && window.YT.Player) {
                initPlayers();
            } else {
                // YT API script één keer laden; meerdere quiz-pagina's hergebruiken dezelfde callback
                window.onYouTubeIframeAPIReady = initPlayers;
                if (!document.querySelector('script[data-yt-api]')) {
                    const tag = document.createElement('script');
                    tag.src = 'https://www.youtube.com/iframe_api';
                    tag.setAttribute('data-yt-api', '1');
                    document.head.appendChild(tag);
                }
            }
        })();
    </script>
</body>
</html>
