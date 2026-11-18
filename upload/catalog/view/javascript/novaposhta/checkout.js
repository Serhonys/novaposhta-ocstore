(function () {
  function cfg() {
    var root = document.getElementById("np-checkout");
    if (!root) return null;
    try { return JSON.parse(root.getAttribute("data-np") || "{}"); } catch (e) { return null; }
  }

  function kindOf(w) {
    var desc = String(w.Description || "");
    var cat = String(w.CategoryOfWarehouse || "");
    if (cat === "Postomat" || /поштомат/i.test(desc)) return "postomat";
    if (cat === "Cargo" || /вантаж/i.test(desc)) return "cargo";
    if (cat === "Store" || cat === "DropOff" || /пункт/i.test(desc)) return "store";
    return "branch";
  }

  function postomatOver(c) {
    var dims = [Number(c.length) || 0, Number(c.width) || 0, Number(c.height) || 0].sort(function (a, b) { return a - b; });
    return Number(c.weight) > 20 || dims[0] > 30 || dims[1] > 40 || dims[2] > 60;
  }

  function selectedService() {
    var input = document.querySelector('input[name="shipping_method"]:checked');
    if (!input) return "";
    return String(input.value || "").replace(/^novaposhta\./, "");
  }

  function hideNative() {
    document.querySelectorAll('input[name="shipping_method"]').forEach(function (input) {
      if (String(input.value || "").indexOf("novaposhta.") === 0) {
        var row = input.closest(".radio") || input.parentElement;
        if (row) row.style.display = "none";
      }
    });
  }

  function save(pointText, pointRef, pointKind) {
    var c = cfg();
    var city = document.getElementById("np-city");
    if (!c || !city || !city.dataset.ref) return;
    fetch(c.selectUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        service: selectedService(),
        city: city.value,
        cityRef: city.dataset.ref || "",
        point: pointText || "",
        pointRef: pointRef || "",
        pointKind: pointKind || ""
      })
    });
  }

  function loadWarehouses() {
    var c = cfg();
    var city = document.getElementById("np-city");
    var point = document.getElementById("np-point");
    var street = document.getElementById("np-street");
    var alertBox = document.getElementById("np-postomat");
    if (!c || !city || !point) return;
    var service = selectedService();
    var doors = /Doors$/.test(service) || service === "DoorsDoors" || service === "WarehouseDoors";
    if (doors) {
      point.hidden = true;
      if (street) street.hidden = false;
      if (alertBox) alertBox.hidden = true;
      return;
    }
    if (street) street.hidden = true;
    point.hidden = false;
    var url = c.warehousesUrl + (c.warehousesUrl.indexOf("?") === -1 ? "?" : "&") + "city_ref=" + encodeURIComponent(city.dataset.ref || "") + "&city=" + encodeURIComponent(city.dataset.name || city.value);
    fetch(url).then(function (r) { return r.json(); }).then(function (res) {
      var rows = (res && res.data) || [];
      var over = postomatOver(c);
      if (alertBox) {
        alertBox.hidden = !(c.kinds && c.kinds.postomat && over);
        alertBox.textContent = "Почтоматы скрыты: посылка превышает лимит ячейки 40×60×30 см / 20 кг.";
      }
      point.innerHTML = "";
      rows.forEach(function (w) {
        var kind = kindOf(w);
        if (c.kinds && c.kinds[kind] === false) return;
        if (kind === "postomat" && over) return;
        var opt = document.createElement("option");
        opt.value = w.Ref;
        opt.textContent = w.Description;
        opt.dataset.kind = kind;
        point.appendChild(opt);
      });
      if (point.options.length) save(point.options[0].textContent, point.options[0].value, point.options[0].dataset.kind || "branch");
    }).catch(function () {});
  }

  function mount() {
    var root = document.getElementById("np-checkout");
    if (!root || root.dataset.bound === "1") return;
    var c = cfg();
    if (!c) return;
    root.dataset.bound = "1";
    hideNative();
    var checks = document.querySelectorAll('input[name="shipping_method"][value^="novaposhta."]');
    if (checks.length && ![].some.call(checks, function (el) { return el.checked; })) checks[0].checked = true;

    var city = document.getElementById("np-city");
    var list = document.getElementById("np-city-list");
    var point = document.getElementById("np-point");
    var street = document.getElementById("np-street");
    var cod = document.getElementById("np-cod");
    if (cod) cod.textContent = c.cod ? (c.codNote || "") : "";

    var timer = 0;
    city.addEventListener("input", function () {
      city.dataset.ref = "";
      clearTimeout(timer);
      var q = city.value.trim();
      if (!c.liveSearch || q.length < 2) { list.hidden = true; return; }
      timer = setTimeout(function () {
        var url = c.citiesUrl + (c.citiesUrl.indexOf("?") === -1 ? "?" : "&") + "q=" + encodeURIComponent(q);
        fetch(url).then(function (r) { return r.json(); }).then(function (rows) {
          list.innerHTML = "";
          (rows || []).forEach(function (row) {
            var btn = document.createElement("button");
            btn.type = "button";
            btn.textContent = row.label;
            btn.addEventListener("click", function () {
              city.value = row.label;
              city.dataset.ref = row.ref;
              city.dataset.settlement = row.settlement || "";
              city.dataset.name = row.name || "";
              list.hidden = true;
              loadWarehouses();
            });
            list.appendChild(btn);
          });
          list.hidden = !list.children.length;
        }).catch(function () {});
      }, 250);
    });

    point.addEventListener("change", function () {
      var opt = point.options[point.selectedIndex];
      if (!opt) return;
      save(opt.textContent, opt.value, opt.dataset.kind || "branch");
    });

    if (street) {
      street.addEventListener("change", function () {
        save(street.value, "", "courier");
      });
    }

    document.querySelectorAll('input[name="shipping_method"]').forEach(function (input) {
      input.addEventListener("change", loadWarehouses);
    });

    var button = document.getElementById("button-shipping-method");
    if (button && !button.dataset.npGuard) {
      button.dataset.npGuard = "1";
      button.addEventListener("click", function (e) {
        var service = selectedService();
        if (!service) return;
        var cityOk = city && city.dataset.ref;
        var pointOk = point && !point.hidden && point.value;
        var streetOk = street && !street.hidden && street.value.trim();
        if (!cityOk || (!pointOk && !streetOk)) {
          e.preventDefault();
          e.stopImmediatePropagation();
          alert("Оберіть місто і відділення Нової Пошти");
        }
      }, true);
    }
  }

  if (window.jQuery) {
    jQuery(document).ajaxComplete(function (_e, _xhr, settings) {
      if (settings && settings.url && String(settings.url).indexOf("checkout/shipping_method") !== -1) {
        var root = document.getElementById("np-checkout");
        if (root) root.dataset.bound = "";
        mount();
      }
    });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mount);
  else mount();
})();
