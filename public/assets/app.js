// LABRE-Pay — JS leve do painel (sem dependências externas).

// -----------------------------------------------------------------------
// Editor visual dos templates de email (sem bibliotecas externas, CSP-safe)
// -----------------------------------------------------------------------
document.querySelectorAll('textarea.editor-rico').forEach(function (ta) {
  const wrap = document.createElement('div');
  wrap.className = 'rt-wrap';
  ta.parentNode.insertBefore(wrap, ta);

  const bar = document.createElement('div');
  bar.className = 'rt-toolbar';
  const area = document.createElement('div');
  area.className = 'rt-area';
  area.contentEditable = 'true';
  area.innerHTML = ta.value;

  wrap.appendChild(bar);
  wrap.appendChild(area);
  wrap.appendChild(ta);
  ta.classList.add('rt-html');
  ta.style.display = 'none';

  let modoHtml = false;
  function sync() { if (!modoHtml) ta.value = area.innerHTML; }
  area.addEventListener('input', sync);

  function exec(cmd, valor) {
    area.focus();
    document.execCommand(cmd, false, valor || null);
    sync();
  }

  function botao(rotulo, titulo, aoClicar, estilo) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'rt-btn';
    b.title = titulo;
    b.innerHTML = rotulo;
    if (estilo) b.style.cssText = estilo;
    b.addEventListener('mousedown', ev => ev.preventDefault()); // não perder a seleção
    b.addEventListener('click', aoClicar);
    bar.appendChild(b);
    return b;
  }

  function separador() {
    const s = document.createElement('span');
    s.className = 'rt-sep';
    bar.appendChild(s);
  }

  botao('<strong>B</strong>', 'Negrito', () => exec('bold'));
  botao('<em>I</em>', 'Itálico', () => exec('italic'));
  botao('<u>U</u>', 'Sublinhado', () => exec('underline'));
  separador();

  const tamanho = document.createElement('select');
  tamanho.className = 'rt-select';
  tamanho.title = 'Tamanho da letra';
  [['', 'Tamanho…'], ['2', 'Pequena'], ['3', 'Normal'], ['5', 'Grande'], ['6', 'Muito grande']]
    .forEach(([v, r]) => tamanho.add(new Option(r, v)));
  tamanho.addEventListener('mousedown', ev => ev.stopPropagation());
  tamanho.addEventListener('change', () => {
    if (tamanho.value) exec('fontSize', tamanho.value);
    tamanho.value = '';
  });
  bar.appendChild(tamanho);
  separador();

  botao('&bull; Lista', 'Lista com marcadores', () => exec('insertUnorderedList'));
  botao('1. Lista', 'Lista numerada', () => exec('insertOrderedList'));
  separador();
  botao('&#128279;', 'Inserir link', () => {
    const url = window.prompt('Endereço do link (https://...):', 'https://');
    if (url && url !== 'https://') exec('createLink', url);
  });
  botao('T&#10005;', 'Limpar formatação do trecho selecionado', () => exec('removeFormat'));
  separador();

  const campos = document.createElement('select');
  campos.className = 'rt-select';
  campos.title = 'Inserir um campo automático no texto';
  [['', 'Inserir campo…'],
   ['{{nome}}', 'Nome do associado'], ['{{indicativo}}', 'Indicativo'],
   ['{{ano}}', 'Ano da anuidade'], ['{{descricao}}', 'Descrição da cobrança'], ['{{valor}}', 'Valor atual'],
   ['{{valor_original}}', 'Valor original'], ['{{vencimento}}', 'Vencimento'],
   ['{{entidade}}', 'Nome da entidade'], ['{{sigla}}', 'Sigla'],
   ['{{botao_pagar}}', 'BOTÃO Pagar agora'], ['{{link_pagamento}}', 'Link de pagamento (texto)'],
   ['{{link_comprovante}}', 'Link do comprovante']]
    .forEach(([v, r]) => campos.add(new Option(r, v)));
  campos.addEventListener('change', () => {
    if (campos.value) exec('insertText', campos.value);
    campos.value = '';
  });
  bar.appendChild(campos);
  separador();

  const btnHtml = botao('&lt;/&gt;', 'Alternar edição em HTML (avançado)', () => {
    modoHtml = !modoHtml;
    if (modoHtml) {
      ta.value = area.innerHTML;
      area.style.display = 'none';
      ta.style.display = 'block';
      bar.querySelectorAll('.rt-btn, .rt-select').forEach(el => { if (el !== btnHtml) el.disabled = true; });
      btnHtml.classList.add('ativo');
    } else {
      area.innerHTML = ta.value;
      ta.style.display = 'none';
      area.style.display = 'block';
      bar.querySelectorAll('.rt-btn, .rt-select').forEach(el => { el.disabled = false; });
      btnHtml.classList.remove('ativo');
    }
  });
});

// Garante que o HTML mais recente vai no envio do formulário
document.addEventListener('submit', function () {
  document.querySelectorAll('.rt-wrap').forEach(function (w) {
    const ta = w.querySelector('textarea.editor-rico');
    const area = w.querySelector('.rt-area');
    if (ta && area && ta.style.display === 'none') ta.value = area.innerHTML;
  });
}, true);

// Botões de impressão (CSP bloqueia onclick inline; o comportamento fica aqui)
document.addEventListener('click', function (ev) {
  if (ev.target.closest('.js-imprimir')) {
    window.print();
  }
});

// Confirmação explícita para ações destrutivas
document.addEventListener('submit', function (ev) {
  const form = ev.target;
  if (form.dataset.confirmar) {
    if (!window.confirm(form.dataset.confirmar)) {
      ev.preventDefault();
    }
  }
});

// Geração de cobranças em lote com progresso (blocos via AJAX para não estourar
// o tempo de execução de hospedagem compartilhada)
const loteForm = document.getElementById('form-lote');
if (loteForm) {
  loteForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    const botao = loteForm.querySelector('button[type=submit]');
    const barra = document.getElementById('lote-barra');
    const statusEl = document.getElementById('lote-status');
    botao.disabled = true;
    barra.closest('.progresso-envolve').style.display = 'block';
    statusEl.textContent = 'Preparando...';

    const dados = new FormData(loteForm);
    let feitos = 0, mensagens = [];
    try {
      for (;;) {
        const resp = await fetch('cobrancas.php?acao=gerar_lote', { method: 'POST', body: dados });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const r = await resp.json();
        if (r.erro) throw new Error(r.erro);
        feitos += r.processados;
        mensagens = mensagens.concat(r.mensagens || []);
        const total = feitos + r.restantes;
        const pct = total > 0 ? Math.round(100 * feitos / total) : 100;
        barra.style.width = pct + '%';
        statusEl.textContent = feitos + ' de ' + total + ' cobranças processadas...';
        if (r.terminou || r.processados === 0) break;
      }
      statusEl.textContent = 'Concluído! ' + feitos + ' cobrança(s) gerada(s).';
      const painel = document.getElementById('lote-mensagens');
      if (painel && mensagens.length) {
        painel.innerHTML = mensagens.map(m => '<li>' + m.replace(/</g, '&lt;') + '</li>').join('');
        painel.parentElement.style.display = 'block';
      }
      setTimeout(() => window.location.reload(), 2500);
    } catch (e) {
      statusEl.textContent = 'Erro: ' + e.message;
      botao.disabled = false;
    }
  });
}
