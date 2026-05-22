#!/usr/bin/env python3
"""Replace Section 14 in briefing-trafego.html with all embedded screenshots."""

import base64, os, re

BASE     = "/home/user/wenderjmn"
BRIEFING = f"{BASE}/docs/briefing-trafego.html"
IMG_DIR  = f"{BASE}/docs/img"

def b64img(fname):
    path = f"{IMG_DIR}/{fname}"
    with open(path, "rb") as f:
        return f"data:image/png;base64,{base64.b64encode(f.read()).decode()}"

print("Loading images...")
dash_b64   = b64img("admin-dashboard.png")
leads_b64  = b64img("admin-leads.png")
tpl_b64    = b64img("admin-templates.png")
wpp_tpl_b64= b64img("admin-wpp-templates.png")
wpp_q_b64  = b64img("admin-whatsapp.png")
painel_b64 = b64img("painel-main.png")
print("Images loaded.")

def browser_frame(label, img_b64, caption):
    return f"""
      <div style="border-radius:12px;overflow:hidden;border:2px solid var(--border);margin-bottom:28px;box-shadow:0 4px 24px rgba(0,0,0,.08)">
        <div style="background:#1e293b;padding:10px 16px;display:flex;align-items:center;gap:8px">
          <span style="width:11px;height:11px;background:#ef4444;border-radius:50%;display:inline-block"></span>
          <span style="width:11px;height:11px;background:#f59e0b;border-radius:50%;display:inline-block"></span>
          <span style="width:11px;height:11px;background:#10b981;border-radius:50%;display:inline-block"></span>
          <span style="font-size:.72rem;color:rgba(255,255,255,.45);margin-left:8px">{label}</span>
        </div>
        <img src="{img_b64}" alt="{caption}" style="width:100%;display:block">
      </div>
      <p style="font-size:.82rem;color:var(--text-light);text-align:center;margin:-20px 0 28px;font-style:italic">{caption}</p>"""

NEW_S14 = f"""    <!-- ═══════════════════════════ SEÇÃO 14 ══════════════════════ -->
    <section id="s14" class="section">
      <div class="section-header">
        <div class="section-badge">14</div>
        <div>
          <h2 class="section-title">🖥️ O Sistema Digital</h2>
          <p class="section-subtitle">Painel de monitoramento e painel administrativo construídos para o lançamento</p>
        </div>
      </div>
      <p style="margin-bottom:24px">Toda a operação é gerenciada por um sistema próprio: o <strong>Painel de Leads</strong> (monitoramento em tempo real) e o <strong>Admin</strong> (gestão de conteúdo, templates, filas e usuários). Abaixo, prints reais das telas em operação.</p>

      <h3 style="font-size:1rem;font-weight:700;color:var(--dark);margin:0 0 10px">📊 Painel de Monitoramento — Visão Geral do Lançamento</h3>
      <p style="margin-bottom:14px;font-size:.9rem">Indicadores em tempo real: total de leads, taxa de leitura de WhatsApp, funil de conversão, distribuição por perfil sabotador e últimos leads capturados.</p>
{browser_frame("painel.php — Monitoramento Operacional", painel_b64, "Painel de monitoramento: leads, funil, distribuição por perfil sabotador e métricas de e-mail e WhatsApp em tempo real")}

      <h3 style="font-size:1rem;font-weight:700;color:var(--dark);margin:0 0 10px">🏠 Admin — Dashboard Principal</h3>
      <p style="margin-bottom:14px;font-size:.9rem">Visão consolidada do dia: leads capturados hoje, gráfico de crescimento e distribuição por perfil (A/B/C/D).</p>
{browser_frame("admin/ — Dashboard Principal", dash_b64, "Dashboard admin: 2.847 leads totais, 43 hoje, distribuição dos 4 perfis sabotadores")}

      <h3 style="font-size:1rem;font-weight:700;color:var(--dark);margin:0 0 10px">👥 Admin — Gestão de Leads</h3>
      <p style="margin-bottom:14px;font-size:.9rem">Lista completa de leads com filtros por perfil, cidade, status de e-mail e WhatsApp. Permite exportar e acionar individualmente.</p>
{browser_frame("admin/ → Leads — Lista Completa", leads_b64, "Lista de leads com nome, perfil sabotador, cidade, status de entrega de e-mail e WPP")}

      <h3 style="font-size:1rem;font-weight:700;color:var(--dark);margin:0 0 10px">📧 Admin — Templates de E-mail</h3>
      <p style="margin-bottom:14px;font-size:.9rem">Criação, edição e ativação/desativação de templates de e-mail com variáveis dinâmicas (nome, perfil, link). Integração Resend.com.</p>
{browser_frame("admin/ → Templates E-mail", tpl_b64, "5 templates de e-mail: boas-vindas, CPLs, abertura de carrinho, win-back e encerramento")}

      <h3 style="font-size:1rem;font-weight:700;color:var(--dark);margin:0 0 10px">💬 Admin — Templates WhatsApp</h3>
      <p style="margin-bottom:14px;font-size:.9rem">Templates de mensagem WPP com preview ao vivo estilo WhatsApp. Variáveis como <code>{{{{nome}}}}</code> são substituídas em tempo real na pré-visualização.</p>
{browser_frame("admin/ → Templates WhatsApp — Preview ao vivo", wpp_tpl_b64, "5 templates WPP com preview em tempo real — mensagens da Ira Soraya e Daniely para cada perfil")}

      <h3 style="font-size:1rem;font-weight:700;color:var(--dark);margin:0 0 10px">📬 Admin — Fila de WhatsApp (Z-API)</h3>
      <p style="margin-bottom:14px;font-size:.9rem">Monitoramento da fila de envio: status por mensagem (Enviado / Pendente / Falhou), confirmação de leitura via Z-API e retry automático de falhas.</p>
{browser_frame("admin/ → Fila WhatsApp — Z-API", wpp_q_b64, "Fila WhatsApp: status de envio, confirmação de leitura (✓✓) e retry de mensagens com falha")}

    </section>"""

print("Reading briefing...")
with open(BRIEFING, "r", encoding="utf-8") as f:
    content = f.read()

# Find and replace section 14
# Match from the comment line to end of </section>
pattern = r'    <!-- ═+[^─]*SEÇÃO 14[^─]*═+ -->\s*<section id="s14".*?</section>'
match = re.search(pattern, content, re.DOTALL)
if match:
    print(f"Found Section 14 at chars {match.start()}-{match.end()}")
    new_content = content[:match.start()] + NEW_S14 + content[match.end():]
else:
    # Fallback: match just from the section tag
    pattern2 = r'<section id="s14".*?</section>'
    match2 = re.search(pattern2, content, re.DOTALL)
    if match2:
        print(f"Found s14 section at chars {match2.start()}-{match2.end()}")
        new_content = content[:match2.start()] + NEW_S14.strip() + content[match2.end():]
    else:
        print("ERROR: Could not find Section 14!")
        exit(1)

print(f"Writing updated briefing ({len(new_content)//1024}KB)...")
with open(BRIEFING, "w", encoding="utf-8") as f:
    f.write(new_content)

print("✅ Done!")
