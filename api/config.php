<?php

define('DB_HOST', 'db.cqmactitrqydpizpmkuv.supabase.co');
define('DB_PORT', '5432');
define('DB_NAME', 'postgres');
define('DB_USER', 'postgres');
define('DB_PASS', 'Koralgol12!');

define('APP_URL', 'https://velqora.pl');
define('APP_NAME', 'Velqora');
define('APP_ENV', 'production');

function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;

            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

        } catch (PDOException $e) {
            die(json_encode([
                'error' => $e->getMessage()
            ]));
        }
    }

    return $pdo;
}

function respond(array $data, int $code = 200): void {
    http_response_code($code);

    echo json_encode($data, JSON_UNESCAPED_UNICODE);

    exit;
}
?>