<?php

/**
 * Geeft alle vragen voor een week terug, gesorteerd op weergavevolgorde.
 *
 * @param int $week Weeknummer
 * @return array[] Lijst van vraagrijen als associatief array
 */
function get_questions_for_week(int $week): array
{
    global $conn;

    $stmt = $conn->prepare("SELECT id, question_number, question_type, question, category, answer FROM questions WHERE week = ? ORDER BY display_order ASC, question_number ASC");
    if (!$stmt) return [];
    $stmt->bind_param("i", $week);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Geeft het hoogste weeknummer terug dat in de database bestaat.
 *
 * @return int Hoogste week (0 als er nog geen vragen zijn)
 */
function get_max_week(): int
{
    global $conn;

    $result = $conn->query("SELECT COALESCE(MAX(week), 0) AS max_week FROM questions");
    return (int) ($result ? $result->fetch_assoc()['max_week'] : 0);
}

/**
 * Geeft een gesorteerde lijst van alle unieke categorieën terug.
 *
 * @return string[] Lijst van categorienamen
 */
function get_all_categories(): array
{
    global $conn;

    $result = $conn->query("SELECT DISTINCT category FROM questions ORDER BY category ASC");
    $categories = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row['category'];
        }
    }
    return $categories;
}

/**
 * Berekent de volgende display_order voor een bepaalde week.
 *
 * @param int $week Weeknummer
 * @return int Volgende display_order (huidige max + 1, minimaal 1)
 */
function next_display_order(int $week): int {
    global $conn;

    $stmt = $conn->prepare("
        SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order
        FROM questions
        WHERE week = ?
    ");

    if (!$stmt) return 1;

    $stmt->bind_param("i", $week);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['next_order'] ?? 1);
}

/**
 * Berekent het volgende unieke vraagnummer voor een bepaalde week.
 *
 * @param int $week Weeknummer
 * @return int Volgend vraagnummer (huidige max + 1, minimaal 1)
 */
function next_question_number(int $week): int {
    global $conn;

    $stmt = $conn->prepare("
        SELECT COALESCE(MAX(question_number), 0) + 1 AS next_num
        FROM questions
        WHERE week = ?
    ");

    if (!$stmt) return 1;

    $stmt->bind_param("i", $week);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['next_num'] ?? 1);
}

/**
 * Verwerkt de media-upload of YouTube-koppeling voor een vraag.
 *
 * Geeft voor elk vraagtype de juiste velden terug:
 * - photo/audio  → media_path
 * - video upload → media_path
 * - video youtube → video_youtube_id, video_start, video_end
 * Niet-media types (text, multiple_choice) geven alle velden als null terug.
 *
 * @param string $question_type Vraagtype: 'photo' | 'audio' | 'video' | 'text' | 'multiple_choice'
 * @param array  $post          $_POST data van het formulier
 * @param array  $files         $_FILES data van het formulier
 * @return array {
 *     error: string|null,
 *     media_path: string|null,
 *     video_source: string|null,
 *     video_youtube_id: string|null,
 *     video_start: int|null,
 *     video_end: int|null
 * }
 */
function resolve_question_media(string $question_type, array $post, array $files): array {
    $media_path       = null;
    $video_source     = null;
    $video_youtube_id = null;
    $video_start      = null;
    $video_end        = null;
    $error            = null;

    if ($question_type === 'photo') {
        $result = handle_upload($files['photo_file'] ?? null, UPLOAD_TYPE_PHOTO);
        if (!$result['ok']) $error = $result['error'];
        else $media_path = $result['path'];

    } elseif ($question_type === 'audio') {
        $result = handle_upload($files['audio_file'] ?? null, UPLOAD_TYPE_AUDIO);
        if (!$result['ok']) $error = $result['error'];
        else $media_path = $result['path'];

    } elseif ($question_type === 'video') {
        $video_source = ($post['video_source'] ?? 'upload') === 'youtube' ? 'youtube' : 'upload';
        $video_start  = isset($post['video_start']) && $post['video_start'] !== '' ? intval($post['video_start']) : null;
        $video_end    = isset($post['video_end'])   && $post['video_end']   !== '' ? intval($post['video_end'])   : null;

        if ($video_source === 'youtube') {
            $youtube_id = extract_youtube_id($post['video_youtube_url'] ?? '');
            if (!$youtube_id) {
                $error = 'Ongeldige YouTube URL of ID';
            } else {
                $video_youtube_id = $youtube_id;
            }
        } else {
            $result = handle_upload($files['video_file'] ?? null, UPLOAD_TYPE_VIDEO);
            if (!$result['ok']) $error = $result['error'];
            else $media_path = $result['path'];
        }
    }

    return compact('error', 'media_path', 'video_source', 'video_youtube_id', 'video_start', 'video_end');
}

/**
 * Voegt een nieuwe vraag in de database in.
 *
 * Points wordt altijd op 1 gezet; display_order bepaalt de volgorde in de quiz.
 * Nullable media-velden worden als NULL opgeslagen als ze niet van toepassing zijn.
 *
 * @param int         $week             Weeknummer
 * @param int         $question_number  Vraagnummer binnen de week
 * @param string      $question         Vraagtekst
 * @param string      $answer           Antwoordtekst (leeg voor media-types)
 * @param string      $category         Categorie (bijv. "Geografie")
 * @param string      $question_type    Type: 'text' | 'multiple_choice' | 'photo' | 'audio' | 'video'
 * @param int         $display_order    Weergavevolgorde in de lijst
 * @param string|null $media_path       Relatief pad naar geüpload bestand (bijv. "uploads/photos/q_x.jpg")
 * @param string|null $video_source     'upload' | 'youtube' | null
 * @param string|null $video_youtube_id 11-tekens YouTube video-ID of null
 * @param int|null    $video_start      Starttijd in seconden of null
 * @param int|null    $video_end        Eindtijd in seconden of null
 * @return int|false  Het nieuwe question-ID bij succes, false bij een databasefout
 */
function insert_question(int $week, int $question_number, string $question, string $answer, string $category, string $question_type, int $display_order, ?string $media_path, ?string $video_source, ?string $video_youtube_id, ?int $video_start, ?int $video_end): int|false {
    global $conn;

    $stmt = $conn->prepare("
        INSERT INTO questions
            (week, question_number, question, answer, category, points, question_type, display_order, media_path, video_source, video_youtube_id, video_start, video_end)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iissssisssii",
        $week, $question_number, $question, $answer, $category,
        $question_type, $display_order,
        $media_path, $video_source, $video_youtube_id, $video_start, $video_end
    );

    return $stmt->execute() ? $conn->insert_id : false;
}

/**
 * Voegt de antwoord-opties van een multiple choice vraag in.
 *
 * Lege opties (geen tekst én geen afbeelding) worden overgeslagen.
 * Elke optie kan een optionele afbeelding hebben die via handle_upload wordt verwerkt.
 *
 * @param int        $question_id     ID van de bijbehorende vraag
 * @param array      $option_texts    Geserialiseerde optieteksten uit $_POST['option_text']
 * @param array      $option_correct  Geserialiseerde correct-vlaggen uit $_POST['option_correct']
 * @param array|null $option_files    Geserialiseerde afbeeldingens uit $_FILES['option_image'], of null
 * @return void
 */
function insert_mc_options(int $question_id, array $option_texts, array $option_correct, ?array $option_files): void {
    global $conn;

    $stmt = $conn->prepare("
        INSERT INTO answer_options (question_id, option_text, option_image_path, is_correct, display_order)
        VALUES (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log("insert_mc_options: prepare mislukt voor question_id=$question_id: " . $conn->error);
        return;
    }

    foreach ($option_texts as $idx => $opt_text) {
        $opt_text       = trim($opt_text);
        $opt_image_path = null;

        if ($option_files && isset($option_files['error'][$idx]) && $option_files['error'][$idx] !== UPLOAD_ERR_NO_FILE) {
            $file_arr = [
                'name'     => $option_files['name'][$idx],
                'type'     => $option_files['type'][$idx],
                'tmp_name' => $option_files['tmp_name'][$idx],
                'error'    => $option_files['error'][$idx],
                'size'     => $option_files['size'][$idx],
            ];
            $opt_result = handle_upload($file_arr, UPLOAD_TYPE_OPTION);
            if ($opt_result['ok']) {
                $opt_image_path = $opt_result['path'];
            }
        }

        if ($opt_text === '' && !$opt_image_path) continue;

        $is_correct = isset($option_correct[$idx]) ? 1 : 0;
        $opt_order  = $idx + 1;
        $stmt->bind_param("issii", $question_id, $opt_text, $opt_image_path, $is_correct, $opt_order);
        if (!$stmt->execute()) {
            error_log("insert_mc_options: execute mislukt voor question_id=$question_id, optie $idx: " . $stmt->error);
        }
    }
}

/**
 * Verwijdert een vraag en ruimt bijbehorende geüploade bestanden op.
 *
 * Haalt vóór het verwijderen alle media-paden op (vraag + MC-opties) zodat de
 * bestanden van schijf verwijderd kunnen worden. answer_options worden via
 * ON DELETE CASCADE automatisch uit de database verwijderd.
 *
 * @param int $question_id ID van de te verwijderen vraag
 * @return array {
 *     message: string,      Gebruikersfeedback (succes of foutmelding)
 *     message_type: string  'success' | 'error'
 * }
 */
function handle_delete_question(int $question_id): array {
    global $conn;

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

    if (!$del_stmt->execute()) {
        return ['message' => 'Fout: ' . $conn->error, 'message_type' => 'error'];
    }

    if ($media_row && $media_row['media_path']) delete_upload($media_row['media_path']);
    foreach ($opt_files as $p) delete_upload($p);

    return ['message' => 'Vraag verwijderd', 'message_type' => 'success'];
}

/**
 * Parset en voegt meerdere tekstvragen tegelijk in vanuit een bulk-tekstblok.
 *
 * Verwacht formaat per regel (twee varianten):
 *   - Genummerd:    "1. Vraag | Antwoord | Categorie"  (categorie optioneel)
 *   - Ongenummerd:  "Vraag | Antwoord | Categorie"     (nummer wordt automatisch bepaald)
 *
 * Regels zonder pipe-scheidingsteken worden overgeslagen. Lege regels ook.
 * Alle ingevoegde vragen krijgen type 'text' en points = 1.
 *
 * @param int    $week             Weeknummer waarvoor de vragen worden ingevoegd
 * @param string $bulk_text        Ruwe tekst uit het bulk-formulierveld
 * @param string $default_category Fallback categorie als een regel er geen bevat
 * @return array {
 *     message: string,      Gebruikersfeedback met aantal toegevoegd / mislukt
 *     message_type: string  'success' | 'error'
 * }
 */
function handle_add_bulk(int $week, string $bulk_text, string $default_category): array {
    global $conn;

    if ($week < 1) {
        return ['message' => 'Weeknummer moet minimaal 1 zijn', 'message_type' => 'error'];
    }

    $lines  = explode("\n", $bulk_text);
    $added  = 0;
    $failed = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $category = $default_category;

        if (preg_match('/^(\d+)\.\s*(.+?)\s*\|\s*(.+?)\s*(?:\|\s*(.+))?$/', $line, $matches)) {
            $question_number = intval($matches[1]);
            $question        = trim($matches[2]);
            $answer          = trim($matches[3]);
            if (!empty($matches[4])) $category = trim($matches[4]);

        } elseif (strpos($line, '|') !== false) {
            $parts    = explode('|', $line);
            $question = trim($parts[0]);
            $answer   = trim($parts[1]);
            if (isset($parts[2])) $category = trim($parts[2]);
            // Automatisch nummer ophalen zodat UNIQUE(week, question_number) niet botst
            $question_number = next_question_number($week);

        } else {
            continue;
        }

        if (empty($question) || empty($answer)) continue;

        $display_order = next_display_order($week);
        $question_type = 'text';

        $stmt = $conn->prepare("
            INSERT INTO questions (week, question_number, question, answer, category, points, question_type, display_order)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?)
        ");
        $stmt->bind_param("iissssi", $week, $question_number, $question, $answer, $category, $question_type, $display_order);

        if ($stmt->execute()) {
            $added++;
        } else {
            $failed++;
        }
    }

    if ($failed > 0) {
        $type = $added > 0 ? 'success' : 'error';
        return ['message' => $added . ' vragen toegevoegd, ' . $failed . ' mislukt (vraagnummer bestaat al?)', 'message_type' => $type];
    }

    return ['message' => $added . ' vragen succesvol toegevoegd', 'message_type' => 'success'];
}

/**
 * Verwerkt de volledige "add" actie voor een nieuwe vraag (vanuit $_POST + $_FILES).
 *
 * Voert achtereenvolgens uit: invoervalidatie, media-verwerking, database-insert
 * en (indien van toepassing) het opslaan van multiple choice opties.
 *
 * @param array $post  $_POST data van het add-formulier
 * @param array $files $_FILES data van het add-formulier
 * @return array {
 *     message: string,       Gebruikersfeedback (succes of foutmelding)
 *     message_type: string,  'success' | 'error'
 *     form: array            Gevulde $_POST bij een fout (voor herinitialisatie formulier), leeg bij succes
 * }
 */
function handle_add_question(array $post, array $files): array {
    global $conn;

    $week                  = intval($post['week']);
    $question_number_input = trim($post['question_number'] ?? '');
    $question              = trim($post['question']);
    $category              = trim($post['category'] ?? 'Algemeen');
    $question_type         = $post['question_type'] ?? 'text';

    $allowed_types = ['text', 'multiple_choice', 'video', 'audio', 'photo'];
    if (!in_array($question_type, $allowed_types, true)) {
        $question_type = 'text';
    }

    $answer = trim($post['answer'] ?? '');

    if ($week < 1) {
        return ['message' => 'Weeknummer moet minimaal 1 zijn', 'message_type' => 'error', 'form' => $post];
    }
    if ($question_number_input !== '' && intval($question_number_input) < 1) {
        return ['message' => 'Vraagnummer moet minimaal 1 zijn', 'message_type' => 'error', 'form' => $post];
    }

    $question_number = $question_number_input === '' ? next_question_number($week) : intval($question_number_input);

    if ($category === '') {
        $category = 'Algemeen';
    }
    if (empty($question)) {
        return ['message' => 'Vraag mag niet leeg zijn', 'message_type' => 'error', 'form' => $post];
    }
    if ($question_type === 'text' && empty($answer)) {
        return ['message' => 'Antwoord mag niet leeg zijn bij tekst-vraag', 'message_type' => 'error', 'form' => $post];
    }
    if ($question_type === 'multiple_choice') {
        $option_texts = is_array($post['option_text'] ?? null) ? $post['option_text'] : [];
        $filled_options = array_filter($option_texts, fn($t) => trim((string)$t) !== '');
        if (count($filled_options) < 2) {
            return ['message' => 'MC-vraag vereist minimaal 2 antwoordopties met tekst', 'message_type' => 'error', 'form' => $post];
        }
    }

    $media = resolve_question_media($question_type, $post, $files);

    if ($media['error']) {
        return ['message' => 'Fout: ' . $media['error'], 'message_type' => 'error', 'form' => $post];
    }

    $display_order = next_display_order($week);

    $conn->begin_transaction();

    $new_id = insert_question(
        $week, $question_number, $question, $answer, $category, $question_type, $display_order,
        $media['media_path'], $media['video_source'], $media['video_youtube_id'], $media['video_start'], $media['video_end']
    );

    if ($new_id === false) {
        $conn->rollback();
        return ['message' => 'Fout: ' . $conn->error, 'message_type' => 'error', 'form' => $post];
    }

    if ($question_type === 'multiple_choice' && isset($post['option_text']) && is_array($post['option_text'])) {
        insert_mc_options($new_id, $post['option_text'], $post['option_correct'] ?? [], $files['option_image'] ?? null);
    }

    $conn->commit();

    return ['message' => 'Vraag succesvol toegevoegd', 'message_type' => 'success', 'form' => []];
}
