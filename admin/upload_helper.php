<?php

const UPLOAD_TYPE_PHOTO  = 'photo';
const UPLOAD_TYPE_AUDIO  = 'audio';
const UPLOAD_TYPE_VIDEO  = 'video';
const UPLOAD_TYPE_OPTION = 'option';

/**
 * Geeft de upload-configuratie terug voor een bepaald bestandstype.
 *
 * @param string $type UPLOAD_TYPE_* constante
 * @return array{dir: string, mimes: array<string, string>, max_size: int}|null Configuratie, of null bij onbekend type
 */
function upload_config(string $type): ?array
{
    return match ($type) {
        UPLOAD_TYPE_PHOTO  => [
            'dir'      => 'photos',
            'mimes'    => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'],
            'max_size' => 5 * 1024 * 1024,
        ],
        UPLOAD_TYPE_AUDIO  => [
            'dir'      => 'audio',
            'mimes'    => ['audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg'],
            'max_size' => 20 * 1024 * 1024,
        ],
        UPLOAD_TYPE_VIDEO  => [
            'dir'      => 'videos',
            'mimes'    => ['video/mp4' => 'mp4', 'video/webm' => 'webm'],
            'max_size' => 100 * 1024 * 1024,
        ],
        UPLOAD_TYPE_OPTION => [
            'dir'      => 'options',
            'mimes'    => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'],
            'max_size' => 3 * 1024 * 1024,
        ],
        default => null,
    };
}

/**
 * Verwerkt een geüpload bestand: valideert, controleert het MIME-type en slaat op in de juiste map.
 *
 * @param array|null $file Entry uit $_FILES (of sub-array bij array-inputs), of null als er niets geüpload is
 * @param string     $type UPLOAD_TYPE_* constante
 * @return array{ok: bool, path: string|null, error: string|null}
 *               path is relatief vanaf de project-root (bijv. "uploads/photos/q_x.jpg")
 */
function handle_upload(?array $file, string $type): array
{
    $config = upload_config($type);
    if (!$config) {
        return ['ok' => false, 'path' => null, 'error' => 'Onbekend upload type'];
    }

    if ($file === null || !isset($file['error'])) {
        return ['ok' => false, 'path' => null, 'error' => 'Geen bestand ontvangen'];
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'path' => null, 'error' => 'Geen bestand geselecteerd'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $php_limit  = ini_get('upload_max_filesize');
        $post_limit = ini_get('post_max_size');
        $messages   = [
            UPLOAD_ERR_INI_SIZE   => 'Bestand te groot voor PHP (upload_max_filesize = ' . $php_limit . '). Verhoog upload_max_filesize/post_max_size in php.ini of .htaccess.',
            UPLOAD_ERR_FORM_SIZE  => 'Bestand te groot voor formulier (MAX_FILE_SIZE).',
            UPLOAD_ERR_PARTIAL    => 'Upload onderbroken — probeer opnieuw.',
            UPLOAD_ERR_NO_TMP_DIR => 'Geen tijdelijke upload-map beschikbaar op de server.',
            UPLOAD_ERR_CANT_WRITE => 'Server kon bestand niet wegschrijven (rechten?).',
            UPLOAD_ERR_EXTENSION  => 'Upload geblokkeerd door een PHP-extensie.',
        ];
        $msg = $messages[$file['error']] ?? ('Upload fout (code ' . $file['error'] . ')');
        if ($file['error'] === UPLOAD_ERR_INI_SIZE) {
            $msg .= ' Huidige post_max_size = ' . $post_limit . '.';
        }
        return ['ok' => false, 'path' => null, 'error' => $msg];
    }

    if ($file['size'] > $config['max_size']) {
        $max_mb = round($config['max_size'] / (1024 * 1024), 1);
        return ['ok' => false, 'path' => null, 'error' => 'Bestand te groot (max ' . $max_mb . ' MB)'];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'path' => null, 'error' => 'Ongeldige upload'];
    }

    // MIME via finfo (niet vertrouwen op $file['type'])
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($config['mimes'][$mime])) {
        return ['ok' => false, 'path' => null, 'error' => 'Bestandstype niet toegestaan (' . htmlspecialchars($mime) . ')'];
    }

    $extension  = $config['mimes'][$mime];
    // uniqid('', true) bevat een punt — vervangen door underscore
    $filename    = str_replace('.', '_', uniqid('q_', true)) . '.' . $extension;
    $target_dir  = __DIR__ . '/../uploads/' . $config['dir'] . '/';

    if (!is_dir($target_dir)) {
        @mkdir($target_dir, 0755, true);
    }

    $target_path = $target_dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        return ['ok' => false, 'path' => null, 'error' => 'Kon bestand niet opslaan'];
    }

    return [
        'ok'    => true,
        'path'  => 'uploads/' . $config['dir'] . '/' . $filename,
        'error' => null,
    ];
}

/**
 * Extraheert het YouTube video-ID uit een URL of een los ID.
 *
 * Accepteert standaard YouTube-URL-varianten (watch, youtu.be, embed, shorts)
 * en een losse 11-tekens ID bestaande uit [a-zA-Z0-9_-].
 *
 * @param string $input URL of ID om te verwerken
 * @return string|null 11-tekens video-ID, of null als er geen geldig ID gevonden is
 */
function extract_youtube_id(string $input): ?string
{
    $input = trim($input);
    if ($input === '') return null;

    $patterns = [
        '~(?:youtube\.com/watch\?(?:.*&)?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/v/|youtube\.com/shorts/)([a-zA-Z0-9_-]{11})~',
        '~^([a-zA-Z0-9_-]{11})$~',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input, $m)) {
            return $m[1];
        }
    }
    return null;
}

/**
 * Verwijdert een geüpload bestand veilig van schijf.
 *
 * Controleert via realpath() dat het doelbestand binnen de uploads/-map
 * valt, zodat path-traversal aanvallen worden voorkomen.
 *
 * @param string|null $relative_path Relatief pad vanaf de project-root (bijv. "uploads/photos/q_x.jpg"), of null
 * @return void
 */
function delete_upload(?string $relative_path): void
{
    if (!$relative_path) return;
    $base   = realpath(__DIR__ . '/../uploads');
    $target = realpath(__DIR__ . '/../' . $relative_path);
    if ($base && $target && str_starts_with($target, $base) && is_file($target)) {
        @unlink($target);
    }
}
