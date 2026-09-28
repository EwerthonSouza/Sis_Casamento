<?php
/*
 * SÓ NO AMBIENTE LOCAL — usado pelo "recarregar sozinho" injetado por
 * docker/dev-session.php. Devolve a data da última alteração em qualquer
 * arquivo de código do projeto (.php/.css/.js/.svg/imagens), pra
 * pré-visualização saber quando recarregar. Fora do localhost não responde.
 */
if (!preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $_SERVER['HTTP_HOST'] ?? '')) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

$raiz = dirname(__DIR__);
$ignorar = ['uploads', 'vendor', '.git', 'node_modules', 'docker'];
$ultima = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
        function ($arquivo, $chave, $iterador) use ($ignorar) {
            if ($iterador->hasChildren()) {
                return !in_array($arquivo->getFilename(), $ignorar, true);
            }
            return (bool)preg_match('/\.(php|css|js|svg|png|jpg|jpeg|webp|json)$/i', $arquivo->getFilename());
        }
    )
);
foreach ($it as $arquivo) {
    $m = $arquivo->getMTime();
    if ($m > $ultima) { $ultima = $m; }
}

echo json_encode(['v' => $ultima]);
