if (!window.__NP_DISPATCH_BOOTED__) {
window.__NP_DISPATCH_BOOTED__ = true;

function npEndpoint() {
  if (window.NP_BOOT && window.NP_BOOT.apiUrl) return window.NP_BOOT.apiUrl;
  const m = window.location.pathname.match(/^\/hds-[a-z0-9]+-\d+-[a-z0-9]+/i);
  return (m ? m[0] : "") + "/api/np";
}
function npFixNav() {
  document.querySelectorAll("[data-np-nav]").forEach((a) => {
    const key = a.getAttribute("data-np-nav") || "";
    if (window.NP_BOOT && NP_BOOT.links && NP_BOOT.links[key]) {
      a.setAttribute("href", NP_BOOT.links[key]);
      return;
    }
    const m = window.location.pathname.match(/^\/hds-[a-z0-9]+-\d+-[a-z0-9]+/i);
    a.setAttribute("href", (m ? m[0] : "") + key);
  });
}
async function npCall(modelName, calledMethod, methodProperties, apiKey, timeoutSec) {
  const ctrl = new AbortController();
  const ms = Math.min(60, Math.max(5, Number(timeoutSec) || 30)) * 1000;
  const timer = setTimeout(() => ctrl.abort(), ms);
  try {
    const res = await fetch(npEndpoint(), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        apiKey: apiKey || "",
        modelName,
        calledMethod,
        methodProperties: methodProperties || {}
      }),
      signal: ctrl.signal
    });
    return await res.json();
  } catch (err) {
    const aborted = err && err.name === "AbortError";
    return { success: false, data: [], errors: [aborted ? "Таймаут запроса к API Новой Почты" : "Нет соединения с API Новой Почты"] };
  } finally {
    clearTimeout(timer);
  }
}
function npErrorText(r) {
  const e = r && r.errors;
  if (!e || (Array.isArray(e) && !e.length)) return "Ошибка API Новой Почты";
  if (typeof e === "string") return e;
  if (Array.isArray(e)) {
    const text = e.map((x) => (typeof x === "string" ? x : (x.ErrorMessage || x.Message || x.message || ""))).filter(Boolean).join("; ");
    return text || "Ошибка API Новой Почты";
  }
  return "Ошибка API Новой Почты";
}
function npScheduleSave(snapshot) {
  if (!window.NP_BOOT || !NP_BOOT.saveUrl) return;
  clearTimeout(npScheduleSave._t);
  npScheduleSave._t = setTimeout(() => {
    fetch(NP_BOOT.saveUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ state: snapshot })
    }).catch(() => {});
  }, 450);
}
function npCityQuery(label) {
  return String(label || "").replace(/^м\.\s*/i, "").replace(/\(.*\)/, "").replace(/,.*/, "").trim();
}


/* ========================================================================= */
/* НАЧАЛЬНЫЕ ДАННЫЕ: ЭКСПРЕСС-НАКЛАДНЫЕ, РЕЕСТРЫ SCANSHEET И ЗАКАЗЫ          */
/* ========================================================================= */
const READY_ORDERS = [
  {
    orderId: "#10492",
    recipient: "Бондаренко Тарас Олегович",
    phone: "380974412088",
    serviceType: "WarehouseWarehouse",
    city: "м. Львів",
    point: "Відділення №12 (вул. Городоцька, 174)",
    weight: 1.5, length: 30, width: 20, height: 15,
    cost: 2450, payer: "Recipient", cod: true,
    description: "Одяг та аксесуари (Замовлення #10492)"
  },
  {
    orderId: "#10493",
    recipient: "Савченко Олена Миколаївна",
    phone: "380638129940",
    serviceType: "WarehouseWarehouse",
    city: "м. Одеса",
    point: "Поштомат №4108 (ТРЦ Riviera)",
    weight: 0.8, length: 25, width: 18, height: 10,
    cost: 3200, payer: "Sender", cod: false,
    description: "Косметика та догляд (Замовлення #10493)"
  },
  {
    orderId: "#10494",
    recipient: "Гриценко Максим Вікторович",
    phone: "380503391022",
    serviceType: "WarehouseDoors",
    city: "м. Дніпро",
    point: "просп. Дмитра Яворницького, 54, кв. 18",
    weight: 4.2, length: 45, width: 35, height: 25,
    cost: 4890, payer: "Recipient", cod: true,
    description: "Дрібна побутова техніка (Замовлення #10494)"
  }
];

let scanSheets = [
  { id: "SS-104", number: "Реестр #104", date: "Сегодня, 14:20", status: "open", docCount: 4 },
  { id: "SS-103", number: "Реестр #103", date: "Вчера, 18:05", status: "closed", docCount: 12 }
];

let waybills = [
  {
    id: "wb-1",
    ttn: "20450984127731",
    createdAt: "Сегодня, 16:40",
    orderId: "#10491",
    recipient: "Кравчук Дмитро Сергійович",
    phone: "380672219044",
    city: "м. Харків",
    point: "Поштомат №2219 (вул. Сумська, 39)",
    pointKind: "postomat",
    serviceType: "WarehouseWarehouse",
    warehouseIndex: "61/219",
    weight: 1.2,
    dimensions: "28×20×12",
    cost: 1650,
    cod: true,
    codAmount: 1650,
    payer: "Recipient",
    shippingCost: 70,
    statusCategory: "new",
    statusCode: "1",
    statusText: "Создана (Ожидает передачи)",
    storageNote: "Не передана в отделение НП",
    scanSheetId: null,
    description: "Кросівки чоловічі (Замовлення #10491)",
    timeline: [
      { time: "Сегодня, 16:40", place: "м. Київ", text: "Экспресс-накладная создана в системе (InternetDocument.save)", done: true },
      { time: "Ожидается", place: "м. Київ, Відділення №14", text: "Передача посылки оператору или курьеру Новой Почты", done: false }
    ]
  },
  {
    id: "wb-2",
    ttn: "20450984126509",
    createdAt: "Сегодня, 15:15",
    orderId: "#10490",
    recipient: "Мельник Анастасія Ігорівна",
    phone: "380935501287",
    city: "м. Київ",
    point: "вул. Велика Васильківська, 72, кв. 14",
    pointKind: "courier",
    serviceType: "WarehouseDoors",
    warehouseIndex: "01/072",
    weight: 2.4,
    dimensions: "35×25×18",
    cost: 3400,
    cod: false,
    codAmount: 0,
    payer: "Sender",
    shippingCost: 105,
    statusCategory: "new",
    statusCode: "1",
    statusText: "Создана (Готова к отгрузке)",
    storageNote: "Маркировка распечатана",
    scanSheetId: null,
    description: "Сумка шкіряна (Замовлення #10490)",
    timeline: [
      { time: "Сегодня, 15:15", place: "м. Київ", text: "Экспресс-накладная создана (Оплата доставки: Отправитель)", done: true },
      { time: "Ожидается", place: "м. Київ", text: "Ожидает включения в реестр отгрузки дня", done: false }
    ]
  },
  {
    id: "wb-3",
    ttn: "20450983991042",
    createdAt: "Вчера, 17:10",
    orderId: "#10486",
    recipient: "Ткаченко Олександр Віталійович",
    phone: "380507741903",
    city: "м. Вінниця",
    point: "Відділення №8 (вул. Келецька, 61)",
    pointKind: "branch",
    serviceType: "WarehouseWarehouse",
    warehouseIndex: "21/008",
    weight: 3.5,
    dimensions: "40×30×22",
    cost: 2890,
    cod: true,
    codAmount: 2890,
    payer: "Recipient",
    shippingCost: 95,
    statusCategory: "transit",
    statusCode: "5",
    statusText: "Направляется в город получателя",
    storageNote: "Ориентировочно: Завтра до 15:00",
    scanSheetId: "SS-104",
    description: "Рюкзак міський + термос (Замовлення #10486)",
    timeline: [
      { time: "Вчера, 17:10", place: "м. Київ", text: "Экспресс-накладная создана", done: true },
      { time: "Вчера, 18:45", place: "м. Київ, Відділення №14", text: "Принято к отправке (в составе Реестра #104)", done: true },
      { time: "Сегодня, 06:20", place: "Терминал Киев-Запад", text: "Отправлено в г. Винница", done: true }
    ]
  },
  {
    id: "wb-4",
    ttn: "20450983810455",
    createdAt: "2 дня назад",
    orderId: "#10481",
    recipient: "Поліщук Марія Андріївна",
    phone: "380981140932",
    city: "м. Полтава",
    point: "Відділення №4 (вул. Соборності, 46)",
    pointKind: "branch",
    serviceType: "WarehouseWarehouse",
    warehouseIndex: "36/004",
    weight: 0.9,
    dimensions: "24×18×10",
    cost: 1290,
    cod: true,
    codAmount: 1290,
    payer: "Recipient",
    shippingCost: 70,
    statusCategory: "arrived",
    statusCode: "7",
    statusText: "Прибыла в отделение",
    storageNote: "2-й день бесплатного хранения",
    scanSheetId: "SS-104",
    description: "Набір аксесуарів (Замовлення #10481)",
    timeline: [
      { time: "2 дня назад", place: "м. Київ", text: "Отправлено из отделения №14", done: true },
      { time: "Вчера, 11:30", place: "м. Полтава, Відділення №4", text: "Посылка прибыла в отделение. Клиенту отправлено SMS", done: true }
    ]
  },
  {
    id: "wb-5",
    ttn: "20450983509188",
    createdAt: "5 дней назад",
    orderId: "#10474",
    recipient: "Лисенко Артем Володимирович",
    phone: "380664028815",
    city: "м. Запоріжжя",
    point: "Відділення №19 (просп. Соборний, 145)",
    pointKind: "branch",
    serviceType: "WarehouseWarehouse",
    warehouseIndex: "69/019",
    weight: 2.0,
    dimensions: "32×22×16",
    cost: 4150,
    cod: true,
    codAmount: 4150,
    payer: "Recipient",
    shippingCost: 70,
    statusCategory: "storage_risk",
    statusCode: "7",
    statusText: "Риск платного хранения (5-й день)",
    storageNote: "С завтрашнего дня начисляется плата за хранение!",
    scanSheetId: "SS-103",
    description: "Бездротові навушники (Замовлення #10474)",
    timeline: [
      { time: "5 дней назад", place: "м. Київ", text: "Отправлено из отделения №14", done: true },
      { time: "4 дня назад", place: "м. Запоріжжя, Відділення №19", text: "Прибыло в отделение. Получатель ещё не забрал груз", done: true },
      { time: "Сегодня, 09:00", place: "Контроль хранения", text: "Внимание: 5-й день хранения! Рекомендуется напомнить клиенту", done: true }
    ]
  },
  {
    id: "wb-6",
    ttn: "20450983290014",
    createdAt: "6 дней назад",
    orderId: "#10468",
    recipient: "Ковальчук Ігор Петрович",
    phone: "380958831049",
    city: "м. Черкаси",
    point: "Відділення №3 (бул. Шевченка, 205)",
    pointKind: "branch",
    serviceType: "WarehouseWarehouse",
    warehouseIndex: "71/003",
    weight: 1.8,
    dimensions: "30×20×15",
    cost: 1980,
    cod: true,
    codAmount: 1980,
    payer: "Recipient",
    shippingCost: 70,
    statusCategory: "returned",
    statusCode: "10",
    statusText: "Отказ получателя • Возврат",
    storageNote: "Оформлен возврат на отправителя",
    scanSheetId: "SS-103",
    description: "Куртка демісезонна (Замовлення #10468)",
    timeline: [
      { time: "6 дней назад", place: "м. Київ", text: "Отправлено из отделения №14", done: true },
      { time: "3 дня назад", place: "м. Черкаси, Відділення №3", text: "Получатель отказался от получения посылки", done: true },
      { time: "Вчера, 14:10", place: "м. Черкаси", text: "Оформлена обратная доставка отправителю (AdditionalService)", done: true }
    ]
  },
  {
    id: "wb-7",
    ttn: "20450983118720",
    createdAt: "4 дня назад",
    orderId: "#10479",
    recipient: "Шевченко Наталія Олегівна",
    phone: "380679904312",
    city: "м. Івано-Франківськ",
    point: "Поштомат №1104 (вул. Незалежності, 34)",
    pointKind: "postomat",
    serviceType: "WarehouseWarehouse",
    warehouseIndex: "26/104",
    weight: 0.7,
    dimensions: "22×16×10",
    cost: 2150,
    cod: true,
    codAmount: 2150,
    payer: "Sender",
    shippingCost: 70,
    statusCategory: "delivered",
    statusCode: "9",
    statusText: "Получена • Наложка выплачена",
    storageNote: "Денежный перевод зачислен",
    scanSheetId: "SS-103",
    description: "Парфумерний набір (Замовлення #10479)",
    timeline: [
      { time: "4 дня назад", place: "м. Київ", text: "Отправлено из отделения №14", done: true },
      { time: "2 дня назад", place: "м. Івано-Франківськ", text: "Загружено в Поштомат №1104", done: true },
      { time: "Вчера, 18:22", place: "м. Івано-Франківськ", text: "Посылка получена. Денежный перевод 2 150 ₴ выполнен", done: true }
    ]
  }
];

/* ========================================================================= */
/* СОСТОЯНИЕ ИНТЕРФЕЙСА                                                      */
/* ========================================================================= */
let activeFilter = "all";
let searchQuery = "";
let selectedRowIds = new Set();
let inspectedId = waybills[0].id;
try {
  if (window.NP_BOOT && NP_BOOT.mode === "opencart" && Array.isArray(NP_BOOT.waybills)) {
    waybills = NP_BOOT.waybills;
    scanSheets = Array.isArray(NP_BOOT.scanSheets) ? NP_BOOT.scanSheets : [];
    inspectedId = waybills[0] ? waybills[0].id : null;
  } else {
    const saved = JSON.parse(localStorage.getItem("np_dispatcher_docs_v1") || "null");
    if (saved && Array.isArray(saved.waybills) && saved.waybills.length) {
      waybills = saved.waybills;
      if (Array.isArray(saved.scanSheets)) scanSheets = saved.scanSheets;
      inspectedId = waybills[0].id;
    }
  }
  if (window.NP_BOOT && Array.isArray(NP_BOOT.readyOrders) && NP_BOOT.readyOrders.length) {
    READY_ORDERS.splice(0, READY_ORDERS.length, ...NP_BOOT.readyOrders);
  }
} catch (e) {}
let activeRightActionForm = null; // 'redirect' | 'return' | null
let isSidebarCollapsed = false;

const STATUS_FILTERS = [
  {
    id: "all",
    label: "Все накладные",
    dot: "bg-[#111111]",
    icon: `<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect></svg>`
  },
  {
    id: "new",
    label: "Новые / К печати",
    dot: "bg-[#111111]",
    icon: `<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>`
  },
  {
    id: "transit",
    label: "В пути",
    dot: "bg-[#5c6370]",
    icon: `<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>`
  },
  {
    id: "arrived",
    label: "Прибыли в отделение",
    dot: "bg-[#157a3a]",
    icon: `<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>`
  },
  {
    id: "storage_risk",
    label: "Риск платного хранения",
    dot: "bg-[#c42323]",
    icon: `<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>`
  },
  {
    id: "returned",
    label: "Отказ / Возврат",
    dot: "bg-[#c42323]",
    icon: `<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 0 0-4-4H4"></path></svg>`
  },
  {
    id: "delivered",
    label: "Получены",
    dot: "bg-[#157a3a]",
    icon: `<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`
  }
];

const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({
  "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
}[c]));

function formatTtn(raw) {
  const digits = String(raw).replace(/\D/g, "");
  if (digits.length !== 14) return raw;
  return `${digits.slice(0, 2)} ${digits.slice(2, 6)} ${digits.slice(6, 10)} ${digits.slice(10, 14)}`;
}

function showApiToast(apiCall, message, tone = "ok") {
  const container = document.getElementById("toast-container");
  const el = document.createElement("div");
  const style = tone === "bad"
    ? "bg-[#fdecec] border-[#c42323] text-[#c42323]"
    : "bg-[#111111] border-[#111111] text-white";
  el.className = `pointer-events-auto p-3 rounded-[10px] border shadow-lg text-xs transition-all duration-200 ${style}`;
  el.innerHTML = `
    <div class="font-mono text-[10px] font-bold opacity-80">${esc(apiCall)}</div>
    <div class="font-bold mt-0.5">${esc(message)}</div>
  `;
  container.appendChild(el);
  setTimeout(() => {
    el.style.opacity = "0";
    setTimeout(() => el.remove(), 220);
  }, 3000);
}

function copyText(text, label) {
  const ta = document.createElement("textarea");
  ta.value = text;
  document.body.appendChild(ta);
  ta.select();
  document.execCommand("copy");
  ta.remove();
  showApiToast("Clipboard.copy", `${label}: ${text}`);
}

function getFilteredWaybills() {
  const q = searchQuery.trim().toLowerCase().replace(/\s+/g, "");
  return waybills.filter(wb => {
    if (activeFilter !== "all" && wb.statusCategory !== activeFilter) return false;
    if (!q) return true;
    const hay = `${wb.ttn} ${wb.recipient} ${wb.phone} ${wb.orderId} ${wb.city}`.toLowerCase().replace(/\s+/g, "");
    return hay.includes(q);
  });
}

/* ========================================================================= */
/* РЕНДЕР ЛЕВОГО САЙДБАРА (ФИЛЬТРЫ + РЕЕСТРЫ SCANSHEET + СВОРАЧИВАНИЕ)       */
/* ========================================================================= */
function renderSidebar() {
  const sidebarEl = document.getElementById("left-sidebar");
  const headerRow = document.getElementById("sidebar-header-row");
  const statusesHeading = document.getElementById("sidebar-statuses-heading");
  const toggleBtn = document.getElementById("btn-toggle-sidebar");
  const toggleIcon = document.getElementById("sidebar-toggle-icon");

  const ssExpanded = document.getElementById("scansheet-expanded-view");
  const ssCollapsed = document.getElementById("scansheet-collapsed-view");
  const whExpanded = document.getElementById("sidebar-warehouse-card");
  const whCollapsed = document.getElementById("sidebar-warehouse-collapsed");

  if (isSidebarCollapsed) {
    sidebarEl.classList.remove("lg:w-[250px]", "p-3.5");
    sidebarEl.classList.add("lg:w-[68px]", "lg:px-2", "lg:py-3", "p-3");
    headerRow.classList.remove("justify-between", "px-1.5");
    headerRow.classList.add("justify-center");
    statusesHeading.classList.add("lg:hidden");
    toggleBtn.title = "Развернуть левую панель";
    toggleIcon.classList.add("rotate-180");

    ssExpanded.classList.add("lg:hidden");
    ssCollapsed.classList.remove("hidden");
    ssCollapsed.classList.add("hidden", "lg:flex");

    whExpanded.classList.remove("lg:block");
    whCollapsed.classList.remove("hidden");
    whCollapsed.classList.add("hidden", "lg:flex");
  } else {
    sidebarEl.classList.remove("lg:w-[68px]", "lg:px-2", "lg:py-3", "p-3");
    sidebarEl.classList.add("lg:w-[250px]", "p-3.5");
    headerRow.classList.remove("justify-center");
    headerRow.classList.add("justify-between", "px-1.5");
    statusesHeading.classList.remove("lg:hidden");
    toggleBtn.title = "Свернуть левую панель";
    toggleIcon.classList.remove("rotate-180");

    ssExpanded.classList.remove("lg:hidden");
    ssCollapsed.classList.add("hidden");
    ssCollapsed.classList.remove("lg:flex");

    whExpanded.classList.add("lg:block");
    whCollapsed.classList.add("hidden");
    whCollapsed.classList.remove("lg:flex");
  }

  const nav = document.getElementById("status-filters-nav");
  nav.innerHTML = STATUS_FILTERS.map(f => {
    const count = f.id === "all"
      ? waybills.length
      : waybills.filter(w => w.statusCategory === f.id).length;
    const isActive = activeFilter === f.id;
    const isDanger = f.id === "storage_risk" || f.id === "returned";

    // Компактный режим иконок (при свернутом сайдбаре на десктопе)
    if (isSidebarCollapsed) {
      const badgeColor = isActive
        ? "bg-[#157a3a] text-white"
        : (isDanger && count > 0)
          ? "bg-[#c42323] text-white"
          : "bg-[#111111] text-white";

      return `
        <button type="button" data-filter-id="${f.id}" title="${esc(f.label)} (${count})"
          class="relative min-w-[165px] lg:min-w-0 lg:w-11 lg:h-11 lg:mx-auto px-2.5 lg:px-0 py-2 rounded-[10px] flex items-center justify-between lg:justify-center gap-2 transition-colors ${isActive ? "bg-[#111111] text-white" : "hover:bg-[#f6f6f7] text-[#111111] border border-transparent hover:border-[#e4e5e9]"}">
          <span class="flex items-center gap-2">
            <span class="${!isActive && isDanger && count > 0 ? "text-[#c42323]" : (!isActive && f.id === "arrived" ? "text-[#157a3a]" : "")}">${f.icon}</span>
            <span class="lg:hidden text-xs font-bold truncate">${esc(f.label)}</span>
          </span>
          <span class="lg:absolute lg:-top-1 lg:-right-1 font-mono text-[10px] font-extrabold min-w-[18px] h-[18px] px-1 rounded-full flex items-center justify-center border-2 border-white ${badgeColor}">
            ${count}
          </span>
        </button>
      `;
    }

    // Развернутый режим с иконкой, текстом и бейджем
    return `
      <button type="button" data-filter-id="${f.id}" title="${esc(f.label)}"
        class="min-w-[165px] lg:min-w-0 w-full px-2.5 py-2 rounded-[10px] text-left flex items-center justify-between gap-2 transition-colors ${isActive ? "bg-[#111111] text-white" : "hover:bg-[#f6f6f7] text-[#111111]"}">
        <span class="flex items-center gap-2 min-w-0">
          <span class="${isActive ? "text-white" : (isDanger && count > 0 ? "text-[#c42323]" : (f.id === "arrived" || f.id === "delivered" ? "text-[#157a3a]" : "text-[#5c6370]"))}">${f.icon}</span>
          <span class="text-xs font-bold truncate">${esc(f.label)}</span>
        </span>
        <span class="font-mono text-[11px] font-bold px-2 py-0.5 rounded-full shrink-0 ${isActive
          ? "bg-white/20 text-white"
          : (isDanger && count > 0)
            ? "bg-[#fdecec] text-[#c42323]"
            : "bg-[#f6f6f7] text-[#5c6370] border border-[#e4e5e9]"}">
          ${count}
        </span>
      </button>
    `;
  }).join("");

  // Список реестров ScanSheet
  document.getElementById("scansheet-count-badge").textContent = scanSheets.length;
  document.getElementById("scansheet-collapsed-badge").textContent = scanSheets.length;
  const ssList = document.getElementById("scansheet-list");
  ssList.innerHTML = scanSheets.map(ss => {
    const liveDocs = waybills.filter(w => w.scanSheetId === ss.id).length;
    const displayCount = Math.max(ss.docCount, liveDocs);
    const isOpen = ss.status === "open";
    return `
      <div class="p-2.5 rounded-[10px] border border-[#e4e5e9] bg-[#f6f6f7] space-y-1">
        <div class="flex items-center justify-between">
          <span class="text-xs font-extrabold text-[#111111]">${esc(ss.number)}</span>
          <span class="font-mono text-[10px] font-bold px-2 py-0.5 rounded-full ${isOpen ? "bg-[#e8f6ee] text-[#157a3a] border border-[#b7e4c7]" : "bg-white text-[#5c6370] border border-[#e4e5e9]"}">
            ${isOpen ? "Открыт" : "Закрыт"} • ${displayCount} ЭН
          </span>
        </div>
        <div class="text-[11px] text-[#5c6370]">${esc(ss.date)}</div>
      </div>
    `;
  }).join("");
}

/* ========================================================================= */
/* РЕНДЕР ЦЕНТРАЛЬНОЙ ТАБЛИЦЫ                                                */
/* ========================================================================= */
function renderTable() {
  const rows = getFilteredWaybills();
  const activeFilterObj = STATUS_FILTERS.find(f => f.id === activeFilter);
  document.getElementById("table-heading").textContent = activeFilterObj ? activeFilterObj.label : "Экспресс-накладные";
  document.getElementById("table-total-pill").textContent = `${rows.length} ЭН`;

  const tbody = document.getElementById("waybills-tbody");
  const emptyState = document.getElementById("empty-table-state");

  if (!rows.length) {
    tbody.innerHTML = "";
    emptyState.classList.remove("hidden");
    return;
  }
  emptyState.classList.add("hidden");

  // Синхронизация главного чекбокса
  const selectAllCb = document.getElementById("select-all-checkbox");
  selectAllCb.checked = rows.length > 0 && rows.every(r => selectedRowIds.has(r.id));

  tbody.innerHTML = rows.map(wb => {
    const isChecked = selectedRowIds.has(wb.id);
    const isInspected = inspectedId === wb.id;

    // Иконка типа точки
    const pointIcon = wb.pointKind === "postomat"
      ? `<span class="font-mono text-[10px] font-bold px-1.5 py-0.5 rounded bg-[#f6f6f7] border border-[#e4e5e9] text-[#111111]">Почтомат</span>`
      : wb.pointKind === "courier"
        ? `<span class="font-mono text-[10px] font-bold px-1.5 py-0.5 rounded bg-[#111111] text-white">Курьер</span>`
        : `<span class="font-mono text-[10px] font-bold px-1.5 py-0.5 rounded bg-[#f6f6f7] border border-[#e4e5e9] text-[#5c6370]">Отделение</span>`;

    // Цвет статус-бейджа
    let badgeClass = "bg-[#f6f6f7] text-[#111111] border-[#e4e5e9]";
    if (wb.statusCategory === "delivered" || wb.statusCategory === "arrived") {
      badgeClass = "bg-[#e8f6ee] text-[#157a3a] border-[#b7e4c7]";
    } else if (wb.statusCategory === "storage_risk" || wb.statusCategory === "returned") {
      badgeClass = "bg-[#fdecec] text-[#c42323] border-[#f3c7c7]";
    }

    const ssObj = scanSheets.find(s => s.id === wb.scanSheetId);

    return `
      <tr data-row-id="${wb.id}"
        class="cursor-pointer transition-colors ${isInspected ? "bg-[#f6f6f7] ring-1 ring-inset ring-[#111111]" : "hover:bg-[#f6f6f7]/70"}">
        <td class="py-3 pl-4 pr-2 align-top" onclick="event.stopPropagation()">
          <input type="checkbox" data-row-check="${wb.id}" ${isChecked ? "checked" : ""} class="w-4 h-4 mt-0.5 rounded accent-[#111111] cursor-pointer">
        </td>

        <!-- ТТН и Дата -->
        <td class="py-3 px-3 align-top">
          <div class="flex items-center gap-1.5">
            <span class="font-mono text-xs font-extrabold text-[#111111]">${formatTtn(wb.ttn)}</span>
            <button type="button" data-copy-ttn="${wb.ttn}" onclick="event.stopPropagation()" title="Скопировать ТТН"
              class="px-1.5 py-0.5 rounded border border-[#e4e5e9] hover:border-[#111111] bg-white font-mono text-[10px] font-bold text-[#5c6370] hover:text-[#111111]">
              Копия
            </button>
          </div>
          <div class="flex items-center gap-1.5 mt-1">
            <span class="text-[11px] text-[#5c6370]">${esc(wb.createdAt)}</span>
            ${ssObj
              ? `<span class="font-mono text-[10px] font-bold px-1.5 py-0.2 rounded bg-[#e8f6ee] text-[#157a3a] border border-[#b7e4c7]">${esc(ssObj.number)}</span>`
              : `<span class="font-mono text-[10px] px-1.5 py-0.2 rounded bg-[#f6f6f7] text-[#5c6370]">Без реестра</span>`
            }
          </div>
        </td>

        <!-- Заказ -->
        <td class="py-3 px-3 align-top">
          <span class="font-mono text-xs font-extrabold text-[#111111] px-2 py-0.5 rounded bg-[#f6f6f7] border border-[#e4e5e9]">${esc(wb.orderId)}</span>
        </td>

        <!-- Получатель и Город/Отделение -->
        <td class="py-3 px-3 align-top max-w-[260px]">
          <div class="font-bold text-xs text-[#111111] truncate">${esc(wb.recipient)}</div>
          <div class="font-mono text-[11px] text-[#5c6370]">+${esc(wb.phone)}</div>
          <div class="flex items-center gap-1.5 mt-1">
            ${pointIcon}
            <span class="text-[11px] text-[#5c6370] truncate">${esc(wb.city)}, ${esc(wb.point)}</span>
          </div>
        </td>

        <!-- Вес и Габариты -->
        <td class="py-3 px-3 align-top whitespace-nowrap">
          <div class="font-mono text-xs font-bold text-[#111111]">${wb.weight} кг</div>
          <div class="font-mono text-[11px] text-[#5c6370]">${esc(wb.dimensions)} см</div>
        </td>

        <!-- Финансы -->
        <td class="py-3 px-3 align-top whitespace-nowrap">
          <div class="font-mono text-xs font-extrabold text-[#111111]">${wb.cost.toLocaleString("ru-RU")} ₴</div>
          <div class="mt-0.5 flex flex-col gap-0.5">
            ${wb.cod
              ? `<span class="inline-block w-fit font-mono text-[10px] font-bold px-1.5 py-0.2 rounded bg-[#fdecec] text-[#c42323]">Наложка: ${wb.codAmount.toLocaleString("ru-RU")} ₴</span>`
              : `<span class="inline-block w-fit font-mono text-[10px] font-bold px-1.5 py-0.2 rounded bg-[#e8f6ee] text-[#157a3a]">Оплачен на сайте</span>`
            }
            <span class="text-[10px] text-[#5c6370]">Доставка (${wb.shippingCost} ₴): ${wb.payer === "Sender" ? "Магазин" : "Клиент"}</span>
          </div>
        </td>

        <!-- Статус трекинга -->
        <td class="py-3 pl-3 pr-4 align-top">
          <span class="inline-block font-bold text-[11px] px-2.5 py-1 rounded-full border ${badgeClass}">
            ${esc(wb.statusText)}
          </span>
          <div class="text-[11px] mt-1 ${wb.statusCategory === "storage_risk" || wb.statusCategory === "returned" ? "text-[#c42323] font-bold" : "text-[#5c6370]"}">
            ${esc(wb.storageNote)}
          </div>
        </td>
      </tr>
    `;
  }).join("");
}

/* ========================================================================= */
/* РЕНДЕР ПРАВОГО ИНСПЕКТОРА ВЫБРАННОЙ ЭН (380px)                            */
/* ========================================================================= */
function renderInspector() {
  const container = document.getElementById("right-inspector");
  const wb = waybills.find(w => w.id === inspectedId) || waybills[0];

  if (!wb) {
    container.innerHTML = `<div class="p-6 text-center text-xs text-[#5c6370]">Выберите накладную в таблице слева</div>`;
    return;
  }

  let statusPillClass = "bg-[#f6f6f7] text-[#111111] border-[#e4e5e9]";
  if (wb.statusCategory === "delivered" || wb.statusCategory === "arrived") {
    statusPillClass = "bg-[#e8f6ee] text-[#157a3a] border-[#b7e4c7]";
  } else if (wb.statusCategory === "storage_risk" || wb.statusCategory === "returned") {
    statusPillClass = "bg-[#fdecec] text-[#c42323] border-[#f3c7c7]";
  }

  const inScanSheet = Boolean(wb.scanSheetId);
  const canDelete = wb.statusCategory === "new";

  container.innerHTML = `
    <!-- Шапка карточки ЭН -->
    <div class="p-3.5 rounded-[12px] border border-[#111111] bg-[#f6f6f7] space-y-2.5">
      <div class="flex items-center justify-between gap-2">
        <span class="font-mono text-[10px] font-bold uppercase tracking-wider text-[#5c6370]">Заказ ${esc(wb.orderId)}</span>
        <span class="text-[11px] font-bold px-2.5 py-0.5 rounded-full border ${statusPillClass}">${esc(wb.statusText)}</span>
      </div>

      <div class="flex items-center justify-between gap-2">
        <div class="font-mono text-[17px] font-extrabold tracking-tight text-[#111111]">${formatTtn(wb.ttn)}</div>
        <button type="button" data-copy-ttn="${wb.ttn}" class="px-2.5 py-1 rounded-[8px] bg-[#111111] text-white font-mono text-[11px] font-bold hover:bg-[#27272a]">
          Копировать
        </button>
      </div>

      <!-- Кнопки быстрой печати -->
      <div class="grid grid-cols-2 gap-2 pt-1">
        <button type="button" id="btn-insp-print-marking" class="py-2 px-2.5 rounded-[8px] bg-[#111111] hover:bg-[#27272a] text-white text-xs font-bold transition-colors text-center">
          Маркировка 100×100
        </button>
        <button type="button" id="btn-insp-print-doc" class="py-2 px-2.5 rounded-[8px] border border-[#111111] bg-white hover:bg-[#f3f4f6] text-[#111111] text-xs font-bold transition-colors text-center">
          Бланк PDF А4
        </button>
      </div>
    </div>

    <!-- Визуальный мини-превью термоэтикетки 100×100 мм -->
    <div class="p-3.5 rounded-[12px] border-2 border-[#111111] bg-white space-y-2 relative">
      <div class="flex items-center justify-between border-b-2 border-[#111111] pb-1.5">
        <div class="flex items-center gap-1.5">
          <span class="px-1.5 py-0.5 bg-[#c42323] text-white font-extrabold text-[10px] rounded-[4px]">НП</span>
          <span class="font-mono text-[11px] font-extrabold text-[#111111]">100×100 мм</span>
        </div>
        <span class="font-mono text-xs font-extrabold text-[#111111]">${esc(wb.warehouseIndex)}</span>
      </div>

      <!-- Имитация штрих-кода -->
      <div class="py-1 flex flex-col items-center">
        <div class="w-full h-9 flex items-stretch justify-between gap-[1.5px] px-2">
          ${Array.from({ length: 38 }).map((_, i) => `<span class="bg-[#111111] ${i % 3 === 0 ? "w-[3px]" : i % 2 === 0 ? "w-[1.5px]" : "w-[2px]"}"></span>`).join("")}
        </div>
        <div class="font-mono text-[11px] font-bold tracking-widest text-[#111111] mt-1">${wb.ttn}</div>
      </div>

      <div class="pt-1.5 border-t border-[#e4e5e9] grid grid-cols-3 gap-2 text-[11px]">
        <div class="col-span-2">
          <div class="text-[9px] font-mono uppercase text-[#5c6370]">Получатель</div>
          <div class="font-bold text-[#111111] truncate">${esc(wb.recipient)}</div>
          <div class="text-[#5c6370] truncate">${esc(wb.city)} • ${esc(wb.point)}</div>
        </div>
        <div class="text-right border-l border-[#e4e5e9] pl-2">
          <div class="text-[9px] font-mono uppercase text-[#5c6370]">Вес / Мест</div>
          <div class="font-mono font-extrabold text-[#111111]">${wb.weight} кг</div>
          <div class="font-mono text-[10px] text-[#5c6370]">1 / 1</div>
        </div>
      </div>
    </div>

    <!-- Хронология трекинга (TrackingDocument) -->
    <div class="p-3.5 rounded-[12px] border border-[#e4e5e9] bg-white space-y-2.5">
      <div class="flex items-center justify-between">
        <span class="text-xs font-extrabold text-[#111111]">Хронология трекинга</span>
        <code class="font-mono text-[10px] px-1.5 py-0.5 rounded bg-[#f6f6f7] border border-[#e4e5e9] text-[#5c6370]">TrackingDocument</code>
      </div>
      <div class="space-y-2.5 pl-1">
        ${wb.timeline.map((step, idx) => `
          <div class="flex items-start gap-2.5 relative">
            <span class="w-2.5 h-2.5 rounded-full mt-1 shrink-0 ${step.done ? "bg-[#157a3a]" : "bg-[#d1d5db]"}"></span>
            <div class="min-w-0 flex-1">
              <div class="flex items-center justify-between gap-2 text-[10px] font-mono text-[#5c6370]">
                <span>${esc(step.time)}</span>
                <span class="truncate">${esc(step.place)}</span>
              </div>
              <div class="text-xs ${step.done ? "font-semibold text-[#111111]" : "text-[#5c6370]"} leading-snug">${esc(step.text)}</div>
            </div>
          </div>
        `).join("")}
      </div>
    </div>

    <!-- Управление и доп. операции API -->
    <div class="p-3.5 rounded-[12px] border border-[#e4e5e9] bg-white space-y-2">
      <div class="text-xs font-extrabold text-[#111111] pb-1">Операции API по накладной</div>

      <!-- Добавить / Убрать из реестра ScanSheet -->
      <button type="button" id="btn-insp-toggle-scansheet"
        class="w-full py-2 px-3 rounded-[10px] border text-xs font-bold flex items-center justify-between transition-colors ${inScanSheet ? "border-[#b7e4c7] bg-[#e8f6ee] text-[#157a3a]" : "border-[#e4e5e9] hover:border-[#111111] bg-white text-[#111111]"}">
        <span>${inScanSheet ? "Убрать из реестра отгрузки" : "Добавить в открытый реестр дня"}</span>
        <code class="font-mono text-[10px] opacity-80">ScanSheet</code>
      </button>

      <!-- Оформить переадресацию -->
      <button type="button" id="btn-insp-toggle-redirect"
        class="w-full py-2 px-3 rounded-[10px] border border-[#e4e5e9] hover:border-[#111111] bg-white text-[#111111] text-xs font-bold flex items-center justify-between transition-colors">
        <span>Оформить переадресацию</span>
        <code class="font-mono text-[10px] text-[#5c6370]">AdditionalServiceGeneral</code>
      </button>

      ${activeRightActionForm === "redirect" ? `
        <div class="p-3 rounded-[10px] bg-[#f6f6f7] border border-[#111111] space-y-2">
          <div class="text-[11px] font-bold text-[#111111]">Новое отделение или адрес получателя:</div>
          <input type="text" id="redirect-new-point" value="Відділення №15 (вул. Шевченка, 102)" class="w-full px-2.5 py-1.5 rounded-[8px] border border-[#d5d7dd] bg-white text-xs font-semibold">
          <button type="button" id="btn-submit-redirect" class="w-full py-1.5 rounded-[8px] bg-[#111111] text-white text-xs font-bold">
            Подтвердить переадресацию (save)
          </button>
        </div>
      ` : ""}

      <!-- Оформить возврат -->
      <button type="button" id="btn-insp-toggle-return"
        class="w-full py-2 px-3 rounded-[10px] border border-[#e4e5e9] hover:border-[#c42323] bg-white text-[#111111] hover:text-[#c42323] text-xs font-bold flex items-center justify-between transition-colors">
        <span>Оформить возврат посылки</span>
        <code class="font-mono text-[10px] text-[#5c6370]">AdditionalService</code>
      </button>

      ${activeRightActionForm === "return" ? `
        <div class="p-3 rounded-[10px] bg-[#fdecec] border border-[#f3c7c7] space-y-2">
          <div class="text-[11px] font-bold text-[#c42323]">Причина возврата (getReturnReasons):</div>
          <select id="return-reason-select" class="w-full px-2.5 py-1.5 rounded-[8px] border border-[#f3c7c7] bg-white text-xs font-semibold text-[#111111]">
            <option>Отказ получателя от посылки</option>
            <option>Получатель не явился в отделение (истёк срок)</option>
            <option>Ошибка в параметрах заказа магазина</option>
          </select>
          <button type="button" id="btn-submit-return" class="w-full py-1.5 rounded-[8px] bg-[#c42323] text-white text-xs font-bold">
            Создать заявку на возврат (save)
          </button>
        </div>
      ` : ""}

      <!-- Удалить ЭН -->
      <button type="button" id="btn-insp-delete-en" ${canDelete ? "" : "disabled"}
        class="w-full py-2 px-3 rounded-[10px] border text-xs font-bold flex items-center justify-between transition-colors ${canDelete ? "border-[#f3c7c7] bg-[#fdecec] hover:bg-[#c42323] text-[#c42323] hover:text-white cursor-pointer" : "border-[#e4e5e9] bg-[#f6f6f7] text-[#a3a3a3] cursor-not-allowed"}">
        <span>${canDelete ? "Удалить черновик ЭН" : "Удаление недоступно (груз в пути)"}</span>
        <code class="font-mono text-[10px] opacity-80">delete</code>
      </button>
    </div>
  `;
}

/* ========================================================================= */
/* ОБНОВЛЕНИЕ ПАНЕЛИ МАССОВЫХ ДЕЙСТВИЙ (BULK ACTION BAR)                      */
/* ========================================================================= */
function renderBulkBar() {
  const bar = document.getElementById("bulk-action-bar");
  const count = selectedRowIds.size;
  if (count === 0) {
    bar.classList.add("hidden");
    return;
  }
  bar.classList.remove("hidden");
  document.getElementById("bulk-selected-count").textContent = `Выбрано: ${count}`;
}

function renderAll() {
  renderSidebar();
  renderTable();
  renderInspector();
  renderBulkBar();
  try {
    if (!window.NP_BOOT || NP_BOOT.mode !== "opencart") {
      localStorage.setItem("np_dispatcher_docs_v1", JSON.stringify({ waybills, scanSheets }));
    } else if (NP_BOOT.docsUrl) {
      clearTimeout(renderAll._saveT);
      renderAll._saveT = setTimeout(() => {
        fetch(NP_BOOT.docsUrl, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ waybills, scanSheets })
        }).catch(() => {});
      }, 400);
    }
  } catch (e) {}
}

/* ========================================================================= */
/* ЛОГИКА МОДАЛЬНОГО МАСТЕРА СОЗДАНИЯ НОВОЙ ЭН                               */
/* ========================================================================= */
function updateVolumetricCalc() {
  const l = Number(document.getElementById("new-length").value) || 0;
  const w = Number(document.getElementById("new-width").value) || 0;
  const h = Number(document.getElementById("new-height").value) || 0;
  const factW = Number(document.getElementById("new-weight").value) || 0;
  const volW = ((l * w * h) / 4000).toFixed(2);
  document.getElementById("new-vol-weight").textContent = `${volW} кг`;

  const sorted = [l, w, h].sort((a, b) => a - b);
  const fits = factW <= 20 && sorted[0] <= 30 && sorted[1] <= 40 && sorted[2] <= 60;
  const fitLabel = document.getElementById("new-postomat-fit");
  fitLabel.textContent = fits ? "Влезает в почтомат" : "Только отделение (>лимита)";
  fitLabel.className = fits ? "text-[10px] font-bold text-[#157a3a]" : "text-[10px] font-bold text-[#c42323]";
}

function openCreateModal() {
  const grid = document.getElementById("preset-orders-grid");
  grid.innerHTML = READY_ORDERS.map((ord, i) => `
    <button type="button" data-fill-order="${i}"
      class="text-left p-2.5 rounded-[10px] border border-[#e4e5e9] hover:border-[#111111] bg-white transition-colors">
      <div class="flex items-center justify-between font-mono text-xs font-extrabold text-[#111111]">
        <span>${esc(ord.orderId)}</span>
        <span>${ord.cost.toLocaleString("ru-RU")} ₴</span>
      </div>
      <div class="text-[11px] font-bold text-[#111111] truncate mt-0.5">${esc(ord.recipient)}</div>
      <div class="text-[10px] text-[#5c6370] truncate">${esc(ord.city)} • ${ord.cod ? "Наложка" : "Оплачен"}</div>
    </button>
  `).join("");
  updateVolumetricCalc();
  document.getElementById("create-modal-backdrop").classList.remove("hidden");
}

function closeCreateModal() {
  document.getElementById("create-modal-backdrop").classList.add("hidden");
}

/* ========================================================================= */
/* ОБРАБОТЧИКИ СОБЫТИЙ                                                       */
/* ========================================================================= */
document.body.addEventListener("click", (e) => {
  // Фильтры слева
  const filterBtn = e.target.closest("[data-filter-id]");
  if (filterBtn) {
    activeFilter = filterBtn.dataset.filterId;
    renderAll();
    return;
  }

  // Копирование ТТН
  const copyBtn = e.target.closest("[data-copy-ttn]");
  if (copyBtn) {
    copyText(copyBtn.dataset.copyTtn, "Номер ТТН скопирован");
    return;
  }

  // Выбор строки таблицы для правого инспектора
  const rowTr = e.target.closest("tr[data-row-id]");
  if (rowTr) {
    inspectedId = rowTr.dataset.rowId;
    activeRightActionForm = null;
    renderTable();
    renderInspector();
    return;
  }

  // Автозаполнение заказа в модальном окне
  const fillBtn = e.target.closest("[data-fill-order]");
  if (fillBtn) {
    const ord = READY_ORDERS[Number(fillBtn.dataset.fillOrder)];
    if (ord) {
      document.getElementById("new-order-id").value = ord.orderId;
      document.getElementById("new-recipient").value = ord.recipient;
      document.getElementById("new-phone").value = ord.phone;
      document.getElementById("new-service-type").value = ord.serviceType;
      document.getElementById("new-city").value = ord.city;
      document.getElementById("new-point").value = ord.point;
      document.getElementById("new-weight").value = ord.weight;
      document.getElementById("new-length").value = ord.length;
      document.getElementById("new-width").value = ord.width;
      document.getElementById("new-height").value = ord.height;
      document.getElementById("new-cost").value = ord.cost;
      document.getElementById("new-payer").value = ord.payer;
      document.getElementById("new-cod").checked = ord.cod;
      document.getElementById("new-description").value = ord.description;
      updateVolumetricCalc();
      showApiToast("Store.OrderFetch", `Данные заказа ${ord.orderId} подставлены`);
    }
    return;
  }

  // Массовые действия (Bulk Action Bar)
  const bulkBtn = e.target.closest("[data-bulk-action]");
  if (bulkBtn) {
    const act = bulkBtn.dataset.bulkAction;
    const count = selectedRowIds.size;
    if (act === "printMarkings") {
      printSelected("markings");
    } else if (act === "printDocument") {
      printSelected("document");
    } else if (act === "addScanSheet") {
      const openSs = scanSheets.find(s => s.status === "open") || scanSheets[0];
      waybills.forEach(w => {
        if (selectedRowIds.has(w.id)) w.scanSheetId = openSs.id;
      });
      showApiToast("ScanSheet.insertDocuments", `${count} ЭН добавлены в ${openSs.number}`);
      selectedRowIds.clear();
      renderAll();
    } else if (act === "refreshTracking") {
      trackWaybills(waybills.filter((w) => selectedRowIds.has(w.id)));
    } else if (act === "deleteDrafts") {
      const before = waybills.length;
      waybills = waybills.filter(w => !(selectedRowIds.has(w.id) && w.statusCategory === "new"));
      const deleted = before - waybills.length;
      selectedRowIds.clear();
      if (waybills.length && !waybills.some(w => w.id === inspectedId)) {
        inspectedId = waybills[0].id;
      }
      renderAll();
      showApiToast(
        "InternetDocument.delete",
        deleted > 0 ? `Удалено черновиков ЭН: ${deleted}` : "Можно удалять только новые не принятые ЭН",
        deleted > 0 ? "ok" : "bad"
      );
    }
    return;
  }

  // Кнопки внутри правого инспектора
  if (e.target.id === "btn-insp-print-marking") {
    const wb = waybills.find(w => w.id === inspectedId);
    if (wb) openNpPrint(wb, "markings");
    return;
  }
  if (e.target.id === "btn-insp-print-doc") {
    const wb = waybills.find(w => w.id === inspectedId);
    if (wb) openNpPrint(wb, "document");
    return;
  }
  if (e.target.id === "btn-insp-toggle-scansheet") {
    const wb = waybills.find(w => w.id === inspectedId);
    if (!wb) return;
    if (wb.scanSheetId) {
      wb.scanSheetId = null;
      showApiToast("ScanSheet.removeDocuments", `ТТН ${wb.ttn} исключена из реестра`);
    } else {
      const openSs = scanSheets.find(s => s.status === "open") || scanSheets[0];
      wb.scanSheetId = openSs.id;
      showApiToast("ScanSheet.insertDocuments", `ТТН ${wb.ttn} добавлена в ${openSs.number}`);
    }
    renderAll();
    return;
  }
  if (e.target.id === "btn-insp-toggle-redirect") {
    activeRightActionForm = activeRightActionForm === "redirect" ? null : "redirect";
    renderInspector();
    return;
  }
  if (e.target.id === "btn-submit-redirect") {
    const wb = waybills.find(w => w.id === inspectedId);
    const newPt = document.getElementById("redirect-new-point").value.trim();
    if (wb && newPt) {
      wb.point = newPt;
      wb.timeline.push({ time: "Только что", place: wb.city, text: `Оформлена переадресация на: ${newPt}`, done: true });
      activeRightActionForm = null;
      renderAll();
      showApiToast("AdditionalServiceGeneral.save", "Заявка на переадресацию создана -> success: true");
    }
    return;
  }
  if (e.target.id === "btn-insp-toggle-return") {
    activeRightActionForm = activeRightActionForm === "return" ? null : "return";
    renderInspector();
    return;
  }
  if (e.target.id === "btn-submit-return") {
    const wb = waybills.find(w => w.id === inspectedId);
    const reason = document.getElementById("return-reason-select").value;
    if (wb) {
      wb.statusCategory = "returned";
      wb.statusCode = "10";
      wb.statusText = "Оформлен возврат отправителю";
      wb.storageNote = reason;
      wb.timeline.push({ time: "Только что", place: wb.city, text: `Создан возврат (${reason})`, done: true });
      activeRightActionForm = null;
      renderAll();
      showApiToast("AdditionalService.save", "Заявка на возврат отправлена -> success: true");
    }
    return;
  }
  if (e.target.id === "btn-insp-delete-en") {
    const wb = waybills.find(w => w.id === inspectedId);
    if (wb && wb.statusCategory === "new") {
      waybills = waybills.filter(w => w.id !== wb.id);
      selectedRowIds.delete(wb.id);
      inspectedId = waybills[0]?.id || null;
      renderAll();
      showApiToast("InternetDocument.delete", `Черновик ТТН ${wb.ttn} удалён`);
    }
    return;
  }
});

// Чекбоксы строк
document.getElementById("waybills-tbody").addEventListener("change", (e) => {
  const id = e.target.dataset.rowCheck;
  if (!id) return;
  if (e.target.checked) selectedRowIds.add(id);
  else selectedRowIds.delete(id);
  renderTable();
  renderBulkBar();
});

// Чекбокс "Выделить все"
document.getElementById("select-all-checkbox").addEventListener("change", (e) => {
  const rows = getFilteredWaybills();
  if (e.target.checked) rows.forEach(r => selectedRowIds.add(r.id));
  else rows.forEach(r => selectedRowIds.delete(r.id));
  renderTable();
  renderBulkBar();
});

document.getElementById("btn-clear-selection").addEventListener("click", () => {
  selectedRowIds.clear();
  renderTable();
  renderBulkBar();
});

// Поиск в шапке
const searchInput = document.getElementById("global-search");
const clearSearchBtn = document.getElementById("clear-search-btn");
searchInput.addEventListener("input", (e) => {
  searchQuery = e.target.value;
  clearSearchBtn.classList.toggle("hidden", !searchQuery);
  renderTable();
});
clearSearchBtn.addEventListener("click", () => {
  searchQuery = "";
  searchInput.value = "";
  clearSearchBtn.classList.add("hidden");
  renderTable();
});

// Синхронизация статусов (TrackingDocument.getStatusDocuments)
document.getElementById("btn-sync-statuses").addEventListener("click", () => {
  const icon = document.getElementById("sync-icon");
  icon.classList.add("spin-sync");
  trackWaybills(waybills).finally(() => icon.classList.remove("spin-sync"));
});

// Кнопки реестра ScanSheet слева и сворачивание сайдбара
document.getElementById("btn-toggle-sidebar").addEventListener("click", () => {
  isSidebarCollapsed = !isSidebarCollapsed;
  renderSidebar();
});

document.getElementById("btn-collapsed-scansheet-info").addEventListener("click", () => {
  isSidebarCollapsed = false;
  renderSidebar();
});

document.getElementById("btn-collapsed-create-scansheet").addEventListener("click", () => {
  document.getElementById("btn-create-scansheet").click();
});

document.getElementById("btn-create-scansheet").addEventListener("click", () => {
  const unbatched = waybills.filter(w => !w.scanSheetId);
  if (!unbatched.length) {
    showApiToast("ScanSheet.insertDocuments", "Все накладные уже включены в реестры", "bad");
    return;
  }
  const nextNum = 105 + scanSheets.length - 2;
  const newSs = {
    id: `SS-${nextNum}`,
    number: `Реестр #${nextNum}`,
    date: "Только что",
    status: "open",
    docCount: unbatched.length
  };
  scanSheets.unshift(newSs);
  unbatched.forEach(w => { w.scanSheetId = newSs.id; });
  renderAll();
  showApiToast("ScanSheet.insertDocuments", `Создан ${newSs.number} (${unbatched.length} ЭН)`);
});

document.getElementById("btn-print-scansheet").addEventListener("click", () => {
  const activeSs = scanSheets[0];
  if (!activeSs) return;
  const key = npSettings().connection.apiKey.trim();
  if (activeSs.ref && key.length >= 32) {
    window.open("https://my.novaposhta.ua/scanSheet/printScanSheet/refs[]/" + encodeURIComponent(activeSs.ref) + "/type/pdf/apiKey/" + encodeURIComponent(key), "_blank", "noopener");
    showApiToast("Print.printScanSheet", "Открыт акт " + activeSs.number);
    return;
  }
  showApiToast("Print.printScanSheet", "Печать сводного акта приёма-передачи (" + activeSs.number + ")");
});

// Модальный мастер создания новой ЭН
document.getElementById("btn-open-create-modal").addEventListener("click", openCreateModal);
document.getElementById("btn-close-create-modal").addEventListener("click", closeCreateModal);
document.getElementById("btn-cancel-create-modal").addEventListener("click", closeCreateModal);

["new-weight", "new-length", "new-width", "new-height"].forEach(id => {
  document.getElementById(id).addEventListener("input", updateVolumetricCalc);
});

document.getElementById("create-en-form").addEventListener("submit", (e) => {
  e.preventDefault();
  createExpressWaybill();
});


function npSettings() {
  if (window.NP_BOOT && NP_BOOT.state) return NP_BOOT.state;
  try { return JSON.parse(localStorage.getItem("np_smart_admin_v2_state") || "null") || {}; } catch (e) { return {}; }
}
function categoryFromCode(code) {
  const n = Number(code);
  if (n === 1) return "new";
  if (n === 4 || n === 5 || n === 6 || n === 41 || n === 101) return "transit";
  if (n === 7 || n === 8) return "arrived";
  if (n === 9 || n === 10 || n === 11) return "delivered";
  if (n >= 102 && n <= 106) return "returned";
  return "";
}
function readFormWaybill() {
  const orderId = document.getElementById("new-order-id").value.trim() || "#10495";
  const recipient = document.getElementById("new-recipient").value.trim();
  const phone = document.getElementById("new-phone").value.replace(/\D/g, "") || "380670000000";
  const serviceType = document.getElementById("new-service-type").value;
  const city = document.getElementById("new-city").value.trim();
  const point = document.getElementById("new-point").value.trim();
  const weight = Number(document.getElementById("new-weight").value) || 1;
  const l = Number(document.getElementById("new-length").value) || 20;
  const w = Number(document.getElementById("new-width").value) || 15;
  const h = Number(document.getElementById("new-height").value) || 10;
  const cost = Number(document.getElementById("new-cost").value) || 500;
  const payer = document.getElementById("new-payer").value;
  const cod = document.getElementById("new-cod").checked;
  const description = document.getElementById("new-description").value.trim();
  const pointKind = serviceType === "WarehouseDoors" ? "courier" : /поштомат/i.test(point) ? "postomat" : "branch";
  return { orderId, recipient, phone, serviceType, city, point, weight, l, w, h, cost, payer, cod, description, pointKind };
}
function pushWaybill(fields, ttn, ref, warehouseIndex) {
  const newWb = {
    id: "wb-" + Date.now(),
    ttn,
    ref: ref || "",
    createdAt: "Только что",
    orderId: fields.orderId,
    recipient: fields.recipient,
    phone: fields.phone,
    city: fields.city,
    point: fields.point,
    pointKind: fields.pointKind,
    serviceType: fields.serviceType,
    warehouseIndex: warehouseIndex || "",
    weight: fields.weight,
    dimensions: fields.l + "×" + fields.w + "×" + fields.h,
    cost: fields.cost,
    cod: fields.cod,
    codAmount: fields.cod ? fields.cost : 0,
    payer: fields.payer,
    shippingCost: fields.serviceType === "WarehouseDoors" ? 105 : 70,
    statusCategory: "new",
    statusCode: "1",
    statusText: "Создана (Ожидает передачи)",
    storageNote: "Готова к печати маркировки",
    scanSheetId: null,
    description: fields.description,
    timeline: [
      { time: "Только что", place: "м. Київ", text: "Экспресс-накладная создана (InternetDocument.save)", done: true },
      { time: "Ожидается", place: fields.city, text: "Ожидает включения в реестр отгрузки", done: false }
    ]
  };
  waybills.unshift(newWb);
  activeFilter = "all";
  inspectedId = newWb.id;
  closeCreateModal();
  renderAll();
  return newWb;
}
async function createExpressWaybill() {
  const fields = readFormWaybill();
  const settings = npSettings();
  const key = String(settings.connection && settings.connection.apiKey || "").trim();
  const timeout = settings.connection && settings.connection.timeoutSec;
  if (key.length < 32) {
    showApiToast("InternetDocument.save", "Нужен рабочий API-ключ в настройках модуля (32 символа)", "bad");
    return;
  }
  showApiToast("InternetDocument.save", "Создание ЭН в Новой Почте…");
  const senderName = npCityQuery((settings.sender && settings.sender.city) || "Київ");
  const [parties, senderCity, recipCity] = await Promise.all([
    npCall("Counterparty", "getCounterparties", { CounterpartyProperty: "Sender", Page: "1" }, key, timeout),
    npCall("Address", "searchSettlements", { CityName: senderName, Limit: "1" }, "", timeout),
    npCall("Address", "searchSettlements", { CityName: npCityQuery(fields.city), Limit: "1" }, "", timeout)
  ]);
  if (!parties || !parties.success || !parties.data || !parties.data[0]) {
    showApiToast("Counterparty.getCounterparties", npErrorText(parties), "bad");
    return;
  }
  const sender = parties.data[0];
  const senderRef = sender.Ref;
  const [contacts, addresses] = await Promise.all([
    npCall("Counterparty", "getCounterpartyContactPersons", { Ref: senderRef, Page: "1" }, key, timeout),
    npCall("Counterparty", "getCounterpartyAddresses", { Ref: senderRef, CounterpartyProperty: "Sender" }, key, timeout)
  ]);
  const contact = contacts && contacts.data && contacts.data[0];
  const address = addresses && addresses.data && addresses.data[0];
  if (!contact || !address) {
    showApiToast("Counterparty.getCounterpartyAddresses", "У контрагента-отправителя нет контакта или адреса в кабинете НП", "bad");
    return;
  }
  const senderCityRef = senderCity && senderCity.data && senderCity.data[0] && senderCity.data[0].Addresses && senderCity.data[0].Addresses[0] && senderCity.data[0].Addresses[0].DeliveryCity;
  const recip = recipCity && recipCity.data && recipCity.data[0] && recipCity.data[0].Addresses && recipCity.data[0].Addresses[0];
  if (!senderCityRef || !recip) {
    showApiToast("Address.searchSettlements", "Город отправителя или получателя не найден", "bad");
    return;
  }
  let recipientAddressRef = "";
  let warehouseIndex = "";
  if (fields.serviceType !== "WarehouseDoors") {
    const wh = await npCall("Address", "getWarehouses", { CityRef: recip.DeliveryCity, Limit: "50", Page: "1" }, "", timeout);
    const list = (wh && wh.data) || [];
    const needle = fields.point.toLowerCase();
    const found = list.find((w) => String(w.Description || "").toLowerCase().includes(needle.slice(0, 18))) || list[0];
    if (!found) {
      showApiToast("Address.getWarehouses", "В городе получателя нет отделений", "bad");
      return;
    }
    recipientAddressRef = found.Ref;
    warehouseIndex = found.WarehouseIndex || (found.Number ? String(found.Number) : "");
  }
  const now = new Date();
  const dateTime = String(now.getDate()).padStart(2, "0") + "." + String(now.getMonth() + 1).padStart(2, "0") + "." + now.getFullYear();
  const props = {
    PayerType: fields.payer,
    PaymentMethod: (settings.waybill && settings.waybill.payment) || "Cash",
    DateTime: dateTime,
    CargoType: (settings.waybill && settings.waybill.cargoType) || "Parcel",
    Weight: String(fields.weight),
    ServiceType: fields.serviceType,
    SeatsAmount: "1",
    Description: fields.description.slice(0, 120),
    Cost: String(fields.cost),
    CitySender: senderCityRef,
    Sender: senderRef,
    SenderAddress: address.Ref,
    ContactSender: contact.Ref,
    SendersPhone: String((contact.Phones || (settings.sender && settings.sender.phone) || "")).replace(/\D/g, ""),
    CityRecipient: recip.DeliveryCity,
    RecipientsPhone: fields.phone,
    NewAddress: "1",
    RecipientName: fields.recipient,
    RecipientType: "PrivatePerson",
    RecipientCityName: recip.Present || fields.city,
    RecipientAddressName: fields.point,
    OptionsSeat: [{
      volumetricVolume: ((fields.l * fields.w * fields.h) / 1000000).toFixed(4),
      volumetricWidth: String(fields.w),
      volumetricLength: String(fields.l),
      volumetricHeight: String(fields.h),
      weight: String(fields.weight)
    }]
  };
  if (fields.serviceType !== "WarehouseDoors" && recipientAddressRef) props.RecipientAddress = recipientAddressRef;
  if (fields.cod) {
    props.BackwardDeliveryData = [{ PayerType: (settings.money && settings.money.codPayer) || "Recipient", CargoType: "Money", RedeliveryString: String(fields.cost) }];
  }
  const model = settings.connection && settings.connection.addressMode === "index" ? "InternetDocumentGeneral" : "InternetDocument";
  const saved = await npCall(model, "save", props, key, timeout);
  if (!saved || !saved.success || !saved.data || !saved.data[0]) {
    showApiToast(model + ".save", npErrorText(saved), "bad");
    return;
  }
  const doc = saved.data[0];
  const ttn = String(doc.IntDocNumber || "");
  pushWaybill(fields, ttn, doc.Ref || "", warehouseIndex);
  showApiToast("InternetDocument.save -> success: true", "Присвоен номер ТТН: " + formatTtn(ttn));
}
async function trackWaybills(list) {
  const rows = (list || []).filter((w) => String(w.ttn || "").replace(/\D/g, "").length === 14);
  if (!rows.length) {
    showApiToast("TrackingDocument.getStatusDocuments", "Нет 14-значных ТТН для трекинга", "bad");
    return;
  }
  const settings = npSettings();
  const key = String(settings.connection && settings.connection.apiKey || "").trim();
  const r = await npCall("TrackingDocument", "getStatusDocuments", {
    Documents: rows.map((w) => ({ DocumentNumber: String(w.ttn).replace(/\D/g, ""), Phone: String(w.phone || "").replace(/\D/g, "") }))
  }, key, settings.connection && settings.connection.timeoutSec);
  if (!r || !r.success || !Array.isArray(r.data)) {
    showApiToast("TrackingDocument.getStatusDocuments", npErrorText(r), "bad");
    return;
  }
  let updated = 0;
  r.data.forEach((row) => {
    const num = String(row.Number || "");
    const wb = waybills.find((w) => String(w.ttn).replace(/\D/g, "") === num);
    if (!wb || !row.Status) return;
    wb.statusText = row.Status;
    wb.statusCode = String(row.StatusCode || wb.statusCode || "");
    const cat = categoryFromCode(row.StatusCode);
    if (cat) wb.statusCategory = cat;
    if (row.WarehouseRecipient) wb.storageNote = row.WarehouseRecipient;
    wb.timeline = wb.timeline || [];
    wb.timeline.push({ time: "Только что", place: row.CityRecipient || wb.city, text: row.Status, done: true });
    updated += 1;
  });
  renderAll();
  showApiToast("TrackingDocument.getStatusDocuments", updated ? ("Актуализированы статусы: " + updated + " из " + rows.length) : "Новая Почта не вернула статусы по этим ТТН", updated ? "ok" : "bad");
}
function openNpPrint(wb, kind) {
  const key = String(npSettings().connection && npSettings().connection.apiKey || "").trim();
  if (wb.ref && key.length >= 32) {
    const path = kind === "markings" ? "printMarking" : "printDocument";
    window.open("https://my.novaposhta.ua/orders/" + path + "/orders[]/" + encodeURIComponent(wb.ref) + "/type/pdf/apiKey/" + encodeURIComponent(key), "_blank", "noopener");
    showApiToast(kind === "markings" ? "Print.printMarkings" : "Print.printDocument", "Открыта печать ТТН " + wb.ttn);
    return;
  }
  showApiToast(kind === "markings" ? "Print.printMarkings" : "Print.printDocument", kind === "markings" ? ("Печать стикера 100×100 мм для ТТН " + wb.ttn) : ("Печать бланка А4 PDF для ТТН " + wb.ttn));
}
function printSelected(kind) {
  const rows = waybills.filter((w) => selectedRowIds.has(w.id));
  const printable = rows.filter((w) => w.ref);
  if (!printable.length) {
    showApiToast(kind === "markings" ? "Print.printMarkings (100x100)" : "Print.printDocument (A4)", "Для " + rows.length + " ЭН нет Ref документа — печать откроется после реального InternetDocument.save");
    return;
  }
  printable.forEach((w) => openNpPrint(w, kind));
}
npFixNav();

// Инициализация
renderAll();

}
