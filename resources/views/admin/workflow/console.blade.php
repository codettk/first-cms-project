<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Frame CMS — 워크플로우 콘솔</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+KR:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ── Design System 토큰 (Design System.dc.html Foundation — light) ── */
:root {
  --bg-app: oklch(0.982 0.002 265); --bg-surface: oklch(1 0 0); --bg-subtle: oklch(0.966 0.003 265); --bg-hover: oklch(0.952 0.004 265);
  --border: oklch(0.918 0.004 265); --border-strong: oklch(0.85 0.006 265);
  --text-1: oklch(0.245 0.012 265); --text-2: oklch(0.47 0.01 265); --text-3: oklch(0.63 0.008 265);
  --accent: oklch(0.52 0.09 264); --accent-hover: oklch(0.46 0.095 264); --accent-fg: oklch(0.99 0 0); --accent-subtle: oklch(0.955 0.022 264); --accent-text: oklch(0.5 0.1 264);
  --ok: oklch(0.62 0.09 155); --ok-bg: oklch(0.95 0.03 155); --ok-fg: oklch(0.44 0.08 155);
  --warn: oklch(0.72 0.1 75); --warn-bg: oklch(0.96 0.04 82); --warn-fg: oklch(0.5 0.09 62);
  --danger: oklch(0.57 0.16 25); --danger-bg: oklch(0.96 0.03 25); --danger-fg: oklch(0.51 0.16 25);
  --shadow-sm: 0 1px 2px oklch(0.2 0.02 265 / 0.06);
  --shadow-md: 0 4px 16px oklch(0.2 0.02 265 / 0.09), 0 1px 3px oklch(0.2 0.02 265 / 0.06);
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg-app); color: var(--text-1); font-family: 'IBM Plex Sans KR', system-ui, sans-serif; font-size: 14px; letter-spacing: -0.006em; -webkit-font-smoothing: antialiased; }
.mono { font-family: 'IBM Plex Mono', monospace; }
.layout { display: grid; grid-template-columns: 208px 1fr; min-height: 100vh; }
.sidebar { background: var(--bg-surface); border-right: 1px solid var(--border); padding: 20px 12px; position: sticky; top: 0; height: 100vh; }
.brand { display: flex; align-items: center; gap: 10px; padding: 4px 10px 18px; font-weight: 700; font-size: 15px; letter-spacing: -0.02em; }
.brand-mark { width: 30px; height: 30px; border-radius: 8px; background: var(--accent); color: var(--accent-fg); display: grid; place-items: center; font-size: 13px; font-weight: 700; }
.navgroup { font-size: 11px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
.navlink { display: block; padding: 8px 11px; border-radius: 8px; font-size: 13px; font-weight: 500; color: var(--text-2); text-decoration: none; }
.navlink:hover { background: var(--bg-subtle); color: var(--text-1); }
.navlink.active { background: var(--accent-subtle); color: var(--accent-text); font-weight: 600; }
.main { padding: 24px 32px 80px; min-width: 0; }
.page-title { font-size: 22px; font-weight: 700; letter-spacing: -0.03em; margin: 4px 0 18px; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 18px; }
.card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; box-shadow: var(--shadow-sm); }
.card .label { font-size: 12px; color: var(--text-3); font-weight: 600; }
.card .value { font-size: 24px; font-weight: 700; letter-spacing: -0.02em; margin-top: 2px; }
.card.danger { border-color: var(--danger); background: var(--danger-bg); }
.card.danger .value { color: var(--danger-fg); }
.banner { display: flex; align-items: center; gap: 10px; background: var(--danger-bg); color: var(--danger-fg); border: 1px solid var(--danger); border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; font-weight: 500; }
.panel { background: var(--bg-surface); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow-sm); margin-bottom: 18px; overflow: hidden; }
.panel-head { padding: 12px 16px; border-bottom: 1px solid var(--border); font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 10px; }
table.grid { width: 100%; border-collapse: collapse; font-size: 13px; }
table.grid th { text-align: left; padding: 9px 12px; background: var(--bg-subtle); color: var(--text-2); font-size: 12px; font-weight: 600; border-bottom: 1px solid var(--border); white-space: nowrap; }
table.grid td { padding: 9px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
table.grid tr:hover td { background: var(--bg-hover); }
.badge { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
/* §12 상태 뱃지 색상 정책 */
.badge.ok { background: var(--ok-bg); color: var(--ok-fg); }
.badge.accent { background: var(--accent-subtle); color: var(--accent-text); }
.badge.warn { background: var(--warn-bg); color: var(--warn-fg); }
.badge.danger { background: var(--danger-bg); color: var(--danger-fg); }
.badge.neutral { background: var(--bg-subtle); color: var(--text-2); }
.badge.pulse { animation: pulse 1.6s ease-in-out infinite; }
@keyframes pulse { 50% { opacity: 0.55; } }
.btn { display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-1); font-size: 12.5px; font-weight: 500; cursor: pointer; font-family: inherit; }
.btn:hover { background: var(--bg-hover); }
.btn.primary { background: var(--accent); border-color: var(--accent); color: var(--accent-fg); }
.btn.primary:hover { background: var(--accent-hover); }
.btn.danger { color: var(--danger-fg); border-color: var(--danger); background: var(--danger-bg); }
.filters { display: flex; gap: 8px; align-items: center; margin-bottom: 14px; flex-wrap: wrap; }
select, input[type=text] { height: 32px; padding: 0 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-surface); color: var(--text-1); font-family: inherit; font-size: 13px; }
.timeline { display: flex; gap: 8px; padding: 16px; overflow-x: auto; }
.step { min-width: 128px; border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; background: var(--bg-surface); }
.step.skipped { border-style: dashed; opacity: 0.75; }
.step.skipped.propagated { border-color: var(--danger); }
.step .type { font-weight: 700; font-size: 12.5px; margin-bottom: 6px; }
.step .meta { font-size: 11px; color: var(--text-3); margin-top: 5px; line-height: 1.5; }
.progressbar { height: 6px; border-radius: 4px; background: var(--bg-subtle); overflow: hidden; margin-top: 6px; }
.progressbar > div { height: 100%; background: var(--accent); }
.fail-card { margin: 0 16px 16px; padding: 12px 14px; border: 1px solid var(--danger); background: var(--danger-bg); color: var(--danger-fg); border-radius: 10px; font-size: 13px; }
.kv { padding: 12px 16px; display: grid; grid-template-columns: 130px 1fr; row-gap: 7px; font-size: 13px; }
.kv .k { color: var(--text-3); }
.lock-badge { font-size: 11px; }
.modal-backdrop { position: fixed; inset: 0; background: oklch(0.2 0.02 265 / 0.4); display: grid; place-items: center; z-index: 50; }
.modal { width: 420px; background: var(--bg-surface); border-radius: 14px; box-shadow: var(--shadow-md); padding: 20px; }
.modal h3 { margin: 0 0 8px; font-size: 16px; }
.modal p { color: var(--text-2); font-size: 13px; margin: 0 0 12px; }
.modal textarea { width: 100%; min-height: 72px; border-radius: 8px; border: 1px solid var(--border); padding: 8px 10px; font-family: inherit; font-size: 13px; resize: vertical; }
.modal .actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
.empty { padding: 28px; text-align: center; color: var(--text-3); }
.toast { position: fixed; bottom: 24px; right: 24px; background: var(--text-1); color: var(--bg-surface); padding: 10px 16px; border-radius: 10px; font-size: 13px; box-shadow: var(--shadow-md); z-index: 60; }
a.link { color: var(--accent-text); text-decoration: none; font-weight: 500; }
</style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand"><span class="brand-mark">F</span> Frame CMS</div>
    <div class="navgroup">워크플로우</div>
    <a class="navlink" data-nav href="#/dashboard">대시보드</a>
    <a class="navlink" data-nav href="#/contents">콘텐츠 처리 현황</a>
    <a class="navlink" data-nav href="#/jobs?status=FAILED">실패 Job</a>
    <a class="navlink" data-nav href="#/jobs">Job 전체</a>
    <a class="navlink" data-nav href="#/workers">Worker 상태</a>
    <a class="navlink" data-nav href="#/alerts">알림센터</a>
  </aside>
  <main class="main" id="view"><div class="empty">불러오는 중…</div></main>
</div>

<script>
/* ─────────────────────────────────────────────────────────────
 * Frame CMS 워크플로우 콘솔 (MVP)
 * 원칙 (Admin UI Wireframe Spec §11 · Revision Checklist §5-④):
 *  - 액션 버튼은 서버가 계산한 available_actions 배열에서만 생성한다.
 *    프론트는 상태 전이 로직을 절대 중복 구현하지 않는다.
 *  - HIGH 위험 액션(release_lock, worker disable/enable/clear_error)은
 *    기본 UI에서 렌더링하지 않는다 (MVP Scope §2 — API는 존재, 화면 미노출).
 *  - Master 원본은 path_masked만 표시 — 직접 경로/URL을 만들지 않는다.
 * ──────────────────────────────────────────────────────────── */
const API = '/admin/workflows';
// HIGH 위험 — 기본 미노출 액션 화이트리스트 필터
const HIDDEN_HIGH_ACTIONS = ['release_lock', 'disable', 'enable', 'clear_error'];

const BADGE = { // §12 색상 정책
  content: { UPLOADING:'accent', REGISTERED:'neutral', PROCESSING:'accent pulse', READY:'ok', FAILED:'danger', ARCHIVED:'neutral', DELETED:'neutral' },
  job: { WAITING:'neutral', READY:'accent', RUNNING:'accent pulse', SUCCESS:'ok', FAILED:'danger', RETRY:'warn', SKIPPED:'neutral', CANCELED:'neutral', TIMEOUT:'danger' },
  worker: { ONLINE:'ok', BUSY:'accent', OFFLINE:'danger', DISABLED:'neutral', ERROR:'danger' },
  index: { PENDING:'neutral', INDEXED:'ok', STALE:'warn', FAILED:'danger' },
  alert: { HIGH:'danger', MED:'warn', LOW:'neutral' },
};

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const badge = (kind, status) => status ? `<span class="badge ${BADGE[kind][status] ?? 'neutral'}">${esc(status)}</span>` : '—';
const view = document.getElementById('view');

async function api(path, options = {}) {
  const res = await fetch(API + path, {
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    ...options,
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok && !body.error) throw new Error('요청 실패 (' + res.status + ')');
  return body;
}

function toast(message) {
  const el = document.createElement('div');
  el.className = 'toast'; el.textContent = message;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 2600);
}

/* 사유 입력 모달 — 확인 모달 + 사유 필수 정책 (§11) */
function reasonModal({ title, description, requireReason }) {
  return new Promise((resolve) => {
    const wrap = document.createElement('div');
    wrap.className = 'modal-backdrop';
    wrap.innerHTML = `<div class="modal">
      <h3>${esc(title)}</h3><p>${esc(description)}</p>
      <textarea placeholder="${requireReason ? '사유 (필수)' : '사유 (선택)'}"></textarea>
      <div class="actions"><button class="btn" data-cancel>취소</button>
      <button class="btn primary" data-ok>확인</button></div></div>`;
    const textarea = wrap.querySelector('textarea');
    wrap.querySelector('[data-cancel]').onclick = () => { wrap.remove(); resolve(null); };
    wrap.querySelector('[data-ok]').onclick = () => {
      const reason = textarea.value.trim();
      if (requireReason && !reason) { textarea.style.borderColor = 'var(--danger)'; return; }
      wrap.remove(); resolve(reason || null);
    };
    document.body.appendChild(wrap);
    textarea.focus();
  });
}

/* available_actions 기반 버튼 렌더링 — 서버 배열만 근거로 한다 */
function actionButtons(actions, defs) {
  return (actions ?? [])
    .filter(a => !HIDDEN_HIGH_ACTIONS.includes(a))
    .map(a => defs[a])
    .filter(Boolean)
    .map(d => `<button class="btn ${d.style ?? ''}" data-action="${d.key}">${esc(d.label)}</button>`)
    .join(' ');
}

async function runCommand(path, payload, { confirmTitle, confirmDesc, requireReason }) {
  const reason = await reasonModal({ title: confirmTitle, description: confirmDesc, requireReason });
  if (requireReason && reason === null) return false;
  const body = await api(path, { method: 'POST', body: JSON.stringify({ ...payload, reason: reason ?? undefined }) });
  if (body.error) { toast('오류: ' + (body.error.message ?? body.error.code)); return false; }
  toast('완료되었습니다.');
  return true;
}

/* ── 대시보드 (§3) ── */
async function renderDashboard() {
  const { data } = await api('/dashboard');
  const jobCards = ['WAITING','READY','RUNNING','RETRY','FAILED','TIMEOUT']
    .map(s => `<div class="card ${['FAILED','TIMEOUT'].includes(s) && (data.jobs[s] ?? 0) > 0 ? 'danger' : ''}">
      <div class="label">${s}</div><div class="value">${data.jobs[s] ?? 0}</div></div>`).join('');
  const contentCards = [['PROCESSING','처리 중'],['READY','READY'],['FAILED','FAILED']]
    .map(([s, label]) => `<div class="card ${s === 'FAILED' && (data.contents[s] ?? 0) > 0 ? 'danger' : ''}">
      <div class="label">${label}</div><div class="value">${data.contents[s] ?? 0}</div></div>`).join('');
  const banners = (data.banners ?? [])
    .map(b => `<div class="banner">⚠ ${esc(b.message)}</div>`).join('');
  const workers = Object.entries(data.workers ?? {})
    .map(([s, n]) => `${badge('worker', s)} ${n}`).join(' &nbsp; ');
  const searchIndex = ['INDEXED','STALE','PENDING','FAILED']
    .map(s => `${s} ${data.search_index?.[s] ?? 0}`).join(' / ');

  view.innerHTML = `
    <div class="page-title">워크플로우 대시보드</div>
    ${banners}
    <div class="cards">${contentCards}</div>
    <div class="cards">${jobCards}</div>
    <div class="panel"><div class="panel-head">Worker 요약</div><div class="kv" style="grid-template-columns:1fr">${workers || '<span class="empty">등록된 Worker 없음</span>'}</div></div>
    <div class="panel"><div class="panel-head">운영 지표 (24h)</div>
      <div class="kv">
        <span class="k">TC Queue</span><span>${data.queue.tc_queue_length}건 (최장 대기 ${data.queue.max_wait_sec}s)</span>
        <span class="k">평균 처리</span><span>${data.metrics.avg_processing_sec}s (p95 ${data.metrics.p95_processing_sec}s)</span>
        <span class="k">실패율 / 재시도율</span><span>${(data.metrics.failure_rate * 100).toFixed(1)}% / ${(data.metrics.retry_rate * 100).toFixed(1)}%</span>
        <span class="k">최근 1시간 처리</span><span>${data.metrics.throughput_1h}건</span>
        <span class="k">Lease 회수 (24h)</span><span>${data.metrics.lease_reclaims_24h ?? 0}건</span>
        <span class="k">Search Index</span><span>${searchIndex}</span>
      </div></div>`;
}

/* ── 콘텐츠 처리 현황 (§4) ── */
async function renderContents(params) {
  const query = new URLSearchParams(params);
  const body = await api('/contents?' + query.toString());
  const rows = body.data.map(c => `
    <tr>
      <td class="mono">${c.content_id}</td>
      <td><a class="link" href="#/contents/${c.content_id}">${esc(c.title)}</a></td>
      <td>${esc(c.media_type ?? '—')}</td>
      <td>${badge('content', c.content_status)}</td>
      <td>${c.current_stage ? esc(c.current_stage.job_type) + ' ' + badge('job', c.current_stage.status) : '—'}</td>
      <td class="mono">${c.progress.done_required}/${c.progress.total_required}</td>
      <td>${c.has_failed_job ? '<span class="badge danger">실패 있음</span>' : ''}</td>
      <td>${c.has_proxy ? '✓' : '—'}</td>
      <td>${c.has_thumbnail ? '✓' : '—'}</td>
      <td>${badge('index', c.index_status)}</td>
      <td>${badge('job', c.publish_status)}</td>
      <td data-actions data-content-id="${c.content_id}">${actionButtons(c.available_actions, CONTENT_ACTIONS)}</td>
    </tr>`).join('');

  view.innerHTML = `
    <div class="page-title">콘텐츠 처리 현황</div>
    <div class="filters">
      <select id="f-status"><option value="">상태 전체</option>${['UPLOADING','REGISTERED','PROCESSING','READY','FAILED','ARCHIVED'].map(s => `<option ${params.status === s ? 'selected' : ''}>${s}</option>`).join('')}</select>
      <label><input type="checkbox" id="f-failed" ${params.has_failed ? 'checked' : ''}> 실패만</label>
      <input type="text" id="f-q" placeholder="제목 검색" value="${esc(params.q ?? '')}">
      <button class="btn" id="f-apply">적용</button>
    </div>
    <div class="panel"><table class="grid">
      <thead><tr><th>ID</th><th>제목</th><th>유형</th><th>상태</th><th>현재 단계</th><th>진행</th><th></th><th>Proxy</th><th>Thumb</th><th>INDEX</th><th>PUBLISH</th><th>액션</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="12" class="empty">결과 없음</td></tr>'}</tbody>
    </table></div>`;

  document.getElementById('f-apply').onclick = () => {
    const p = new URLSearchParams();
    const status = document.getElementById('f-status').value;
    if (status) p.set('status', status);
    if (document.getElementById('f-failed').checked) p.set('has_failed', '1');
    const q = document.getElementById('f-q').value.trim();
    if (q) p.set('q', q);
    location.hash = '#/contents?' + p.toString();
  };
  bindContentActions();
}

const CONTENT_ACTIONS = {
  view_detail: { key: 'view_detail', label: '상세' },
  reprocess: { key: 'reprocess', label: '재처리' },
  cancel: { key: 'cancel', label: '취소', style: 'danger' },
  publish_retry: { key: 'publish_retry', label: 'PUBLISH 재시도' },
};

function bindContentActions() {
  view.querySelectorAll('[data-actions][data-content-id] [data-action]').forEach(btn => {
    const contentId = btn.closest('[data-content-id]').dataset.contentId;
    btn.onclick = async () => {
      const action = btn.dataset.action;
      if (action === 'view_detail') { location.hash = `#/contents/${contentId}`; return; }
      if (action === 'reprocess') {
        if (await runCommand(`/contents/${contentId}/reprocess`, { mode: 'failed_from' }, {
          confirmTitle: '콘텐츠 재처리', confirmDesc: '실패 지점부터 새 워크플로우 인스턴스를 생성합니다.', requireReason: true,
        })) route();
      }
      if (action === 'cancel') {
        if (await runCommand(`/contents/${contentId}/cancel`, {}, {
          confirmTitle: '콘텐츠 처리 취소', confirmDesc: '비종결 작업이 모두 취소되고 콘텐츠는 FAILED로 전환됩니다.', requireReason: true,
        })) route();
      }
    };
  });
}

/* ── 콘텐츠 상세 (§5) ── */
async function renderContentDetail(id) {
  const { data } = await api('/contents/' + id);
  const failedJobs = data.job_pipeline.filter(j => j.status === 'FAILED');

  const steps = data.job_pipeline.map(j => `
    <div class="step ${j.status === 'SKIPPED' ? 'skipped' : ''}">
      <div class="type">${esc(j.job_type)}</div>
      ${badge('job', j.status)}
      ${j.progress_percent !== null ? `<div class="progressbar"><div style="width:${j.progress_percent}%"></div></div>` : ''}
      <div class="meta">
        ${j.worker_name ? 'worker: ' + esc(j.worker_name) + '<br>' : ''}
        ${j.elapsed_sec !== null ? '소요 ' + j.elapsed_sec + 's<br>' : ''}
        ${j.retry_count > 0 ? `<span class="badge warn">retry ${j.retry_count}</span><br>` : ''}
        ${j.fail_reason_code ? `<span class="badge danger">${esc(j.fail_reason_code)}</span>` : ''}
      </div>
    </div>`).join('');

  view.innerHTML = `
    <div class="page-title">${esc(data.content.title)} ${badge('content', data.content.status)}</div>
    <div class="panel"><div class="panel-head">원본 / Rendition</div>
      <div class="kv">
        <span class="k">원본 파일</span><span>${data.media_files.map(f => esc(f.original_filename) + ' (' + f.file_size.toLocaleString() + ' bytes)').join(', ') || '—'}</span>
        <span class="k">Master 🔒</span><span class="mono">${data.renditions.master.exists ? esc(data.renditions.master.path_masked) : '없음'}</span>
        <span class="k">Proxy</span><span>${data.renditions.proxy.map(r => esc(r.variant_key)).join(', ') || '—'}</span>
        <span class="k">Thumbnail / Catalog</span><span>${data.renditions.thumbnail.length} / ${data.renditions.catalog.length}건</span>
        <span class="k">Search Index</span><span>${badge('index', data.search_index.status)} v${data.search_index.index_version}</span>
      </div></div>
    <div class="panel">
      <div class="panel-head">Job Pipeline
        ${data.workflow_instance ? `<span class="badge neutral">${esc(data.workflow_instance.template_code)} · ${esc(data.workflow_instance.status)}</span>` : ''}
        <span style="flex:1"></span>
        <span data-actions data-content-id="${data.content.content_id}">${actionButtons(data.available_actions, CONTENT_ACTIONS)}</span>
      </div>
      <div class="timeline">${steps}</div>
      ${failedJobs.map(j => `<div class="fail-card"><b>${esc(j.job_type)} 실패</b> — ${esc(j.fail_reason_code ?? '')}
        <a class="link" href="#/jobs/${j.job_id}">Job 상세 →</a></div>`).join('')}
    </div>
    <div class="panel"><div class="panel-head">최근 로그</div>
      <table class="grid"><tbody>
        ${data.recent_logs.map(l => `<tr><td style="width:70px">${badge('job', l.level === 'ERROR' ? 'FAILED' : 'SUCCESS')}</td><td class="mono">${esc(l.message ?? '')}</td><td style="width:190px" class="mono">${esc(l.at ?? '')}</td></tr>`).join('') || '<tr><td class="empty">로그 없음</td></tr>'}
      </tbody></table></div>`;
  bindContentActions();
}

/* ── Job 목록 / 실패 Job (§8) ── */
const JOB_ACTIONS = {
  retry: { key: 'retry', label: '재시도', style: 'primary' },
  cancel: { key: 'cancel', label: '취소', style: 'danger' },
  view_logs: { key: 'view_logs', label: '로그' },
  view_content: { key: 'view_content', label: '콘텐츠' },
  // release_lock·update_priority는 MVP 기본 화면 미노출 (HIGH / 큐 화면 M6)
};

async function renderJobs(params) {
  const query = new URLSearchParams(params);
  const body = await api('/jobs?' + query.toString());
  const isFailedView = params.status === 'FAILED';

  const rows = body.data.map(j => `
    <tr data-job-id="${j.job_id}" data-content-id="${j.content_id}">
      <td class="mono">${j.job_id}</td>
      <td><a class="link" href="#/contents/${j.content_id}">${esc(j.content_title ?? j.content_id)}</a></td>
      <td class="mono">${esc(j.job_type)}</td>
      <td>${j.is_required ? '<span class="badge danger">필수 — PUBLISH 차단</span>' : '<span class="badge warn">선택 — READY 가능</span>'}</td>
      <td>${badge('job', j.status)}</td>
      <td class="mono">${esc(j.fail_reason_code ?? '—')}</td>
      <td class="mono">${j.retry_count}/${j.max_retry}</td>
      <td>${j.progress ? Math.round(j.progress.percent) + '%' : '—'}</td>
      <td>${j.timeout_imminent ? '<span class="badge warn">타임아웃 임박</span>' : ''}</td>
      <td data-actions>${actionButtons(j.available_actions, JOB_ACTIONS)}</td>
    </tr>`).join('');

  view.innerHTML = `
    <div class="page-title">${isFailedView ? '실패 Job' : 'Job 목록'}</div>
    <div class="filters">
      <select id="f-status"><option value="">상태 전체</option>${['WAITING','READY','RUNNING','SUCCESS','FAILED','RETRY','SKIPPED','CANCELED','TIMEOUT'].map(s => `<option ${params.status === s ? 'selected' : ''}>${s}</option>`).join('')}</select>
      <select id="f-type"><option value="">유형 전체</option>${['TM','VERIFY','MA','TC','CA','INDEX','PUBLISH','CLEANUP'].map(t => `<option ${params.job_type === t ? 'selected' : ''}>${t}</option>`).join('')}</select>
      <button class="btn" id="f-apply">적용</button>
    </div>
    <div class="panel"><table class="grid">
      <thead><tr><th>ID</th><th>콘텐츠</th><th>유형</th><th>영향도</th><th>상태</th><th>실패 사유</th><th>재시도</th><th>진행률</th><th></th><th>액션</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="10" class="empty">결과 없음</td></tr>'}</tbody>
    </table></div>`;

  document.getElementById('f-apply').onclick = () => {
    const p = new URLSearchParams();
    const status = document.getElementById('f-status').value;
    const type = document.getElementById('f-type').value;
    if (status) p.set('status', status);
    if (type) p.set('job_type', type);
    location.hash = '#/jobs?' + p.toString();
  };

  view.querySelectorAll('tr[data-job-id] [data-action]').forEach(btn => {
    const row = btn.closest('tr');
    const jobId = row.dataset.jobId;
    btn.onclick = async () => {
      const action = btn.dataset.action;
      if (action === 'view_logs') { location.hash = `#/jobs/${jobId}`; return; }
      if (action === 'view_content') { location.hash = `#/contents/${row.dataset.contentId}`; return; }
      if (action === 'retry') { // 단건 재시도는 확인 모달 없음 (§11 LOW)
        const body = await api(`/jobs/${jobId}/retry`, { method: 'POST', body: JSON.stringify({}) });
        if (body.error) { toast('오류: ' + (body.error.message ?? body.error.code)); return; }
        toast('재시도 예약 완료 (RETRY)'); route();
      }
      if (action === 'cancel') {
        if (await runCommand(`/jobs/${jobId}/cancel`, {}, {
          confirmTitle: 'Job 취소', confirmDesc: '후속 작업은 SKIPPED로 전파됩니다.', requireReason: true,
        })) route();
      }
    };
  });
}

/* ── Job 상세 (사유 열람 — §10 요약형) ── */
async function renderJobDetail(id) {
  const { data } = await api('/jobs/' + id);
  view.innerHTML = `
    <div class="page-title">Job #${data.job.job_id} — ${esc(data.job.job_type)} ${badge('job', data.job.status)}</div>
    <div class="panel"><div class="panel-head">기본 정보
      <span style="flex:1"></span>
      <span data-actions>${actionButtons(data.available_actions, JOB_ACTIONS)}</span></div>
      <div class="kv">
        <span class="k">콘텐츠</span><span><a class="link" href="#/contents/${data.content.content_id}">${esc(data.content.title ?? data.content.content_id)}</a></span>
        <span class="k">재시도</span><span>${data.job.retry_count}/${data.job.max_retry} · priority ${data.job.priority}</span>
        <span class="k">Worker</span><span>${data.worker ? esc(data.worker.name) + ' ' + badge('worker', data.worker.status) : '—'}</span>
      </div></div>
    <div class="panel"><div class="panel-head">상태 전이 타임라인 (무슨 일이 있었나)</div>
      <table class="grid"><tbody>
        ${data.histories.map(h => `<tr><td style="width:200px">${h.from ? badge('job', h.from) + ' → ' : ''}${badge('job', h.to)}</td><td class="mono">${esc(h.actor)}</td><td>${esc(h.note ?? '')}</td><td style="width:190px" class="mono">${esc(h.at ?? '')}</td></tr>`).join('')}
      </tbody></table></div>
    <div class="panel"><div class="panel-head">실행 로그 (왜 그랬나)</div>
      ${data.attempts.map(a => `<div class="panel-head" style="border-top:1px solid var(--border)">attempt ${a.attempt_no}</div>
        <table class="grid"><tbody>${a.logs.map(l => `<tr><td style="width:70px" class="mono">${esc(l.level)}</td><td>${esc(l.message ?? '')}${l.detail ? `<div class="mono" style="color:var(--text-3);font-size:11px">${esc(JSON.stringify(l.detail))}</div>` : ''}</td><td style="width:190px" class="mono">${esc(l.at ?? '')}</td></tr>`).join('')}</tbody></table>`).join('') || '<div class="empty">로그 없음</div>'}
    </div>`;

  view.querySelectorAll('[data-action]').forEach(btn => {
    btn.onclick = async () => {
      if (btn.dataset.action === 'retry') {
        const body = await api(`/jobs/${id}/retry`, { method: 'POST', body: JSON.stringify({}) });
        if (body.error) { toast('오류: ' + (body.error.message ?? body.error.code)); return; }
        toast('재시도 예약 완료'); route();
      }
      if (btn.dataset.action === 'view_content') location.hash = `#/contents/${data.content.content_id}`;
      if (btn.dataset.action === 'cancel') {
        if (await runCommand(`/jobs/${id}/cancel`, {}, {
          confirmTitle: 'Job 취소', confirmDesc: '후속 작업은 SKIPPED로 전파됩니다.', requireReason: true,
        })) route();
      }
    };
  });
}

/* ── Worker 상태 (§9 — MVP 조회 전용, 관리 버튼은 기본 미노출) ── */
async function renderWorkers() {
  const { data } = await api('/workers');
  const rows = data.map(w => `
    <tr>
      <td class="mono">${w.worker_id}</td>
      <td>${esc(w.worker_name)}</td>
      <td class="mono">${esc(w.worker_type)}</td>
      <td>${badge('worker', w.status)}</td>
      <td>${(w.supported_job_types ?? []).map(t => `<span class="badge neutral">${esc(t)}</span>`).join(' ')}</td>
      <td class="mono">${w.heartbeat_delay_sec === null ? '—' : w.heartbeat_delay_sec + 's'}
        ${w.heartbeat_delay_sec > 90 ? '<span class="badge danger">지연</span>' : w.heartbeat_delay_sec > 30 ? '<span class="badge warn">지연</span>' : ''}</td>
      <td>${w.current_job ? `<a class="link" href="#/jobs/${w.current_job.job_id}">#${w.current_job.job_id} ${esc(w.current_job.job_type)}</a>` : '—'}</td>
      <td class="mono">${esc(w.version ?? '—')}</td>
    </tr>`).join('');

  view.innerHTML = `
    <div class="page-title">Worker 상태</div>
    <div class="panel"><table class="grid">
      <thead><tr><th>ID</th><th>이름</th><th>유형</th><th>상태</th><th>지원 작업</th><th>Heartbeat</th><th>현재 Job</th><th>버전</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="8" class="empty">등록된 Worker 없음</td></tr>'}</tbody>
    </table></div>
    <p style="color:var(--text-3);font-size:12px">Worker 비활성화·ERROR 해제는 HIGH 권한 조작으로 기본 화면에 노출하지 않습니다 (MVP Scope §2).</p>`;
}

/* ── 알림센터 (Wireframe §13 · ADR-0006) ── */
async function renderAlerts(params) {
  const qs = new URLSearchParams(params).toString();
  const { data } = await api('/alerts' + (qs ? '?' + qs : ''));
  const rows = (data ?? []).map(a => `<tr>
      <td>${badge('alert', a.severity)}</td>
      <td>${esc(a.event_type)}</td>
      <td>${esc(a.message)}</td>
      <td>${esc(a.created_at ?? '')}</td>
      <td>${a.acknowledged_at ? '확인됨' : `<button class="btn" data-ack="${a.alert_id}">확인</button>`}</td>
    </tr>`).join('');

  view.innerHTML = `
    <div class="page-title">알림센터</div>
    <div class="panel">
      <table class="table">
        <thead><tr><th>심각도</th><th>이벤트</th><th>메시지</th><th>발생</th><th>확인</th></tr></thead>
        <tbody>${rows || '<tr><td colspan="5" class="empty">알림 없음</td></tr>'}</tbody>
      </table>
    </div>`;

  view.querySelectorAll('[data-ack]').forEach(btn => btn.addEventListener('click', async () => {
    await api('/alerts/' + btn.dataset.ack + '/ack', { method: 'POST', body: JSON.stringify({}) });
    toast('알림 확인 처리됨'); route();
  }));
}

/* ── hash 라우터 ── */
async function route() {
  const hash = location.hash || '#/dashboard';
  const [path, queryString] = hash.slice(2).split('?');
  const params = Object.fromEntries(new URLSearchParams(queryString ?? ''));
  const segments = path.split('/');

  document.querySelectorAll('[data-nav]').forEach(a => a.classList.toggle('active', a.getAttribute('href') === hash || a.getAttribute('href') === '#/' + segments[0]));
  view.innerHTML = '<div class="empty">불러오는 중…</div>';

  try {
    if (segments[0] === 'dashboard' || segments[0] === '') await renderDashboard();
    else if (segments[0] === 'contents' && segments[1]) await renderContentDetail(segments[1]);
    else if (segments[0] === 'contents') await renderContents(params);
    else if (segments[0] === 'jobs' && segments[1]) await renderJobDetail(segments[1]);
    else if (segments[0] === 'jobs') await renderJobs(params);
    else if (segments[0] === 'workers') await renderWorkers();
    else if (segments[0] === 'alerts') await renderAlerts(params);
  } catch (e) {
    view.innerHTML = `<div class="empty">불러오기 실패: ${esc(e.message)}</div>`;
  }
}

window.addEventListener('hashchange', route);
route();
// 대시보드 10초 자동 폴링 (§3)
setInterval(() => { if ((location.hash || '#/dashboard').startsWith('#/dashboard')) route(); }, 10000);
</script>
</body>
</html>
