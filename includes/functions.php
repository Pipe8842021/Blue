<?php

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function formatPrice(float $amount): string {
    return '$' . number_format($amount, 0, ',', '.');
}

function formatDate(string $date): string {
    $months = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
               'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $d = new DateTime($date);
    return $d->format('d') . ' de ' . $months[(int)$d->format('n') - 1] . ' de ' . $d->format('Y');
}

function formatTime(string $time): string {
    return (new DateTime($time))->format('g:i A');
}

function statusLabel(string $status): array {
    return match($status) {
        'pending'   => ['label' => 'Pendiente',  'class' => 'status--pending'],
        'confirmed' => ['label' => 'Confirmada', 'class' => 'status--confirmed'],
        'completed' => ['label' => 'Completada', 'class' => 'status--completed'],
        'cancelled' => ['label' => 'Cancelada',  'class' => 'status--cancelled'],
        default     => ['label' => $status,       'class' => ''],
    };
}

function jsonResponse(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
