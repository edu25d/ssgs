const data = {
  role: "teacher",
  teacherPage: "overview",
  adminPage: "overview",
  studentPage: "login",
  student: null,
  pendingStudent: null,
  noticeHidden: false,
  sidebarOpen: false,
  filter: "pending",
  search: "",
  answer: "",
  quizIndex: 0,
  selected: null,
  quizHint: false,
  beforeScore: 0,
  afterScore: 0,
  chatCount: 0,
  chat: [],
  chatLogs: {
    "04": [
      { role: "tutor", text: "금속 숟가락 손잡이가 따뜻해지는 까닭을 생각해 볼까요? 물과 숟가락 중 어디가 더 따뜻했나요?" },
      { role: "student", text: "물이 더 뜨거워요. 열이 물에서 숟가락으로 가는 것 같아요." },
      { role: "tutor", text: "좋은 생각이에요. 금속은 그 열을 손잡이까지 어떻게 전달할까요?" }
    ]
  },
  chatViews: [],
  finished: false,
  fileLocation: "학교 관리 PC / 교무 전용 폴더",
  fileConfirmed: false,
  approvalDone: false,
  checks: { retention: false, vendor: false, backup: false, access: false }
};

const students = [
  { id: "04", name: "학생 04", pin: "4921", color: "" },
  { id: "11", name: "학생 11", pin: "8354", color: "blue" },
  { id: "12", name: "학생 12", pin: "7364", color: "warm" },
  { id: "17", name: "학생 17", pin: "2106", color: "rose" },
  { id: "26", name: "학생 26", pin: "6729", color: "blue" },
  { id: "29", name: "학생 29", pin: "3815", color: "warm" }
];

const submissions = [
  { id: "04", name: "학생 04", color: "", topic: "열전도와 온도", date: "오늘 09:18", status: "approved", answer: "금속은 열을 잘 전달해서 숟가락 손잡이까지 열이 이동해요. 금속이 열을 새로 만드는 게 아니라 뜨거운 물에서 받은 열을 전달한 거예요.", evidence: "금속은 열을 잘 전달해서 숟가락 손잡이까지 열이 이동해요.", scores: [4, 3, 3], aiScores: [3, 3, 2], feedback: "금속이 열을 만드는 것이 아니라, 뜨거운 물에서 받은 열을 손잡이 쪽으로 전달한다는 점을 잘 설명했어요. 열의 이동 방향도 정확히 짚었습니다." },
  { id: "11", name: "학생 11", color: "blue", topic: "열의 이동 방향", date: "오늘 09:42", status: "pending", answer: "열은 온도가 높은 물에서 낮은 금속 숟가락 쪽으로 이동합니다. 금속은 열이 잘 지나가는 물질이라 손잡이까지 따뜻해집니다.", evidence: "온도가 높은 물에서 낮은 금속 숟가락 쪽으로 이동합니다.", aiScores: [4, 3, 3], feedback: "높은 온도에서 낮은 온도로 열이 이동한다는 설명이 정확합니다. 관찰한 현상과 금속의 열전도 성질을 연결했습니다." },
  { id: "17", name: "학생 17", color: "rose", topic: "흔한 오개념 확인", date: "오늘 10:06", status: "pending", answer: "금속이 뜨거워서 열을 만들어내고, 그 열이 손잡이에 퍼진 것 같습니다.", evidence: "금속이 뜨거워서 열을 만들어내고", aiScores: [2, 1, 3], feedback: "금속이 열을 만들어낸다고 표현했습니다. 열이 물에서 금속으로 이동하는지, 금속이 열을 생성하는지 교사가 확인해야 합니다." },
  { id: "26", name: "학생 26", color: "warm", topic: "비교와 근거", date: "오늘 10:21", status: "pending", answer: "나무 숟가락은 금속 숟가락보다 손잡이가 덜 뜨거워요. 나무는 열이 잘 이동하지 않아서 뜨거운 물의 열이 손잡이까지 천천히 전달되기 때문입니다.", evidence: "나무는 열이 잘 이동하지 않아서", aiScores: [3, 3, 2], feedback: "나무와 금속의 차이를 비교하고 물질의 성질을 근거로 들었습니다. 열이 이동하는 방향을 문장에 더해 보도록 안내하면 좋겠습니다." }
];

const quizzes = {
  before: [
    { q: "뜨거운 물에 담근 금속 숟가락의 손잡이가 따뜻해졌어요. 열은 어디에서 어디로 이동했을까요?", a: ["온도가 높은 물에서 낮은 숟가락 쪽으로", "숟가락에서 물 쪽으로만", "금속이 열을 만들어 손잡이로"], correct: 0, why: "열은 온도가 높은 곳에서 낮은 곳으로 이동해요." },
    { q: "금속 숟가락이 나무보다 빨리 따뜻해졌어요. 가장 알맞은 설명은 무엇일까요?", a: ["금속이 물의 열을 더 잘 전달해요", "금속이 열을 더 많이 만들어요", "나무에는 온도가 없어요"], correct: 0, why: "금속은 열을 잘 전달하는 물질이에요. 스스로 열을 만든다는 뜻은 아니에요." },
    { q: "나무 숟가락 손잡이가 덜 뜨거운 까닭은 무엇인가요?", a: ["나무는 열을 잘 전달하지 않아요", "나무가 물의 열을 없애요", "나무는 항상 차가워요"], correct: 0, why: "나무는 금속보다 열을 잘 전달하지 않아 손잡이까지 열이 천천히 이동해요." }
  ],
  after: [
    { q: "따뜻한 물에 넣은 금속 막대의 반대쪽도 따뜻해졌어요. 왜 그럴까요?", a: ["물에서 받은 열이 금속을 따라 이동해요", "막대 끝이 열을 새로 만들어요", "차가운 쪽의 온도가 물로 가요"], correct: 0, why: "따뜻한 물에서 받은 열에너지가 금속을 따라 전달됩니다." },
    { q: "뜨거운 냄비를 나무 손잡이로 잡으면 덜 뜨거운 까닭은 무엇인가요?", a: ["나무가 열을 천천히 전달해요", "나무가 냄비의 열을 없애요", "나무에서는 열이 반대로 움직여요"], correct: 0, why: "나무는 열을 잘 전달하지 않아 손으로 열이 오는 속도를 늦춰요." },
    { q: "‘금속이 열을 만들었다’는 문장을 과학적으로 고치면 무엇이 좋을까요?", a: ["금속이 받은 열을 손잡이 쪽으로 전달했다", "금속이 스스로 열을 만들었다", "손잡이 열이 물로 사라졌다"], correct: 0, why: "열을 만드는 것과 받은 열을 전달하는 것은 구분해야 해요." }
  ]
};

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" })[c]);
const icon = (name, cls = "icon") => `<span class="${cls}" aria-hidden="true"><svg><use href="#i-${name}"></use></svg></span>`;
const scoreSum = (scores = []) => scores.reduce((sum, value) => sum + Number(value), 0);
const scoreAverage = (scores = []) => scores.length ? scores.reduce((sum, value) => sum + Number(value), 0) / scores.length : 0;
const scoreText = (value) => Number.isInteger(value) ? String(value) : value.toFixed(1);
const studentFor = (id) => students.find((student) => student.id === String(id));
const submissionFor = (id) => submissions.find((submission) => submission.id === String(id));
const published = (submission) => submission?.status === "approved" && submission.scores;
const pendingCount = () => submissions.filter((submission) => submission.status === "pending").length;
const approvedCount = () => 23 + submissions.filter((submission) => submission.status === "approved").length;
const submittedCount = () => 28 + Math.max(0, submissions.length - 4);

function currentPageName() {
  if (data.role === "teacher") return ({ overview: "오늘의 현황", review: "서술형 검토", class: "학급 · 학생", chats: "학습 대화", files: "파일 · 보관" })[data.teacherPage];
  if (data.role === "admin") return ({ overview: "운영 현황", approvals: "교사 승인", policies: "운영 정책" })[data.adminPage];
  if (!data.student) return "학생 접속";
  return ({ login: "학생 접속", home: "이번 평가", pending: "결과 확인 대기", assessment: "평가 응시", before: "학습 전 확인", chat: "보충학습", after: "학습 후 확인", complete: "학습 완료" })[data.studentPage];
}

function navItems() {
  if (data.role === "teacher") return [
    ["overview", "오늘의 현황", "grid"], ["review", "서술형 검토", "review", pendingCount()], ["class", "학급 · 학생", "users"], ["chats", "학습 대화", "chat"], ["files", "파일 · 보관", "file"]
  ];
  if (data.role === "admin") return [["overview", "운영 현황", "grid"], ["approvals", "교사 승인", "shield", data.approvalDone ? 0 : 1], ["policies", "운영 정책", "settings"]];
  if (!data.student) return [["login", "학생 접속", "lock"]];
  return [["home", "이번 평가", "book"], ["feedback", "내 피드백", "review"], ["learning", "보충학습", "chat"]];
}

function renderNav() {
  const active = data.role === "teacher" ? data.teacherPage : data.role === "admin" ? data.adminPage : data.studentPage === "login" ? "login" : ["before", "chat", "after", "complete"].includes(data.studentPage) ? "learning" : data.studentPage === "feedback" ? "feedback" : "home";
  $("#side-nav").innerHTML = navItems().map(([id, label, symbol, count]) => `<button class="nav-link" type="button" data-nav="${id}" aria-current="${id === active ? "page" : "false"}"><span class="nav-icon"><svg aria-hidden="true"><use href="#i-${symbol}"></use></svg></span><span>${label}</span>${count ? `<span class="nav-count">${count}</span>` : ""}</button>`).join("");
}

function renderChrome() {
  const teacher = data.role === "teacher";
  const admin = data.role === "admin";
  $("#nav-label").textContent = teacher ? "교사 작업" : admin ? "서비스 운영" : data.student ? "나의 학습" : "학생 접속";
  $("#workspace-name").textContent = teacher ? "2학년 3반" : admin ? "전체 서비스" : data.student ? "2학년 3반" : "학급 코드로 접속";
  $("#crumb-parent").textContent = teacher ? "과학 수업" : admin ? "운영 콘솔" : data.student ? "나의 과학" : "학생";
  $("#crumb-current").textContent = currentPageName();
  $("#top-avatar").textContent = teacher ? "이지" : admin ? "관" : data.student?.id || "학";
  $("#profile-mini").innerHTML = teacher
    ? `<span class="profile-avatar">이지</span><span class="profile-copy"><strong>이지은 선생님</strong><small>과학 · 2학년 3반</small></span><button class="icon-button" type="button" data-action="signout" aria-label="체험 종료">${icon("logout")}</button>`
    : admin
      ? `<span class="profile-avatar">관</span><span class="profile-copy"><strong>서비스 관리자</strong><small>운영 메타데이터 전용</small></span><button class="icon-button" type="button" data-action="signout" aria-label="체험 종료">${icon("logout")}</button>`
      : data.student
        ? `<span class="profile-avatar">${esc(data.student.id)}</span><span class="profile-copy"><strong>학생 ${esc(data.student.id)}</strong><small>예시 계정 · 이 기기</small></span><button class="icon-button" type="button" data-action="student-logout" aria-label="로그아웃">${icon("logout")}</button>`
        : `<span class="profile-avatar">학</span><span class="profile-copy"><strong>학생 체험</strong><small>이메일 없이 접속</small></span>`;
  $("#sidebar-sync").innerHTML = `<div class="sync-head"><i class="sync-dot"></i>${teacher ? "시연 환경" : admin ? "운영 데이터" : "개인 학습"}</div><p>${teacher ? "학생 데이터 없이 화면 흐름만 체험합니다." : admin ? "개별 답안과 점수는 표시하지 않습니다." : data.student ? "예시 결과만 표시됩니다." : "이메일이나 연락처를 입력하지 않습니다."}</p>`;
  $("#notice-banner").hidden = data.noticeHidden;
  $("#sidebar").classList.toggle("is-open", data.sidebarOpen);
  $$('[data-role]').forEach((button) => button.setAttribute("aria-pressed", String(button.dataset.role === data.role)));
  renderNav();
}

function intro(kicker, title, description, actions = "") {
  return `<section class="page-intro"><div class="page-intro-copy"><span class="eyebrow"><i class="eyebrow-dot"></i>${kicker}</span><h1>${title}</h1><p>${description}</p></div>${actions ? `<div class="intro-actions">${actions}</div>` : ""}</section>`;
}

function button(label, action, style = "primary", symbol = "arrow", extra = "") {
  return `<button class="button button-${style}" type="button" data-action="${action}" ${extra}>${label} ${icon(symbol)}</button>`;
}

function metric(label, value, note, symbol) {
  return `<article class="metric-card"><span class="metric-icon"><svg aria-hidden="true"><use href="#i-${symbol}"></use></svg></span><div class="metric-copy"><span>${label}</span><strong>${value}</strong><small>${note}</small></div></article>`;
}

function pill(label, type = "") { return `<span class="status-pill ${type}">${label}</span>`; }

function reviewRow(item) {
  const pending = item.status === "pending";
  const criteria = pending ? item.aiScores : item.scores;
  return `<div class="review-row"><span class="student-avatar ${item.color}">${esc(item.id)}</span><span class="review-person"><strong>${esc(item.name)}</strong><span>${esc(item.topic)} · ${esc(item.date)}</span></span><span class="score-proposal"><strong>${pending ? `${scoreText(scoreAverage(criteria))} / 4` : `${scoreSum(criteria)} / 12`}</strong><small>${pending ? "AI 초안" : "교사 확정"}</small></span>${pill(pending ? "검토 대기" : "결과 공개", pending ? "" : "done")}<button class="button button-secondary button-small" type="button" data-action="open-review" data-id="${esc(item.id)}">${pending ? "검토하기" : "결과 보기"}</button></div>`;
}

function pageIntroTeacher() {
  return intro("교사 작업대 · 화요일, 11월 10일", "답안을 한 명씩 살펴봐요.", "AI는 채점 초안을 준비했고, 마지막 판단은 선생님이 합니다. 확정한 학생에게만 결과가 공개돼요.", `${button("검토 이어하기", "go-review", "primary", "review")}<button class="button button-secondary" type="button" data-action="go-class">학급 보기</button>`);
}

function renderTeacherOverview() {
  const pending = submissions.filter((item) => item.status === "pending");
  const reviewed = approvedCount();
  const submitted = submittedCount();
  const progress = Math.min(100, Math.round(reviewed / submitted * 100));
  return `${pageIntroTeacher()}<section class="metrics-strip" aria-label="평가 현황">${metric("이번 평가 응답", `${submitted} / 32`, "열전달과 물질의 성질", "book")}${metric("교사 확인 대기", pending.length, "모두 확인 후 학생별 공개", "clock")}${metric("검토 · 확정 완료", `${reviewed} / ${submitted}`, "교사 최종 결과 기준", "check")}${metric("보충학습 완료", "21명", "확인 퀴즈까지 마쳤어요", "chart")}</section>
    <section class="assessment-hero"><div class="assessment-copy"><div class="assessment-meta"><span class="tag">서술형 평가</span><span>2학년 3반</span><span>11월 17일 시작</span></div><h2>열전달과 물질의 성질</h2><p>학생들이 설명한 열의 이동 방향과 금속·나무의 열전도 차이를 확인하고 있어요.</p><div class="assessment-actions"><button class="button button-lime" type="button" data-action="go-review">답안 ${pending.length}개 검토 ${icon("arrow")}</button><button class="button button-secondary" type="button" data-action="go-files">확정 결과 내보내기</button></div></div><div class="assessment-progress"><span class="progress-title">교사 검토 진행</span><strong class="progress-number">${reviewed}<span> / ${submitted}명</span></strong><div class="progress-track" role="progressbar" aria-label="교사 검토 진행률" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${progress}"><span style="width:${progress}%"></span></div><small>${progress}% 검토 완료 · 학생별 확정 즉시 공개</small><div class="progress-legend"><span><i class="legend-dot"></i>확정 ${reviewed}</span><span><i class="legend-dot muted"></i>대기 ${pending.length}</span></div></div></section>
    <div class="content-grid" style="margin-top:16px"><div class="stack"><section class="panel panel-pad"><div class="panel-header"><div><h2>다음에 확인할 답안</h2><p>확정하면 해당 학생에게만 결과가 열립니다.</p></div><button class="text-link" type="button" data-action="go-review">전체 대기열 ${icon("arrow")}</button></div><div class="queue-summary"><span><strong>${pending.length}개</strong> 답안이 선생님 확인을 기다려요.</span><span>100% 교사 검토</span></div><div class="review-list">${pending.length ? pending.slice(0, 3).map(reviewRow).join("") : `<div class="empty-state"><span class="empty-icon">${icon("check")}</span><strong>검토 대기 답안이 없어요</strong><p>새 답안이 제출되면 이곳에서 확인할 수 있어요.</p></div>`}</div></section><section class="panel panel-pad"><div class="panel-header"><div><h2>이번 평가의 흐름</h2><p>제출부터 학습 확인까지 이어집니다.</p></div><span class="tag green">평가 중</span></div><div class="timeline">${[["book", "답안 제출", `${submitted}명 제출`, "complete"], ["spark", "AI 초안", "예시 분석 준비", "complete"], ["review", "교사 확정", `${pending.length}명 대기`, pending.length ? "current" : "complete"], ["file", "개별 결과", "확정 학생 공개", ""], ["chat", "보충학습", "전후 퀴즈 확인", ""]].map(([sym, label, sub, cls]) => `<div class="timeline-step ${cls}"><span class="timeline-node">${icon(sym)}</span><strong>${label}</strong><small>${sub}</small></div>`).join("")}</div></section></div><div class="stack"><section class="panel panel-pad"><div class="panel-header"><div><h2>학생들이 다시 보는 개념</h2><p>확정 피드백에서 모은 예시 집계</p></div><span class="tag">예시 통계</span></div>${[["열의 이동 방향", 68], ["열전도와 열 생성", 42], ["근거와 관찰", 36], ["설명 연결", 24]].map(([label, value]) => `<div class="concept-row"><div class="concept-label"><strong>${label}</strong><small>다시 살펴볼 개념</small></div><div class="concept-track"><span style="width:${value}%"></span></div><span class="concept-percent">${value}%</span></div>`).join("")}<div class="chart-note">${icon("info")}<span>집계는 교사가 확정한 결과에서 계산합니다.</span></div></section><section class="notice-card"><span class="notice-card-icon">${icon("spark")}</span><div><strong>AI 점수는 초안입니다</strong><p>교사 100% 검토 후에만 학생에게 공개돼요. 현재 점수는 모두 시연용 예시입니다.</p></div></section></div></div>`;
}

function reviewRows() {
  let rows = submissions.filter((item) => data.filter === "all" || item.status === data.filter);
  if (data.search) rows = rows.filter((item) => `${item.id} ${item.name} ${item.topic} ${item.answer}`.toLowerCase().includes(data.search.toLowerCase()));
  return rows;
}

function renderReviewPage() {
  const rows = reviewRows();
  const pending = pendingCount();
  return `${intro("교사 작업대 · 2학년 3반", "서술형 답안을 검토해요.", "AI 초안은 참고 자료입니다. 근거를 확인하고 학생별로 확정하면 해당 학생에게 결과가 공개됩니다.", `<button class="button button-secondary" type="button" data-action="go-files">${icon("download")}확정 결과 내보내기</button>`)}
  <section class="metrics-strip">${metric("검토 대기", pending, "확정 전에는 학생에게 비공개", "clock")}${metric("확정된 학생", `${approvedCount()}명`, `전체 ${submittedCount()}명 제출`, "check")}${metric("평가 기준", "3개", "개념 · 근거 · 설명 연결", "review")}${metric("교사 검토", "100%", "모든 답안을 직접 확인", "shield")}</section>
  <section class="panel panel-pad"><div class="panel-header"><div><h2>열전달과 물질의 성질</h2><p>2학년 3반 · 서술 문항 2개 · 제출 ${submittedCount()} / 32</p></div><span class="tag amber">${pending}개 확인 대기</span></div><div class="data-note" style="margin-bottom:14px">${icon("info")}<span><strong>전수 검토:</strong> 학생별 확정이 끝나야 그 학생에게 결과가 열립니다. AI 초안은 학생에게 보이지 않아요.</span></div>
  <div class="table-toolbar"><div class="filter-row" role="group" aria-label="답안 상태 필터">${[["pending", `검토 대기 ${pending}`], ["approved", `확정 완료 ${submissions.filter((s) => s.status === "approved").length}`], ["all", "전체 답안"]].map(([id, label]) => `<button class="filter-chip" type="button" data-filter="${id}" aria-pressed="${data.filter === id}">${label}</button>`).join("")}</div><label class="table-search"><span class="sr-only">학생 또는 답안 검색</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg><input id="review-search" type="search" value="${esc(data.search)}" placeholder="학생 번호·개념 검색"></label></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th scope="col">학생</th><th scope="col">답안의 핵심</th><th scope="col">평균 점수</th><th scope="col">상태</th><th scope="col"><span class="sr-only">작업</span></th></tr></thead><tbody>${rows.length ? rows.map((item) => `<tr><td><span class="student-cell"><span class="student-avatar ${item.color}">${esc(item.id)}</span>${esc(item.name)}</span></td><td class="answer-cell">${esc(item.answer.slice(0, 70))}${item.answer.length > 70 ? "…" : ""}</td><td class="score-cell">${item.status === "approved" ? `${scoreSum(item.scores)} / 12` : `${scoreText(scoreAverage(item.aiScores))} / 4`}<br><span class="microcopy">${item.status === "approved" ? "교사 확정" : "AI 제안 초안"}</span></td><td>${pill(item.status === "pending" ? "검토 대기" : "학생 공개", item.status === "pending" ? "" : "done")}</td><td class="action-cell"><button class="button button-secondary button-small" type="button" data-action="open-review" data-id="${esc(item.id)}">${item.status === "pending" ? "답안 검토" : "결과 확인"}</button></td></tr>`).join("") : `<tr><td colspan="5"><div class="empty-state"><span class="empty-icon">${icon("review")}</span><strong>조건에 맞는 답안이 없어요</strong><p>필터를 바꾸거나 다른 학생 번호를 검색해 보세요.</p></div></td></tr>`}</tbody></table></div><div class="panel-footer"><p class="microcopy">AI 제안 결과와 교사 최종 결과는 분리해 기록합니다. 모든 답안은 가상 데이터입니다.</p><span class="section-label">${rows.length}명 표시</span></div></section>`;
}

function renderClassPage() {
  return `${intro("학급 관리 · 시연 명단", "학급과 학생을 살펴봐요.", "학급 코드는 입구일 뿐이에요. 학생마다 다른 PIN으로 본인 결과를 보호합니다.", `<button class="button button-secondary" type="button" data-action="pin-sheet">${icon("key")}PIN 배부표 미리보기</button>`)}<div class="content-grid"><section class="panel panel-pad"><div class="panel-header"><div><h2>2학년 3반 · 과학</h2><p>전체 32명 · 데모 명단 ${students.length}명</p></div><span class="tag green">담당 교사</span></div><div class="data-note" style="margin-bottom:13px">${icon("lock")}<span><strong>PIN 안전 안내:</strong> 기존 PIN은 다시 확인하지 않아요. 분실하면 새 번호를 발급하고 이전 PIN과 세션을 무효화해요.</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>학생</th><th>출석번호</th><th>평가 상태</th><th>점수</th><th>PIN 관리</th></tr></thead><tbody>${students.map((student) => { const sub = submissionFor(student.id); return `<tr><td><span class="student-cell"><span class="student-avatar ${student.color}">${student.id}</span>${student.name}</span></td><td>${student.id}번</td><td>${pill(!sub ? "미제출" : sub.status === "approved" ? "결과 공개" : "검토 대기", !sub ? "neutral" : sub.status === "approved" ? "done" : "")}</td><td>${sub?.status === "approved" ? `${scoreSum(sub.scores)} / 12` : "—"}</td><td class="action-cell"><button class="button button-ghost button-small" type="button" data-action="reissue-pin" data-id="${student.id}">${icon("refresh")}재발급</button></td></tr>`; }).join("")}</tbody></table></div></section><div class="stack"><section class="panel panel-pad"><div class="panel-header"><div><h2>학생 접속 안내</h2><p>공개 코드와 개인 인증을 분리해요.</p></div></div><div class="retention-date"><span>학급 코드</span><strong>SCI-26-03</strong></div><div class="file-check-list"><div class="file-check">${icon("check")}학생은 출석번호와 개인 PIN을 함께 입력해요.</div><div class="file-check">${icon("check")}PIN을 잊으면 본인 확인 후 새 번호를 발급해요.</div><div class="file-check">${icon("check")}배부 뒤 인쇄물과 다운로드 사본을 파기해요.</div></div><button class="button button-secondary button-small" style="margin-top:15px" type="button" data-action="copy-code">학급 코드 복사 ${icon("file")}</button></section><section class="notice-card"><span class="notice-card-icon">${icon("info")}</span><div><strong>4자리 PIN은 간편 인증이에요</strong><p>실제 운영에는 시도 제한, 점진 지연과 안전한 배부 절차가 필요합니다.</p></div></section></div></div>`;
}

function renderFilesPage() {
  return `${intro("결과 내보내기 · 담당 교사 책임", "파일은 승인된 학교 위치에 보관해요.", "내보내기는 교사가 확정한 개인 평가 결과와 피드백만 포함합니다.")}<div class="file-policy-grid"><section class="panel panel-pad"><div class="panel-header"><div><h2>개인 결과 내보내기</h2><p>${submissions.filter((s) => s.status === "approved").length}건의 확정 예시 결과 · 읽기용 사본</p></div><span class="tag green">DB 역반영 없음</span></div><div class="file-type-card"><span class="file-icon">${icon("file")}</span><div class="file-type-copy"><strong>CSV · 스프레드시트용</strong><p>학생 이름, 평가명, 교사 확정 점수와 피드백만 담습니다.</p></div></div><div class="file-type-card"><span class="file-icon">${icon("print")}</span><div class="file-type-copy"><strong>개인 결과 이미지 · 인쇄</strong><p>학생 본인의 최종 결과만 포함하며 PIN, AI 초안, 원문 답안, 챗봇 대화는 제외합니다.</p></div></div><div class="divider"></div><div class="form-field"><label for="file-location">학교 승인 로컬 저장 위치</label><input id="file-location" value="${esc(data.fileLocation)}" placeholder="예: 학교 관리 PC / 교무 전용 폴더"></div><label class="ack-row"><input id="file-ack" type="checkbox" ${data.fileConfirmed ? "checked" : ""}><span>학기 초 확인한 학교 승인 위치이며, 접근 권한·보관 기간·파기 책임을 안내했습니다.</span></label><div class="export-actions"><button class="button button-secondary" type="button" data-action="save-location">${icon("check")}위치 확인</button><button class="button button-primary" type="button" data-action="export-csv" ${data.fileConfirmed ? "" : "disabled"}>${icon("download")}확정 결과 CSV 받기</button></div><p class="microcopy" style="margin:10px 0 0">이 데모는 브라우저에서 예시 CSV를 만듭니다. 실제 서비스는 서버에서 권한을 다시 확인해야 합니다.</p></section><div class="stack"><section class="panel panel-pad"><div class="panel-header"><div><h2>학기별 관리 약속</h2><p>담당 교사가 1차 책임자입니다.</p></div></div><div class="retention-date"><span>상세 대화 삭제</span><strong>학기 종료 시</strong></div><div class="retention-date" style="margin-top:8px"><span>개인기록 기본 파기</span><strong>2월 말일</strong></div><div class="file-check-list"><div class="file-check">${icon("check")}개인 기기나 공유 메신저에는 보관하지 않아요.</div><div class="file-check">${icon("check")}공식 기록은 학교 보존 일정을 따릅니다.</div><div class="file-check">${icon("check")}학생 기기 파일은 원격으로 회수할 수 없어요.</div></div></section><div class="data-note">${icon("info")}<span>학교가 공식 기록으로 보존해야 할 기간은 학교 개인정보 보호책임자와 확인해 주세요.</span></div></div></div>`;
}

function renderTeacherChats() {
  const entries = Object.entries(data.chatLogs).filter(([, messages]) => messages?.length);
  return `${intro("교사 작업대 · 학기 종료 전까지 열람", "학생의 학습 대화를 살펴봐요.", "담당 학급 학생의 상세 대화는 학기 종료까지 확인할 수 있어요. 14일 미접속이면 학습 세션은 닫히지만, 대화 기록은 학기 종료 때 삭제됩니다.")}<section class="panel panel-pad"><div class="panel-header"><div><h2>보충학습 대화 기록</h2><p>학기 종료 예정 · 2026년 12월 20일 · 열람 시각을 감사 기록에 남기는 데모</p></div><span class="tag green">${entries.length}개 대화</span></div><div class="data-note" style="margin-bottom:14px">${icon("eye")}<span>상세 대화는 교사만 볼 수 있습니다. 관리자 화면에는 대화 내용이 표시되지 않으며, 학기 종료 시 대화를 삭제합니다.</span></div>${entries.length ? `<div class="review-list">${entries.map(([id, messages]) => { const student = studentFor(id); return `<div class="review-row"><span class="student-avatar ${student?.color || ""}">${esc(id)}</span><span class="review-person"><strong>${esc(student?.name || `학생 ${id}`)}</strong><span>열전달과 물질의 성질 · 최근 활동 예시 · ${messages.length}개 메시지</span></span>${pill("교사 열람 가능", "info")}<button class="button button-secondary button-small" type="button" data-action="open-chat-log" data-id="${esc(id)}">대화 보기</button></div>`; }).join("")}</div>` : `<div class="empty-state"><span class="empty-icon">${icon("chat")}</span><strong>아직 대화 기록이 없어요</strong><p>학생이 보충학습에서 대화를 시작하면 이곳에 표시됩니다.</p></div>`}<div class="panel-footer"><p class="microcopy">학생은 챗봇 하단에서 교사의 열람 기간과 학기말 삭제 시점을 안내받습니다.</p><span class="section-label">학기 종료 시 삭제</span></div></section>`;
}

function renderTeacher() {
  if (data.teacherPage === "review") return renderReviewPage();
  if (data.teacherPage === "class") return renderClassPage();
  if (data.teacherPage === "chats") return renderTeacherChats();
  if (data.teacherPage === "files") return renderFilesPage();
  return renderTeacherOverview();
}

function legacyAdminOverview() {
  const checkCount = Object.values(data.checks).filter(Boolean).length;
  return `${intro("운영 콘솔 · 메타데이터 전용", "서비스 운영 상태를 확인해요.", "관리자 화면에는 계정·연동·사용량·작업 상태만 표시합니다. 개별 학생 답안과 점수는 열리지 않습니다.", `<button class="button button-secondary" type="button" data-nav="policies">${icon("settings")}운영 기준 보기</button>`)}<section class="admin-stats"><article class="admin-stat"><span>참여 학교</span><strong>3</strong><small>예시 데이터</small></article><article class="admin-stat"><span>승인 교사</span><strong>18</strong><small>1명 확인 대기</small></article><article class="admin-stat"><span>활성 평가</span><strong>12</strong><small>이번 학기</small></article><article class="admin-stat"><span>AI 예산 사용</span><strong>72%</strong><small>한도까지 28%</small></article></section><div class="admin-grid"><div class="stack"><section class="panel panel-pad"><div class="panel-header"><div><h2>연동과 처리 상태</h2><p>실제 연결이 아닌 화면 예시입니다.</p></div><span class="tag amber">데모</span></div>${[["flask", "Supabase DB", "데모 환경 · 실제 연결 전", "예시 상태", "done"], ["spark", "Upstage API", "키 미설정 · 요청 없음", "미연결", "neutral"], ["file", "자동 백업", "프로젝트 설정 전", "확인 필요", ""], ["clock", "삭제 작업", "대기 작업 없음 · 예시", "예시 확인", "done"]].map(([sym, title, sub, status, cls]) => `<div class="service-row"><span class="service-info"><span class="service-symbol">${icon(sym)}</span><span class="service-copy"><strong>${title}</strong><small>${sub}</small></span></span>${pill(status, cls)}</div>`).join("")}<div class="budget-wrap"><div class="budget-head"><span>이번 달 AI 예산 예시</span><strong>72% 사용</strong></div><div class="budget-bar" role="progressbar" aria-valuenow="72" aria-valuemin="0" aria-valuemax="100" aria-label="예산 사용 예시"><span></span></div><p>실제 운영은 70%·90% 알림과 한도 도달 시 차단을 설정합니다. 이 데모는 AI를 호출하지 않습니다.</p></div></section><section class="panel panel-pad"><div class="panel-header"><div><h2>최근 운영 이벤트</h2><p>학생 콘텐츠가 아닌 메타데이터 예시</p></div></div><div class="service-row"><span class="service-info"><span class="service-symbol">${icon("shield")}</span><span class="service-copy"><strong>개별 답안 접근</strong><small>기본 관리자 권한에서 제외</small></span></span>${pill("제한됨", "done")}</div><div class="service-row"><span class="service-info"><span class="service-symbol">${icon("download")}</span><span class="service-copy"><strong>개인 결과 내보내기</strong><small>관리자 개인 데이터 접근 없음</small></span></span>${pill("0건", "neutral")}</div></section></div><div class="stack"><section class="panel panel-pad"><div class="panel-header"><div><h2>교사 승인 대기</h2><p>학교 소속 확인 후 활성화합니다.</p></div>${pill(data.approvalDone ? "확인 완료" : "1건 확인 필요", data.approvalDone ? "done" : "")}</div>${data.approvalDone ? `<div class="empty-state"><span class="empty-icon">${icon("check")}</span><strong>승인 요청을 확인했어요</strong><p>이 화면의 상태는 데모 예시입니다.</p></div>` : `<div class="approval-card"><span class="approval-details"><span class="student-avatar">교</span><span><strong>시범 교사 · 과학</strong><small>학교 이메일 확인 예시</small></span></span><button class="button button-primary button-small" type="button" data-action="approve-teacher">승인 확인</button></div>`}<div class="data-note" style="margin-top:12px">${icon("info")}<span>확인 결과·일자·검토자만 기록하고 증빙 원본은 불필요하게 보관하지 않도록 합니다.</span></div></section><section class="panel panel-pad"><div class="panel-header"><div><h2>파일럿 준비 기준</h2><p>운영 메타데이터 체크 · ${checkCount} / 4</p></div></div><div class="policy-list">${[["retention", "보관 기간·삭제 일정", "학기말 대화·2월말 개인기록"], ["vendor", "AI 업체 처리 조건", "학습 미사용·보관·처리 국가"], ["backup", "백업 복구 절차", "삭제 요청 재적용 뒤 복구"], ["access", "역할별 권한 시험", "학생·교사·관리자 범위"]].map(([id, title, sub]) => `<button class="policy-item" type="button" data-check="${id}" aria-pressed="${data.checks[id]}"><span class="policy-check">${icon("check")}</span><span class="policy-copy"><strong>${title}</strong><small>${sub}</small></span><span class="policy-state">${data.checks[id] ? "확인됨" : "미확인"}</span></button>`).join("")}</div></section></div></div>`;
}

function legacyAdminView() {
  if (data.adminPage === "approvals") return `${intro("운영 콘솔 · 교사 권한", "교사 소속을 확인해요.", "학교 확인을 마친 계정만 학급과 평가 기능을 사용할 수 있습니다.")}<section class="panel panel-pad"><div class="panel-header"><div><h2>승인 요청</h2><p>이 화면에서는 학생 답안이나 점수를 볼 수 없습니다.</p></div>${pill(data.approvalDone ? "대기 없음" : "1건 확인 필요", data.approvalDone ? "done" : "")}</div>${data.approvalDone ? `<div class="empty-state"><span class="empty-icon">${icon("check")}</span><strong>확인했어요</strong><p>예시 상태이며 실제 계정 활성화는 서버에서 처리해야 합니다.</p></div>` : `<div class="approval-card"><span class="approval-details"><span class="student-avatar">교</span><span><strong>시범 교사 · 과학</strong><small>학교 이메일 확인 예시 · 사용 예정 11월 24일</small></span></span><button class="button button-primary button-small" type="button" data-action="approve-teacher">확인 후 승인</button></div>`}<div class="divider"></div><div class="data-note">${icon("shield")}<span>Super Admin은 확인 결과·날짜·검토자만 기록하는 방식을 권장합니다.</span></div></section>`;
  if (data.adminPage === "policies") return `${intro("운영 콘솔 · 준비 체크", "운영 원칙을 점검해요.", "체크 상태는 이 브라우저의 시연 상태만 변경합니다.")}<div class="content-grid"><section class="panel panel-pad"><div class="panel-header"><div><h2>파일럿 전 확인</h2><p>모든 항목은 실제 운영 전 검증이 필요합니다.</p></div></div><div class="policy-list">${[["retention", "보관·삭제 일정", "학기말 대화 삭제와 2월 말 개인기록 파기"], ["vendor", "Upstage 처리 조건", "학습 미사용·보관·처리 국가"], ["backup", "백업과 삭제 재적용", "복구 후 삭제 요청을 다시 반영"], ["access", "RLS 권한 검사", "학생 본인·담당 교사·관리자 범위"]].map(([id, title, sub]) => `<button class="policy-item" type="button" data-check="${id}" aria-pressed="${data.checks[id]}"><span class="policy-check">${icon("check")}</span><span class="policy-copy"><strong>${title}</strong><small>${sub}</small></span><span class="policy-state">${data.checks[id] ? "확인됨" : "미확인"}</span></button>`).join("")}</div></section><div class="stack"><div class="notice-card"><span class="notice-card-icon">${icon("lock")}</span><div><strong>최소 권한</strong><p>개별 답안·점수·대화는 기본 관리자 화면에서 보이지 않습니다.</p></div></div><section class="panel panel-pad"><div class="panel-header"><div><h2>학년도 말 일정</h2><p>학교 기록 기준 확인 필요</p></div></div><div class="retention-date"><span>기본 파기 예정</span><strong>2월 말일</strong></div><p class="microcopy">모든 학교의 법적 공통 파기일은 아니므로 담당자 확인이 필요합니다.</p></section></div></div>`;
  return renderAdminOverview();
}

function renderLogin() {
  return `${intro("학생 접속 · 이메일 불필요", "내 평가에서 이어서 시작해요.", "학급 코드와 출석번호, 나만의 PIN으로 접속합니다. 이 데모는 예시 계정만 사용합니다.")}<section class="student-login-wrap"><div class="login-layout"><div class="login-story"><div><span class="login-stamp">${icon("flask")}과학 학습 여정</span><h2>틀린 답은,<br>다음 질문의 시작.</h2><p>내 답을 돌아보고, 선생님 피드백을 읽고, 이해될 때까지 작은 질문으로 다시 배워요.</p></div><div class="login-steps"><span><b>01</b> 평가</span><span><b>02</b> 피드백</span><span><b>03</b> 보충학습</span></div></div><div class="login-side"><span class="eyebrow">학생용 접속</span><h3>학급을 확인해요</h3><p>이메일이나 전화번호는 받지 않습니다.</p><form id="student-login-form" novalidate><div class="form-field"><label for="class-code">학급 코드</label><input id="class-code" value="SCI-26-03" autocomplete="off" required></div><div class="form-field"><label for="attendance-number">출석번호</label><input id="attendance-number" value="04" inputmode="numeric" autocomplete="off" required></div><div class="form-field"><label for="student-pin">나의 PIN 4자리</label><input class="pin-input" id="student-pin" value="4921" type="password" inputmode="numeric" maxlength="4" autocomplete="off" required></div><p class="login-error" id="login-error" role="alert"></p><button class="button button-primary login-submit" type="submit">안내 확인 후 접속 ${icon("arrow")}</button></form><div class="sample-credentials">${icon("info")}<span><strong>예시 계정:</strong> 학생 04 / PIN 4921은 피드백, 학생 12 / PIN 7364는 평가 응시용이에요.</span></div><div class="button-row" style="justify-content:flex-start;margin-top:8px"><button class="text-link" type="button" data-action="fill-account" data-id="04">피드백 예시 채우기 ${icon("arrow")}</button><button class="text-link" type="button" data-action="fill-account" data-id="12">응시 예시 채우기 ${icon("arrow")}</button></div><p class="login-footnote">PIN을 잊으면 수업 중 선생님께 말씀해 새 번호를 요청해요.</p></div></div></section>`;
}

function feedbackCard(item) {
  const points = scoreSum(item.scores || []);
  const percent = Math.round(points / 12 * 100);
  return `<div class="feedback-topline"><strong>열전달과 물질의 성질 · 11월 17일</strong>${pill("교사 확인 완료", "done")}</div><div class="feedback-body"><div class="score-orbit" style="--score:${percent}%" role="img" aria-label="총점 ${points}점, ${percent}퍼센트"><strong>${percent}</strong><small>%</small></div><div class="feedback-summary"><h3>내 설명을 돌아봐요</h3><p>선생님이 답안을 직접 읽고 남긴 최종 피드백이에요.</p><div class="topic-pills"><span class="tag green">열의 이동 이해</span><span class="tag amber">열전도 구분</span><span class="tag blue">근거 설명하기</span></div></div></div><div class="feedback-lower"><div class="feedback-note"><strong>잘 이해한 개념</strong><p>열은 온도가 높은 곳에서 낮은 곳으로 이동해요.</p></div><div class="feedback-note need"><strong>다시 살펴볼 개념</strong><p>물질이 열을 만드는 것과 열을 전달하는 것은 달라요.</p></div></div><div class="feedback-actions"><button class="button button-secondary button-small" type="button" data-action="save-image">${icon("download")}결과 이미지 저장</button><button class="button button-secondary button-small" type="button" data-action="print-result">${icon("print")}인쇄</button><span class="microcopy">최종 점수 ${points} / 12</span></div>`;
}

function renderStudentHome() {
  const submission = submissionFor(data.student.id);
  if (submission && submission.status === "pending") return renderPendingHome(submission);
  if (!submission) return renderNewAssessmentHome();
  const complete = data.finished;
  return `${intro(`나의 과학 · 학생 ${data.student.id}`, "선생님 피드백을 확인했어요.", "다시 배울 개념을 찾았어요. 점수보다 중요한 것은 무엇을 이해했고, 무엇을 더 알아볼지 확인하는 거예요.", `<button class="button button-ghost" type="button" data-action="student-logout">${icon("logout")}로그아웃</button>`)}<div class="student-welcome"><div class="student-welcome-copy"><span class="eyebrow">과학 단원 평가 · 열전달</span><h2>내 설명을 한 번 더 살펴봐요.</h2><p>선생님이 확정한 피드백에서 시작해 취약 개념을 다시 연습해요.</p></div><div class="welcome-progress"><strong>${complete ? "완료" : "1 / 3"}</strong><span>${complete ? "보충학습을 마쳤어요" : "학습 단계 진행"}</span></div></div><div class="student-step-grid"><div class="student-step-card complete"><span class="student-step-icon">${icon("check")}</span><span class="student-step-copy"><strong>평가 제출</strong><small>완료</small></span></div><div class="student-step-card complete"><span class="student-step-icon">${icon("review")}</span><span class="student-step-copy"><strong>선생님 피드백</strong><small>내 결과 공개됨</small></span></div><div class="student-step-card ${complete ? "complete" : "active"}"><span class="student-step-icon">${icon(complete ? "check" : "chat")}</span><span class="student-step-copy"><strong>보충학습</strong><small>${complete ? "완료했어요" : "내가 고른 속도로"}</small></span></div></div><div class="student-home-grid"><section class="feedback-card" id="feedback-card">${feedbackCard(submission)}</section><div class="stack">${complete ? `<section class="panel panel-pad"><div class="panel-header"><div><h2>보충학습 완료</h2><p>확인 퀴즈 결과</p></div>${pill("완료", "done")}</div><div class="retention-date"><span>학습 전</span><strong>${data.beforeScore} / 3</strong></div><div class="retention-date" style="margin-top:8px"><span>학습 후</span><strong>${data.afterScore} / 3</strong></div></section>` : `<section class="learning-launch"><span class="illustration">${icon("spark")}</span><h3>AI 선생님과 보충학습</h3><p>먼저 내 생각을 확인하고, 짧은 질문으로 개념을 다시 살펴봐요.</p><button class="button button-primary" type="button" data-action="start-learning">학습 시작하기 ${icon("arrow")}</button></section>`}<section class="panel panel-pad"><div class="panel-header"><div><h2>나의 학습 약속</h2><p>안전하고 편안한 보충학습</p></div></div><div class="file-check-list"><div class="file-check">${icon("check")}대화는 AI 모델 학습에 사용하지 않아요.</div><div class="file-check">${icon("eye")}담당 선생님은 학기 종료까지 볼 수 있어요.</div><div class="file-check">${icon("clock")}14일 동안 접속이 없으면 학습은 닫혀요.</div></div></section></div></div>`;
}

function renderPendingHome(submission) {
  return `${intro(`나의 과학 · 학생 ${data.student.id}`, "답안을 제출했어요.", "선생님이 답안을 확인하고 확정하면 나에게만 결과가 공개돼요.", `<button class="button button-ghost" type="button" data-action="student-logout">${icon("logout")}로그아웃</button>`)}<div class="student-welcome"><div class="student-welcome-copy"><span class="eyebrow">제출 완료 · 교사 확인 중</span><h2>내 답안은 안전하게 제출됐어요.</h2><p>교사가 직접 검토하고 확정하면 점수와 피드백이 열립니다.</p></div><div class="welcome-progress"><strong>검토 중</strong><span>AI 초안은 비공개</span></div></div><div class="student-step-grid"><div class="student-step-card complete"><span class="student-step-icon">${icon("check")}</span><span class="student-step-copy"><strong>평가 제출</strong><small>완료</small></span></div><div class="student-step-card active"><span class="student-step-icon">${icon("clock")}</span><span class="student-step-copy"><strong>선생님 검토</strong><small>확정 후 결과 공개</small></span></div><div class="student-step-card"><span class="student-step-icon">${icon("chat")}</span><span class="student-step-copy"><strong>보충학습</strong><small>피드백 뒤 시작</small></span></div></div><div class="content-grid"><section class="panel panel-pad"><div class="panel-header"><div><h2>제출한 답안</h2><p>열전달과 물질의 성질 · 서술 문항</p></div>${pill("교사 확인 대기")}</div><div class="answer-paper"><p>${esc(submission.answer)}</p></div><div class="data-note" style="margin-top:12px">${icon("lock")}<span>교사 확정 전에는 AI 점수 초안과 내부 채점 정보를 볼 수 없어요.</span></div></section><section class="notice-card"><span class="notice-card-icon">${icon("clock")}</span><div><strong>교사 확정 후 열립니다</strong><p>다른 학생의 검토 상태와 관계없이 내 결과가 확정되면 바로 확인할 수 있어요.</p><button class="text-link" type="button" data-role="teacher" style="margin-top:7px">교사 화면에서 검토하기 ${icon("arrow")}</button></div></section></div>`;
}

function renderNewAssessmentHome() {
  return `${intro(`나의 과학 · 학생 ${data.student.id}`, "이번 평가를 시작해 볼까요?", "서술형 답안을 제출하면 선생님이 직접 검토하고 결과를 알려드려요.", `<button class="button button-ghost" type="button" data-action="student-logout">${icon("logout")}로그아웃</button>`)}<div class="student-welcome"><div class="student-welcome-copy"><span class="eyebrow">과학 단원 평가 · 열전달</span><h2>생각을 문장으로 설명해 봐요.</h2><p>정답뿐 아니라 어떻게 생각했는지 이유와 근거를 적어 주세요.</p></div><div class="welcome-progress"><strong>약 8분</strong><span>서술형 1문항</span></div></div><div class="student-step-grid"><div class="student-step-card active"><span class="student-step-icon">${icon("book")}</span><span class="student-step-copy"><strong>평가 응시</strong><small>아직 제출 전이에요</small></span></div><div class="student-step-card"><span class="student-step-icon">${icon("review")}</span><span class="student-step-copy"><strong>교사 피드백</strong><small>제출 뒤 확인</small></span></div><div class="student-step-card"><span class="student-step-icon">${icon("chat")}</span><span class="student-step-copy"><strong>보충학습</strong><small>결과와 함께 시작</small></span></div></div><section class="panel panel-pad"><div class="panel-header"><div><h2>열전달 확인 평가</h2><p>서술형 문항 1개 · 제출 후 교사 전수 검토</p></div><span class="tag green">응시 가능</span></div><h3 class="question-prompt" style="font-size:18px">뜨거운 물에 담근 금속 숟가락의 손잡이도 따뜻해지는 까닭을, 열이 이동하는 방향과 금속의 성질을 포함해 설명해 보세요.</h3><p class="microcopy">AI 채점은 초안으로만 사용하고 선생님이 최종 확인합니다.</p><button class="button button-primary" type="button" data-action="start-assessment">평가 시작하기 ${icon("arrow")}</button></section>`;
}

function renderAssessment() {
  const length = data.answer.trim().length;
  const progress = Math.min(100, Math.round(length / 120 * 100));
  return `${intro("학생 응시 · 열전달", "내 생각을 설명해요.", "정답 단어만 찾기보다 어떻게 알게 됐는지 이유와 근거를 적어 보세요.")}<section class="assessment-card"><div class="assessment-card-top"><div><span class="tag green">서술형 · 1문항</span><h2>열전달과 물질의 성질</h2><p>2학년 과학 · 예상 8분</p></div><span class="tag">작성 중 자동 저장 예시</span></div><p class="assessment-question">문항 1 · 설명하기</p><h3 class="question-prompt">뜨거운 물에 담근 금속 숟가락의 손잡이도 따뜻해지는 까닭을, 열이 이동하는 방향과 금속의 성질을 포함해 설명해 보세요.</h3><div class="data-note" style="margin-bottom:12px">${icon("info")}<span>선생님이 AI 초안을 확인하고 최종 확정한 뒤에만 결과가 공개돼요.</span></div><div class="form-field"><label for="assessment-answer">나의 설명</label><textarea id="assessment-answer" placeholder="예: 열은 ... 에서 ... 로 이동하고, 금속은 ...">${esc(data.answer)}</textarea></div><div class="answer-help"><span>내 말로 설명해도 괜찮아요. 특별한 문장 틀은 필요 없어요.</span><span class="answer-meter"><span id="answer-length">${length}자</span><span class="answer-meter-bar"><span id="answer-progress" style="width:${progress}%"></span></span></span></div><div class="assessment-card-footer"><span class="autosave-note">${icon("check")}답안은 이 데모 화면 안에만 임시 보관됩니다.</span><div class="button-row"><button class="button button-secondary" type="button" data-action="student-home">나가기</button><button class="button button-primary" id="submit-answer" type="button" data-action="submit-assessment" ${length < 15 ? "disabled" : ""}>답안 제출 ${icon("arrow")}</button></div></div></section>`;
}

function renderQuiz() {
  const kind = data.studentPage === "before" ? "before" : "after";
  const questions = quizzes[kind];
  const question = questions[data.quizIndex];
  const selected = data.selected;
  const answered = selected !== null;
  const correct = selected === question.correct;
  const hint = "먼저 온도가 높은 쪽과 낮은 쪽을 찾아보세요. 물질이 열을 만드는지, 받은 열을 옮기는지도 구분해요.";
  return `${intro(`보충학습 · ${kind === "before" ? "학습 전 확인" : "학습 후 확인"}`, kind === "before" ? "먼저, 지금 생각을 확인해요." : "새로운 문제로 다시 확인해요.", kind === "before" ? "틀려도 괜찮아요. 어떤 설명이 필요한지 찾는 짧은 확인이에요." : "같은 문제를 반복하지 않고, 배운 개념을 다른 상황에 적용해 봐요.")}<section class="quiz-card"><div class="quiz-progress-head"><span>${kind === "before" ? "학습 전" : "학습 후"} · ${data.quizIndex + 1} / ${questions.length}</span><strong>${Math.round((data.quizIndex + (answered && correct ? 1 : 0)) / questions.length * 100)}%</strong></div><div class="quiz-progress-line">${questions.map((_, index) => `<span class="${index < data.quizIndex || index === data.quizIndex && answered && correct ? "done" : ""}"></span>`).join("")}</div><span class="eyebrow"><i class="eyebrow-dot"></i>개념 이해 확인</span><h2>${question.q}</h2><div class="quiz-options" role="group" aria-label="답 선택">${question.a.map((answer, index) => `<button class="quiz-option ${answered && correct && index === question.correct ? "correct" : ""} ${answered && index === selected && !correct ? "incorrect" : ""}" type="button" data-option="${index}" aria-pressed="${selected === index}"><span class="option-letter">${String.fromCharCode(65 + index)}</span><span>${answer}</span></button>`).join("")}</div><div class="quiz-feedback ${answered ? correct ? "success" : "try-again" : data.quizHint ? "try-again" : ""}" role="status">${answered && correct ? `맞았어요. ${question.why}` : answered ? "아직 정답은 공개하지 않을게요. 아래 힌트를 보고 다시 생각해 보세요." : data.quizHint ? `힌트: ${hint}` : "하나를 골라 보세요. 선택한 뒤 이유도 살펴볼게요."}</div><div class="quiz-footer"><span class="microcopy">${kind === "before" ? "학습 전 이해를 확인해요." : "학습 전과 다른 문제로 비교해요."}</span><button class="button button-primary" type="button" data-action="quiz-next" ${!answered ? "disabled" : ""}>${answered && !correct ? "힌트 보고 다시 시도" : data.quizIndex === questions.length - 1 ? kind === "before" ? "선생님과 대화하기" : "학습 결과 보기" : "다음 문제"} ${icon("arrow")}</button></div></section>`;
}

function heatDiagram() {
  return `<div class="concept-visual" role="img" aria-label="뜨거운 물에서 금속 손잡이 쪽으로 열이 이동하는 개념도"><svg viewBox="0 0 360 170" xmlns="http://www.w3.org/2000/svg"><defs><marker id="heat-arrow" markerWidth="8" markerHeight="8" refX="6" refY="3" orient="auto"><path d="M0 0 6 3 0 6" fill="none" stroke="#c1743c" stroke-width="1.5"/></marker></defs><path d="M84 80h179" stroke="#597a60" stroke-width="12" stroke-linecap="round"/><path d="M86 80h171" stroke="#b8c8aa" stroke-width="5" stroke-linecap="round"/><path d="M107 66c11-16 10-31 8-42M136 65c10-15 9-28 7-38M165 64c9-15 8-26 6-36" stroke="#cf8251" stroke-width="2" stroke-dasharray="3 5" fill="none"/><path d="M99 111h112" stroke="#c1743c" stroke-width="2" marker-end="url(#heat-arrow)"/><circle cx="101" cy="80" r="18" fill="#f7ddbf" stroke="#dfa16a"/><text x="78" y="143" fill="#7a7460" font-size="11" font-family="sans-serif">뜨거운 물</text><text x="224" y="143" fill="#7a7460" font-size="11" font-family="sans-serif">손잡이 쪽</text><text x="136" y="130" fill="#a45e36" font-size="10" font-family="sans-serif">열이 이동하는 방향</text></svg></div>`;
}

function tutorReply(message) {
  const text = message.toLowerCase();
  if (text.includes("모르")) return "괜찮아요. 작은 단서부터 볼게요. 물과 숟가락 중 어느 쪽의 온도가 더 높았나요? 열은 어느 쪽에서 어느 쪽으로 움직일 수 있을까요?";
  if (text.includes("이동") || text.includes("전달")) return "좋은 단서를 찾았어요. 열이 물에서 숟가락으로 이동한다면, 금속은 그 열을 어떤 역할로 손잡이까지 보내 주는 걸까요?";
  if (text.includes("나무")) return "나무와 금속을 비교했군요. 나무 손잡이가 덜 뜨거웠다면, 열을 더 빠르게 전달하는 재료는 어느 쪽일까요?";
  if (text.includes("높") || text.includes("뜨거운 물")) return "맞아요, 물의 온도가 더 높았어요. 그럼 열은 어느 쪽으로 이동했을까요? 금속은 열을 만드는 재료일까요, 받은 열을 전달하는 재료일까요?";
  return "생각을 나눠줘서 고마워요. 지금 말한 내용에서 관찰한 결과와 과학 원리를 하나씩 찾아볼까요? 금속의 성질이 손잡이에 어떤 영향을 주었나요?";
}

function renderChat() {
  const first = data.chat.length === 0;
  const messages = first ? `<span class="chat-time">지금부터 보충학습을 시작해요</span><div class="chat-message tutor">금속 숟가락 손잡이가 따뜻해지는 까닭을 생각해 볼까요? 먼저 물과 숟가락 중 어디의 온도가 더 높았나요?<small>이어봄 과학 선생님 · 예시 응답</small></div>` : data.chat.map((message) => `<div class="chat-message ${message.role}">${esc(message.text)}<small>${message.role === "student" ? "나" : "이어봄 과학 선생님 · 예시 응답"}</small></div>`).join("");
  return `${intro("보충학습 · 개념 대화", "작은 질문부터 같이 풀어봐요.", "답을 바로 알려주기보다, 생각을 한 단계씩 확인해요.")}<div class="chat-layout"><section class="chat-panel" aria-label="보충학습 대화"><div class="chat-panel-head"><span class="chat-tutor"><span class="tutor-mark">${icon("spark")}</span><span><strong>이어봄 과학 선생님</strong><small><i></i> 열전도와 열의 이동</small></span></span><span class="tag amber">예시 응답 · AI 미연결</span></div><div class="chat-messages" id="chat-messages" aria-live="polite">${messages}${first ? `<div class="chat-prompt-row"><button class="chat-prompt" type="button" data-chat-prompt="뜨거운 물이 더 뜨거워요">힌트: 온도 차이 생각하기</button><button class="chat-prompt" type="button" data-chat-prompt="모르겠어요">모르겠어요</button></div>` : ""}</div><form class="chat-composer" id="chat-form"><label class="sr-only" for="chat-input">선생님에게 답하기</label><textarea id="chat-input" rows="1" maxlength="500" placeholder="내 생각을 적어 보세요"></textarea><button class="chat-send" type="submit" aria-label="답변 보내기">${icon("send")}</button></form><div class="chat-disclaimer"><strong>AI 학습 미사용:</strong> 답안과 대화는 AI 모델 학습·개선에 사용하지 않아요. 답변에 필요한 내용은 Upstage로 전달될 수 있고, 담당 교사는 상세 대화를 학기 종료까지 확인할 수 있어요. 14일 미접속 시 학습은 닫히지만 대화는 학기 종료까지 보관돼요.</div></section><aside class="learning-sidebar"><section class="panel panel-pad"><div class="panel-header"><div><h2>이번 학습 목표</h2><p>내 피드백에서 이어왔어요</p></div><span class="tag green">개인 학습</span></div><div class="learning-goal"><span class="goal-symbol">${icon("flask")}</span><span><strong>열전도와 열 생성 구분</strong><p>금속이 열을 만드는지, 받은 열을 전달하는지 설명해 봐요.</p></span></div><div class="divider"></div><span class="section-label">설명 그림 예시</span>${heatDiagram()}<p class="concept-caption">뜨거운 물에서 받은 열이 숟가락을 따라 손잡이 쪽으로 이동하는 개념도예요.</p></section><div class="chat-safety">${icon("eye")}<span><strong>대화는 선생님이 볼 수 있어요.</strong> 담당 교사는 학기 종료 전까지 확인할 수 있습니다. 화면의 답변은 예시이며 실제 AI와 연결되지 않았어요.</span></div><button class="button button-primary" type="button" data-action="go-after" ${data.chat.length === 0 ? "disabled" : ""}>이해 확인 문제로 ${icon("arrow")}</button><button class="button button-ghost" type="button" data-action="student-home">내 피드백으로 돌아가기</button></aside></div>`;
}

function renderComplete() {
  return `${intro("보충학습 완료", "다시 살펴본 만큼 이해가 넓어졌어요.", "점수뿐 아니라 어떤 설명이 더 분명해졌는지도 돌아봐요.")}<section class="result-complete"><span class="completion-mark">${icon("check")}</span><span class="eyebrow"><i class="eyebrow-dot"></i>학습 마침</span><h2>여기까지 잘 해냈어요.</h2><p>정답 개수는 현재 이해를 살펴보는 신호예요. 더 궁금하면 다음 수업에서 선생님과 이야기해 보세요.</p><div class="before-after"><div><strong>학습 전</strong><span>${data.beforeScore} / 3</span></div><span class="score-arrow">${icon("arrow")}</span><div><strong>학습 후</strong><span>${data.afterScore} / 3</span></div></div><div class="button-row" style="justify-content:center"><button class="button button-secondary" type="button" data-action="student-home">내 피드백 보기</button><button class="button button-primary" type="button" data-action="student-logout">로그아웃</button></div><p class="microcopy" style="margin-top:15px">상세 대화는 담당 선생님이 학기 종료까지 확인할 수 있고, 그때 삭제돼요.</p></section>`;
}

function renderStudent() {
  if (!data.student) return renderLogin();
  if (data.studentPage === "assessment") return renderAssessment();
  if (data.studentPage === "before" || data.studentPage === "after") return renderQuiz();
  if (data.studentPage === "chat") return renderChat();
  if (data.studentPage === "complete") return renderComplete();
  return renderStudentHome();
}

function setRole(role) {
  if (!["teacher", "student", "admin"].includes(role)) return;
  data.role = role;
  data.sidebarOpen = false;
  render();
}

function openPrivacy() {
  openDialog(`<div class="dialog-head"><div><span class="eyebrow">학생 안내 · 쉬운 설명</span><h2 id="dialog-title">내 정보가 어떻게 쓰이나요?</h2><p>읽어 본 뒤 확인해 주세요.</p></div><button class="dialog-close" type="button" data-action="close-dialog" aria-label="닫기">${icon("close")}</button></div><div class="stack" style="gap:10px"><div class="file-check">${icon("check")}평가 답안, 점수와 피드백은 서비스를 제공하기 위해 사용해요.</div><div class="file-check">${icon("check")}담당 선생님은 내 결과와 학기 종료까지 보관되는 상세 대화를 볼 수 있어요.</div><div class="file-check">${icon("check")}챗봇 답변에 필요한 내용은 Upstage로 전달될 수 있지만 AI 모델 학습에는 사용하지 않아요.</div><div class="file-check">${icon("check")}이메일은 받지 않아요. PIN을 잊으면 선생님께 새 번호를 요청해요.</div></div><div class="notice-card" style="margin-top:14px"><span class="notice-card-icon">${icon("info")}</span><div><strong>학교의 필요한 동의 절차는 별도예요</strong><p>이 확인은 안내를 읽었다는 뜻이며, 필요한 보호자 동의를 대신하지 않습니다.</p></div></div><div class="review-dialog-actions"><button class="button button-secondary" type="button" data-action="close-dialog">돌아가기</button><button class="button button-primary" type="button" data-action="accept-notice">읽고 확인했어요 ${icon("arrow")}</button></div>`);
}

function openReviewLegacy(id) {
  const submission = submissionFor(id);
  if (!submission) return;
  const pending = submission.status === "pending";
  const criteria = pending ? submission.aiScores : submission.scores;
  const feedback = pending ? submission.feedback : submission.finalFeedback;
  openDialog(`<div class="dialog-head"><div><span class="eyebrow">${pending ? "교사 검토 · 공개 전" : "교사 최종 결과"}</span><h2 id="dialog-title">${esc(submission.name)} · 답안 검토</h2><p>${esc(submission.topic)} · 학생별 확정 후 결과 공개</p></div><button class="dialog-close" type="button" data-action="close-dialog" aria-label="닫기">${icon("close")}</button></div><div class="review-detail-grid"><section class="answer-paper"><div class="answer-paper-head"><strong>학생 서술형 답안</strong>${pill(pending ? "AI 초안" : "결과 공개", pending ? "" : "done")}</div><p>${esc(submission.answer)}</p><div class="answer-evidence"><strong>AI가 참고한 답안 일부 · 최종 판단은 교사</strong><div class="evidence-quote">“${esc(submission.evidence)}”</div></div></section><form class="review-form" id="review-form"><div class="ai-summary"><div class="ai-summary-top"><strong>${icon("spark")}제안 평균 ${scoreText(scoreAverage(criteria))} / 4</strong><span class="tag amber">${pending ? "확정 전 비공개" : "교사 확정"}</span></div><p>${esc(feedback)}</p><small>실제 AI 연결 전 · 시연용 예시 점수</small></div><div class="rubric-list">${["개념 정확성", "근거 제시", "설명 연결"].map((label, index) => `<div class="rubric-edit-row"><label for="criterion-${index}">${label}</label><span class="rubric-score-track"><span style="width:${criteria[index] / 4 * 100}%"></span></span><select id="criterion-${index}" name="criterion-${index}" aria-label="${label} 점수" ${pending ? "" : "disabled"}>${[0, 1, 2, 3, 4].map((score) => `<option value="${score}" ${criteria[index] === score ? "selected" : ""}>${score} / 4</option>`).join("")}</select></div>`).join("")}</div><div class="form-field"><label for="teacher-feedback">학생에게 보낼 최종 피드백</label><textarea id="teacher-feedback" name="teacherFeedback" ${pending ? "" : "readonly"}>${esc(feedback)}</textarea></div><p class="microcopy" style="margin:0">학생 화면에는 교사가 확정한 최종 결과만 보여요.</p></form></div><div class="review-dialog-actions">${pending ? `<button class="button button-secondary" type="button" data-action="confirm-review" data-mode="edit" data-id="${esc(id)}">수정 후 확정</button><button class="button button-primary" type="button" data-action="confirm-review" data-mode="ai" data-id="${esc(id)}">초안대로 확정 ${icon("check")}</button>` : `<button class="button button-secondary" type="button" data-action="close-dialog">닫기</button><button class="button button-ghost" type="button" data-action="export-one" data-id="${esc(id)}">${icon("download")}이 학생 결과 내보내기</button>`}</div>`);
}

function confirmReviewLegacy(id, mode) {
  const submission = submissionFor(id);
  if (!submission || submission.status !== "pending") return;
  let scores = submission.aiScores.slice();
  let feedback = submission.feedback;
  if (mode === "edit") {
    const form = $("#review-form");
    scores = [0, 1, 2].map((index) => Number(new FormData(form).get(`criterion-${index}`)));
    feedback = String(new FormData(form).get("teacherFeedback") || feedback).trim();
  }
  submission.scores = scores;
  submission.finalFeedback = feedback;
  submission.status = "approved";
  submission.reviewedAt = new Date().toISOString();
  closeDialog();
  render();
  toast(`${submission.name}의 결과를 확정했어요. 학생 본인에게 공개됩니다.`);
}

function loginStudent(form) {
  const code = String(new FormData(form).get("classCode") || "").trim().toUpperCase();
  const id = String(new FormData(form).get("attendance") || "").trim().padStart(2, "0");
  const pin = String(new FormData(form).get("pin") || "").trim();
  const student = studentFor(id);
  const error = $("#login-error");
  if (code !== "SCI-26-03" || !student || pin !== student.pin) {
    error.textContent = "학급 코드, 출석번호 또는 PIN을 확인해 주세요.";
    return;
  }
  data.pendingStudent = student;
  openPrivacy();
}

function createDraft(answer) {
  const matches = ["열", "온도", "전달", "금속", "물", "이동", "나무"].filter((word) => answer.includes(word)).length;
  const base = Math.max(1, Math.min(4, 1 + Math.floor(matches / 2)));
  return { scores: [base, Math.max(0, base - (answer.length < 55 ? 1 : 0)), Math.max(0, base - (answer.includes("때문") ? 0 : 1))], feedback: "열이 이동하는 방향과 금속의 성질을 살펴본 예시 초안입니다. 답안의 의미와 근거는 교사가 확인해 주세요." };
}

function submitAnswer() {
  const answer = data.answer.trim();
  const student = data.student;
  if (!student || answer.length < 15) return toast("생각을 조금 더 적어 주세요. 15자 이상이면 제출할 수 있어요.", "info");
  if (submissionFor(student.id)) { data.studentPage = "home"; render(); return toast("이미 제출한 답안이 있어요.", "info"); }
  const draft = createDraft(answer);
  submissions.push({ id: student.id, name: student.name, color: student.color, topic: "열의 이동 방향", date: "방금 제출", status: "pending", answer, evidence: answer.slice(0, 76), aiScores: draft.scores, feedback: draft.feedback });
  data.answer = "";
  data.studentPage = "home";
  render();
  toast("답안을 제출했어요. 예시 초안은 선생님 검토 전까지 비공개입니다.");
}

function nextQuiz() {
  const kind = data.studentPage === "before" ? "before" : "after";
  const question = quizzes[kind][data.quizIndex];
  if (data.selected === null) return;
  if (data.selected === question.correct) {
    if (kind === "before") data.beforeScore += 1;
    else data.afterScore += 1;
  }
  if (data.quizIndex < quizzes[kind].length - 1) {
    data.quizIndex += 1;
    data.selected = null;
    render();
    return;
  }
  if (kind === "before") {
    data.studentPage = "chat";
    data.selected = null;
  } else {
    data.studentPage = "complete";
    data.finished = true;
  }
  render();
}

function sendChat(text) {
  const value = String(text || "").trim();
  if (!value) return;
  data.chat.push({ role: "student", text: value }, { role: "tutor", text: tutorReply(value) });
  data.chatCount += 1;
  render();
  $("#chat-input")?.focus();
}

function tutorReply(value) {
  const text = value.toLowerCase();
  if (text.includes("모르")) return "괜찮아요. 물과 숟가락 중 어느 쪽이 처음에 더 따뜻했나요? 열은 어디서 어디로 움직일 수 있을까요?";
  if (text.includes("이동") || text.includes("전달")) return "좋은 단서를 찾았어요. 금속은 열을 만드는 걸까요, 받은 열을 손잡이까지 전달하는 걸까요?";
  if (text.includes("나무")) return "나무와 금속을 비교했군요. 열을 더 빠르게 전달하는 재료는 어느 쪽일까요? 관찰한 결과와 재료의 성질을 이어 보세요.";
  if (text.includes("물") || text.includes("높")) return "맞아요, 물의 온도가 더 높았어요. 그럼 열은 어느 쪽으로 이동했을까요?";
  return "생각을 나눠줘서 고마워요. 지금 말한 내용에서 관찰과 과학 원리를 하나씩 찾아볼까요?";
}

function renderAdminView() {
  if (data.adminPage === "approvals") return `${intro("운영 콘솔 · 교사 권한", "교사 소속을 확인해요.", "학교 확인을 마친 계정만 학급과 평가 기능을 사용할 수 있습니다.")}<section class="panel panel-pad"><div class="panel-header"><div><h2>승인 요청</h2><p>이 화면에서는 학생 답안이나 점수를 보지 않아요.</p></div>${pill(data.approvalDone ? "확인 완료" : "1건 확인 필요", data.approvalDone ? "done" : "")}</div>${data.approvalDone ? `<div class="empty-state"><span class="empty-icon">${icon("check")}</span><strong>승인 요청을 확인했어요</strong><p>데모 상태입니다. 실제 계정 활성화는 서버에서 처리해야 합니다.</p></div>` : `<div class="approval-card"><span class="approval-details"><span class="student-avatar">교</span><span><strong>시범 교사 · 과학</strong><small>학교 이메일 확인 예시</small></span></span><button class="button button-primary button-small" type="button" data-action="approve-teacher">확인 후 승인</button></div>`}<div class="data-note" style="margin-top:12px">${icon("shield")}<span>확인 결과·날짜·검토자만 기록하고 증빙 원본은 불필요하게 보관하지 않도록 합니다.</span></div></section>`;
  if (data.adminPage === "policies") return `${intro("운영 콘솔 · 준비 체크", "운영 원칙을 점검해요.", "체크 상태는 이 브라우저의 시연 상태만 바뀝니다.")}<section class="panel panel-pad"><div class="panel-header"><div><h2>파일럿 전 확인</h2><p>실제 운영 전 담당자가 검증해야 합니다.</p></div></div>${adminChecklist()}</section>`;
  return renderAdminOverview();
}

function adminChecklist() {
  return `<div class="policy-list">${[["retention", "보관 기간·삭제 일정", "학기말 대화와 2월 말 개인기록"], ["vendor", "AI 업체 처리 조건", "학습 미사용·보관·처리 국가"], ["backup", "백업과 삭제 재적용", "복구 뒤 삭제 요청을 다시 반영"], ["access", "역할별 권한 시험", "학생·교사·관리자 범위"]].map(([id, title, sub]) => `<button class="policy-item" type="button" data-check="${id}" aria-pressed="${data.checks[id]}"><span class="policy-check">${icon("check")}</span><span class="policy-copy"><strong>${title}</strong><small>${sub}</small></span><span class="policy-state">${data.checks[id] ? "확인됨" : "미확인"}</span></button>`).join("")}</div>`;
}

function renderAdminOverview() {
  return `${intro("운영 콘솔 · 메타데이터 전용", "서비스 운영 상태를 확인해요.", "관리자는 계정·연동·사용량·작업 상태를 살핍니다. 개별 학생 답안과 점수는 기본 화면에 표시하지 않습니다.", `<button class="button button-secondary" type="button" data-nav="policies">${icon("settings")}운영 기준 보기</button>`)}<section class="admin-stats"><article class="admin-stat"><span>참여 학교</span><strong>3</strong><small>예시 데이터</small></article><article class="admin-stat"><span>승인 교사</span><strong>18</strong><small>1명 확인 대기</small></article><article class="admin-stat"><span>활성 평가</span><strong>12</strong><small>이번 학기</small></article><article class="admin-stat"><span>AI 예산 사용</span><strong>72%</strong><small>한도까지 28%</small></article></section><div class="admin-grid"><div class="stack"><section class="panel panel-pad"><div class="panel-header"><div><h2>연동과 처리 상태</h2><p>실제 연결이 아닌 예시입니다.</p></div><span class="tag amber">데모</span></div>${[["flask", "서비스 DB", "실제 프로젝트 미연결", "미연결", "neutral"], ["spark", "Upstage API", "키 미설정 · 요청 없음", "미연결", "neutral"], ["file", "자동 백업", "프로젝트 설정 전", "확인 필요", ""], ["clock", "삭제 작업", "대기 없음 · 예시", "예시 상태", "done"]].map(([sym, name, note, label, type]) => `<div class="service-row"><span class="service-info"><span class="service-symbol">${icon(sym)}</span><span class="service-copy"><strong>${name}</strong><small>${note}</small></span></span>${pill(label, type)}</div>`).join("")}<div class="budget-wrap"><div class="budget-head"><span>AI 예산 사용 예시</span><strong>72%</strong></div><div class="budget-bar"><span></span></div><p>현재 데모는 AI 요청을 보내지 않습니다. 실제 운영은 경고와 한도 차단을 설정해야 합니다.</p></div></section><section class="panel panel-pad"><div class="panel-header"><div><h2>최근 운영 이벤트</h2><p>학생 콘텐츠가 아닌 작업 정보</p></div></div><div class="service-row"><span class="service-copy"><strong>개별 답안 접근</strong><small>기본 관리자 권한에서 제외</small></span>${pill("제한됨", "done")}</div><div class="service-row"><span class="service-copy"><strong>개인 결과 내보내기</strong><small>관리자는 개인 결과에 접근하지 않음</small></span>${pill("0건", "neutral")}</div></section></div><div class="stack"><section class="panel panel-pad"><div class="panel-header"><div><h2>교사 승인 대기</h2><p>학교 소속 확인 후 활성화해요.</p></div>${pill(data.approvalDone ? "완료" : "1건 확인 필요", data.approvalDone ? "done" : "")}</div>${data.approvalDone ? `<div class="empty-state"><span class="empty-icon">${icon("check")}</span><strong>승인 요청을 확인했어요</strong><p>시연용 상태입니다.</p></div>` : `<div class="approval-card"><span class="approval-details"><span class="student-avatar">교</span><span><strong>시범 교사 · 과학</strong><small>학교 이메일 확인 예시</small></span></span><button class="button button-primary button-small" type="button" data-action="approve-teacher">승인 확인</button></div>`}</section><section class="panel panel-pad"><div class="panel-header"><div><h2>파일럿 준비 기준</h2><p>${Object.values(data.checks).filter(Boolean).length} / 4 확인</p></div></div>${adminChecklist()}</section></div></div>`;
}

function render() {
  const main = $("#main-content");
  if (data.role === "teacher") main.innerHTML = renderTeacher();
  else if (data.role === "admin") main.innerHTML = renderAdminView();
  else main.innerHTML = renderStudent();
  renderChrome();
  if (data.studentPage === "chat") { const box = $("#chat-messages"); if (box) box.scrollTop = box.scrollHeight; }
}

function goNav(page) {
  if (data.role === "teacher") data.teacherPage = page;
  else if (data.role === "admin") data.adminPage = page;
  else {
    if (page === "feedback") data.studentPage = submissionFor(data.student?.id)?.status === "pending" ? "pending" : "home";
    else if (page === "learning") beginLearning();
    else data.studentPage = "home";
  }
  data.sidebarOpen = false;
  render();
}

function toast(message, symbol = "check") {
  const node = document.createElement("div");
  node.className = "toast";
  node.innerHTML = `${icon(symbol)}<span>${esc(message)}</span>`;
  $("#toast-stack").append(node);
  setTimeout(() => node.remove(), 3800);
}

function openDialog(markup) {
  $("#dialog-content").innerHTML = markup;
  const dialog = $("#app-dialog");
  if (!dialog.open) dialog.showModal();
}

function closeDialog() { const dialog = $("#app-dialog"); if (dialog.open) dialog.close(); }

function openPrivacy() {
  openDialog(`<div class="dialog-head"><div><span class="eyebrow">학생 안내 · 쉬운 설명</span><h2 id="dialog-title">내 정보가 어떻게 쓰이나요?</h2><p>읽어 본 뒤 확인해 주세요.</p></div><button class="dialog-close" type="button" data-action="close-dialog" aria-label="닫기">${icon("close")}</button></div><div class="stack" style="gap:10px"><div class="file-check">${icon("check")}평가 답안과 피드백은 이 서비스를 제공하는 데 사용돼요.</div><div class="file-check">${icon("check")}담당 선생님은 내 결과와 학기 종료까지 보관되는 상세 대화를 볼 수 있어요.</div><div class="file-check">${icon("check")}챗봇 답변에 필요한 내용은 Upstage로 전달될 수 있지만 AI 학습에는 사용하지 않아요.</div><div class="file-check">${icon("check")}이메일은 받지 않아요. PIN을 잊으면 선생님께 새 번호를 요청해요.</div></div><div class="notice-card" style="margin-top:14px"><span class="notice-card-icon">${icon("info")}</span><div><strong>학교에서 필요한 동의 절차는 별도예요</strong><p>이 확인은 안내를 읽었다는 뜻이며, 필요한 보호자 동의를 대신하지 않습니다.</p></div></div><div class="review-dialog-actions"><button class="button button-secondary" type="button" data-action="close-dialog">돌아가기</button><button class="button button-primary" type="button" data-action="accept-notice">읽고 확인했어요 ${icon("arrow")}</button></div>`);
}

function openReview(id) {
  const submission = submissionFor(id);
  if (!submission) return;
  const pending = submission.status === "pending";
  const scores = pending ? submission.aiScores : submission.scores;
  const feedback = pending ? submission.feedback : submission.finalFeedback;
  openDialog(`<div class="dialog-head"><div><span class="eyebrow">${pending ? "교사 검토 · 공개 전" : "교사 최종 결과"}</span><h2 id="dialog-title">${esc(submission.name)} · 답안 검토</h2><p>${esc(submission.topic)} · 확정하면 학생 본인에게 공개</p></div><button class="dialog-close" type="button" data-action="close-dialog" aria-label="닫기">${icon("close")}</button></div><div class="review-detail-grid"><section class="answer-paper"><div class="answer-paper-head"><strong>학생 서술형 답안</strong>${pill(pending ? "AI 초안" : "결과 공개", pending ? "" : "done")}</div><p>${esc(submission.answer)}</p><div class="answer-evidence"><strong>AI가 참고한 답안 일부 · 최종 판단은 교사</strong><div class="evidence-quote">“${esc(submission.evidence)}”</div></div></section><form class="review-form" id="review-form"><div class="ai-summary"><div class="ai-summary-top"><strong>${icon("spark")}제안 평균 ${scoreText(scoreAverage(scores))} / 4</strong><span class="tag amber">${pending ? "확정 전 비공개" : "교사 확정"}</span></div><p>${esc(feedback)}</p><small>실제 AI 연결 전 · 시연용 예시 점수</small></div><div class="rubric-list">${["개념 정확성", "근거 제시", "설명 연결"].map((label, index) => `<div class="rubric-edit-row"><label for="criterion-${index}">${label}</label><span class="rubric-score-track"><span style="width:${scores[index] / 4 * 100}%"></span></span><select id="criterion-${index}" name="criterion-${index}" aria-label="${label} 점수" ${pending ? "" : "disabled"}>${[0, 1, 2, 3, 4].map((score) => `<option value="${score}" ${scores[index] === score ? "selected" : ""}>${score} / 4</option>`).join("")}</select></div>`).join("")}</div><div class="form-field"><label for="teacher-feedback">학생에게 보낼 최종 피드백</label><textarea id="teacher-feedback" name="teacherFeedback" ${pending ? "" : "readonly"}>${esc(feedback)}</textarea></div><p class="microcopy" style="margin:0">학생 화면에는 교사가 확정한 최종 결과만 보입니다.</p></form></div><div class="review-dialog-actions">${pending ? `<button class="button button-secondary" type="button" data-action="confirm-review" data-mode="edit" data-id="${esc(id)}">수정 후 확정</button><button class="button button-primary" type="button" data-action="confirm-review" data-mode="ai" data-id="${esc(id)}">초안대로 확정 ${icon("check")}</button>` : `<button class="button button-secondary" type="button" data-action="close-dialog">닫기</button><button class="button button-ghost" type="button" data-action="export-one" data-id="${esc(id)}">${icon("download")}이 학생 결과 내보내기</button>`}</div>`);
}

function confirmReview(id, mode) {
  const submission = submissionFor(id);
  if (!submission || submission.status !== "pending") return;
  let scores = submission.aiScores.slice();
  let feedback = submission.feedback;
  if (mode === "edit") {
    const formData = new FormData($("#review-form"));
    scores = [0, 1, 2].map((index) => Number(formData.get(`criterion-${index}`)));
    feedback = String(formData.get("teacherFeedback") || feedback).trim();
  }
  submission.scores = scores;
  submission.finalFeedback = feedback;
  submission.status = "approved";
  submission.reviewedAt = new Date().toISOString();
  closeDialog();
  render();
  toast(`${submission.name}의 결과를 확정했어요. 학생 본인에게 공개됩니다.`);
}

function openChatLog(id) {
  if (data.role !== "teacher") return;
  const messages = data.chatLogs[String(id)] || [];
  const student = studentFor(id);
  if (!messages.length) return toast("대화 기록을 찾을 수 없어요.", "info");
  data.chatViews.push({ id: String(id), at: new Date().toISOString() });
  openDialog(`<div class="dialog-head"><div><span class="eyebrow">담당 교사 · 학기 종료 전까지 열람</span><h2 id="dialog-title">${esc(student?.name || `학생 ${id}`)} · 보충학습 대화</h2><p>열람 시각을 기록했습니다. 학기 종료 시 대화 전문을 삭제합니다.</p></div><button class="dialog-close" type="button" data-action="close-dialog" aria-label="닫기">${icon("close")}</button></div><div class="chat-messages" style="max-height:52vh;min-height:220px;border:1px solid var(--line);border-radius:13px">${messages.map((message) => `<div class="chat-message ${message.role === "student" ? "student" : "tutor"}">${esc(message.text)}<small>${message.role === "student" ? "학생 답변" : "과학 선생님"}</small></div>`).join("")}</div><div class="chat-disclaimer" style="margin-top:12px">${icon("eye")}담당 학급에 한해 열람할 수 있어요. 관리자에게 대화 전문은 표시되지 않습니다. 실제 권한과 감사 기록은 서버에서 적용해야 합니다.</div><div class="review-dialog-actions"><button class="button button-primary" type="button" data-action="close-dialog">확인</button></div>`);
}

function createDraft(answer) {
  const matches = ["열", "온도", "전달", "금속", "물", "이동", "나무"].filter((word) => answer.includes(word)).length;
  const base = Math.max(1, Math.min(4, 1 + Math.floor(matches / 2)));
  return { scores: [base, Math.max(0, base - (answer.length < 55 ? 1 : 0)), Math.max(0, base - (answer.includes("때문") ? 0 : 1))], feedback: "열이 이동하는 방향과 금속의 성질을 살펴본 시연용 초안입니다. 의미와 근거는 교사가 확인해 주세요." };
}

function submitAnswer() {
  const answer = data.answer.trim();
  const student = data.student;
  if (!student || answer.length < 15) return toast("생각을 조금 더 적어 주세요. 15자 이상이면 제출할 수 있어요.", "info");
  if (submissionFor(student.id)) {
    data.studentPage = "home";
    render();
    return toast("이미 제출한 답안이 있어요.", "info");
  }
  const draft = createDraft(answer);
  submissions.push({ id: student.id, name: student.name, color: student.color, topic: "열의 이동 방향", date: "방금 제출", status: "pending", answer, evidence: answer.slice(0, 76), aiScores: draft.scores, feedback: draft.feedback });
  data.answer = "";
  data.studentPage = "home";
  render();
  toast("답안을 제출했어요. 시연용 초안은 선생님 검토 전까지 비공개입니다.");
}

function loginStudent(form) {
  const code = String($("#class-code", form).value || "").trim().toUpperCase();
  const id = String($("#attendance-number", form).value || "").trim().padStart(2, "0");
  const pin = String($("#student-pin", form).value || "").trim();
  const student = studentFor(id);
  if (code !== "SCI-26-03" || !student || pin !== student.pin) {
    $("#login-error").textContent = "학급 코드, 출석번호 또는 PIN을 확인해 주세요.";
    return;
  }
  data.pendingStudent = student;
  openPrivacy();
}

function acceptNotice() {
  if (!data.pendingStudent) return;
  data.student = data.pendingStudent;
  data.pendingStudent = null;
  data.role = "student";
  data.studentPage = "home";
  data.answer = "";
  closeDialog();
  render();
  toast(`학생 ${data.student.id} 예시 계정으로 접속했어요.`);
}

function startAssessment() {
  if (!data.student) return;
  if (submissionFor(data.student.id)) {
    data.studentPage = "home";
    render();
    return toast("이미 제출된 답안이 있어요.", "info");
  }
  data.answer = "";
  data.studentPage = "assessment";
  render();
}

function beginLearning() {
  const submission = submissionFor(data.student?.id);
  if (!published(submission)) {
    data.studentPage = "home";
    render();
    return toast("선생님이 결과를 확정하면 보충학습을 시작할 수 있어요.", "info");
  }
  data.quizIndex = 0;
  data.selected = null;
  data.quizHint = false;
  data.beforeScore = 0;
  data.afterScore = 0;
  data.chatCount = 0;
  data.chat = [];
  data.finished = false;
  data.studentPage = "before";
  render();
}

function nextQuiz() {
  const kind = data.studentPage === "before" ? "before" : "after";
  const question = quizzes[kind][data.quizIndex];
  if (data.selected === null) return;
  if (data.selected !== question.correct) {
    data.selected = null;
    data.quizHint = true;
    render();
    return;
  }
  data.quizHint = false;
  if (data.selected === question.correct) data[kind === "before" ? "beforeScore" : "afterScore"] += 1;
  if (data.quizIndex < quizzes[kind].length - 1) {
    data.quizIndex += 1;
    data.selected = null;
    data.quizHint = false;
    render();
    return;
  }
  data.quizIndex = 0;
  data.selected = null;
  data.quizHint = false;
  if (kind === "before") data.studentPage = "chat";
  else { data.studentPage = "complete"; data.finished = true; }
  render();
}

function sendChat(text) {
  const message = String(text || "").trim();
  if (!message) return;
  data.chat.push({ role: "student", text: message }, { role: "tutor", text: tutorReply(message) });
  data.chatCount += 1;
  if (data.student) data.chatLogs[data.student.id] = data.chat.slice();
  render();
  $("#chat-input")?.focus();
}

function tutorReply(message) {
  const text = message.toLowerCase();
  if (text.includes("모르")) return "괜찮아요. 물과 숟가락 중 어느 쪽이 처음에 더 따뜻했나요? 열은 어디서 어디로 움직일 수 있을까요?";
  if (text.includes("이동") || text.includes("전달")) return "좋은 단서예요. 열이 물에서 숟가락으로 이동한다면 금속은 그 열을 어떤 역할로 손잡이까지 보내 줄까요?";
  if (text.includes("나무")) return "나무와 금속을 비교했군요. 열을 더 빠르게 전달하는 재료는 어느 쪽일까요? 관찰한 결과와 재료의 성질을 이어 보세요.";
  if (text.includes("물") || text.includes("높")) return "맞아요, 물의 온도가 더 높았어요. 열은 어느 쪽으로 이동했을까요?";
  return "생각을 나눠줘서 고마워요. 지금 말한 내용에서 관찰과 과학 원리를 하나씩 찾아볼까요?";
}

function downloadBlob(filename, content, mime) {
  const url = URL.createObjectURL(new Blob([content], { type: mime }));
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function csvCell(value) {
  let text = String(value ?? "");
  if (/^[\s]*[=+@-]/.test(text)) text = `'${text}`;
  return `"${text.replace(/"/g, '""')}"`;
}

function exportCsv(items = submissions) {
  const approved = items.filter((item) => item.status === "approved");
  if (!approved.length) return toast("내보낼 확정 결과가 없어요.", "info");
  const rows = [["학생", "평가", "교사 확정 점수", "교사 피드백"], ...approved.map((item) => [item.name, "열전달과 물질의 성질", `${scoreSum(item.scores)} / 12`, item.finalFeedback])];
  downloadBlob("이어봄_학생별_확정결과_시연.csv", `\uFEFF${rows.map((row) => row.map(csvCell).join(",")).join("\r\n")}`, "text/csv;charset=utf-8");
  toast("확정 결과 예시 CSV를 받았어요. 필요한 정보만 포함했습니다.", "download");
}

function saveResultImage(item) {
  if (!published(item)) return toast("교사가 확정한 결과만 저장할 수 있어요.", "info");
  const percent = Math.round(scoreSum(item.scores) / 12 * 100);
  const lines = [item.finalFeedback || "선생님 피드백", "열은 온도가 높은 곳에서 낮은 곳으로 이동해요."];
  const feedback = lines.map((line, index) => `<text x="92" y="430" dy="${index * 38}" class="body">${esc(line)}</text>`).join("");
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="760" viewBox="0 0 1200 760"><defs><style>.title{font:700 42px sans-serif;fill:#1c2923}.sub{font:500 20px sans-serif;fill:#758178}.label{font:700 18px sans-serif;fill:#4c654d}.body{font:500 21px sans-serif;fill:#435347}</style></defs><rect width="1200" height="760" fill="#f2f3ed"/><rect x="60" y="55" width="1080" height="650" rx="28" fill="#fffefa" stroke="#dce4da" stroke-width="2"/><rect x="60" y="55" width="1080" height="16" rx="8" fill="#173d32"/><text x="92" y="135" class="label">이어봄 · 과학 평가 피드백</text><text x="92" y="205" class="title">열전달과 물질의 성질</text><text x="92" y="248" class="sub">${esc(item.name)} · 선생님이 확인한 최종 결과</text><circle cx="1000" cy="184" r="82" fill="#edf3e8"/><text x="950" y="199" style="font:800 76px sans-serif;fill:#173d32">${percent}</text><text x="95" y="345" class="label">선생님 피드백</text>${feedback}<text x="95" y="650" class="sub">본인 결과만 포함 · 학교에서 안내한 위치에 보관해 주세요</text></svg>`;
  downloadBlob(`학생_${item.id}_과학_피드백.svg`, svg, "image/svg+xml;charset=utf-8");
  toast("본인 최종 점수와 피드백만 담은 이미지 예시를 만들었어요.", "download");
}

function openPinSheet() {
  openDialog(`<div class="dialog-head"><div><span class="eyebrow">최초 전달용 · 예시</span><h2 id="dialog-title">학생별 PIN 배부표</h2><p>PIN은 개인 인증자료입니다. 기존 PIN 조회 기능이 아닙니다.</p></div><button class="dialog-close" type="button" data-action="close-dialog" aria-label="닫기">${icon("close")}</button></div><div class="pin-slip">${students.map((student) => `<div class="pin-card"><strong>${esc(student.name)} · 출석번호 ${esc(student.id)}</strong><code>${esc(student.pin)}</code></div>`).join("")}</div><div class="pin-warning">${icon("alert")}<span>실제 PIN은 학생별로 전달하고 다운로드·인쇄 잔여물은 배부 후 파기해야 합니다. 여기에 보이는 번호는 데모용입니다.</span></div><div class="review-dialog-actions"><button class="button button-secondary" type="button" data-action="close-dialog">닫기</button><button class="button button-primary" type="button" data-action="download-pins">${icon("download")}예시 CSV 받기</button></div>`);
}

function reissuePin(id) {
  const student = studentFor(id);
  if (!student) return;
  openDialog(`<div class="dialog-head"><div><span class="eyebrow">학생 본인 확인 후 새로 발급</span><h2 id="dialog-title">${esc(student.name)} PIN 초기화</h2><p>기존 PIN은 다시 볼 수 없습니다. 재발급하면 기존 세션도 무효화합니다.</p></div><button class="dialog-close" type="button" data-action="close-dialog" aria-label="닫기">${icon("close")}</button></div><div class="data-note">${icon("lock")}<span>학생 본인을 수업 중 확인한 뒤 새 PIN을 발급해 주세요. 이 데모에서는 예시 값만 사용합니다.</span></div><div class="review-dialog-actions"><button class="button button-secondary" type="button" data-action="close-dialog">취소</button><button class="button button-primary" type="button" data-action="confirm-reissue" data-id="${esc(id)}">새 PIN 발급 ${icon("refresh")}</button></div>`);
}

function confirmReissue(id) {
  const student = studentFor(id);
  if (!student) return;
  student.pin = String(Math.floor(Math.random() * 10000)).padStart(4, "0");
  if (data.student?.id === student.id) { data.student = null; data.studentPage = "login"; }
  openDialog(`<div class="dialog-head"><div><span class="eyebrow">한 번만 표시</span><h2 id="dialog-title">새 PIN을 안전하게 전달해 주세요.</h2><p>${esc(student.name)} · 이전 PIN과 세션은 무효화됐습니다.</p></div><button class="dialog-close" type="button" data-action="close-dialog" aria-label="닫기">${icon("close")}</button></div><div class="new-pin-display"><span>새 PIN 4자리</span><strong>${esc(student.pin)}</strong></div><p class="dialog-footnote">실제 서비스는 PIN 원문을 저장하지 않아 창을 닫으면 다시 조회할 수 없습니다. 이 데모는 시연을 위해 번호를 예시 메모리에 둡니다.</p><div class="review-dialog-actions"><button class="button button-primary" type="button" data-action="close-dialog">확인했어요</button></div>`);
  renderChrome();
}

function downloadPins() {
  const rows = [["학생", "출석번호", "예시 PIN"], ...students.map((student) => [student.name, student.id, student.pin])];
  downloadBlob("이어봄_PIN배부표_시연.csv", `\uFEFF${rows.map((row) => row.map(csvCell).join(",")).join("\r\n")}`, "text/csv;charset=utf-8");
  toast("예시 PIN 배부표를 받았어요. 실제 배부표는 안전하게 다뤄야 합니다.", "download");
}

function handleAction(action, target) {
  const id = target.dataset.id;
  if (action === "toggle-sidebar") { data.sidebarOpen = !data.sidebarOpen; renderChrome(); }
  else if (action === "dismiss-notice") { data.noticeHidden = true; renderChrome(); }
  else if (action === "notifications") toast("새 운영 알림이 없습니다. (시연 데이터)", "bell");
  else if (action === "close-dialog") closeDialog();
  else if (action === "accept-notice") acceptNotice();
  else if (action === "go-review") { data.teacherPage = "review"; render(); }
  else if (action === "go-class") { data.teacherPage = "class"; render(); }
  else if (action === "go-chats") { data.teacherPage = "chats"; render(); }
  else if (action === "go-files") { data.teacherPage = "files"; render(); }
  else if (action === "open-review") openReview(id);
  else if (action === "open-chat-log") openChatLog(id);
  else if (action === "confirm-review") confirmReview(id, target.dataset.mode);
  else if (action === "start-assessment") startAssessment();
  else if (action === "submit-assessment") submitAnswer();
  else if (action === "student-home") { data.studentPage = "home"; render(); }
  else if (action === "student-logout") { data.student = null; data.role = "student"; data.studentPage = "login"; data.chat = []; render(); toast("학생 계정에서 로그아웃했어요."); }
  else if (action === "signout") { data.role = "teacher"; data.teacherPage = "overview"; render(); }
  else if (action === "fill-account") fillAccount(id);
  else if (action === "start-learning") beginLearning();
  else if (action === "quiz-next") nextQuiz();
  else if (action === "go-after") { if (!data.chat.length) return; data.studentPage = "after"; data.quizIndex = 0; data.selected = null; render(); }
  else if (action === "open-pin-sheet") openPinSheet();
  else if (action === "pin-sheet") openPinSheet();
  else if (action === "reissue-pin") reissuePin(id);
  else if (action === "confirm-reissue") confirmReissue(id);
  else if (action === "download-pins") downloadPins();
  else if (action === "copy-code") { navigator.clipboard?.writeText("SCI-26-03").then(() => toast("학급 코드 예시를 복사했어요.")).catch(() => toast("학급 코드 예시: SCI-26-03", "info")); }
  else if (action === "save-location") saveFileLocation();
  else if (action === "export-csv") exportCsv();
  else if (action === "export-one") { const item = submissionFor(id); if (item) exportCsv([item]); }
  else if (action === "save-image") saveResultImage(submissionFor(data.student?.id));
  else if (action === "print-result") window.print();
  else if (action === "approve-teacher") { data.approvalDone = true; render(); toast("교사 승인 확인 상태를 변경했어요. (데모)"); }
}

function fillAccount(id) {
  const student = studentFor(id);
  if (!student) return;
  $("#class-code").value = "SCI-26-03";
  $("#attendance-number").value = student.id;
  $("#student-pin").value = student.pin;
  $("#login-error").textContent = "";
}

function saveFileLocation() {
  const location = String($("#file-location")?.value || "").trim();
  const checked = Boolean($("#file-ack")?.checked);
  if (!location || !checked) {
    toast("학교 승인 위치를 입력하고 담당 책임을 확인해 주세요.", "info");
    return;
  }
  data.fileLocation = location;
  data.fileConfirmed = true;
  render();
  toast("저장 위치와 담당 책임 확인을 기록했어요. (이 브라우저 데모)");
}

function handleClick(event) {
  const roleButton = event.target.closest("[data-role]");
  if (roleButton) { setRole(roleButton.dataset.role); return; }
  const nav = event.target.closest("[data-nav]");
  if (nav) { goNav(nav.dataset.nav); return; }
  const filter = event.target.closest("[data-filter]");
  if (filter) { data.filter = filter.dataset.filter; render(); return; }
  const check = event.target.closest("[data-check]");
  if (check) { data.checks[check.dataset.check] = !data.checks[check.dataset.check]; render(); return; }
  const option = event.target.closest("[data-option]");
  if (option) { data.selected = Number(option.dataset.option); render(); return; }
  const prompt = event.target.closest("[data-chat-prompt]");
  if (prompt) { sendChat(prompt.dataset.chatPrompt); return; }
  const action = event.target.closest("[data-action]");
  if (action) handleAction(action.dataset.action, action);
}

document.addEventListener("click", handleClick);

document.addEventListener("submit", (event) => {
  if (event.target.id === "student-login-form") { event.preventDefault(); loginStudent(event.target); }
  if (event.target.id === "chat-form") { event.preventDefault(); sendChat($("#chat-input").value); }
});

document.addEventListener("input", (event) => {
  if (event.target.id === "assessment-answer") {
    data.answer = event.target.value;
    const length = data.answer.trim().length;
    const progress = Math.min(100, Math.round(length / 120 * 100));
    $("#answer-length").textContent = `${length}자`;
    $("#answer-progress").style.width = `${progress}%`;
    $("#submit-answer").disabled = length < 15;
  }
  if (event.target.id === "review-search") {
    const position = event.target.selectionStart;
    data.search = event.target.value;
    render();
    const replacement = $("#review-search");
    replacement?.focus();
    replacement?.setSelectionRange(position, position);
  }
  if (event.target.id === "file-location") data.fileLocation = event.target.value;
});

document.addEventListener("change", (event) => {
  if (event.target.id === "file-ack") data.fileConfirmed = event.target.checked;
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && data.sidebarOpen) { data.sidebarOpen = false; renderChrome(); }
  if (event.key === "Enter" && event.target.id === "chat-input" && !event.shiftKey) {
    event.preventDefault();
    sendChat(event.target.value);
  }
});

render();
