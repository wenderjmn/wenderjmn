#!/usr/bin/env python3
"""Generate standalone admin-style mock screenshots for the briefing."""

import os
from playwright.sync_api import sync_playwright

OUT = "/home/user/wenderjmn/docs/img"

ADMIN_STYLE = """
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#f4f6fb;color:#1a1a2e;display:flex;min-height:100vh}
.sidebar{width:240px;background:#1e1e3a;color:#fff;flex-shrink:0;display:flex;flex-direction:column;padding:0}
.sidebar-logo{padding:22px 20px 18px;border-bottom:1px solid rgba(255,255,255,.08)}
.sidebar-logo h2{font-size:16px;font-weight:700;color:#a5b4fc}
.sidebar-logo p{font-size:11px;color:#64748b;margin-top:2px}
.nav-item{display:flex;align-items:center;gap:10px;padding:11px 20px;font-size:13px;color:#94a3b8;cursor:pointer;transition:.15s}
.nav-item:hover,.nav-item.active{background:rgba(99,102,241,.15);color:#a5b4fc}
.nav-icon{font-size:16px;width:20px;text-align:center}
.main{flex:1;display:flex;flex-direction:column}
.topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 28px;display:flex;justify-content:space-between;align-items:center}
.topbar-title{font-size:16px;font-weight:700;color:#1e293b}
.topbar-user{font-size:13px;color:#64748b;display:flex;align-items:center;gap:8px}
.avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:700}
.content{padding:24px;flex:1}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.page-header h3{font-size:20px;font-weight:700}
.page-header p{color:#64748b;font-size:13px;margin-top:2px}
.btn-primary{background:#6366f1;color:#fff;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden}
table{width:100%;border-collapse:collapse;font-size:13px}
th{background:#f8f9fa;padding:12px 16px;text-align:left;font-weight:600;color:#475569;border-bottom:1px solid #e5e7eb}
td{padding:12px 16px;border-bottom:1px solid #f9fafb;color:#374151}
tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-on{background:#dcfce7;color:#166534}
.badge-off{background:#fee2e2;color:#991b1b}
.stat-mini{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.stat-box{background:#fff;border-radius:10px;padding:14px 16px;box-shadow:0 2px 6px rgba(0,0,0,.05);text-align:center}
.stat-box .num{font-size:26px;font-weight:800}
.stat-box .lbl{font-size:11px;color:#94a3b8;margin-top:4px}
.footer-note{margin-top:12px;color:#94a3b8;font-size:12px}
</style>
"""

def admin_shell(active_item, active_label, page_html, topbar_btn=""):
    nav_items = [
        ("🏠","Dashboard","dashboard"),
        ("👥","Leads","leads"),
        ("📧","Templates E-mail","email_templates"),
        ("💬","Templates WPP","wpp_templates"),
        ("📬","Fila WhatsApp","wpp_queue"),
        ("👤","Usuários","users"),
        ("📊","Relatórios","reports"),
    ]
    nav_html = "".join(
        f'<div class="nav-item{"  active" if n[2]==active_item else ""}"><span class="nav-icon">{n[0]}</span>{n[1]}</div>'
        for n in nav_items
    )
    topbar_right = topbar_btn or '<div class="topbar-user"><div class="avatar">A</div>admin@emagreser.com</div>'
    return f"""<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8">{ADMIN_STYLE}</head>
<body>
<div class="sidebar">
  <div class="sidebar-logo">
    <h2>EmagreSer 2.0</h2>
    <p>Painel Administrativo</p>
  </div>
  {nav_html}
</div>
<div class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title">{active_label}</div>
    </div>
    {topbar_right}
  </div>
  <div class="content">
    {page_html}
  </div>
</div>
</body>
</html>"""


# ── templates page ────────────────────────────────────────────────────────────

templates = [
    (True,  "Boas-vindas ao Quiz",       "boas-vindas",   "Você descobriu seu Sabotador! 🎯",              "2026-05-20"),
    (True,  "Convite CPLs",              "cpls-convite",  "Sua vaga está reservada — Treinamento 🔥",     "2026-05-21"),
    (True,  "Abertura Carrinho",         "masterclass",   "As inscrições abriram! R$1.997 + bônus",       "2026-05-21"),
    (True,  "Win-back: Quebra-gelo",     "quebra-gelo",   "Vi que você ainda não garantiu sua vaga...",   "2026-05-21"),
    (False, "Último aviso — Encerramento","encerramento", "ÚLTIMO DIA: sua vaga expira às 23h59 ⏰",       "2026-05-22"),
]

tpl_rows = "".join(f"""<tr>
  <td><span class="badge {'badge-on' if a else 'badge-off'}">{'Ativo' if a else 'Inativo'}</span></td>
  <td><strong>{nm}</strong><br><small style="color:#94a3b8">{sl}</small></td>
  <td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#374151">{sb}</td>
  <td style="color:#94a3b8;font-size:12px">{cr}</td>
  <td>
    <button style="background:#ede9fe;color:#6d28d9;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;margin-right:4px">✏️ Editar</button>
    <button style="background:{'#fee2e2' if a else '#dcfce7'};color:{'#991b1b' if a else '#166534'};border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px">{'⏸ Inativar' if a else '▶ Ativar'}</button>
  </td>
</tr>""" for a,nm,sl,sb,cr in templates)

email_tpl_page = f"""
<div class="page-header">
  <div>
    <h3>📧 Templates de E-mail</h3>
    <p>Gerencie as mensagens automáticas enviadas aos leads</p>
  </div>
  <button class="btn-primary">+ Novo Template</button>
</div>
<div class="card">
  <table>
    <thead><tr>
      <th>Status</th><th>Nome / Slug</th><th>Assunto</th><th>Criado em</th><th>Ações</th>
    </tr></thead>
    <tbody>{tpl_rows}</tbody>
  </table>
</div>
<div class="footer-note">5 templates · 4 ativos · 1 inativo · Servidor Resend.com</div>
"""

# ── wpp templates page ─────────────────────────────────────────────────────────

wpp_templates = [
    (True,  "Boas-vindas Quiz",     "wpp-boas-vindas", "Olá {{nome}}! 👋 Você acaba de descobrir..."),
    (True,  "Convite Treinamentos", "wpp-cpls",        "{{nome}}, os treinamentos gratuitos começam 03/06..."),
    (True,  "Abertura Carrinho",    "wpp-carrinho",    "{{nome}}, chegou a hora! EmagreSer 2.0 aberto..."),
    (True,  "Lembrete Masterclass", "wpp-lembrete",    "Não esqueça! Sua masterclass começa em 1h 🔔"),
    (False, "Encerramento",         "wpp-encerramento","Último aviso: vagas encerram hoje às 23h59 ⏰"),
]

wpp_rows = "".join(f"""<tr>
  <td><span class="badge {'badge-on' if a else 'badge-off'}">{'Ativo' if a else 'Inativo'}</span></td>
  <td><strong>{nm}</strong><br><small style="color:#94a3b8">{sl}</small></td>
  <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#374151">{pr}</td>
  <td>
    <button style="background:#dcfce7;color:#166534;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;margin-right:4px">👁 Preview</button>
    <button style="background:#ede9fe;color:#6d28d9;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px">✏️ Editar</button>
  </td>
</tr>""" for a,nm,sl,pr in wpp_templates)

wpp_tpl_page = f"""
<div class="page-header">
  <div>
    <h3>💬 Templates WhatsApp</h3>
    <p>Mensagens Z-API com preview em tempo real</p>
  </div>
  <button class="btn-primary">+ Novo Template WPP</button>
</div>
<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">
  <div class="card">
    <table>
      <thead><tr>
        <th>Status</th><th>Nome / Slug</th><th>Preview da mensagem</th><th>Ações</th>
      </tr></thead>
      <tbody>{wpp_rows}</tbody>
    </table>
  </div>
  <div style="background:#e5ddd5;border-radius:12px;padding:16px">
    <div style="font-size:12px;font-weight:700;color:#4a4a4a;text-align:center;margin-bottom:12px">📱 Preview WhatsApp</div>
    <div style="background:#dcf8c6;border-radius:12px 12px 2px 12px;padding:12px 14px;max-width:280px;margin-left:auto;font-size:13px;line-height:1.5;box-shadow:0 1px 2px rgba(0,0,0,.15)">
      <strong>Olá Ana Paula!</strong> 👋<br><br>
      Você acabou de descobrir que seu perfil é a <strong>Recompensadora</strong> — e isso explica tudo sobre sua relação com a comida.<br><br>
      Nos próximos dias vou te enviar 3 treinamentos gratuitos que vão mudar sua vida. Fique de olho! 😊<br><br>
      — Ira Soraya &amp; Daniely
      <div style="text-align:right;font-size:10px;color:#667781;margin-top:6px">15:23 ✓✓</div>
    </div>
  </div>
</div>
<div class="footer-note" style="margin-top:12px">5 templates WPP · 4 ativos · Z-API conectado ✅</div>
"""


# ── wpp queue page ────────────────────────────────────────────────────────────

queue = [
    ("Ana Paula Ferreira",  "55 (11) 99123-4567","boas-vindas","sent","read",   "08:15"),
    ("Carla Mendonça",      "55 (21) 98765-4321","boas-vindas","sent","delivered","09:03"),
    ("Fernanda Oliveira",   "55 (31) 96543-2198","cpls-convite","sent","read",  "09:46"),
    ("Juliana Costa",       "55 (41) 98877-6655","cpls-convite","pending",None, "10:12"),
    ("Mariana Santos",      "55 (51) 97766-5544","boas-vindas","sent","read",   "10:39"),
    ("Renata Lima",         "55 (61) 96655-4433","boas-vindas","failed",None,   "11:04"),
    ("Patricia Souza",      "55 (81) 95544-3322","cpls-convite","sent","sent",  "11:23"),
    ("Luciana Rodrigues",   "55 (71) 94433-2211","boas-vindas","sent","read",   "11:48"),
]

status_html = {
    "sent":    "<span style='background:#dcfce7;color:#166534;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600'>✅ Enviado</span>",
    "pending": "<span style='background:#fef9c3;color:#854d0e;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600'>⏳ Pendente</span>",
    "failed":  "<span style='background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600'>❌ Falhou</span>",
}
deliv_html = {
    "read":      "<span style='color:#22c55e;font-size:12px;font-weight:600'>✓✓ Lido</span>",
    "delivered": "<span style='color:#3b82f6;font-size:12px;font-weight:600'>✓✓ Entregue</span>",
    "sent":      "<span style='color:#94a3b8;font-size:12px'>✓ Enviado</span>",
    None:        "<span style='color:#d1d5db;font-size:12px'>—</span>",
}

queue_rows = "".join(f"""<tr>
  <td><strong>{n}</strong><br><small style="color:#94a3b8">{w}</small></td>
  <td><code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:12px">{t}</code></td>
  <td>{status_html[s]}</td>
  <td>{deliv_html[d]}</td>
  <td style="color:#94a3b8;font-size:12px">22/05 {h}</td>
</tr>""" for n,w,t,s,d,h in queue)

sent_c  = sum(1 for _,_,_,s,_,_ in queue if s=='sent')
pend_c  = sum(1 for _,_,_,s,_,_ in queue if s=='pending')
fail_c  = sum(1 for _,_,_,s,_,_ in queue if s=='failed')
read_c  = sum(1 for _,_,_,_,d,_ in queue if d=='read')

wpp_queue_page = f"""
<div class="page-header">
  <div>
    <h3>📬 Fila WhatsApp — Z-API</h3>
    <p>Monitoramento em tempo real das mensagens enviadas</p>
  </div>
  <div style="display:flex;gap:8px">
    <button style="background:#fef9c3;color:#854d0e;border:1px solid #fde68a;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:600">🔄 Retentar Falhas ({fail_c})</button>
    <button style="background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:12px">🔃 Atualizar</button>
  </div>
</div>
<div class="stat-mini">
  <div class="stat-box"><div class="num" style="color:#6366f1">{sent_c}</div><div class="lbl">Enviados</div></div>
  <div class="stat-box"><div class="num" style="color:#f59e0b">{pend_c}</div><div class="lbl">Pendentes</div></div>
  <div class="stat-box"><div class="num" style="color:#ef4444">{fail_c}</div><div class="lbl">Falhas</div></div>
</div>
<div class="card">
  <table>
    <thead><tr>
      <th>Lead</th><th>Template</th><th>Status Envio</th><th>Leitura</th><th>Horário</th>
    </tr></thead>
    <tbody>{queue_rows}</tbody>
  </table>
</div>
<div class="footer-note">8 mensagens · {read_c} lidas ({int(read_c/sent_c*100) if sent_c else 0}% taxa de leitura) · Última atualização: 22/05/2026 15:31</div>
"""


# ── render ────────────────────────────────────────────────────────────────────

PAGES = [
    ("admin-templates.png",  "email_templates", "Templates de E-mail",  email_tpl_page),
    ("admin-wpp-templates.png","wpp_templates", "Templates WhatsApp",    wpp_tpl_page),
    ("admin-whatsapp.png",   "wpp_queue",       "Fila WhatsApp",         wpp_queue_page),
]


def main():
    os.makedirs(OUT, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for fname, active, label, content in PAGES:
            print(f"  → {fname}...")
            html = admin_shell(active, label, content)
            ctx = browser.new_context(viewport={"width": 1280, "height": 820})
            page = ctx.new_page()
            page.set_content(html, wait_until="domcontentloaded")
            page.wait_for_timeout(400)
            page.screenshot(path=f"{OUT}/{fname}", full_page=True)
            ctx.close()
            size = os.path.getsize(f"{OUT}/{fname}") // 1024
            print(f"  ✅ {fname} ({size}KB)")
        browser.close()
    print("\nDone!")

if __name__ == "__main__":
    main()
