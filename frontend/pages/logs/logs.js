/**
 * logs.js
 *
 * Responsabilidades:
 *  1. Listar serviços concluídos com filtros de busca, funcionário e período
 *  2. Atualizar stat cards com totais da página atual
 *  3. Paginar resultados
 */

let pagina_atual  = 1;
let total_paginas = 1;
let total_global  = 0;
let timeout_busca = null;

/* ── Badges de tipo e nível (mesmo padrão de cores do bdg/b-* global) ── */
const tipo_para_badge = {
  preventiva: 'b-green',
  corretiva:  'b-amber',
  revisao:    'b-blue',
  revisão:    'b-blue',
};

const nivel_para_estilo = {
  gerente:  { av: 'av-gerente',  pb: 'pb-gerente',  bdg: 'b-red',  label: 'Gerente' },
  recepcao: { av: 'av-recepcao', pb: 'pb-recepcao', bdg: 'b-cyan', label: 'Recepcionista' },
  mecanico: { av: 'av-mecanico', pb: 'pb-mecanico', bdg: 'b-blue', label: 'Mecânico' },
};

/*
 * Escapa caracteres HTML especiais antes de interpolar em innerHTML.
 * Barreira contra XSS armazenado para qualquer dado vindo do banco.
 */
function esc(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/* ═══════════════════════════════════════════════
   USUÁRIO DA SESSÃO
═══════════════════════════════════════════════ */
function init_user_display() {
  const user = window.__session_user || {};
  const estilo = nivel_para_estilo[user.nivel] ?? nivel_para_estilo.mecanico;

  const av = document.getElementById('sbAv');
  av.textContent = user.iniciais || '?';
  av.className   = 'av ' + (user.nivel ? estilo.av : '');

  document.getElementById('sbName').textContent = user.nome || '';

  const role = document.getElementById('sbRole');
  role.textContent = user.nivel ? estilo.label : '';
  role.className   = 'pbadge ' + (user.nivel ? estilo.pb : '');

  const perms = user.permissoes || [];
  document.querySelectorAll('.rnav.r-g').forEach(el => {
    if (!perms.includes('funcionarios.visualizar') && !perms.includes('logs.visualizar')) {
      el.style.display = 'none';
    }
  });
  document.querySelectorAll('.rnav.r-m').forEach(el => {
    if (!perms.includes('estoque.visualizar')) el.style.display = 'none';
  });
  document.querySelectorAll('.rnav.r-c').forEach(el => {
    if (!perms.includes('clientes.gerenciar')) el.style.display = 'none';
  });
}

function inject_csrf_logout() {
  const token = window.__session_user?.csrf_token ?? '';
  const input = document.getElementById('csrfLogout');
  if (input) input.value = token;
}

/* ═══════════════════════════════════════════════
   SIDEBAR / TOAST
═══════════════════════════════════════════════ */
function toggleSidebar() {
  const sb   = document.getElementById('sidebar');
  const ov   = document.getElementById('overlay');
  const open = sb.classList.toggle('open');
  ov.classList.toggle('show', open);
}

function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('show');
}

function toast(msg, type = 'ok') {
  const ico = { ok: 'check-circle-fill', er: 'x-circle-fill' };
  const col = { ok: 'var(--green)', er: 'var(--rose)' };
  const el  = document.createElement('div');
  el.className = `tmsg t-${type}`;

  const icon = document.createElement('i');
  icon.className = `bi bi-${ico[type]}`;
  icon.style.cssText = `color:${col[type]};font-size:18px;flex-shrink:0`;
  icon.setAttribute('aria-hidden', 'true');

  el.appendChild(icon);
  el.appendChild(document.createTextNode(msg));
  document.getElementById('toastC').appendChild(el);

  setTimeout(() => {
    el.style.opacity    = '0';
    el.style.transition = 'opacity .3s';
    setTimeout(() => el.remove(), 300);
  }, 3500);
}

/* ═══════════════════════════════════════════════
   HELPERS DE FORMATAÇÃO
═══════════════════════════════════════════════ */
function fmt_data(iso) {
  if (!iso) return '—';
  const [y, m, d] = iso.split('-');
  return `${d}/${m}/${y}`;
}

function fmt_moeda(valor) {
  if (valor === null || valor === undefined) return null;
  return valor.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

/* ═══════════════════════════════════════════════
   CARREGAMENTO DE DADOS
═══════════════════════════════════════════════ */
async function carregar_funcionarios() {
  try {
    const res = await fetch('/api/logs/funcionarios');
    if (!res.ok) return;
    const lista = await res.json();
    const sel   = document.getElementById('selectFuncionario');
    lista.forEach(f => {
      const opt = document.createElement('option');
      opt.value       = f.id;
      opt.textContent = f.nome;
      sel.appendChild(opt);
    });
  } catch {
    /* select fica só com "Todos os funcionários" */
  }
}

async function carregar_logs(pagina = 1) {
  pagina_atual = pagina;

  const busca       = document.getElementById('inputBusca').value.trim();
  const funcionario = document.getElementById('selectFuncionario').value;
  const data_inicio = document.getElementById('inputDataInicio').value;
  const data_fim    = document.getElementById('inputDataFim').value;

  const params = new URLSearchParams({ pagina });
  if (busca)       params.set('busca', busca);
  if (funcionario) params.set('funcionario', funcionario);
  if (data_inicio) params.set('data_inicio', data_inicio);
  if (data_fim)    params.set('data_fim', data_fim);

  try {
    const res = await fetch('/api/logs?' + params.toString());
    if (!res.ok) throw new Error('Erro ' + res.status);
    const data = await res.json();

    total_paginas = data.total_paginas || 1;
    total_global  = data.total || 0;

    renderizar_tabela(data.registros || []);
    renderizar_stats(data.registros || [], data.total);
    renderizar_paginacao();

  } catch (e) {
    console.error('[carregar_logs]', e);
    toast('Erro ao carregar logs.', 'er');
    document.getElementById('tabelaBody').innerHTML = `
      <tr><td colspan="9">
        <div class="empty">
          <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
          <h4>Erro ao carregar</h4>
          <p>Tente atualizar a página.</p>
        </div>
      </td></tr>`;
    document.getElementById('pagBar').style.display = 'none';
  }
}

/* ═══════════════════════════════════════════════
   RENDERIZAÇÃO
═══════════════════════════════════════════════ */
function renderizar_tabela(registros) {
  const tbody = document.getElementById('tabelaBody');

  if (registros.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="9">
        <div class="empty">
          <i class="bi bi-journal-x" aria-hidden="true"></i>
          <h4>Nenhum serviço encontrado</h4>
          <p>Tente ajustar os filtros.</p>
        </div>
      </td></tr>`;
    return;
  }

  tbody.innerHTML = registros.map(r => {
    const nivel        = r.nivel_de_acesso || '';
    const nivel_estilo  = nivel_para_estilo[nivel];
    const tipo_bdg      = tipo_para_badge[(r.tipo_ordem || '').toLowerCase()] || 'b-steel';
    const orcamento     = fmt_moeda(r.orcamento);
    const mdo           = fmt_moeda(r.mao_de_obra);

    return `
      <tr>
        <td><span class="osn">#${String(r.id_ordem).padStart(4, '0')}</span></td>
        <td style="color:var(--off-white)">${esc(r.nome_funcionario) || '—'}</td>
        <td>${nivel_estilo ? `<span class="bdg ${nivel_estilo.bdg}">${esc(nivel_estilo.label)}</span>` : '—'}</td>
        <td>${esc(r.nome_cliente) || '—'}</td>
        <td><span class="bdg ${tipo_bdg}">${esc(r.tipo_ordem) || '—'}</span></td>
        <td style="color:var(--text-dim)">${fmt_data(r.abertura)}</td>
        <td>${fmt_data(r.fechamento)}</td>
        <td style="font-family:var(--font-mono);font-size:12px;color:${orcamento ? 'var(--green)' : 'var(--text-faint)'}">${orcamento ?? 'não informado'}</td>
        <td style="font-family:var(--font-mono);font-size:12px;color:${mdo ? 'var(--blue)' : 'var(--text-faint)'}">${mdo ?? 'não informado'}</td>
      </tr>`;
  }).join('');
}

function renderizar_stats(registros, total) {
  let receita = 0;
  let mdo     = 0;
  registros.forEach(r => {
    if (r.orcamento)   receita += r.orcamento;
    if (r.mao_de_obra) mdo     += r.mao_de_obra;
  });
  document.getElementById('statTotal').textContent   = total ?? registros.length;
  document.getElementById('statReceita').textContent = receita > 0 ? fmt_moeda(receita) : '—';
  document.getElementById('statMdo').textContent     = mdo > 0 ? fmt_moeda(mdo) : '—';
}

function renderizar_paginacao() {
  const bar  = document.getElementById('pagBar');
  const info = document.getElementById('pagInfo');
  const btns = document.getElementById('pagBtns');

  if (total_paginas <= 1) { bar.style.display = 'none'; return; }
  bar.style.display = '';
  info.textContent  = `Página ${pagina_atual} de ${total_paginas} — ${total_global} registros`;
  btns.innerHTML    = '';

  const criar_botao = (label, pagina, ativo = false, desabilitado = false) => {
    const b = document.createElement('button');
    b.className   = 'pb' + (ativo ? ' active' : '');
    b.textContent = label;
    b.disabled    = desabilitado;
    if (!desabilitado) b.onclick = () => carregar_logs(pagina);
    return b;
  };

  btns.appendChild(criar_botao('‹', pagina_atual - 1, false, pagina_atual === 1));

  const inicio = Math.max(1, pagina_atual - 2);
  const fim    = Math.min(total_paginas, pagina_atual + 2);

  if (inicio > 1) {
    btns.appendChild(criar_botao('1', 1));
    if (inicio > 2) btns.insertAdjacentHTML('beforeend', '<span style="color:var(--text-faint);padding:0 4px">…</span>');
  }
  for (let p = inicio; p <= fim; p++) {
    btns.appendChild(criar_botao(String(p), p, p === pagina_atual));
  }
  if (fim < total_paginas) {
    if (fim < total_paginas - 1) btns.insertAdjacentHTML('beforeend', '<span style="color:var(--text-faint);padding:0 4px">…</span>');
    btns.appendChild(criar_botao(String(total_paginas), total_paginas));
  }

  btns.appendChild(criar_botao('›', pagina_atual + 1, false, pagina_atual === total_paginas));
}

/* ═══════════════════════════════════════════════
   FILTROS
═══════════════════════════════════════════════ */
function limparFiltros() {
  document.getElementById('inputBusca').value        = '';
  document.getElementById('selectFuncionario').value = '';
  document.getElementById('inputDataInicio').value   = '';
  document.getElementById('inputDataFim').value      = '';
  carregar_logs(1);
}

function setup_filtros() {
  document.getElementById('inputBusca').addEventListener('input', () => {
    clearTimeout(timeout_busca);
    timeout_busca = setTimeout(() => carregar_logs(1), 350);
  });
  ['selectFuncionario', 'inputDataInicio', 'inputDataFim'].forEach(id =>
    document.getElementById(id).addEventListener('change', () => carregar_logs(1))
  );
}

/* ═══════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', async () => {
  init_user_display();
  inject_csrf_logout();
  setup_filtros();
  await carregar_funcionarios();
  await carregar_logs(1);
});