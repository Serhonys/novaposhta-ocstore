if (!window.__NP_SETTINGS_BOOTED__) {
window.__NP_SETTINGS_BOOTED__ = true;

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


const DATA = JSON.parse(document.getElementById("np-data").textContent);
const STORAGE_KEY = "np_smart_admin_v2_state";

const STEPS = [
  { id: "step1", num: "01", title: "Подключение и склад", sub: "API-ключ, база, отправитель" },
  { id: "step2", num: "02", title: "Чекаут и тарифы", sub: "Способы, точки, расчёт цены" },
  { id: "step3", num: "03", title: "Оплата и наложка", sub: "Плательщик, комиссия, порог" },
  { id: "step4", num: "04", title: "Накладная и услуги", sub: "Габариты, ЭН, допсервисы" },
  { id: "step5", num: "05", title: "Трекинг, реестр и печать", sub: "Статусы, ScanSheet, марки" },
  { id: "step6", num: "06", title: "Каталог API (66)", sub: "Все методы v2.0 и поиск" },
];

function createDefaults() {
  const defaultSync = new Set([
    "getCities", "getWarehouses", "getWarehouseTypes", "getAreas",
    "getCargoTypes", "getServiceTypes", "getPackList", "getTypesOfPayers",
    "getPaymentForms", "getBackwardDeliveryCargoTypes"
  ]);
  const sync = {};
  DATA.methods.forEach(m => {
    if (m.model === "Address" || m.model === "Common") {
      sync[m.method] = defaultSync.has(m.method);
    }
  });

  const makeMethod = (title, enabled, maxWeight, freeFrom = "0") => ({
    enabled, title, priceMode: "tariff", fixedPrice: "70", freeFrom, maxWeight
  });

  return {
    connection: {
      enabled: true,
      apiKey: "9a7f3c81e4b20d56c18f4a29b7e301d8",
      format: "json",
      timeoutSec: "30",
      debug: false,
      addressMode: "ref"
    },
    directories: {
      liveSearch: true,
      intervalHours: "24",
      lastSync: "Сегодня, 04:00",
      sync
    },
    sender: {
      counterpartyType: "Organization",
      name: "ФОП Коваленко О.В.",
      edrpou: "3194820194",
      contact: "Мельник Ірина",
      phone: "380675550199",
      email: "logistics@store.ua",
      city: "м. Київ",
      handover: "warehouse",
      warehouse: "Відділення №14, вул. Хрещатик, 22",
      street: "",
      building: ""
    },
    warehouseKinds: { branch: true, postomat: true, cargo: false, store: false },
    methods: {
      WarehouseWarehouse: makeMethod("Нова Пошта (отделение / почтомат)", true, "30", "2000"),
      WarehouseDoors: makeMethod("Курьером Новой Почты до двери", true, "30", "0"),
      DoorsWarehouse: makeMethod("Забор курьером → отделение", false, "30", "0"),
      DoorsDoors: makeMethod("Курьер → курьер", false, "30", "0"),
    },
    waybill: {
      newAddress: true,
      description: "Замовлення інтернет-магазину #{order.id}",
      cargoType: "Parcel",
      weight: "1.5",
      seats: "1",
      length: "30",
      width: "20",
      height: "15",
      costSource: "order",
      fixedCost: "500",
      payer: "Recipient",
      payment: "Cash",
      thirdPerson: "",
      dateOffset: "0",
      infoBarcode: true
    },
    money: {
      cod: true,
      codPayer: "Recipient",
      codCommissionNote: "Комиссия Новой Почты за денежный перевод: 20 грн + 2% от суммы заказа.",
      paymentControl: false,
      paymentControlSource: "order"
    },
    extras: {
      saturday: false,
      sameDay: false,
      localExpress: false,
      timeInterval: false,
      preferredDate: false,
      deliveryByHand: false,
      floors: "0",
      elevator: false,
      ageCheck: false,
      expressPallet: false,
      avia: false,
      redBox: false,
      additionalInfo: "Замовлення #{order.id}"
    },
    tracking: {
      enabled: true,
      intervalMin: "60",
      batch: "100",
      notifyCustomer: true,
      map: [
        { np: "Створено (Код 1)", store: "Готов к отгрузке" },
        { np: "Відправлення прямує (Код 4/5)", store: "Отправлен (В пути)" },
        { np: "Прибув у відділення (Код 7/8)", store: "Ожидает в отделении" },
        { np: "Одержано (Код 9)", store: "Выполнен" },
        { np: "Відмова / Повернення (Код 10/11)", store: "Возврат / Отмена" },
      ]
    },
    registry: { auto: true, orientation: "portrait", removeOnCancel: true },
    returns: { allowReturn: true, allowRedirect: true, defaultReason: "" },
    print: { format: "pdf", marking: true, copies: "1" },
  };
}

function deepMerge(base, extra) {
  if (Array.isArray(base)) return Array.isArray(extra) ? extra : base;
  if (base && typeof base === "object") {
    const out = {};
    for (const k of Object.keys(base)) out[k] = deepMerge(base[k], extra ? extra[k] : undefined);
    return out;
  }
  return extra === undefined ? base : extra;
}

let state = (() => {
  const defaults = createDefaults();
  let stored = null;
  try { stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || "null"); } catch (e) { stored = null; }
  const boot = window.NP_BOOT && window.NP_BOOT.state && typeof window.NP_BOOT.state === "object" ? window.NP_BOOT.state : null;
  return deepMerge(defaults, boot || stored);
})();

let activeStep = "step1";
let activePreset = "classic";
let inspectorTab = "preview";
let selectedCheckoutMethod = "WarehouseWarehouse";
let catalogQuery = "";
let catalogModel = "";
let catalogAuth = "";

const getVal = (path) => path.split(".").reduce((acc, k) => acc?.[k], state);
function setVal(path, value) {
  const keys = path.split(".");
  let cur = state;
  for (let i = 0; i < keys.length - 1; i++) cur = cur[keys[i]];
  cur[keys[keys.length - 1]] = value;
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  npScheduleSave(state);
}

const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({
  "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
}[c]));

function notify(msg, tone = "ok") {
  const box = document.getElementById("toast-box");
  const el = document.createElement("div");
  const border = tone === "bad" ? "border-[#c42323] bg-[#fdecec] text-[#c42323]" : "border-[#111111] bg-[#111111] text-white";
  el.className = `pointer-events-auto px-3.5 py-2.5 rounded-[10px] border text-xs font-bold shadow-sm transition-all duration-200 ${border}`;
  el.textContent = msg;
  box.appendChild(el);
  setTimeout(() => {
    el.style.opacity = "0";
    setTimeout(() => el.remove(), 200);
  }, 2600);
}

/* ========================================================================= */
/* ВАЛИДАТОР БЛОКЕРОВ И ПРЕДУПРЕЖДЕНИЙ                                       */
/* ========================================================================= */
function getIssues() {
  const list = [];
  const key = state.connection.apiKey.trim();
  if (!key) {
    list.push({ level: "bad", step: "step1", text: "Не указан API-ключ кабинета Новой Почты. Без него невозможен расчёт тарифов и создание ЭН." });
  } else if (key.length < 20) {
    list.push({ level: "bad", step: "step1", text: "API-ключ слишком короткий (ожидается 32 символа из раздела Настройки → Безопасность → API)." });
  }
  if (!state.sender.name.trim() || !state.sender.phone.trim() || !state.sender.city.trim()) {
    list.push({ level: "bad", step: "step1", text: "Заполните организацию/ФИО, телефон и город отправителя (Sender, SendersPhone, CitySender)." });
  }
  if (state.sender.handover === "warehouse" && !state.sender.warehouse.trim()) {
    list.push({ level: "bad", step: "step1", text: "Не выбрано отделение сдачи посылок (SenderAddress / SenderWarehouseIndex)." });
  }
  if (state.sender.handover === "address" && !state.sender.street.trim()) {
    list.push({ level: "bad", step: "step1", text: "Для забора курьером со склада укажите улицу отправителя." });
  }

  const enabledServices = DATA.service.filter(s => state.methods[s.id].enabled);
  if (!enabledServices.length) {
    list.push({ level: "bad", step: "step2", text: "Не включён ни один способ доставки (ServiceType) — Новая Почта не отобразится на чекауте." });
  }
  if (!Object.values(state.warehouseKinds).some(Boolean)) {
    list.push({ level: "bad", step: "step2", text: "Отключены все типы точек выдачи (отделения, почтоматы, грузовые). Покупателю нечего выбрать." });
  }
  for (const s of enabledServices) {
    const m = state.methods[s.id];
    if (m.priceMode === "fixed" && Number(m.fixedPrice) <= 0) {
      list.push({ level: "bad", step: "step2", text: `«${m.title}»: фиксированная стоимость должна быть больше 0 ₴.` });
    }
  }

  if (state.money.cod && state.money.paymentControl) {
    list.push({ level: "warn", step: "step3", text: "Включены одновременно Наложенный платёж (BackwardDelivery) и Контроль оплаты (AfterpaymentOnGoodsCost). Оставьте один вариант по договору." });
  }
  if (state.waybill.payer === "ThirdPerson" && !state.waybill.thirdPerson.trim()) {
    list.push({ level: "bad", step: "step3", text: "Выбран плательщик ThirdPerson, но не указан Ref третьего лица." });
  }
  if (state.waybill.description.trim().length > 120) {
    list.push({ level: "bad", step: "step4", text: "Описание груза (Description) превышает 120 символов — API отклонит накладную." });
  }
  if (Number(state.waybill.seats) > 1) {
    list.push({ level: "warn", step: "step4", text: `Указано мест: ${state.waybill.seats}. Массив OptionsSeat должен содержать столько же элементов при отправке.` });
  }
  return list;
}

/* ========================================================================= */
/* БИЗНЕС-ПРЕСЕТЫ В 1 КЛИК                                                   */
/* ========================================================================= */
function applyPreset(presetKey) {
  activePreset = presetKey;
  if (presetKey === "classic") {
    state.methods.WarehouseWarehouse.enabled = true;
    state.methods.WarehouseWarehouse.freeFrom = "2500";
    state.methods.WarehouseDoors.enabled = true;
    state.methods.WarehouseDoors.freeFrom = "0";
    state.waybill.payer = "Recipient";
    state.waybill.payment = "Cash";
    state.money.cod = true;
    state.money.codPayer = "Recipient";
    state.money.paymentControl = false;
    notify("Пресет «Классический E-commerce» применён");
  } else if (presetKey === "prepaid_free") {
    state.methods.WarehouseWarehouse.enabled = true;
    state.methods.WarehouseWarehouse.freeFrom = "2000";
    state.methods.WarehouseDoors.enabled = true;
    state.methods.WarehouseDoors.freeFrom = "2000";
    state.waybill.payer = "Sender";
    state.waybill.payment = "NonCash";
    state.money.cod = false;
    state.money.paymentControl = false;
    notify("Пресет «Предоплата + Бесплатно от 2000 ₴» применён");
  } else if (presetKey === "b2b_control") {
    state.methods.WarehouseWarehouse.enabled = true;
    state.methods.WarehouseWarehouse.freeFrom = "0";
    state.waybill.payer = "Recipient";
    state.waybill.payment = "NonCash";
    state.money.cod = false;
    state.money.paymentControl = true;
    state.money.paymentControlSource = "order";
    notify("Пресет «B2B / Контроль оплаты на р/с» применён");
  }
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  renderAll();
}

/* ========================================================================= */
/* ГЕНЕРАТОР ЖИВОГО JSON ДЛЯ InternetDocument.save                           */
/* ========================================================================= */
function buildSavePayload() {
  const modelName = state.connection.addressMode === "index" ? "InternetDocumentGeneral" : "InternetDocument";
  const senderAddrObj = state.connection.addressMode === "index"
    ? { SenderWarehouseIndex: "101/102" }
    : { SenderAddress: "{sender.addressRef}" };

  const props = {
    PayerType: state.waybill.payer,
    PaymentMethod: state.waybill.payment,
    DateTime: `{today+${state.waybill.dateOffset}}`,
    CargoType: state.waybill.cargoType,
    Weight: state.waybill.weight,
    ServiceType: selectedCheckoutMethod,
    SeatsAmount: state.waybill.seats,
    Description: state.waybill.description,
    Cost: state.waybill.costSource === "order" ? "{order.total}" : state.waybill.fixedCost,
    CitySender: "{sender.cityRef}",
    Sender: "{sender.counterpartyRef}",
    ...senderAddrObj,
    ContactSender: "{sender.contactRef}",
    SendersPhone: (state.sender.phone || "").replace(/\D/g, "") || "380000000000",
    CityRecipient: "{recipient.cityRef}",
    RecipientsPhone: "{recipient.phone}"
  };

  if (state.waybill.newAddress) {
    Object.assign(props, {
      NewAddress: "1",
      RecipientName: "{recipient.fullName}",
      RecipientType: "PrivatePerson",
      RecipientCityName: "{recipient.city}",
      RecipientAddressName: "{recipient.warehouseOrStreet}",
      RecipientHouse: "{recipient.house}",
      RecipientFlat: "{recipient.flat}"
    });
  } else {
    Object.assign(props, {
      Recipient: "{recipient.counterpartyRef}",
      RecipientAddress: "{recipient.addressRef}",
      ContactRecipient: "{recipient.contactRef}"
    });
  }

  if (state.connection.addressMode === "index") {
    props.RecipientWarehouseIndex = "{recipient.warehouseIndex}";
  }

  const l = Number(state.waybill.length) || 20;
  const w = Number(state.waybill.width) || 15;
  const h = Number(state.waybill.height) || 10;
  props.OptionsSeat = [{
    weight: state.waybill.weight,
    volumetricLength: String(l),
    volumetricWidth: String(w),
    volumetricHeight: String(h),
    volumetricVolume: ((l * w * h) / 1000000).toFixed(4)
  }];

  if (state.waybill.payer === "ThirdPerson") {
    props.ThirdPerson = state.waybill.thirdPerson || "{thirdPerson.ref}";
  }
  if (state.money.cod) {
    props.BackwardDeliveryData = [{
      PayerType: state.money.codPayer,
      CargoType: "Money",
      RedeliveryString: "{order.total}"
    }];
  }
  if (state.money.paymentControl) {
    props.AfterpaymentOnGoodsCost = state.money.paymentControlSource === "order" ? "{order.total}" : "{paymentControl.amount}";
  }
  if (state.waybill.infoBarcode) {
    props.InfoRegClientBarcodes = "{order.id}";
  }

  const extraFlags = [
    ["saturday", "SaturdayDelivery"],
    ["sameDay", "SameDayDelivery"],
    ["localExpress", "LocalExpress"],
    ["deliveryByHand", "DeliveryByHand"],
    ["elevator", "Elevator"],
    ["ageCheck", "AgeIdentification"],
    ["expressPallet", "ExpressPallet"],
    ["avia", "AviaDelivery"]
  ];
  extraFlags.forEach(([k, apiField]) => {
    if (state.extras[k]) props[apiField] = "1";
  });
  if (state.extras.timeInterval) props.TimeInterval = "{timeInterval.ref}";
  if (state.extras.preferredDate) props.PreferredDeliveryDate = "{preferred.date}";
  if (Number(state.extras.floors) > 0) props.NumberOfFloorsLifting = state.extras.floors;
  if (state.extras.redBox) props.RedBoxBarcode = "{redBox.barcode}";
  if (state.extras.additionalInfo.trim()) props.AdditionalInformation = state.extras.additionalInfo;

  return {
    modelName,
    jsonText: JSON.stringify({
      apiKey: state.connection.apiKey ? "••••••••••••••••••••••••••••••••" : "",
      modelName,
      calledMethod: "save",
      methodProperties: props
    }, null, 2)
  };
}

/* ========================================================================= */
/* UI-ХЕЛПЕРЫ ДЛЯ ПОЛЕЙ, СВИТЧЕЙ И АККОРДЕОНОВ                                */
/* ========================================================================= */
function uiSwitch(path, title, hint, tone = "black") {
  const checked = Boolean(getVal(path));
  return `
    <label class="flex items-start justify-between gap-3 p-3.5 rounded-[10px] border transition-colors cursor-pointer select-none ${checked ? "border-[#111111] bg-white" : "border-[#e4e5e9] bg-[#f6f6f7]"}">
      <div class="space-y-0.5">
        <div class="font-bold text-[13px] text-[#111111]">${esc(title)}</div>
        <div class="text-[12px] text-[#5c6370] leading-snug">${hint}</div>
      </div>
      <span class="pill-switch tone-${tone} mt-0.5">
        <input type="checkbox" data-path="${esc(path)}" ${checked ? "checked" : ""}>
        <span class="pill-knob"></span>
      </span>
    </label>
  `;
}

function uiInput(path, label, hint, apiBadge = "", type = "text", placeholder = "") {
  return `
    <label class="flex flex-col gap-1.5">
      <span class="flex items-center justify-between gap-2 text-xs font-bold text-[#111111]">
        <span>${esc(label)}</span>
        ${apiBadge ? `<code class="font-mono text-[11px] font-semibold px-1.5 py-0.5 rounded-[5px] bg-[#f6f6f7] border border-[#e4e5e9] text-[#5c6370]">${esc(apiBadge)}</code>` : ""}
      </span>
      <input type="${type}" data-path="${esc(path)}" value="${esc(getVal(path))}" placeholder="${esc(placeholder)}"
        class="w-full px-3 py-2 rounded-[8px] border border-[#d5d7dd] focus:border-[#111111] focus:outline-none bg-white text-[13px] font-medium text-[#111111]">
      <span class="text-[11px] text-[#5c6370] leading-snug">${hint}</span>
    </label>
  `;
}

function uiSelect(path, label, hint, options, apiBadge = "") {
  const cur = getVal(path);
  return `
    <label class="flex flex-col gap-1.5">
      <span class="flex items-center justify-between gap-2 text-xs font-bold text-[#111111]">
        <span>${esc(label)}</span>
        ${apiBadge ? `<code class="font-mono text-[11px] font-semibold px-1.5 py-0.5 rounded-[5px] bg-[#f6f6f7] border border-[#e4e5e9] text-[#5c6370]">${esc(apiBadge)}</code>` : ""}
      </span>
      <select data-path="${esc(path)}" class="w-full px-3 py-2 rounded-[8px] border border-[#d5d7dd] focus:border-[#111111] focus:outline-none bg-white text-[13px] font-semibold text-[#111111] cursor-pointer">
        ${options.map(o => `<option value="${esc(o.id)}" ${o.id === cur ? "selected" : ""}>${esc(o.title)}</option>`).join("")}
      </select>
      <span class="text-[11px] text-[#5c6370] leading-snug">${hint}</span>
    </label>
  `;
}

function uiSectionMethods(stepId) {
  const rows = DATA.methods.filter(m => m.section === stepId);
  if (!rows.length) return "";
  return `
    <details class="bg-white border border-[#e4e5e9] rounded-[12px] overflow-hidden">
      <summary class="px-4 py-3 flex items-center justify-between gap-2 cursor-pointer hover:bg-[#f6f6f7] transition-colors">
        <div class="flex items-center gap-2">
          <span class="font-mono text-[11px] font-bold px-2 py-0.5 rounded-[6px] bg-[#111111] text-white">${rows.length} API</span>
          <span class="text-xs font-bold text-[#111111]">Методы API Новой Почты, используемые в этом разделе</span>
        </div>
        <span class="accordion-chevron font-mono text-xs text-[#5c6370] transition-transform">▼</span>
      </summary>
      <div class="px-4 pb-4 pt-1 border-t border-[#e4e5e9] divide-y divide-[#e4e5e9]">
        ${rows.map(m => `
          <div class="py-2.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div class="space-y-0.5">
              <div class="flex items-center gap-2 flex-wrap">
                <code class="font-mono text-[11px] font-bold px-1.5 py-0.5 rounded-[5px] bg-[#f6f6f7] border border-[#e4e5e9] text-[#111111]">${esc(m.model)}.${esc(m.method)}</code>
                <span class="text-xs font-bold text-[#111111]">${esc(m.title)}</span>
              </div>
              <p class="text-[11px] text-[#5c6370]">${esc(m.hint)}</p>
            </div>
            <span class="self-start sm:self-center shrink-0 font-mono text-[10px] font-bold px-2.5 py-0.5 rounded-full border ${m.auth === "key" ? "bg-[#fdecec] text-[#c42323] border-[#f3c7c7]" : "bg-[#e8f6ee] text-[#157a3a] border-[#b7e4c7]"}">
              ${m.auth === "key" ? "нужен ключ" : "без ключа"}
            </span>
          </div>
        `).join("")}
      </div>
    </details>
  `;
}

/* ========================================================================= */
/* РЕНДЕР КАЖДОГО ИЗ 6 ШАГОВ                                                 */
/* ========================================================================= */
function renderStep1() {
  const isAddressHandover = state.sender.handover === "address";
  const dirRows = DATA.methods.filter(m => m.model === "Address" || m.model === "Common");

  return `
    <div class="space-y-4">
      <div>
        <h1 class="text-xl font-extrabold tracking-tight text-[#111111]">1. Подключение API, справочники и склад отправителя</h1>
        <p class="text-xs text-[#5c6370] mt-0.5">Базовая авторизация шлюза, кэширование городов/отделений и реквизиты вашей компании в накладных.</p>
      </div>

      <!-- Карточка API-ключа -->
      <div class="bg-white border border-[#e4e5e9] rounded-[12px] p-4 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-[#e4e5e9]">
          <div>
            <h2 class="text-sm font-extrabold text-[#111111]">Авторизация в шлюзе api.novaposhta.ua/v2.0</h2>
            <p class="text-xs text-[#5c6370]">Ключ используется только на сервере магазина и не передаётся в браузер покупателя.</p>
          </div>
          <span class="font-mono text-[11px] font-bold px-2.5 py-1 rounded-full ${state.connection.apiKey.trim().length >= 20 ? "bg-[#e8f6ee] text-[#157a3a] border border-[#b7e4c7]" : "bg-[#fdecec] text-[#c42323] border border-[#f3c7c7]"}">
            ${state.connection.apiKey.trim().length >= 20 ? "Ключ активен" : "Требуется ключ"}
          </span>
        </div>

        ${uiSwitch("connection.enabled", "Включить модуль доставки Новая Почта", "Когда выключено, способы доставки скрываются на чекауте и блокируется выпуск ЭН.", "green")}

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
          <div class="flex flex-col gap-1.5">
            <span class="flex items-center justify-between text-xs font-bold text-[#111111]">
              <span>API-ключ бизнес-кабинета</span>
              <code class="font-mono text-[11px] px-1.5 py-0.5 rounded-[5px] bg-[#f6f6f7] border border-[#e4e5e9] text-[#5c6370]">apiKey</code>
            </span>
            <div class="flex gap-2">
              <input type="text" data-path="connection.apiKey" value="${esc(state.connection.apiKey)}" placeholder="Вставьте 32-символьный ключ..."
                class="flex-1 px-3 py-2 rounded-[8px] border border-[#d5d7dd] focus:border-[#111111] focus:outline-none font-mono text-xs font-semibold text-[#111111]">
              <button type="button" id="btn-test-key" class="px-3 py-2 rounded-[8px] bg-[#111111] hover:bg-[#27272a] text-white text-xs font-bold shrink-0">
                Проверить
              </button>
            </div>
            <span class="text-[11px] text-[#5c6370]">Получить: business.novaposhta.ua → Настройки → Безопасность → API.</span>
          </div>

          ${uiSelect("connection.addressMode", "Формат идентификации отделений", "Ref (UUID) — стандарт API. Индекс (101/102) использует модель InternetDocumentGeneral.", [
            { id: "ref", title: "Ref отделения (UUID из getWarehouses)" },
            { id: "index", title: "Индекс отделения (формат 101/102)" }
          ], "AddressMode")}
        </div>

        <!-- Спойлер расширенных настроек шлюза и кэша -->
        <details class="border border-[#e4e5e9] rounded-[10px] bg-[#f6f6f7]">
          <summary class="px-3.5 py-2.5 flex items-center justify-between cursor-pointer text-xs font-bold text-[#111111]">
            <span>Расширенные параметры API: справочники, Cron, таймауты и логи</span>
            <span class="accordion-chevron font-mono text-[#5c6370] transition-transform">▼</span>
          </summary>
          <div class="p-3.5 pt-2 border-t border-[#e4e5e9] bg-white rounded-b-[10px] space-y-3.5">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              ${uiSelect("connection.format", "Формат протокола", "JSON рекомендуется для всех современных CMS.", [{ id: "json", title: "JSON" }, { id: "xml", title: "XML" }], "Content-Type")}
              ${uiInput("connection.timeoutSec", "Таймаут запроса (сек)", "Для крупных городов getWarehouses может отвечать до 10–15 сек.", "timeout", "number")}
              ${uiInput("directories.intervalHours", "Автообновление кэша (часов)", "Рекомендуется раз в 24 часа ночным Cron.", "cron", "number")}
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              ${uiSwitch("directories.liveSearch", "Живой поиск городов и улиц на чекауте", "Использует быстрые методы searchSettlements и searchSettlementStreets.", "green")}
              ${uiSwitch("connection.debug", "Писать логи запросов API", "Сохраняет modelName, calledMethod и ошибки с маскировкой ключа.", "black")}
            </div>

            <div class="flex items-center justify-between p-3 rounded-[8px] bg-[#e8f6ee] border border-[#b7e4c7]">
              <div class="text-xs">
                <span class="font-bold text-[#157a3a]">Локальная база справочников синхронизирована</span>
                <span id="last-sync-label" class="block font-mono text-[11px] text-[#157a3a]">Обновлено: ${esc(state.directories.lastSync)}</span>
              </div>
              <button type="button" id="btn-run-sync" class="px-3 py-1.5 rounded-[8px] bg-[#157a3a] hover:bg-[#11632f] text-white text-xs font-bold">
                Обновить сейчас
              </button>
            </div>

            <div>
              <div class="text-xs font-bold text-[#111111] mb-2">Какие справочники включать в ночную синхронизацию:</div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                ${dirRows.map(m => uiSwitch(`directories.sync.${m.method}`, `${m.model}.${m.method}`, m.title, "black")).join("")}
              </div>
            </div>
          </div>
        </details>
      </div>

      <!-- Карточка отправителя -->
      <div class="bg-white border border-[#e4e5e9] rounded-[12px] p-4 space-y-4">
        <div class="pb-3 border-b border-[#e4e5e9]">
          <h2 class="text-sm font-extrabold text-[#111111]">Отправитель и точка отгрузки по умолчанию</h2>
          <p class="text-xs text-[#5c6370]">Эти реквизиты автоматически подставляются в каждую создаваемую экспресс-накладную.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
          ${uiSelect("sender.counterpartyType", "Тип отправителя", "Organization — для ФЛП/ООО, PrivatePerson — для физлица.", [
            { id: "Organization", title: "Организация / ФЛП (Organization)" },
            { id: "PrivatePerson", title: "Физическое лицо (PrivatePerson)" }
          ], "CounterpartyType")}
          ${uiInput("sender.name", "Название организации или ФИО", "Подтягивается из Counterparty.getCounterparties.", "Sender")}
          ${uiInput("sender.contact", "Контактное лицо (менеджер склада)", "ФИО ответственного за передачу груза.", "ContactSender")}
          ${uiInput("sender.phone", "Телефон отправителя", "Формат API: 380XXXXXXXXX без плюса и пробелов.", "SendersPhone")}
          ${uiInput("sender.city", "Город отправки", "Базовая точка А для расчёта тарифов и сроков.", "CitySender")}
          ${uiSelect("sender.handover", "Способ передачи посылок в Новую Почту", "Привозите в отделение или вызываете курьера на склад.", [
            { id: "warehouse", title: "Привозим в отделение (Warehouse)" },
            { id: "address", title: "Забор курьером со склада (Address)" }
          ], "Handover")}
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
          ${isAddressHandover
            ? uiInput("sender.street", "Улица склада отгрузки", "Ref или название улицы для забора курьером.", "SenderAddress") +
              uiInput("sender.building", "Номер дома / корпуса", "Обязательно при курьерском заборе.", "BuildingNumber")
            : uiInput("sender.warehouse", "Отделение отгрузки по умолчанию", "Отделение, куда магазин сдаёт отправления.", "SenderAddress") +
              uiInput("sender.edrpou", "ЕГРПОУ / ИНН (опционально)", "Для юридических лиц и ФЛП.", "EDRPOU")
          }
        </div>
      </div>

      ${uiSectionMethods("step1")}
    </div>
  `;
}

function renderStep2() {
  return `
    <div class="space-y-4">
      <div>
        <h1 class="text-xl font-extrabold tracking-tight text-[#111111]">2. Чекаут, точки выдачи и расчёт тарифов</h1>
        <p class="text-xs text-[#5c6370] mt-0.5">Управляйте тем, какие варианты доставки увидит покупатель в корзине и как рассчитывается цена.</p>
      </div>

      <!-- Типы точек выдачи -->
      <div class="bg-white border border-[#e4e5e9] rounded-[12px] p-4 space-y-3">
        <div class="flex items-center justify-between gap-2 pb-2 border-b border-[#e4e5e9]">
          <div>
            <h2 class="text-sm font-extrabold text-[#111111]">Фильтр типов отделений в чекауте</h2>
            <p class="text-xs text-[#5c6370]">Модуль автоматически скроет почтоматы, если вес > 20 кг или габариты > 40×60×30 см.</p>
          </div>
          <code class="font-mono text-[11px] px-2 py-0.5 rounded-[6px] bg-[#f6f6f7] border border-[#e4e5e9] text-[#5c6370]">getWarehouseTypes</code>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
          ${DATA.kinds.map(k => uiSwitch(`warehouseKinds.${k.id}`, k.title, k.hint, "black")).join("")}
        </div>
      </div>

      <!-- 4 технологии доставки ServiceType -->
      <div class="space-y-3">
        ${DATA.service.map(s => {
          const base = `methods.${s.id}`;
          const m = state.methods[s.id];
          return `
            <div class="bg-white border ${m.enabled ? "border-[#111111]" : "border-[#e4e5e9]"} rounded-[12px] p-4 space-y-3">
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                  <div class="flex items-center gap-2">
                    <h3 class="text-sm font-extrabold text-[#111111]">${esc(s.title)}</h3>
                    <code class="font-mono text-[11px] px-1.5 py-0.5 rounded-[5px] bg-[#f6f6f7] border border-[#e4e5e9] text-[#5c6370]">${esc(s.id)}</code>
                  </div>
                  <p class="text-xs text-[#5c6370] mt-0.5">${esc(s.hint)}</p>
                </div>
                <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                  <span class="text-xs font-bold ${m.enabled ? "text-[#157a3a]" : "text-[#5c6370]"}">${m.enabled ? "Активен" : "Выключен"}</span>
                  <span class="pill-switch tone-green">
                    <input type="checkbox" data-path="${base}.enabled" ${m.enabled ? "checked" : ""}>
                    <span class="pill-knob"></span>
                  </span>
                </label>
              </div>

              ${m.enabled ? `
                <div class="pt-3 border-t border-[#e4e5e9] grid grid-cols-1 md:grid-cols-2 gap-3">
                  ${uiInput(`${base}.title`, "Название способа для покупателя", "Отображается в списке доставки при оформлении заказа.")}
                  ${uiSelect(`${base}.priceMode`, "Расчёт стоимости доставки", "Тариф API вызывает getDocumentPrice с учётом веса и оценки.", [
                    { id: "tariff", title: "Динамический тариф Новой Почты (getDocumentPrice)" },
                    { id: "fixed", title: "Фиксированная цена магазина" }
                  ], "PriceMode")}
                  ${m.priceMode === "fixed"
                    ? uiInput(`${base}.fixedPrice`, "Фиксированная цена, ₴", "Списывается с клиента вместо тарифа API.", "", "number")
                    : uiInput(`${base}.freeFrom`, "Бесплатно при заказе от, ₴ (0 = откл.)", "При достижении суммы доставка становится 0 ₴ для покупателя.", "FreeThreshold", "number")
                  }
                  ${uiInput(`${base}.maxWeight`, "Максимальный вес для метода, кг", "Способ скроется в корзине, если вес заказа выше лимита.", "MaxWeight", "number")}
                </div>
              ` : ""}
            </div>
          `;
        }).join("")}
      </div>

      ${uiSectionMethods("step2")}
    </div>
  `;
}

function renderStep3() {
  return `
    <div class="space-y-4">
      <div>
        <h1 class="text-xl font-extrabold tracking-tight text-[#111111]">3. Оплата доставки, наложенный платёж и контроль оплаты</h1>
        <p class="text-xs text-[#5c6370] mt-0.5">Настройте распределение расходов на логистику и защиту маржи магазина при наложке.</p>
      </div>

      <!-- Плательщик доставки -->
      <div class="bg-white border border-[#e4e5e9] rounded-[12px] p-4 space-y-3.5">
        <div class="pb-2 border-b border-[#e4e5e9]">
          <h2 class="text-sm font-extrabold text-[#111111]">Оплата самой перевозки</h2>
          <p class="text-xs text-[#5c6370]">Кто оплачивает услуги транспортировки в экспресс-накладной по умолчанию.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
          ${uiSelect("waybill.payer", "Плательщик за доставку", "При срабатывании порога бесплатной доставки автоматически меняется на Sender.", [
            { id: "Recipient", title: "Получатель / Покупатель (Recipient)" },
            { id: "Sender", title: "Отправитель / Магазин (Sender)" },
            { id: "ThirdPerson", title: "Третье лицо по договору (ThirdPerson)" }
          ], "PayerType")}
          ${uiSelect("waybill.payment", "Форма оплаты магазина", "NonCash доступен при наличии подписанного договора с Новой Почтой.", [
            { id: "Cash", title: "Наличный расчёт / при получении (Cash)" },
            { id: "NonCash", title: "Безналичный расчёт по договору (NonCash)" }
          ], "PaymentMethod")}
        </div>

        ${state.waybill.payer === "ThirdPerson" ? `
          <div class="pt-2">
            ${uiInput("waybill.thirdPerson", "Ref контрагента третьего лица", "Обязателен, если доставку оплачивает партнёр или фулфилмент.", "ThirdPerson")}
          </div>
        ` : ""}
      </div>

      <!-- Наложенный платеж и Контроль оплаты -->
      <div class="bg-white border border-[#e4e5e9] rounded-[12px] p-4 space-y-3.5">
        <div class="pb-2 border-b border-[#e4e5e9]">
          <h2 class="text-sm font-extrabold text-[#111111]">Обратная доставка денег (Наложка vs Контроль оплаты)</h2>
          <p class="text-xs text-[#5c6370]">Выберите один из инструментов приёма оплаты при получении в соответствии с вашим договором.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          ${uiSwitch("money.cod", "Наложенный платёж (Денежный перевод)", "BackwardDeliveryData с типом Money. Возврат наличными или на карту.", "black")}
          ${uiSwitch("money.paymentControl", "Контроль оплаты (Afterpayment на р/с)", "Перевод выручки напрямую на расчётный счёт ФЛП/ООО по договору.", "green")}
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
          ${uiSelect("money.codPayer", "Кто оплачивает комиссию (20 ₴ + 2%)", "Если выбрать Sender, комиссия удержится из выплаты магазину.", [
            { id: "Recipient", title: "Получатель / Покупатель (Recipient)" },
            { id: "Sender", title: "Отправитель / Магазин (Sender)" }
          ], "Redelivery.PayerType")}
          ${uiSelect("money.paymentControlSource", "База расчёта суммы наложки", "Полная стоимость товаров или остаток за вычетом предоплаты.", [
            { id: "order", title: "Полная сумма заказа ({order.total})" },
            { id: "fixed", title: "Остаток к доплате ({paymentControl.amount})" }
          ], "RedeliveryString")}
        </div>

        <div class="p-3 rounded-[10px] ${state.money.codPayer === "Sender" ? "bg-[#fdecec] border border-[#f3c7c7] text-[#c42323]" : "bg-[#e8f6ee] border border-[#b7e4c7] text-[#157a3a]"} text-xs leading-snug">
          <strong>${state.money.codPayer === "Sender" ? "Внимание на маржу:" : "Маржа защищена:"}</strong>
          ${state.money.codPayer === "Sender"
            ? "комиссию Новой Почты (20 ₴ + 2%) оплачивает магазин. Проверьте чистую выплату в правом инспекторе."
            : "комиссию за перевод денег (20 ₴ + 2%) оплачивает покупатель при получении посылки."}
        </div>

        ${uiInput("money.codCommissionNote", "Подсказка покупателю о комиссии в корзине", "Текст отображается рядом с методом оплаты при получении.")}
      </div>

      ${uiSectionMethods("step3")}
    </div>
  `;
}

function renderStep4() {
  return `
    <div class="space-y-4">
      <div>
        <h1 class="text-xl font-extrabold tracking-tight text-[#111111]">4. Экспресс-накладная, габариты и допуслуги</h1>
        <p class="text-xs text-[#5c6370] mt-0.5">Шаблон формирования InternetDocument.save, резервные габариты коробки и дополнительные опции доставки.</p>
      </div>

      <!-- Основные параметры ЭН и габариты -->
      <div class="bg-white border border-[#e4e5e9] rounded-[12px] p-4 space-y-4">
        <div class="pb-2 border-b border-[#e4e5e9]">
          <h2 class="text-sm font-extrabold text-[#111111]">Параметры груза и коробки по умолчанию (Fallback)</h2>
          <p class="text-xs text-[#5c6370]">Используются при создании накладной, если у товаров в каталоге не заполнены вес и размеры.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
          ${uiSelect("waybill.cargoType", "Тип отправления", "Parcel — стандартная посылка магазина до 30 кг.", DATA.cargo.map(c => ({
            id: c.id, title: `${c.title} (${c.id})`
          })), "CargoType")}
          ${uiInput("waybill.description", "Шаблон описания груза (до 120 симв.)", "Поддерживает переменные {order.id}, {items.names}.", "Description")}
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          ${uiInput("waybill.weight", "Вес, кг", "Фактический вес.", "Weight", "number")}
          ${uiInput("waybill.length", "Длина, см", "Для объёма.", "volumetricLength", "number")}
          ${uiInput("waybill.width", "Ширина, см", "Для объёма.", "volumetricWidth", "number")}
          ${uiInput("waybill.height", "Высота, см", "Для объёма.", "volumetricHeight", "number")}
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          ${uiSwitch("waybill.newAddress", "Создавать получателя на лету (NewAddress=1)", "Не требует предварительного вызова Counterparty.save для каждого нового клиента.", "green")}
          ${uiSwitch("waybill.infoBarcode", "Передавать номер заказа в штрихкод ЭН", "Записывает ID заказа в поле InfoRegClientBarcodes для быстрого поиска.", "black")}
        </div>

        <!-- Спойлер редких опций API и допуслуг -->
        <details class="border border-[#e4e5e9] rounded-[10px] bg-[#f6f6f7]">
          <summary class="px-3.5 py-2.5 flex items-center justify-between cursor-pointer text-xs font-bold text-[#111111]">
            <span>Расширенные параметры API: места, оценка и 11 допуслуг (Авиа, RedBox, этаж, интервалы)</span>
            <span class="accordion-chevron font-mono text-[#5c6370] transition-transform">▼</span>
          </summary>
          <div class="p-3.5 pt-3 border-t border-[#e4e5e9] bg-white rounded-b-[10px] space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              ${uiInput("waybill.seats", "Количество мест", "При >1 нужен массив OptionsSeat.", "SeatsAmount", "number")}
              ${uiSelect("waybill.costSource", "Оценочная стоимость", "Страховая сумма посылки.", [
                { id: "order", title: "Сумма заказа ({order.total})" },
                { id: "fixed", title: "Фиксированная сумма" }
              ], "Cost")}
              ${uiInput("waybill.dateOffset", "Сдвиг даты отправки (+дней)", "0 — сегодня, 1 — завтра (макс. 90).", "DateTime", "number")}
            </div>

            ${state.waybill.costSource === "fixed" ? uiInput("waybill.fixedCost", "Фиксированная оценка, ₴", "Указывается в поле Cost.", "Cost", "number") : ""}

            <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
              ${uiSwitch("extras.saturday", "Субботняя доставка", "SaturdayDelivery=1 (работает в областных центрах).", "black")}
              ${uiSwitch("extras.sameDay", "Доставка день в день", "SameDayDelivery=1 при сдаче до отсечки.", "black")}
              ${uiSwitch("extras.localExpress", "Локал-экспресс", "LocalExpress=1 (ускоренная доставка внутри города).", "black")}
              ${uiSwitch("extras.timeInterval", "Выбор интервала времени", "TimeInterval из справочника getTimeIntervals.", "black")}
              ${uiSwitch("extras.preferredDate", "Желаемая дата доставки", "PreferredDeliveryDate (дд.мм.гггг).", "black")}
              ${uiSwitch("extras.deliveryByHand", "Лично в руки", "DeliveryByHand=1 — выдача строго получателю.", "black")}
              ${uiSwitch("extras.elevator", "Наличие лифта при подъёме", "Elevator=1 (снижает тариф подъёма на этаж).", "black")}
              ${uiSwitch("extras.ageCheck", "Идентификация возраста (18+)", "AgeIdentification=1 для товаров с возрастным цензом.", "black")}
              ${uiSwitch("extras.expressPallet", "Экспресс-палета", "ExpressPallet=1 (только для типа груза Pallet).", "black")}
              ${uiSwitch("extras.avia", "Авиадоставка", "AviaDelivery=1 (где доступно авиаплечо).", "black")}
              ${uiSwitch("extras.redBox", "Фирменная коробка RedBox", "Передаёт штрихкод упаковки RedBoxBarcode.", "red")}
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              ${uiInput("extras.floors", "Подъём на этаж (0 = без подъёма)", "Передаётся в NumberOfFloorsLifting.", "NumberOfFloorsLifting", "number")}
              ${uiInput("extras.additionalInfo", "Дополнительный комментарий в ЭН", "Отображается в поле AdditionalInformation.", "AdditionalInformation")}
            </div>
          </div>
        </details>
      </div>

      ${uiSectionMethods("step4")}
    </div>
  `;
}

function renderStep5() {
  return `
    <div class="space-y-4">
      <div>
        <h1 class="text-xl font-extrabold tracking-tight text-[#111111]">5. Трекинг статусов, реестры отгрузок и печать</h1>
        <p class="text-xs text-[#5c6370] mt-0.5">Автоматическое обновление статусов заказов в магазине, сборка ScanSheet и формат термоэтикеток.</p>
      </div>

      <!-- Трекинг и маппинг статусов -->
      <div class="bg-white border border-[#e4e5e9] rounded-[12px] p-4 space-y-3.5">
        <div class="flex items-center justify-between pb-2 border-b border-[#e4e5e9]">
          <div>
            <h2 class="text-sm font-extrabold text-[#111111]">Автотрекинг посылок (TrackingDocument)</h2>
            <p class="text-xs text-[#5c6370]">Фоновый опрос статусов пачками до 100 накладных за 1 запрос.</p>
          </div>
          <code class="font-mono text-[11px] px-2 py-0.5 rounded-[6px] bg-[#f6f6f7] border border-[#e4e5e9] text-[#5c6370]">getStatusDocuments</code>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          ${uiSwitch("tracking.enabled", "Включить фоновый трекинг посылок", "Обновляет статусы заказов по расписанию Cron.", "green")}
          ${uiSwitch("tracking.notifyCustomer", "Уведомлять клиента при смене статуса", "Отправляет Email/SMS магазина при ключевых событиях.", "black")}
        </div>

        <div class="space-y-2 pt-1">
          <div class="text-xs font-bold text-[#111111]">Соответствие статусов Новой Почты статусам магазина:</div>
          ${state.tracking.map.map((row, idx) => `
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 p-2.5 rounded-[8px] bg-[#f6f6f7] border border-[#e4e5e9]">
              <input type="text" data-map-field="np" data-map-idx="${idx}" value="${esc(row.np)}"
                class="px-2.5 py-1.5 rounded-[6px] border border-[#d5d7dd] bg-white text-xs font-mono font-semibold text-[#111111]">
              <input type="text" data-map-field="store" data-map-idx="${idx}" value="${esc(row.store)}"
                class="px-2.5 py-1.5 rounded-[6px] border border-[#d5d7dd] bg-white text-xs font-bold text-[#111111]">
            </div>
          `).join("")}
          <button type="button" id="btn-add-map-row" class="px-3 py-1.5 rounded-[8px] border border-[#111111] bg-white hover:bg-[#111111] hover:text-white text-xs font-bold text-[#111111] transition-colors">
            + Добавить правило статуса
          </button>
        </div>
      </div>

      <!-- Реестры ScanSheet, Возвраты и Печать -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-white border border-[#e4e5e9] rounded-[12px] p-4 space-y-3">
          <h2 class="text-sm font-extrabold text-[#111111]">Реестры (ScanSheet) и Возвраты</h2>
          ${uiSwitch("registry.auto", "Автодобавление ЭН в реестр дня", "Использует ScanSheet.insertDocuments для пакетной сдачи.", "green")}
          ${uiSwitch("registry.removeOnCancel", "Исключать отменённые заказы из реестра", "Вызывает removeDocuments при отмене заказа.", "black")}
          ${uiSwitch("returns.allowReturn", "Разрешить оформление возврата из админки", "Проверка через CheckPossibilityCreateReturn.", "black")}
          ${uiSwitch("returns.allowRedirect", "Разрешить переадресацию посылки", "Смена отделения или адреса получателя.", "black")}
        </div>

        <div class="bg-white border border-[#e4e5e9] rounded-[12px] p-4 space-y-3">
          <h2 class="text-sm font-extrabold text-[#111111]">Печать накладных и термоэтикеток</h2>
          ${uiSelect("print.format", "Формат бланка ЭН", "PDF рекомендуется для термопринтеров и складской печати.", [
            { id: "pdf", title: "PDF документ (printDocument/pdf)" },
            { id: "html", title: "HTML для печати из браузера" }
          ], "Print")}
          ${uiSelect("registry.orientation", "Ориентация печатного реестра", "Формат листа при печати ScanSheet.", [
            { id: "portrait", title: "Книжная (Portrait)" },
            { id: "landscape", title: "Альбомная (Landscape)" }
          ], "Orientation")}
          ${uiSwitch("print.marking", "Печатать маркировку 100×100 мм", "Генерирует стикер на коробку (printMarkings).", "black")}
          ${uiInput("print.copies", "Количество копий стикера", "Обычно 1 копия на грузовое место.", "Copies", "number")}
        </div>
      </div>

      ${uiSectionMethods("step5")}
    </div>
  `;
}

function renderStep6() {
  const models = [...new Set(DATA.methods.map(m => m.model))];
  const q = catalogQuery.trim().toLowerCase();
  const filtered = DATA.methods.filter(m => {
    if (catalogModel && m.model !== catalogModel) return false;
    if (catalogAuth && m.auth !== catalogAuth) return false;
    if (!q) return true;
    return `${m.model} ${m.method} ${m.title} ${m.hint}`.toLowerCase().includes(q);
  });

  return `
    <div class="space-y-4">
      <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 class="text-xl font-extrabold tracking-tight text-[#111111]">6. Полный каталог API Новой Почты v2.0 (66 методов)</h1>
          <p class="text-xs text-[#5c6370] mt-0.5">Справочник всех моделей и вызовов, заложенных в архитектуру модуля, с фильтрацией по модели и типу доступа.</p>
        </div>
        <span class="font-mono text-xs font-bold px-2.5 py-1 rounded-[8px] bg-[#111111] text-white">Найдено: ${filtered.length} из ${DATA.methods.length}</span>
      </div>

      <div class="bg-white border border-[#e4e5e9] rounded-[12px] p-4 space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
          <input type="text" id="cat-search" value="${esc(catalogQuery)}" placeholder="Поиск по методу, модели или описанию..."
            class="px-3 py-2 rounded-[8px] border border-[#d5d7dd] focus:border-[#111111] focus:outline-none text-xs font-medium">
          <select id="cat-model" class="px-3 py-2 rounded-[8px] border border-[#d5d7dd] bg-white text-xs font-bold cursor-pointer">
            <option value="">Все модели (${models.length})</option>
            ${models.map(mod => `<option value="${esc(mod)}" ${mod === catalogModel ? "selected" : ""}>${esc(mod)}</option>`).join("")}
          </select>
          <select id="cat-auth" class="px-3 py-2 rounded-[8px] border border-[#d5d7dd] bg-white text-xs font-bold cursor-pointer">
            <option value="">Любой тип авторизации</option>
            <option value="key" ${catalogAuth === "key" ? "selected" : ""}>Только с API-ключом (красные)</option>
            <option value="public" ${catalogAuth === "public" ? "selected" : ""}>Публичные без ключа (зелёные)</option>
          </select>
        </div>

        <div class="divide-y divide-[#e4e5e9]">
          ${filtered.map(m => `
            <div class="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
              <div class="space-y-0.5">
                <div class="flex items-center gap-2 flex-wrap">
                  <code class="font-mono text-xs font-bold px-2 py-0.5 rounded-[6px] bg-[#f6f6f7] border border-[#e4e5e9] text-[#111111]">${esc(m.model)}.${esc(m.method)}</code>
                  <span class="text-xs font-extrabold text-[#111111]">${esc(m.title)}</span>
                </div>
                <p class="text-xs text-[#5c6370]">${esc(m.hint)}</p>
              </div>
              <span class="self-start sm:self-center shrink-0 font-mono text-[11px] font-bold px-2.5 py-0.5 rounded-full border ${m.auth === "key" ? "bg-[#fdecec] text-[#c42323] border-[#f3c7c7]" : "bg-[#e8f6ee] text-[#157a3a] border-[#b7e4c7]"}">
                ${m.auth === "key" ? "нужен ключ" : "без ключа"}
              </span>
            </div>
          `).join("")}
        </div>
      </div>
    </div>
  `;
}

/* ========================================================================= */
/* ОБНОВЛЕНИЕ ЛЕВОЙ НАВИГАЦИИ, БЛОКЕРОВ И ПРАВОГО ЖИВОГО ИНСПЕКТОРА          */
/* ========================================================================= */
function renderSidebarAndIssues() {
  const issuesList = getIssues();
  const badCount = issuesList.filter(i => i.level === "bad").length;

  // Индикатор готовности
  const readinessBadge = document.getElementById("readiness-badge");
  const readinessBar = document.getElementById("readiness-bar");
  const readinessHint = document.getElementById("readiness-hint");

  if (badCount === 0) {
    readinessBadge.textContent = "Готов (100%)";
    readinessBadge.className = "font-mono text-[11px] font-bold px-2 py-0.5 rounded-full bg-[#e8f6ee] text-[#157a3a]";
    readinessBar.style.width = "100%";
    readinessHint.textContent = "Все обязательные поля API заполнены корректно.";
  } else {
    const pct = Math.max(25, 100 - badCount * 20);
    readinessBadge.textContent = `Блокеров: ${badCount}`;
    readinessBadge.className = "font-mono text-[11px] font-bold px-2 py-0.5 rounded-full bg-[#fdecec] text-[#c42323]";
    readinessBar.style.width = `${pct}%`;
    readinessHint.textContent = "Исправьте пункты с красным индикатором.";
  }

  // Навигация
  const nav = document.getElementById("main-nav");
  nav.innerHTML = STEPS.map(st => {
    const isActive = st.id === activeStep;
    const stepIssues = issuesList.filter(i => i.step === st.id);
    const hasBad = stepIssues.some(i => i.level === "bad");
    const hasWarn = stepIssues.some(i => i.level === "warn");

    return `
      <button type="button" data-go-step="${st.id}"
        class="min-w-[175px] lg:min-w-0 w-full text-left px-3 py-2.5 rounded-[10px] transition-colors flex items-center justify-between gap-2 ${isActive ? "bg-[#111111] text-white" : "hover:bg-[#f6f6f7] text-[#111111]"}">
        <div class="min-w-0">
          <div class="flex items-center gap-1.5">
            <span class="font-mono text-[10px] font-bold ${isActive ? "text-[#a3a3a3]" : "text-[#5c6370]"}">${st.num}</span>
            <span class="text-xs font-extrabold truncate">${esc(st.title)}</span>
          </div>
          <div class="text-[11px] truncate ${isActive ? "text-[#d4d4d8]" : "text-[#5c6370]"}">${esc(st.sub)}</div>
        </div>
        ${hasBad
          ? `<span class="w-2.5 h-2.5 rounded-full bg-[#c42323] shrink-0" title="Есть обязательные поля"></span>`
          : hasWarn
            ? `<span class="w-2.5 h-2.5 rounded-full bg-[#eab308] shrink-0" title="Предупреждение"></span>`
            : `<span class="w-2 h-2 rounded-full bg-[#157a3a] shrink-0 opacity-75"></span>`
        }
      </button>
    `;
  }).join("");

  // Кликабельные блокеры вверху
  const banner = document.getElementById("issues-banner");
  if (!issuesList.length) {
    banner.innerHTML = `
      <div class="px-3.5 py-2.5 rounded-[10px] bg-[#e8f6ee] border border-[#b7e4c7] text-xs font-bold text-[#157a3a] flex items-center justify-between">
        <span>Все обязательные параметры API v2.0 заполнены. Модуль готов к созданию экспресс-накладных.</span>
        <span class="font-mono text-[11px]">READY</span>
      </div>
    `;
  } else {
    banner.innerHTML = issuesList.map(iss => `
      <button type="button" data-jump-step="${iss.step}"
        class="w-full text-left px-3.5 py-2 rounded-[10px] border text-xs font-semibold flex items-center justify-between gap-2 transition-opacity hover:opacity-90 ${iss.level === "bad" ? "bg-[#fdecec] border-[#f3c7c7] text-[#c42323]" : "bg-[#fff6df] border-[#f5d98e] text-[#855a00]"}">
        <span>${esc(iss.text)}</span>
        <span class="font-mono text-[11px] font-bold underline shrink-0">Перейти →</span>
      </button>
    `).join("");
  }

  // Подсветка активных кнопок пресетов
  document.querySelectorAll(".preset-top-btn").forEach(btn => {
    const active = btn.dataset.preset === activePreset;
    btn.className = active
      ? "preset-top-btn px-3 py-1.5 rounded-[8px] border border-[#111111] bg-[#111111] text-white text-xs font-bold transition-colors"
      : "preset-top-btn px-3 py-1.5 rounded-[8px] border border-[#e4e5e9] hover:border-[#111111] bg-white text-[#111111] text-xs font-bold transition-colors";
  });
}

function renderInspector() {
  // 1. Обновление JSON вкладки
  const { modelName, jsonText } = buildSavePayload();
  document.getElementById("json-model-subtitle").textContent = `${modelName}.save`;
  document.getElementById("live-json-output").textContent = jsonText;

  // 2. Обновление живого предпросмотра чекаута
  const cartSum = Number(document.getElementById("sim-cart-range").value) || 1850;
  const simWeight = Number(document.getElementById("sim-weight-range").value) || 1.5;
  document.getElementById("sim-cart-label").textContent = `${cartSum.toLocaleString("ru-RU")} ₴`;
  document.getElementById("sim-weight-label").textContent = `${simWeight} кг`;

  const modStatus = document.getElementById("sim-module-status");
  if (state.connection.enabled) {
    modStatus.textContent = "Активен";
    modStatus.className = "text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-[#e8f6ee] text-[#157a3a]";
  } else {
    modStatus.textContent = "Модуль выкл.";
    modStatus.className = "text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-[#fdecec] text-[#c42323]";
  }

  const enabledServices = DATA.service.filter(s => {
    const cfg = state.methods[s.id];
    return cfg.enabled && simWeight <= Number(cfg.maxWeight || 1000);
  });

  if (enabledServices.length && !enabledServices.some(s => s.id === selectedCheckoutMethod)) {
    selectedCheckoutMethod = enabledServices[0].id;
  }

  const servicesBox = document.getElementById("sim-services-container");
  if (!state.connection.enabled || !enabledServices.length) {
    servicesBox.innerHTML = `<div class="p-3 rounded-[8px] bg-[#fdecec] text-[#c42323] text-xs font-bold">Нет доступных способов доставки для веса ${simWeight} кг</div>`;
  } else {
    servicesBox.innerHTML = enabledServices.map(s => {
      const cfg = state.methods[s.id];
      const isSelected = s.id === selectedCheckoutMethod;
      const baseTariff = s.id.includes("Doors") ? (simWeight <= 2 ? 105 : 145) : (simWeight <= 2 ? 70 : (simWeight <= 10 ? 95 : 135));
      const rawPrice = cfg.priceMode === "fixed" ? Number(cfg.fixedPrice) : baseTariff;
      const freeLimit = Number(cfg.freeFrom) || 0;
      const isFree = state.waybill.payer === "Sender" || (freeLimit > 0 && cartSum >= freeLimit);

      return `
        <div data-select-sim-method="${s.id}"
          class="p-2.5 rounded-[8px] border transition-colors cursor-pointer flex items-center justify-between gap-2 ${isSelected ? "border-[#111111] bg-[#f6f6f7]" : "border-[#e4e5e9] bg-white hover:border-[#111111]"}">
          <div class="flex items-center gap-2 min-w-0">
            <span class="w-3.5 h-3.5 rounded-full shrink-0 ${isSelected ? "border-4 border-[#111111]" : "border-2 border-[#d5d7dd]"}"></span>
            <span class="text-xs font-bold text-[#111111] truncate">${esc(cfg.title)}</span>
          </div>
          <span class="font-mono text-xs font-extrabold shrink-0 ${isFree ? "text-[#157a3a]" : "text-[#111111]"}">
            ${isFree ? "Бесплатно" : `${rawPrice} ₴`}
          </span>
        </div>
      `;
    }).join("");
  }

  // Предупреждение по почтомату (вес > 20 кг или габариты > 40x60x30)
  const dims = [Number(state.waybill.length), Number(state.waybill.width), Number(state.waybill.height)].sort((a, b) => a - b);
  const postomatOverLimit = simWeight > 20 || dims[0] > 30 || dims[1] > 40 || dims[2] > 60;
  const postAlert = document.getElementById("sim-postomat-alert");
  if (state.warehouseKinds.postomat && postomatOverLimit) {
    postAlert.classList.remove("hidden");
    postAlert.textContent = `Почтоматы автоматически скрыты: посылка (${simWeight} кг, ${dims[2]}×${dims[1]}×${dims[0]} см) превышает лимит ячейки 40×60×30 см / 20 кг.`;
  } else {
    postAlert.classList.add("hidden");
  }

  // Точка выдачи
  const pointLabel = document.getElementById("sim-point-label");
  const pointSelect = document.getElementById("sim-point-select");
  if (selectedCheckoutMethod.endsWith("Doors")) {
    pointLabel.textContent = "Адрес курьерской доставки";
    pointSelect.innerHTML = `<option>вул. Коперника, 15, кв. 42 (Курьер НП)</option>`;
  } else if (postomatOverLimit || !state.warehouseKinds.postomat) {
    pointLabel.textContent = "Отделение Новой Почты";
    pointSelect.innerHTML = `<option>Відділення №12: вул. Городоцька, 174</option><option>Грузове №1 (до 1000 кг)</option>`;
  } else {
    pointLabel.textContent = "Отделение или почтомат";
    pointSelect.innerHTML = `<option>Відділення №12: вул. Городоцька, 174</option><option>Поштомат №3412: ТРЦ Forum Lviv</option>`;
  }

  // Прогресс-бар бесплатной доставки
  const activeCfg = state.methods[selectedCheckoutMethod] || state.methods.WarehouseWarehouse;
  const freeThreshold = Number(activeCfg.freeFrom) || 0;
  const freeBox = document.getElementById("sim-free-box");
  if (freeThreshold > 0 && state.waybill.payer !== "Sender") {
    freeBox.classList.remove("hidden");
    const pct = Math.min(100, Math.round((cartSum / freeThreshold) * 100));
    const left = Math.max(0, freeThreshold - cartSum);
    document.getElementById("sim-free-pct").textContent = `${pct}%`;
    document.getElementById("sim-free-bar").style.width = `${pct}%`;
    document.getElementById("sim-free-msg").textContent = left > 0
      ? `До бесплатной доставки: ${left.toLocaleString("ru-RU")} ₴`
      : "Бесплатная доставка активирована!";
  } else {
    freeBox.classList.add("hidden");
  }

  // Калькулятор выплаты и комиссии наложки
  const baseTariff = selectedCheckoutMethod.includes("Doors") ? (simWeight <= 2 ? 105 : 145) : (simWeight <= 2 ? 70 : (simWeight <= 10 ? 95 : 135));
  const shipCost = activeCfg.priceMode === "fixed" ? Number(activeCfg.fixedPrice) : baseTariff;
  const shopPaysShipping = state.waybill.payer === "Sender" || (freeThreshold > 0 && cartSum >= freeThreshold);

  const codFee = Math.round(20 + cartSum * 0.02);
  const hasCod = state.money.cod || state.money.paymentControl;
  const shopPaysCod = hasCod && state.money.codPayer === "Sender";

  document.getElementById("calc-order-total").textContent = `${cartSum.toLocaleString("ru-RU")} ₴`;
  document.getElementById("calc-ship-who").textContent = shopPaysShipping ? "Доставка (платит магазин):" : "Доставка (платит клиент):";
  document.getElementById("calc-ship-val").textContent = shopPaysShipping ? `-${shipCost} ₴` : `${shipCost} ₴`;
  document.getElementById("calc-ship-val").className = shopPaysShipping ? "font-mono font-bold text-[#c42323]" : "font-mono font-semibold text-[#111111]";

  const codLine = document.getElementById("calc-cod-line");
  if (!hasCod) {
    codLine.classList.add("hidden");
  } else {
    codLine.classList.remove("hidden");
    document.getElementById("calc-cod-who").textContent = shopPaysCod ? "Комиссия 20₴+2% (магазин):" : "Комиссия 20₴+2% (клиент):";
    document.getElementById("calc-cod-val").textContent = shopPaysCod ? `-${codFee} ₴` : `${codFee} ₴`;
  }

  const netPayout = Math.max(0, cartSum - (shopPaysShipping ? shipCost : 0) - (shopPaysCod ? codFee : 0));
  document.getElementById("calc-net-payout").textContent = `${netPayout.toLocaleString("ru-RU")} ₴`;

  const payoutBadge = document.getElementById("sim-payout-badge");
  if (shopPaysShipping || shopPaysCod) {
    payoutBadge.textContent = "Расходы магазина";
    payoutBadge.className = "font-mono text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#fdecec] text-[#c42323]";
  } else {
    payoutBadge.textContent = "Маржа ОК";
    payoutBadge.className = "font-mono text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#e8f6ee] text-[#157a3a]";
  }
  scheduleCheckoutLive();
}

function renderAll() {
  renderSidebarAndIssues();
  const view = document.getElementById("workspace-view");
  const mapViews = {
    step1: renderStep1,
    step2: renderStep2,
    step3: renderStep3,
    step4: renderStep4,
    step5: renderStep5,
    step6: renderStep6
  };
  view.innerHTML = mapViews[activeStep]();
  renderInspector();
}

/* ========================================================================= */
/* ОБРАБОТЧИКИ СОБЫТИЙ                                                       */
/* ========================================================================= */
document.body.addEventListener("click", (e) => {
  const stepBtn = e.target.closest("[data-go-step]");
  if (stepBtn) {
    activeStep = stepBtn.dataset.goStep;
    renderAll();
    window.scrollTo({ top: 0, behavior: "smooth" });
    return;
  }

  const jumpBtn = e.target.closest("[data-jump-step]");
  if (jumpBtn) {
    activeStep = jumpBtn.dataset.jumpStep;
    renderAll();
    window.scrollTo({ top: 0, behavior: "smooth" });
    return;
  }

  const presetBtn = e.target.closest("[data-preset]");
  if (presetBtn) {
    applyPreset(presetBtn.dataset.preset);
    return;
  }

  const simMethod = e.target.closest("[data-select-sim-method]");
  if (simMethod) {
    selectedCheckoutMethod = simMethod.dataset.selectSimMethod;
    renderInspector();
    return;
  }

  if (e.target.id === "btn-test-key") {
    const key = state.connection.apiKey.trim();
    if (key.length < 32) {
      notify("Ошибка: введите корректный 32-значный API-ключ", "bad");
      return;
    }
    notify("Counterparty.getCounterparties…");
    npCall("Counterparty", "getCounterparties", { CounterpartyProperty: "Sender", Page: "1" }, key, state.connection.timeoutSec).then((r) => {
      if (r && r.success) {
        window.__npKeyBad = "";
        const row = (r.data && r.data[0]) || {};
        const name = row.Description || [row.FirstName, row.LastName].filter(Boolean).join(" ");
        notify(name ? "Соединение с API Новой Почты v2.0 успешно подтверждено: " + name : "Соединение с API Новой Почты v2.0 успешно подтверждено");
      } else {
        window.__npKeyBad = key;
        notify(npErrorText(r), "bad");
      }
    });
    return;
  }

  if (e.target.id === "btn-run-sync") {
    runDirectorySync();
    return;
  }

  if (e.target.id === "btn-add-map-row") {
    state.tracking.map.push({ np: "", store: "" });
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    renderAll();
    return;
  }
});

document.body.addEventListener("input", (e) => {
  const t = e.target;
  if (t.id === "sim-cart-range" || t.id === "sim-weight-range") {
    renderInspector();
    return;
  }
  if (t.id === "cat-search") {
    catalogQuery = t.value;
    const pos = t.selectionStart;
    renderAll();
    const restored = document.getElementById("cat-search");
    if (restored) { restored.focus(); restored.setSelectionRange(pos, pos); }
    return;
  }
  if (t.dataset.mapField) {
    state.tracking.map[Number(t.dataset.mapIdx)][t.dataset.mapField] = t.value;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    return;
  }
  if (t.dataset.path && t.type !== "checkbox") {
    setVal(t.dataset.path, t.value);
    renderSidebarAndIssues();
    renderInspector();
  }
});

document.body.addEventListener("change", (e) => {
  const t = e.target;
  if (t.id === "sim-city") { scheduleCheckoutLive(); return; }
  if (t.id === "cat-model") { catalogModel = t.value; renderAll(); return; }
  if (t.id === "cat-auth") { catalogAuth = t.value; renderAll(); return; }
  if (t.dataset.path) {
    setVal(t.dataset.path, t.type === "checkbox" ? t.checked : t.value);
    renderAll();
  }
});

// Переключение вкладок правого инспектора
document.getElementById("tab-preview-btn").addEventListener("click", () => {
  inspectorTab = "preview";
  document.getElementById("tab-preview-btn").className = "py-1.5 px-3 rounded-[8px] bg-[#111111] text-white text-xs font-bold transition-colors";
  document.getElementById("tab-json-btn").className = "py-1.5 px-3 rounded-[8px] text-[#5c6370] hover:text-[#111111] text-xs font-bold transition-colors";
  document.getElementById("inspector-preview-pane").classList.remove("hidden");
  document.getElementById("inspector-json-pane").classList.add("hidden");
});

document.getElementById("tab-json-btn").addEventListener("click", () => {
  inspectorTab = "json";
  document.getElementById("tab-json-btn").className = "py-1.5 px-3 rounded-[8px] bg-[#111111] text-white text-xs font-bold transition-colors";
  document.getElementById("tab-preview-btn").className = "py-1.5 px-3 rounded-[8px] text-[#5c6370] hover:text-[#111111] text-xs font-bold transition-colors";
  document.getElementById("inspector-json-pane").classList.remove("hidden");
  document.getElementById("inspector-preview-pane").classList.add("hidden");
});

// Копирование JSON (через execCommand для совместимости с iframe)
document.getElementById("btn-copy-json").addEventListener("click", () => {
  const { jsonText } = buildSavePayload();
  const ta = document.createElement("textarea");
  ta.value = jsonText;
  document.body.appendChild(ta);
  ta.select();
  document.execCommand("copy");
  ta.remove();
  notify("JSON-структура InternetDocument.save скопирована");
});

// Скачивание JSON конфигурации
document.getElementById("btn-download-json").addEventListener("click", () => {
  const blob = new Blob([JSON.stringify(state, null, 2)], { type: "application/json" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = "nova-poshta-settings.json";
  a.click();
  URL.revokeObjectURL(a.href);
  notify("Файл конфигурации nova-poshta-settings.json скачан");
});

// Импорт JSON конфигурации
document.getElementById("input-import-json").addEventListener("change", (e) => {
  const file = e.target.files?.[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = () => {
    try {
      state = deepMerge(createDefaults(), JSON.parse(String(reader.result)));
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
      renderAll();
      notify("Конфигурация успешно импортирована из JSON");
    } catch {
      notify("Ошибка: выбранный файл не является корректным JSON", "bad");
    }
  };
  reader.readAsText(file);
});

// Сброс настроек
document.getElementById("btn-reset").addEventListener("click", () => {
  state = createDefaults();
  activePreset = "classic";
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  renderAll();
  notify("Настройки сброшены к стандартным значениям");
});


const cityRefCache = {};
function syncProps(method) {
  if (method === "searchSettlements") return { CityName: "Київ", Limit: "5" };
  if (method === "searchSettlementStreets") return { StreetName: "Хрещатик", Limit: "5", SettlementRef: cityRefCache.kyivSettlement || "e718a680-4b33-11e4-ab6d-005056801329" };
  if (method === "getWarehouses") return { CityName: "Київ", Limit: "20", Page: "1" };
  if (method === "getCities" || method === "getSettlements") return { Page: "1", Limit: "20" };
  if (method === "getStreet") return { CityRef: cityRefCache["Київ"] || "8d5a980d-391c-11dd-90d9-001a92567626", Limit: "5", Page: "1" };
  return {};
}
async function runDirectorySync() {
  const key = state.connection.apiKey.trim();
  const jobs = DATA.methods.filter((m) => (m.model === "Address" || m.model === "Common") && state.directories.sync[m.method] && /^(get|search)/.test(m.method));
  notify("Синхронизация справочников API v2.0…");
  const kyiv = await npCall("Address", "searchSettlements", { CityName: "Київ", Limit: "1" }, "", state.connection.timeoutSec);
  const addr = kyiv && kyiv.data && kyiv.data[0] && kyiv.data[0].Addresses && kyiv.data[0].Addresses[0];
  if (addr) {
    cityRefCache["Київ"] = addr.DeliveryCity;
    cityRefCache.kyivSettlement = addr.Ref;
  }
  let ok = 0;
  let fail = 0;
  for (const m of jobs) {
    if (m.auth === "key" && key.length < 32) { fail += 1; continue; }
    const r = await npCall(m.model, m.method, syncProps(m.method), m.auth === "key" ? key : "", state.connection.timeoutSec);
    if (r && r.success) ok += 1;
    else fail += 1;
  }
  const now = new Date();
  state.directories.lastSync = "Сегодня, " + String(now.getHours()).padStart(2, "0") + ":" + String(now.getMinutes()).padStart(2, "0");
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  npScheduleSave(state);
  renderAll();
  notify(ok ? ("Справочники обновлены: " + ok + " методов" + (fail ? ", ошибок " + fail : "")) : npErrorText({ errors: ["Справочники не обновились"] }), ok ? "ok" : "bad");
}
async function deliveryCityRef(label) {
  const q = npCityQuery(label);
  if (!q) return "";
  if (cityRefCache[q]) return cityRefCache[q];
  const r = await npCall("Address", "searchSettlements", { CityName: q, Limit: "1" }, "", state.connection.timeoutSec);
  const ref = r && r.data && r.data[0] && r.data[0].Addresses && r.data[0].Addresses[0] && r.data[0].Addresses[0].DeliveryCity;
  if (ref) cityRefCache[q] = ref;
  return ref || "";
}
let liveToken = 0;
function scheduleCheckoutLive() {
  const token = ++liveToken;
  clearTimeout(scheduleCheckoutLive._t);
  scheduleCheckoutLive._t = setTimeout(() => refreshCheckoutLive(token), 280);
}
async function refreshCheckoutLive(token) {
  const cityLabel = document.getElementById("sim-city") && document.getElementById("sim-city").value;
  const cartSum = Number(document.getElementById("sim-cart-range") && document.getElementById("sim-cart-range").value) || 0;
  const simWeight = Number(document.getElementById("sim-weight-range") && document.getElementById("sim-weight-range").value) || 0;
  const cityName = npCityQuery(cityLabel);
  if (!selectedCheckoutMethod.endsWith("Doors") && cityName) {
    const r = await npCall("Address", "getWarehouses", { CityName: cityName, Limit: "50", Page: "1" }, "", state.connection.timeoutSec);
    if (token !== liveToken) return;
    const sel = document.getElementById("sim-point-select");
    const rows = r && r.success && Array.isArray(r.data) ? r.data : [];
    if (sel && rows.length) {
      const dims = [Number(state.waybill.length), Number(state.waybill.width), Number(state.waybill.height)].sort((a, b) => a - b);
      const postomatOver = simWeight > 20 || dims[0] > 30 || dims[1] > 40 || dims[2] > 60;
      const items = rows.filter((w) => {
        const desc = String(w.Description || "");
        const cat = String(w.CategoryOfWarehouse || "");
        const kind = (cat === "Postomat" || /поштомат/i.test(desc)) ? "postomat"
          : (cat === "Cargo" || /вантаж/i.test(desc)) ? "cargo"
          : (cat === "Store" || cat === "DropOff" || /пункт/i.test(desc)) ? "store" : "branch";
        if (!state.warehouseKinds[kind]) return false;
        if (kind === "postomat" && postomatOver) return false;
        const maxW = Number(w.TotalMaxWeightAllowed || w.PlaceMaxWeightAllowed || 0);
        if (maxW && simWeight > maxW) return false;
        return true;
      }).slice(0, 40);
      if (items.length) {
        const prev = sel.value;
        sel.innerHTML = items.map((w) => '<option value="' + esc(w.Ref) + '">' + esc(w.Description) + "</option>").join("");
        if ([...sel.options].some((o) => o.value === prev)) sel.value = prev;
      }
    }
  }
  const cfg = state.methods[selectedCheckoutMethod];
  const key = state.connection.apiKey.trim();
  if (!cfg || cfg.priceMode !== "tariff" || key.length < 32 || window.__npKeyBad === key) return;
  const [senderRef, recipRef] = await Promise.all([
    deliveryCityRef(state.sender.city || "Київ"),
    deliveryCityRef(cityName)
  ]);
  if (token !== liveToken || !senderRef || !recipRef) return;
  const price = await npCall("InternetDocument", "getDocumentPrice", {
    CitySender: senderRef,
    CityRecipient: recipRef,
    Weight: String(simWeight),
    ServiceType: selectedCheckoutMethod,
    Cost: String(cartSum),
    CargoType: state.waybill.cargoType || "Parcel",
    SeatsAmount: String(state.waybill.seats || "1")
  }, key, state.connection.timeoutSec);
  if (token !== liveToken) return;
  if (!price || !price.success) {
    if (price && /api key/i.test(npErrorText(price))) window.__npKeyBad = key;
    return;
  }
  const cost = Number(price.data && price.data[0] && price.data[0].Cost);
  if (!Number.isFinite(cost)) return;
  const freeLimit = Number(cfg.freeFrom) || 0;
  const isFree = state.waybill.payer === "Sender" || (freeLimit > 0 && cartSum >= freeLimit);
  const row = document.querySelector('[data-select-sim-method="' + selectedCheckoutMethod + '"] span.font-mono');
  if (row && !isFree) row.textContent = Math.round(cost) + " ₴";
  const shipVal = document.getElementById("calc-ship-val");
  if (shipVal) shipVal.textContent = (isFree ? "-" : "") + Math.round(cost) + " ₴";
  const codFee = Math.round(20 + cartSum * 0.02);
  const hasCod = state.money.cod || state.money.paymentControl;
  const shopPaysCod = hasCod && state.money.codPayer === "Sender";
  const net = Math.max(0, cartSum - (isFree ? cost : 0) - (shopPaysCod ? codFee : 0));
  const netEl = document.getElementById("calc-net-payout");
  if (netEl) netEl.textContent = net.toLocaleString("ru-RU") + " ₴";
}
npFixNav();

renderAll();

}
