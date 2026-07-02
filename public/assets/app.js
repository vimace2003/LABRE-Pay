// LABRE-Pay — JS leve do painel (sem dependências externas).

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
