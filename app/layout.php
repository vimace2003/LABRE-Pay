<?php
/** Layout do painel administrativo e das páginas públicas. */

/** URL de asset com cache-busting pela data do arquivo (evita CSS/JS velho em cache). */
function asset_url(string $arquivo): string
{
    $fisico = dirname((string)$_SERVER['SCRIPT_FILENAME']) . '/assets/' . $arquivo;
    $v = @filemtime($fisico) ?: APP_VERSION;
    return 'assets/' . $arquivo . '?v=' . $v;
}

/** URL da logo personalizada ('' se não houver), com cache-busting. */
function logo_url(): string
{
    $rel = setting('logo_arquivo');
    if ($rel === '' || !preg_match('#^assets/uploads/logo-[\w.]+$#', $rel)) return '';
    $fisico = dirname((string)$_SERVER['SCRIPT_FILENAME']) . '/' . $rel;
    return is_file($fisico) ? $rel . '?v=' . (@filemtime($fisico) ?: 1) : '';
}

/** Ícone do WhatsApp em SVG inline (sem depender de arquivo/CDN). */
function icone_whatsapp(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" role="img" aria-label="WhatsApp">'
        . '<path fill="#25D366" d="M12 2a10 10 0 0 0-8.66 15L2 22l5.16-1.35A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.18-1.15l-.3-.18-3.06.8.82-2.98-.2-.31A8.2 8.2 0 1 1 12 20.2z"/>'
        . '<path fill="#25D366" d="M16.56 13.99c-.25-.12-1.47-.72-1.7-.8-.22-.09-.39-.13-.55.12-.17.25-.64.8-.78.97-.14.16-.29.18-.54.06a6.7 6.7 0 0 1-1.97-1.21 7.4 7.4 0 0 1-1.36-1.7c-.14-.24-.02-.37.11-.5.11-.11.25-.29.37-.43.12-.15.16-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.55-1.34-.76-1.83-.2-.48-.4-.42-.55-.42h-.47c-.16 0-.43.06-.66.3-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.57.12.16 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.6.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.1-.23-.16-.48-.28z"/></svg>';
}

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
        'configuracoes.php' => 'Configurações',
        'usuarios.php'      => 'Usuários',
    ];
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($titulo) . ' — ' . e($sigla) . ' Pay</title>';
    echo '<link rel="stylesheet" href="' . e(asset_url('style.css')) . '">';
    echo '</head><body class="admin tema-' . e(tema_atual()) . '">';
    echo env_banner();
    echo '<div class="layout">';
    $logo = logo_url();
    echo '<aside class="sidebar">';
    if ($logo) echo '<img class="logo-sidebar" src="' . e($logo) . '" alt="Logo ' . e($sigla) . '">';
    echo '<div class="brand">' . e($sigla) . '<span>Pay</span></div><nav aria-label="Menu principal">';
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
    echo '</main></div><script src="' . e(asset_url('app.js')) . '"></script></body></html>';
}

/**
 * Página de relatório imprimível (sem menu): cabeçalho da entidade, título,
 * subtítulo com os filtros aplicados e data de emissão.
 */
function report_header(string $titulo, string $subtitulo, string $voltarUrl): void
{
    security_headers();
    $cnpj = setting('entidade_cnpj');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($titulo) . ' — ' . e(setting('entidade_sigla', 'LABRE')) . '</title>';
    echo '<link rel="stylesheet" href="' . e(asset_url('style.css')) . '">';
    echo '</head><body class="relatorio tema-' . e(tema_atual()) . '">';
    echo env_banner();
    echo '<div class="rel-pagina">';
    echo '<p class="nao-imprimir rel-acoes">';
    echo '<button type="button" class="botao botao-primario js-imprimir">Imprimir / salvar PDF</button> ';
    echo '<a class="botao" href="' . e($voltarUrl) . '">← Voltar</a></p>';
    $logo = logo_url();
    echo '<header class="rel-topo">';
    echo '<div class="rel-identidade">';
    if ($logo) echo '<img class="logo-relatorio" src="' . e($logo) . '" alt="">';
    echo '<div><div class="rel-entidade">' . e(setting('entidade_nome')) . '</div>';
    echo '<div class="rel-mini">' . e(setting('entidade_sigla')) . ($cnpj ? ' — CNPJ ' . e($cnpj) : '') . '</div></div></div>';
    echo '<div class="rel-mini">Emitido em ' . e(date('d/m/Y H:i')) . '</div>';
    echo '</header>';
    echo '<h1>' . e($titulo) . '</h1>';
    if ($subtitulo !== '') echo '<p class="rel-sub">' . e($subtitulo) . '</p>';
}

function report_footer(): void
{
    echo '<p class="rel-rodape">Gerado pelo sistema de anuidades ' . e(setting('entidade_sigla')) . ' — LABRE-Pay v' . e(APP_VERSION) . '</p>';
    echo '</div><script src="' . e(asset_url('app.js')) . '"></script></body></html>';
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
    echo '<link rel="stylesheet" href="' . e(asset_url('style.css')) . '">';
    echo '</head><body class="publica tema-' . e(tema_atual()) . '">';
    echo env_banner();
    echo '<main class="publica-caixa">';
    $logo = logo_url();
    echo '<header class="publica-topo">';
    if ($logo) echo '<img class="logo-publica" src="' . e($logo) . '" alt="Logo ' . e($sigla) . '">';
    echo '<div class="brand">' . e($sigla) . '<span>Pay</span></div><p>' . e($nome) . '</p></header>';
    echo flashes_html();
}

function public_footer(): void
{
    echo '<footer class="publica-rodape"><a href="privacidade.php">Política de privacidade</a></footer>';
    echo '</main><script src="' . e(asset_url('app.js')) . '"></script></body></html>';
}
