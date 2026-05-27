<?php

/**
 * Laadt één vraag op basis van ID.
 *
 * @param int $id Vraag-ID
 * @return array|null Vraagrij als associatief array, of null als niet gevonden
 */
function get_question(int $id): ?array
{
    global $conn;

    $stmt = $conn->prepare("SELECT * FROM questions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Laadt de antwoord-opties van een multiple choice vraag, gesorteerd op weergavevolgorde.
 *
 * @param int $question_id ID van de bijbehorende vraag
 * @return array[] Lijst van optierijen als associatief array
 */
function get_question_options(int $question_id): array
{
    global $conn;

    $stmt = $conn->prepare("SELECT * FROM answer_options WHERE question_id = ? ORDER BY display_order ASC, id ASC");
    $stmt->bind_param("i", $question_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Verwerkt de media-vervanging voor een bestaande vraag bij bewerken.
 *
 * Houdt rekening met wisselen van video-bron (upload ↔ youtube) en het optioneel
 * vervangen of verwijderen van bestaande bestanden. Niet-media types (text,
 * multiple_choice) geven alle media-velden ongewijzigd terug.
 *
 * @param array $current Huidige vraagrij uit de database
 * @param array $post    $_POST data van het bewerkformulier
 * @param array $files   $_FILES data van het bewerkformulier
 * @return array {
 *     error: string|null,
 *     media_path: string|null,
 *     video_source: string|null,
 *     video_youtube_id: string|null,
 *     video_start: int|null,
 *     video_end: int|null
 * }
 */
function resolve_edit_media(array $current, array $post, array $files): array
{
    $type             = $current['question_type'];
    $media_path       = $current['media_path'];
    $video_source     = $current['video_source'];
    $video_youtube_id = $current['video_youtube_id'];
    $video_start      = isset($post['video_start']) && $post['video_start'] !== '' ? intval($post['video_start']) : null;
    $video_end        = isset($post['video_end'])   && $post['video_end']   !== '' ? intval($post['video_end'])   : null;
    $error            = null;

    if ($type === 'photo') {
        if (!empty($files['photo_file']['name'])) {
            $res = handle_upload($files['photo_file'], UPLOAD_TYPE_PHOTO);
            if (!$res['ok']) {
                $error = $res['error'];
            } else {
                if ($current['media_path']) delete_upload($current['media_path']);
                $media_path = $res['path'];
            }
        }

    } elseif ($type === 'audio') {
        if (!empty($files['audio_file']['name'])) {
            $res = handle_upload($files['audio_file'], UPLOAD_TYPE_AUDIO);
            if (!$res['ok']) {
                $error = $res['error'];
            } else {
                if ($current['media_path']) delete_upload($current['media_path']);
                $media_path = $res['path'];
            }
        }

    } elseif ($type === 'video') {
        $new_source = ($post['video_source'] ?? $video_source) === 'youtube' ? 'youtube' : 'upload';

        if ($new_source === 'youtube') {
            $yt_id = extract_youtube_id($post['video_youtube_url'] ?? '');
            if (!$yt_id) {
                $error = 'Ongeldige YouTube URL of ID';
            } else {
                if ($video_source === 'upload' && $media_path) {
                    delete_upload($media_path);
                    $media_path = null;
                }
                $video_source     = 'youtube';
                $video_youtube_id = $yt_id;
            }
        } else {
            if (!empty($files['video_file']['name'])) {
                $res = handle_upload($files['video_file'], UPLOAD_TYPE_VIDEO);
                if (!$res['ok']) {
                    $error = $res['error'];
                } else {
                    if ($video_source === 'upload' && $current['media_path']) {
                        delete_upload($current['media_path']);
                    }
                    $media_path       = $res['path'];
                    $video_source     = 'upload';
                    $video_youtube_id = null;
                }
            } elseif ($video_source !== 'upload') {
                $error = 'Selecteer een videobestand om naar upload-modus te wisselen';
            }
        }
    }

    return compact('error', 'media_path', 'video_source', 'video_youtube_id', 'video_start', 'video_end');
}

/**
 * Synchroniseert de multiple choice opties van een vraag met de ingediende POST-data.
 *
 * Bestaande opties worden bijgewerkt of verwijderd; nieuwe worden ingevoegd.
 * Lege opties (geen tekst én geen afbeelding) worden genegeerd of verwijderd.
 * Opties die niet langer in de POST staan worden uit de database verwijderd.
 *
 * @param int   $question_id ID van de bijbehorende vraag
 * @param array $post        $_POST data van het bewerkformulier
 * @param array $files       $_FILES data van het bewerkformulier
 * @return void
 */
function sync_mc_options(int $question_id, array $post, array $files): void
{
    global $conn;

    $existing_options = get_question_options($question_id);
    $existing_by_id   = [];
    foreach ($existing_options as $o) {
        $existing_by_id[intval($o['id'])] = $o;
    }

    $posted_ids          = $post['option_id']           ?? [];
    $posted_texts        = $post['option_text']         ?? [];
    $posted_correct      = $post['option_correct']      ?? [];
    $posted_delete_image = $post['option_delete_image'] ?? [];
    $option_files        = $files['option_image']       ?? null;

    $kept_ids    = [];
    $update_stmt = $conn->prepare("UPDATE answer_options SET option_text = ?, option_image_path = ?, is_correct = ?, display_order = ? WHERE id = ?");
    $insert_stmt = $conn->prepare("INSERT INTO answer_options (question_id, option_text, option_image_path, is_correct, display_order) VALUES (?, ?, ?, ?, ?)");
    $delete_stmt = $conn->prepare("DELETE FROM answer_options WHERE id = ?");

    foreach ($posted_texts as $idx => $text) {
        $text        = trim($text);
        $existing_id = isset($posted_ids[$idx]) ? intval($posted_ids[$idx]) : 0;
        $is_correct  = isset($posted_correct[$idx]) ? 1 : 0;
        $delete_img  = isset($posted_delete_image[$idx]);
        $order       = $idx + 1;

        $image_path      = null;
        $existing_option = $existing_id > 0 ? ($existing_by_id[$existing_id] ?? null) : null;
        if ($existing_option) {
            $image_path = $existing_option['option_image_path'];
        }

        $new_image_uploaded = false;
        if ($option_files && isset($option_files['error'][$idx]) && $option_files['error'][$idx] !== UPLOAD_ERR_NO_FILE) {
            $file_arr = [
                'name'     => $option_files['name'][$idx],
                'type'     => $option_files['type'][$idx],
                'tmp_name' => $option_files['tmp_name'][$idx],
                'error'    => $option_files['error'][$idx],
                'size'     => $option_files['size'][$idx],
            ];
            $up = handle_upload($file_arr, UPLOAD_TYPE_OPTION);
            if ($up['ok']) {
                if ($image_path) delete_upload($image_path);
                $image_path         = $up['path'];
                $new_image_uploaded = true;
            }
        }

        if (!$new_image_uploaded && $delete_img && $image_path) {
            delete_upload($image_path);
            $image_path = null;
        }

        if ($text === '' && !$image_path) {
            if ($existing_id > 0) {
                $delete_stmt->bind_param("i", $existing_id);
                $delete_stmt->execute();
            }
            continue;
        }

        if ($existing_id > 0 && isset($existing_by_id[$existing_id])) {
            $update_stmt->bind_param("ssiii", $text, $image_path, $is_correct, $order, $existing_id);
            $update_stmt->execute();
            $kept_ids[] = $existing_id;
        } else {
            $insert_stmt->bind_param("issii", $question_id, $text, $image_path, $is_correct, $order);
            $insert_stmt->execute();
        }
    }

    foreach ($existing_by_id as $id => $opt) {
        if (!in_array($id, $kept_ids, true)) {
            if ($opt['option_image_path']) delete_upload($opt['option_image_path']);
            $delete_stmt->bind_param("i", $id);
            $delete_stmt->execute();
        }
    }
}

/**
 * Verwerkt de volledige "update" actie voor een bestaande vraag (vanuit $_POST + $_FILES).
 *
 * Voert achtereenvolgens uit: invoervalidatie, media-vervanging, database-update
 * en (indien van toepassing) het synchroniseren van multiple choice opties.
 *
 * @param int   $question_id      ID van de te bewerken vraag
 * @param array $current_question Huidige vraagrij uit de database (voor media-vergelijking)
 * @param array $post             $_POST data van het bewerkformulier
 * @param array $files            $_FILES data van het bewerkformulier
 * @return array {
 *     message: string,      Gebruikersfeedback (succes of foutmelding)
 *     message_type: string  'success' | 'error'
 * }
 */
function handle_update_question(int $question_id, array $current_question, array $post, array $files): array
{
    global $conn;

    $week            = intval($post['week']);
    $question_number = intval($post['question_number']);
    $question_text   = trim($post['question'] ?? '');
    $category        = trim($post['category']  ?? 'Algemeen');
    $answer          = trim($post['answer']    ?? '');
    $type            = $current_question['question_type'];

    if ($category === '') {
        $category = 'Algemeen';
    }
    if ($week < 1) {
        return ['message' => 'Weeknummer moet minimaal 1 zijn', 'message_type' => 'error'];
    }
    if ($question_number < 1) {
        return ['message' => 'Vraagnummer moet minimaal 1 zijn', 'message_type' => 'error'];
    }
    if ($question_text === '') {
        return ['message' => 'Vraag mag niet leeg zijn', 'message_type' => 'error'];
    }
    if ($type === 'text' && $answer === '') {
        return ['message' => 'Antwoord mag niet leeg zijn bij tekst-vraag', 'message_type' => 'error'];
    }
    if ($type === 'multiple_choice') {
        $option_texts = is_array($post['option_text'] ?? null) ? $post['option_text'] : [];
        $filled_options = array_filter($option_texts, fn($t) => trim((string)$t) !== '');
        if (count($filled_options) < 2) {
            return ['message' => 'MC-vraag vereist minimaal 2 antwoordopties met tekst', 'message_type' => 'error'];
        }
    }

    $media = resolve_edit_media($current_question, $post, $files);

    if ($media['error']) {
        return ['message' => 'Fout: ' . $media['error'], 'message_type' => 'error'];
    }

    $stmt = $conn->prepare("
        UPDATE questions
        SET week = ?, question_number = ?, question = ?, answer = ?, category = ?,
            media_path = ?, video_source = ?, video_youtube_id = ?, video_start = ?, video_end = ?
        WHERE id = ?
    ");
    $stmt->bind_param(
        "iissssssiii",
        $week, $question_number, $question_text, $answer, $category,
        $media['media_path'], $media['video_source'], $media['video_youtube_id'],
        $media['video_start'], $media['video_end'],
        $question_id
    );

    $conn->begin_transaction();

    if (!$stmt->execute()) {
        $conn->rollback();
        return ['message' => 'Fout: ' . $conn->error, 'message_type' => 'error'];
    }

    if ($type === 'multiple_choice' && isset($post['option_text']) && is_array($post['option_text'])) {
        sync_mc_options($question_id, $post, $files);
    }

    $conn->commit();

    return ['message' => 'Vraag bijgewerkt', 'message_type' => 'success'];
}
