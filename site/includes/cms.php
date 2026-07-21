<?php
require_once(__DIR__ . '/cms_defaults.php');

function cmsEnsureTables($conn)
{
    $sql = "CREATE TABLE IF NOT EXISTS cms_pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(100) NOT NULL UNIQUE,
        page_title VARCHAR(255) NOT NULL,
        data_json LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql);
}

function cmsMergeDefaults(array $defaults, array $current)
{
    $merged = $defaults;
    foreach ($current as $key => $value) {
        if (is_array($value) && isset($merged[$key]) && is_array($merged[$key]) && cmsIsAssoc($value) && cmsIsAssoc($merged[$key])) {
            $merged[$key] = cmsMergeDefaults($merged[$key], $value);
        } else {
            $merged[$key] = $value;
        }
    }
    return $merged;
}

function cmsIsAssoc(array $arr)
{
    return array_keys($arr) !== range(0, count($arr) - 1);
}

function cmsAllPages()
{
    return getCmsPageDefaults();
}

function cmsGetPage($conn, $slug)
{
    cmsEnsureTables($conn);
    $all = cmsAllPages();
    if (!isset($all[$slug])) {
        return null;
    }

    $defaults = $all[$slug];
    $stmt = $conn->prepare("SELECT id, page_title, data_json FROM cms_pages WHERE slug = ? LIMIT 1");
    $stmt->bind_param("s", $slug);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $json = json_encode($defaults['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $insert = $conn->prepare("INSERT INTO cms_pages (slug, page_title, data_json) VALUES (?, ?, ?)");
        $insert->bind_param("sss", $slug, $defaults['title'], $json);
        $insert->execute();
        $insert->close();
        return [
            'slug' => $slug,
            'label' => $defaults['label'],
            'title' => $defaults['title'],
            'data' => $defaults['data'],
        ];
    }

    $dbData = json_decode($row['data_json'], true);
    if (!is_array($dbData)) {
        $dbData = [];
    }

    $mergedData = cmsMergeDefaults($defaults['data'], $dbData);
    $dbTitle = trim((string)$row['page_title']);
    $finalTitle = $dbTitle !== '' ? $dbTitle : $defaults['title'];

    if ($mergedData !== $dbData || $dbTitle === '') {
        $json = json_encode($mergedData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $update = $conn->prepare("UPDATE cms_pages SET page_title = ?, data_json = ? WHERE slug = ?");
        $update->bind_param("sss", $finalTitle, $json, $slug);
        $update->execute();
        $update->close();
    }

    return [
        'slug' => $slug,
        'label' => $defaults['label'],
        'title' => $finalTitle,
        'data' => $mergedData,
    ];
}

function cmsSavePage($conn, $slug, $pageTitle, array $data)
{
    cmsEnsureTables($conn);
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $stmt = $conn->prepare("INSERT INTO cms_pages (slug, page_title, data_json) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE page_title = VALUES(page_title), data_json = VALUES(data_json)");
    $stmt->bind_param("sss", $slug, $pageTitle, $json);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
