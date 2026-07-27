<?php

function getEventsFromDb(mysqli $conn, string $type = 'event', int $limit = 0): array
{
    $type = $conn->real_escape_string($type);
    $sql = "SELECT * FROM events WHERE type = '$type' AND is_active = 1 ORDER BY sort_order ASC";
    if ($limit > 0) {
        $sql .= " LIMIT " . (int) $limit;
    }
    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}
