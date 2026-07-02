<?php
/** Layout do painel administrativo e das páginas públicas. */

function env_banner(): string
{
    if (is_production()) return '';
    return '<div class="env-banner">AMBIENTE DE TESTES (' . e(strtoupper(APP_ENV)) . ') — as cobranças aqui não têm validade</div>';
}

function flashes_html(): string
{
    $html = '';
    foreach (flash_get() as $f) {
        $classe = $f['tipo'] === 'erro' ? 'flash flash-erro' : 'flash flash-ok';
        $html .= '<div class="' . $classe . '" role="alert">' . e($f['msg']) . '</div>';
    }
    return $html;
}

/** Cabeçalho das páginas do painel (com menu lateral). $ativo = arquivo atual. */
function page_header(string $titulo, string $ativo, array $user): void
{
    security_headers();
    $sigla = setting('entidade_sigla', 'LABRE');
    $menu = [
        'dashboard.php'     => 'Início',
        'associados.php'    => 'Associados',
        'cobrancas.php'     => 'Cobranças',
        'situacao.php'      => 'Situação anual',
        'relatorios.php'    => 'Relatórios',
        'importar.php'      => 'Importar planilha',
        'configuracoes.php' => 'Configurações',
        'usuarios.php'      => 'Usuários',
    ];
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($titulo) . ' — ' . e($sigla) . ' Pay</title>';
    echo '<link rel="stylesheet" href="assets/style.css">';
    echo '</head><body class="admin">';
    echo env_banner();
    echo '<div class="layout">';
    echo '<aside class="sidebar"><div class="brand">' . e($sigla) . '<span>Pay</span></div><nav aria-label="Menu principal">';
    foreach ($menu as $arquivo => $rotulo) {
        $cls = $arquivo === $ativo ? 'ativo' : '';
        echo '<a class="' . $cls . '" href="' . e($arquivo) . '">' . e($rotulo) . '</a>';
    }
    echo '</nav><div class="sidebar-rodape"><span>' . e($user['nome']) . '</span><a href="sair.php">Sair</a></div></aside>';
    echo '<main class="conteudo"><h1>' . e($titulo) . '</h1>';
    echo flashes_html();
}

function page_footer(): void
{
    echo '</main></div><script src="assets/app.js"></script></body></html>';
}

/** Cabeçalho das páginas públicas (consulta, comprovante, privacidade). */
function public_header(string $titulo): void
{
    security_headers();
    $sigla = setting('entidade_sigla', 'LABRE');
    $nome = setting('entidade_nome', 'LABRE');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($titulo) . ' — ' . e($sigla) . '</title>';
    echo '<link rel="stylesheet" href="assets/style.css">';
    echo '</head><body class="publica">';
    echo env_banner();
    echo '<main class="publica-caixa">';
    echo '<header class="publica-topo"><div class="brand">' . e($sigla) . '<span>Pay</span></div><p>' . e($nome) . '</p></header>';
    echo flashes_html();
}

function public_footer(): void
{
    echo '<footer class="publica-rodape"><a href="privacidade.php">Política de privacidade</a></footer>';
    echo '</main></body></html>';
}
