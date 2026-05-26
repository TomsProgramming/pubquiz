<?php
include 'database_connect.php';

// Get all teams sorted by score
$result = $conn->query("SELECT * FROM teams ORDER BY score DESC");

// Create CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="leaderboard_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// Add UTF-8 BOM for proper Excel support
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write header
fputcsv($output, array('Plaats', 'Teamnaam', 'Score'));

// Write data
$rank = 1;
while ($row = $result->fetch_assoc()) {
    fputcsv($output, array($rank, $row['name'], $row['score']));
    $rank++;
}

fclose($output);
exit;
?>
