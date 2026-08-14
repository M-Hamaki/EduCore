<?php

function paginationState(int $total, int $perPage, string $parameter = 'page'): array
{
    $perPage = max(1, min(200, $perPage));
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($pages, (int)($_GET[$parameter] ?? 1)));
    return ['page' => $page, 'pages' => $pages, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage, 'parameter' => $parameter];
}

function renderPagination(array $state): void
{
    if (($state['pages'] ?? 1) <= 1) {
        return;
    }
    $current = (int)$state['page'];
    $pages = (int)$state['pages'];
    $parameter = (string)$state['parameter'];
    echo '<nav class="mt-3" aria-label="التنقل بين الصفحات"><ul class="pagination pagination-sm justify-content-center">';
    for ($page = max(1, $current - 2); $page <= min($pages, $current + 2); $page++) {
        $query = $_GET;
        $query[$parameter] = $page;
        $active = $page === $current ? ' active' : '';
        printf('<li class="page-item%s"><a class="page-link" href="?%s">%d</a></li>', $active, htmlspecialchars(http_build_query($query)), $page);
    }
    echo '</ul></nav>';
}
