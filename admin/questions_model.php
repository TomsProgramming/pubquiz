<?php
/** 
 * Berekent de volgende display order voor een bepaalde week
 *
 * @param int $week De week waarvoor de display order moet worden berekend
 * @return int De volgende display order
 */
function next_display_order($week) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order 
        FROM questions 
        WHERE week = ?
    ");

    $stmt->bind_param("i", $week);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    return (int) $row['next_order'];
}

/**
 * Berekent het volgende vraagnummer voor een bepaalde week (uniek binnen week)
 * 
 * @param int $week De week waarvoor het volgende vraagnummer moet worden berekend
 * @return int Het volgende vraagnummer
 */
function next_question_number($week) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT COALESCE(MAX(question_number), 0) + 1 AS next_num 
        FROM questions 
        WHERE week = ?
    ");

    $stmt->bind_param("i", $week);
    $stmt->execute();
    
    $row = $stmt->get_result()->fetch_assoc();
    
    return (int) $row['next_num'];
}
?>