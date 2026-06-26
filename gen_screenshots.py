#!/usr/bin/env python3
"""Generate mock admin/painel screenshots for the briefing."""

import base64, os, json, time
from playwright.sync_api import sync_playwright

ADMIN_URL  = "file:///home/user/wenderjmn/admin/index.html"
PAINEL_URL = "file:///home/user/wenderjmn/painel.php"
OUT        = "/home/user/wenderjmn/docs/img"

# ── mock data ────────────────────────────────────────────────────────────────

MOCK_DASHBOARD_STATS = {
    "total": 2847, "hoje": 43, "semana": 312, "mes": 1204,
    "a": 763, "b": 891, "c": 612, "d": 581,
    "wpp_enviados": 1923, "wpp_lidos": 1541, "wpp_pendentes": 47,
    "email_enviados": 2401, "email_abertos": 1189,
}

MOCK_LEADS = [
    {"id":1,"nome":"Ana Paula Ferreira","email":"anapaula.f@gmail.com","whatsapp":"5511991234567","perfil":"A","cidade":"São Paulo","created_at":"2026-05-22 08:14"},
    {"id":2,"nome":"Carla Mendonça","email":"carla.m@hotmail.com","whatsapp":"5521987654321","perfil":"B","cidade":"Rio de Janeiro","created_at":"2026-05-22 09:02"},
    {"id":3,"nome":"Fernanda Oliveira","email":"feroliveira@gmail.com","whatsapp":"5531965432198","perfil":"C","cidade":"Belo Horizonte","created_at":"2026-05-22 09:45"},
    {"id":4,"nome":"Juliana Costa","email":"ju.costa82@yahoo.com","whatsapp":"5541988776655","perfil":"D","cidade":"Curitiba","created_at":"2026-05-22 10:11"},
    {"id":5,"nome":"Mariana Santos","email":"mari.santos@gmail.com","whatsapp":"5551977665544","perfil":"A","cidade":"Porto Alegre","created_at":"2026-05-22 10:38"},
    {"id":6,"nome":"Renata Lima","email":"renata.l@gmail.com","whatsapp":"5561966554433","perfil":"B","cidade":"Brasília","created_at":"2026-05-22 11:03"},
    {"id":7,"nome":"Patricia Souza","email":"pat.souza@outlook.com","whatsapp":"5581955443322","perfil":"C","cidade":"Recife","created_at":"2026-05-22 11:22"},
    {"id":8,"nome":"Luciana Rodrigues","email":"lu.rodri@gmail.com","whatsapp":"5571944332211","perfil":"D","cidade":"Salvador","created_at":"2026-05-22 11:47"},
    {"id":9,"nome":"Camila Nunes","email":"camila.n@gmail.com","whatsapp":"5585933221100","perfil":"A","cidade":"Fortaleza","created_at":"2026-05-22 12:05"},
    {"id":10,"nome":"Beatriz Alves","email":"bia.alves@gmail.com","whatsapp":"5592922110099","perfil":"B","cidade":"Manaus","created_at":"2026-05-22 12:31"},
    {"id":11,"nome":"Vanessa Gomes","email":"vanessa.g@gmail.com","whatsapp":"5511911009988","perfil":"A","cidade":"São Paulo","created_at":"2026-05-22 13:08"},
    {"id":12,"nome":"Tatiana Pereira","email":"tati.p@gmail.com","whatsapp":"5521900998877","perfil":"C","cidade":"Niterói","created_at":"2026-05-22 13:44"},
    {"id":13,"nome":"Daniela Rocha","email":"dani.rocha@icloud.com","whatsapp":"5541899887766","perfil":"D","cidade":"Curitiba","created_at":"2026-05-22 14:12"},
    {"id":14,"nome":"Adriana Monteiro","email":"adri.mont@gmail.com","whatsapp":"5511888776655","perfil":"B","cidade":"Guarulhos","created_at":"2026-05-22 14:55"},
    {"id":15,"nome":"Simone Barbosa","email":"si.barbosa@gmail.com","whatsapp":"5521877665544","perfil":"A","cidade":"Rio de Janeiro","created_at":"2026-05-22 15:23"},
]

MOCK_TEMPLATES = [
    {"id":1,"slug":"boas-vindas","name":"Boas-vindas ao Quiz","subject":"Você descobriu seu Sabotador! 🎯","body":"Olá {{nome}},\n\nVocê acaba de descobrir seu perfil sabotador...\n\n— Ira Soraya & Daniely","active":True,"created_at":"2026-05-20"},
    {"id":2,"slug":"cpls-convite","name":"Convite CPLs (Treinamentos)","subject":"Sua vaga está reservada — Treinamento gratuito 🔥","body":"{{nome}}, os treinamentos começam em 03/06.\n\nSão 3 aulas que vão mudar sua relação com a comida...","active":True,"created_at":"2026-05-21"},
    {"id":3,"slug":"masterclass","name":"Abertura Carrinho — Masterclass","subject":"As inscrições abriram! R$1.997 com bônus exclusivos","body":"Chegou a hora, {{nome}}!\n\nO EmagreSer 2.0 está aberto para matrícula...","active":True,"created_at":"2026-05-21"},
    {"id":4,"slug":"quebra-gelo","name":"Win-back: Quebra-gelo","subject":"{{nome}}, vi que você ainda não garantiu sua vaga...","body":"Sei que a vida é corrida...\n\nMas sua transformação não pode esperar mais.","active":True,"created_at":"2026-05-21"},
    {"id":5,"slug":"encerramento","name":"Último aviso — Encerramento","subject":"ÚLTIMO DIA: sua vaga expira às 23h59 ⏰","body":"{{nome}}, hoje é o último dia.\n\nÀs 23h59 de 16/06 as inscrições fecham definitivamente.","active":False,"created_at":"2026-05-22"},
]

MOCK_WPP_QUEUE = [
    {"id":1,"nome":"Ana Paula Ferreira","whatsapp":"5511991234567","template":"boas-vindas","status":"sent","delivery_status":"read","created_at":"2026-05-22 08:15"},
    {"id":2,"nome":"Carla Mendonça","whatsapp":"5521987654321","template":"boas-vindas","status":"sent","delivery_status":"delivered","created_at":"2026-05-22 09:03"},
    {"id":3,"nome":"Fernanda Oliveira","whatsapp":"5531965432198","template":"cpls-convite","status":"sent","delivery_status":"read","created_at":"2026-05-22 09:46"},
    {"id":4,"nome":"Juliana Costa","whatsapp":"5541988776655","template":"cpls-convite","status":"pending","delivery_status":None,"created_at":"2026-05-22 10:12"},
    {"id":5,"nome":"Mariana Santos","whatsapp":"5551977665544","template":"boas-vindas","status":"sent","delivery_status":"read","created_at":"2026-05-22 10:39"},
    {"id":6,"nome":"Renata Lima","whatsapp":"5561966554433","template":"boas-vindas","status":"failed","delivery_status":None,"created_at":"2026-05-22 11:04"},
    {"id":7,"nome":"Patricia Souza","whatsapp":"5581955443322","template":"cpls-convite","status":"sent","delivery_status":"sent","created_at":"2026-05-22 11:23"},
    {"id":8,"nome":"Luciana Rodrigues","whatsapp":"5571944332211","template":"boas-vindas","status":"sent","delivery_status":"read","created_at":"2026-05-22 11:48"},
]

# ── helpers ──────────────────────────────────────────────────────────────────

def bypass_login(page):
    page.evaluate("""() => {
        window.currentUser = {id:'admin-001', email:'admin@emagreser.com', role:'superadmin', nome:'Admin EmagreSer'};
        if(typeof renderApp === 'function') renderApp();
        if(typeof renderNav === 'function') renderNav();
        const lo = document.getElementById('login-overlay');
        if(lo) lo.style.display='none';
    }""")

def inject_html(page, html_content):
    # Escape for JS template literal — no backslashes in f-string
    safe = html_content.replace("\\", "\\\\").replace("`", chr(96)).replace("${", "\\${")
    page.evaluate("(h) => { const el = document.getElementById('main-content'); if(el) el.innerHTML = h; }", safe)

# ── screenshot: admin templates ──────────────────────────────────────────────

def make_admin_templates(page):
    print("  → navigating to admin...")
    page.goto(ADMIN_URL, wait_until="domcontentloaded")
    page.wait_for_timeout(1500)
    bypass_login(page)
    page.wait_for_timeout(800)

    rows = "".join(f"""
        <tr>
          <td><span class="badge {'badge-success' if t['active'] else 'badge-danger'}">{'Ativo' if t['active'] else 'Inativo'}</span></td>
          <td><strong>{t['name']}</strong><br><small style="color:#888">{t['slug']}</small></td>
          <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{t['subject']}</td>
          <td style="color:#888;font-size:12px">{t['created_at']}</td>
          <td>
            <button class="btn btn-sm btn-outline-primary">✏️ Editar</button>
            <button class="btn btn-sm btn-outline-{'danger' if t['active'] else 'success'}">{'⏸ Inativar' if t['active'] else '▶ Ativar'}</button>
          </td>
        </tr>""" for t in MOCK_TEMPLATES)

    html = f"""
<div style="padding:24px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
    <div>
      <h2 style="margin:0;font-size:22px;font-weight:700">📧 Templates de E-mail</h2>
      <p style="color:#888;margin:4px 0 0">Gerencie as mensagens automáticas enviadas aos leads</p>
    </div>
    <button style="background:#6366f1;color:#fff;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-weight:600">+ Novo Template</button>
  </div>
  <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden">
    <table style="width:100%;border-collapse:collapse;font-size:14px">
      <thead style="background:#f8f9fa">
        <tr>
          <th style="padding:14px 16px;text-align:left;font-weight:600;color:#444;border-bottom:1px solid #e5e7eb">Status</th>
          <th style="padding:14px 16px;text-align:left;font-weight:600;color:#444;border-bottom:1px solid #e5e7eb">Nome / Slug</th>
          <th style="padding:14px 16px;text-align:left;font-weight:600;color:#444;border-bottom:1px solid #e5e7eb">Assunto</th>
          <th style="padding:14px 16px;text-align:left;font-weight:600;color:#444;border-bottom:1px solid #e5e7eb">Criado</th>
          <th style="padding:14px 16px;text-align:left;font-weight:600;color:#444;border-bottom:1px solid #e5e7eb">Ações</th>
        </tr>
      </thead>
      <tbody>{rows}</tbody>
    </table>
  </div>
  <div style="margin-top:16px;color:#888;font-size:13px">{len(MOCK_TEMPLATES)} templates cadastrados · {sum(1 for t in MOCK_TEMPLATES if t['active'])} ativos · {sum(1 for t in MOCK_TEMPLATES if not t['active'])} inativos</div>
</div>"""

    inject_html(page, html)
    page.wait_for_timeout(600)
    page.screenshot(path=f"{OUT}/admin-templates.png", full_page=False)
    print("  ✅ admin-templates.png")


# ── screenshot: admin whatsapp queue ─────────────────────────────────────────

def make_admin_whatsapp(page):
    print("  → whatsapp queue...")
    page.goto(ADMIN_URL, wait_until="domcontentloaded")
    page.wait_for_timeout(1500)
    bypass_login(page)
    page.wait_for_timeout(800)

    status_badge = {
        "sent": ("<span style='background:#dcfce7;color:#166534;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600'>✅ Enviado</span>", "#dcfce7"),
        "pending": ("<span style='background:#fef9c3;color:#854d0e;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600'>⏳ Pendente</span>", "#fef9c3"),
        "failed": ("<span style='background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600'>❌ Falhou</span>", "#fee2e2"),
    }
    delivery_map = {
        "read": "<span style='color:#22c55e;font-size:12px'>✓✓ Lido</span>",
        "delivered": "<span style='color:#3b82f6;font-size:12px'>✓✓ Entregue</span>",
        "sent": "<span style='color:#94a3b8;font-size:12px'>✓ Enviado</span>",
        None: "<span style='color:#cbd5e1;font-size:12px'>—</span>",
    }

    rows = "".join(f"""
        <tr style="border-bottom:1px solid #f1f5f9">
          <td style="padding:12px 16px">{q['id']}</td>
          <td style="padding:12px 16px"><strong>{q['nome']}</strong><br><small style="color:#888">{q['whatsapp']}</small></td>
          <td style="padding:12px 16px"><code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:12px">{q['template']}</code></td>
          <td style="padding:12px 16px">{status_badge.get(q['status'],('—','#fff'))[0]}</td>
          <td style="padding:12px 16px">{delivery_map.get(q['delivery_status'],'—')}</td>
          <td style="padding:12px 16px;color:#888;font-size:12px">{q['created_at']}</td>
        </tr>""" for q in MOCK_WPP_QUEUE)

    sent_c  = sum(1 for q in MOCK_WPP_QUEUE if q['status']=='sent')
    pend_c  = sum(1 for q in MOCK_WPP_QUEUE if q['status']=='pending')
    fail_c  = sum(1 for q in MOCK_WPP_QUEUE if q['status']=='failed')
    read_c  = sum(1 for q in MOCK_WPP_QUEUE if q['delivery_status']=='read')

    html = f"""
<div style="padding:24px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
    <div>
      <h2 style="margin:0;font-size:22px;font-weight:700">💬 Fila WhatsApp</h2>
      <p style="color:#888;margin:4px 0 0">Monitoramento em tempo real das mensagens Z-API</p>
    </div>
    <div style="display:flex;gap:8px">
      <button style="background:#fef9c3;color:#854d0e;border:1px solid #fde68a;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:13px">🔄 Retentar Falhas ({fail_c})</button>
      <button style="background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:13px">🔃 Atualizar</button>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">
    <div style="background:#fff;border-radius:12px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);text-align:center">
      <div style="font-size:28px;font-weight:700;color:#6366f1">{sent_c}</div>
      <div style="color:#888;font-size:13px;margin-top:4px">Enviados</div>
    </div>
    <div style="background:#fff;border-radius:12px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);text-align:center">
      <div style="font-size:28px;font-weight:700;color:#f59e0b">{pend_c}</div>
      <div style="color:#888;font-size:13px;margin-top:4px">Pendentes</div>
    </div>
    <div style="background:#fff;border-radius:12px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);text-align:center">
      <div style="font-size:28px;font-weight:700;color:#ef4444">{fail_c}</div>
      <div style="color:#888;font-size:13px;margin-top:4px">Falhas</div>
    </div>
    <div style="background:#fff;border-radius:12px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);text-align:center">
      <div style="font-size:28px;font-weight:700;color:#22c55e">{read_c}</div>
      <div style="color:#888;font-size:13px;margin-top:4px">Lidas</div>
    </div>
  </div>

  <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden">
    <table style="width:100%;border-collapse:collapse;font-size:14px">
      <thead style="background:#f8f9fa">
        <tr>
          <th style="padding:14px 16px;text-align:left;font-weight:600;color:#444">#</th>
          <th style="padding:14px 16px;text-align:left;font-weight:600;color:#444">Lead</th>
          <th style="padding:14px 16px;text-align:left;font-weight:600;color:#444">Template</th>
          <th style="padding:14px 16px;text-align:left;font-weight:600;color:#444">Status Envio</th>
          <th style="padding:14px 16px;text-align:left;font-weight:600;color:#444">Leitura</th>
          <th style="padding:14px 16px;text-align:left;font-weight:600;color:#444">Horário</th>
        </tr>
      </thead>
      <tbody>{rows}</tbody>
    </table>
  </div>
  <div style="margin-top:12px;color:#888;font-size:13px">Última atualização: 22/05/2026 às 15:31 · Total na fila: {len(MOCK_WPP_QUEUE)} mensagens</div>
</div>"""

    inject_html(page, html)
    page.wait_for_timeout(600)
    page.screenshot(path=f"{OUT}/admin-whatsapp.png", full_page=False)
    print("  ✅ admin-whatsapp.png")


# ── screenshot: painel main ───────────────────────────────────────────────────

PAINEL_HTML = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Painel EmagreSer</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',sans-serif;background:#f0f4f8;color:#1a1a2e;min-height:100vh}
  .topbar{background:linear-gradient(135deg,#6c63ff,#48cae4);color:#fff;padding:14px 28px;display:flex;justify-content:space-between;align-items:center}
  .topbar h1{font-size:18px;font-weight:700}
  .topbar span{font-size:13px;opacity:.85}
  .container{max-width:1100px;margin:0 auto;padding:28px}
  .grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
  .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 3px 12px rgba(0,0,0,.07)}
  .card-num{font-size:36px;font-weight:800;margin-bottom:4px}
  .card-lbl{color:#888;font-size:13px}
  .card-sub{font-size:11px;color:#22c55e;margin-top:6px}
  .blue{color:#6366f1} .green{color:#22c55e} .amber{color:#f59e0b} .pink{color:#ec4899}
  .section-title{font-size:16px;font-weight:700;margin-bottom:14px;color:#1e293b}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th{background:#f8fafc;padding:10px 14px;text-align:left;font-weight:600;color:#64748b;border-bottom:2px solid #e2e8f0}
  td{padding:10px 14px;border-bottom:1px solid #f1f5f9}
  .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
  .badge-a{background:#ede9fe;color:#6d28d9} .badge-b{background:#dbeafe;color:#1d4ed8}
  .badge-c{background:#fce7f3;color:#9d174d} .badge-d{background:#fef3c7;color:#92400e}
  .bar-wrap{background:#f1f5f9;border-radius:8px;height:10px;overflow:hidden;margin-top:8px}
  .bar{height:10px;border-radius:8px}
  .funnel{list-style:none;padding:0}
  .funnel li{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px}
  .funnel li:last-child{border:none}
  .funnel .num{font-weight:700;font-size:15px}
  .alert{background:#fef9c3;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;font-size:13px;color:#713f12;margin-bottom:20px}
</style>
</head>
<body>
<div class="topbar">
  <h1>📊 Painel EmagreSer 2.0 — Lançamento Junho/2026</h1>
  <span>Atualizado: 22/05/2026 às 15:45 · admin@emagreser.com</span>
</div>
<div class="container">

  <div class="alert">⚡ <strong>Lançamento ativo!</strong> LP publicada hoje (22/05). Captação pesada: 22/05 a 06/06. Masterclass: 11/06. Encerramento: 16/06.</div>

  <div class="grid4">
    <div class="card">
      <div class="card-num blue">2.847</div>
      <div class="card-lbl">Total de Leads</div>
      <div class="card-sub">↑ +43 hoje</div>
    </div>
    <div class="card">
      <div class="card-num green">R$1.997</div>
      <div class="card-lbl">Ticket do Programa</div>
      <div class="card-sub">Meta: 60 vendas = R$119.820</div>
    </div>
    <div class="card">
      <div class="card-num amber">R$1,67</div>
      <div class="card-lbl">CPL Máximo</div>
      <div class="card-sub">Investimento: R$5.000</div>
    </div>
    <div class="card">
      <div class="card-num pink">87,3%</div>
      <div class="card-lbl">Taxa de Abertura WPP</div>
      <div class="card-sub">1.923 msgs enviadas</div>
    </div>
  </div>

  <div class="grid2">
    <div class="card">
      <div class="section-title">Distribuição por Perfil Sabotador</div>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between"><span>🔴 A — Recompensadora</span><strong>763 (26,8%)</strong></div>
        <div class="bar-wrap"><div class="bar" style="width:26.8%;background:#6d28d9"></div></div>
      </div>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between"><span>🔵 B — Piloto Automático</span><strong>891 (31,3%)</strong></div>
        <div class="bar-wrap"><div class="bar" style="width:31.3%;background:#1d4ed8"></div></div>
      </div>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between"><span>🟣 C — Prisioneira do Esforço</span><strong>612 (21,5%)</strong></div>
        <div class="bar-wrap"><div class="bar" style="width:21.5%;background:#ec4899"></div></div>
      </div>
      <div>
        <div style="display:flex;justify-content:space-between"><span>🟡 D — Compensadora Noturna</span><strong>581 (20,4%)</strong></div>
        <div class="bar-wrap"><div class="bar" style="width:20.4%;background:#f59e0b"></div></div>
      </div>
    </div>

    <div class="card">
      <div class="section-title">Funil de Conversão</div>
      <ul class="funnel">
        <li><span>Visitantes LP</span><span class="num blue">4.381</span></li>
        <li><span>Iniciaram quiz</span><span class="num blue">3.204 (73%)</span></li>
        <li><span>Leads capturados</span><span class="num green">2.847 (89%)</span></li>
        <li><span>Abriram e-mail</span><span class="num amber">1.189 (42%)</span></li>
        <li><span>Clicaram no link</span><span class="num amber">634 (53%)</span></li>
        <li><span>Meta de vendas</span><span class="num pink">60 matrículas</span></li>
      </ul>
    </div>
  </div>

  <div class="card" style="margin-bottom:28px">
    <div class="section-title">Últimos Leads Capturados</div>
    <table>
      <thead>
        <tr>
          <th>Nome</th><th>Cidade</th><th>Perfil</th><th>WhatsApp</th><th>E-mail</th><th>Horário</th>
        </tr>
      </thead>
      <tbody>
        <tr><td><strong>Simone Barbosa</strong></td><td>Rio de Janeiro</td><td><span class="badge badge-a">A</span></td><td>✅ Ativo</td><td>✉ Enviado</td><td>15:23</td></tr>
        <tr><td><strong>Adriana Monteiro</strong></td><td>Guarulhos</td><td><span class="badge badge-b">B</span></td><td>✅ Ativo</td><td>✉ Enviado</td><td>14:55</td></tr>
        <tr><td><strong>Daniela Rocha</strong></td><td>Curitiba</td><td><span class="badge badge-d">D</span></td><td>⏳ Fila</td><td>✉ Enviado</td><td>14:12</td></tr>
        <tr><td><strong>Tatiana Pereira</strong></td><td>Niterói</td><td><span class="badge badge-c">C</span></td><td>✅ Ativo</td><td>✉ Enviado</td><td>13:44</td></tr>
        <tr><td><strong>Vanessa Gomes</strong></td><td>São Paulo</td><td><span class="badge badge-a">A</span></td><td>✅ Ativo</td><td>✉ Enviado</td><td>13:08</td></tr>
      </tbody>
    </table>
  </div>

  <div class="grid2">
    <div class="card">
      <div class="section-title">E-mail Marketing</div>
      <ul class="funnel">
        <li><span>E-mails enviados</span><span class="num blue">2.401</span></li>
        <li><span>Abertos</span><span class="num green">1.189 (49,5%)</span></li>
        <li><span>Clicados</span><span class="num amber">634 (53,3%)</span></li>
        <li><span>Descadastros</span><span class="num" style="color:#ef4444">12 (0,5%)</span></li>
      </ul>
    </div>
    <div class="card">
      <div class="section-title">WhatsApp (Z-API)</div>
      <ul class="funnel">
        <li><span>Mensagens enviadas</span><span class="num blue">1.923</span></li>
        <li><span>Entregues</span><span class="num blue">1.889 (98,2%)</span></li>
        <li><span>Lidas</span><span class="num green">1.541 (81,6%)</span></li>
        <li><span>Pendentes na fila</span><span class="num amber">47</span></li>
      </ul>
    </div>
  </div>

</div>
</body>
</html>"""


def make_painel(browser):
    print("  → painel main...")
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    page = ctx.new_page()
    page.set_content(PAINEL_HTML, wait_until="domcontentloaded")
    page.wait_for_timeout(500)
    page.screenshot(path=f"{OUT}/painel-main.png", full_page=True)
    ctx.close()
    print("  ✅ painel-main.png")


# ── main ─────────────────────────────────────────────────────────────────────

def main():
    os.makedirs(OUT, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch()

        # Admin screenshots share one context/page with nav sidebar
        ctx = browser.new_context(viewport={"width": 1280, "height": 800})
        page = ctx.new_page()

        make_admin_templates(page)
        make_admin_whatsapp(page)

        ctx.close()

        make_painel(browser)
        browser.close()

    print("\n✅ All screenshots generated!")
    for f in ["admin-templates.png","admin-whatsapp.png","painel-main.png"]:
        path = f"{OUT}/{f}"
        if os.path.exists(path):
            print(f"  {f}: {os.path.getsize(path)//1024}KB")

if __name__ == "__main__":
    main()
