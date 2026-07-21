<?php

declare(strict_types=1);

namespace core;

use PDO;

abstract class Controller
{
    public function __construct(protected PDO $pdo)
    {
    }

    protected function ok(array $data = [], string $message = 'Success'): void
    {
        Response::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    protected function fail(string $message, int $status = 400): void
    {
        Response::json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    protected function pagination(): array
    {
        $page = max(1, Request::intQuery('page', 1));
        $limit = Request::intQuery('limit', 20);
        $limit = max(1, min($limit, 100));
        $offset = ($page - 1) * $limit;

        return [$page, $limit, $offset];
    }

    protected function listResponse(array $rows, int $total, int $page, int $limit, string $label = 'items'): void
    {
        $this->ok([
            $label => $rows,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => (int) ceil(max($total, 1) / $limit),
            ],
        ]);
    }
}
