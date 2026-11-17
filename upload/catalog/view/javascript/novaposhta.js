(function ($) {
  function cfg() {
    return window.NovaPoshtaConfig || null;
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var self = this;
      var args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, ms);
    };
  }

  function closeLists() {
    $('.np-list').remove();
  }

  function isUuid(value) {
    return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(String(value || ''));
  }

  function nodes(selector, fallback) {
    var query = $.trim(selector || '') || fallback || '';
    if (!query) return $();
    var $found = $();
    $.each(query.split(','), function (_, part) {
      part = $.trim(part);
      if (!part) return;
      var $one;
      var looksLikeSelector = /^(#|\.|input\b|select\b|textarea\b)/.test(part) || part.indexOf(' ') !== -1;
      if (looksLikeSelector) {
        $one = $(part);
      } else {
        var name = part.replace(/"/g, '\\"');
        $one = $('input[name="' + name + '"], textarea[name="' + name + '"]');
      }
      $found = $found.add($one.filter('input, textarea')).add($one.find('input, textarea'));
    });
    return $found.filter(':visible').length ? $found.filter(':visible') : $found;
  }

  function npRadio() {
    var $radios = $('input[type="radio"]').filter(function () {
      return String(this.value || '').indexOf('novaposhta.') !== -1;
    });
    var $checked = $radios.filter(':checked');
    return $checked.length ? $checked.first() : $();
  }

  function method() {
    var $checked = npRadio();
    if ($checked.length) {
      var parts = String($checked.val()).split('.');
      return parts.length > 1 ? parts[1] : 'department';
    }
    var named = $('input[name="shipping_method"]:checked');
    if (named.length && String(named.val()).indexOf('novaposhta.') === 0) {
      return String(named.val()).split('.')[1];
    }
    var c = cfg();
    return (c && c.method) || 'department';
  }

  function cityInput() {
    var $own = $('.np-own-city:visible').first();
    if ($own.length) return $own;
    var c = cfg();
    return nodes(c && c.city, 'input[name="city"], input[name="shipping_address[city]"]').not('.np-own-city').first();
  }

  function allowed($input) {
    var c = cfg();
    if (!c || !c.countryId) return true;
    var $country = $input.closest('form').find('select[name="country_id"], select[name$="[country_id]"]');
    if (!$country.length) return true;
    var value = String($country.val() || '');
    return !value || value === '0' || value === String(c.countryId);
  }

  function list($input, rows, onPick) {
    closeLists();
    if (!rows || !rows.length) return;
    var $parent = $input.parent();
    if ($parent.css('position') === 'static') {
      $parent.css('position', 'relative');
    }
    var $box = $('<div class="np-list"></div>');
    $.each(rows, function (_, row) {
      var label = row.area ? (row.name + ', ' + row.area) : row.name;
      var $item = $('<button type="button" class="np-item"></button>').text(label);
      $item.on('mousedown', function (e) {
        e.preventDefault();
        onPick(row);
        closeLists();
      });
      $box.append($item);
    });
    $input.after($box);
  }

  function payload() {
    var c = cfg();
    var $city = cityInput();
    var $point = $('.np-own-point:visible').first();
    if (!$point.length) {
      $point = nodes(c.point, 'input[name="address_1"], input[name="shipping_address[address_1]"]').not('.np-own-point').first();
    }
    var $street = $('.np-own-street:visible').first();
    if (!$street.length) $street = nodes(c.street).not('.np-own-street').first();
    var $building = $('.np-own-building:visible').first();
    if (!$building.length) $building = nodes(c.building).not('.np-own-building').first();
    var $flat = $('.np-own-flat:visible').first();
    if (!$flat.length) $flat = nodes(c.flat).not('.np-own-flat').first();
    return {
      method: method(),
      city_ref: $city.data('np-ref') || '',
      city_name: $city.val() || '',
      division_ref: $point.data('np-ref') || '',
      division_id: $point.data('np-id') || '',
      division_name: $point.val() || '',
      settlement_ref: $city.data('np-settlement') || '',
      delivery_city: $city.data('np-delivery') || '',
      street_ref: $street.data('np-ref') || '',
      street_name: $street.val() || '',
      building: $building.val() || '',
      flat: $flat.val() || ''
    };
  }

  function save() {
    var c = cfg();
    if (!c || !c.saveUrl) return;
    $.ajax({
      url: c.saveUrl,
      method: 'POST',
      contentType: 'application/json; charset=UTF-8',
      data: JSON.stringify(payload())
    });
  }

  function bind($inputs, kind) {
    var c = cfg();
    $inputs.each(function () {
      var $input = $(this);
      if ($input.data('np-bound') || !allowed($input)) return;
      $input.data('np-bound', 1);
      $input.on('input', debounce(function () {
        if (isUuid($input.data('np-ref')) && $input.data('np-label') !== $input.val()) {
          $input.removeData('np-ref');
        }
        var q = $.trim($input.val());
        if (q.length < 2) {
          closeLists();
          save();
          return;
        }
        if (kind === 'city') {
          $.getJSON(c.cityUrl, { q: q }, function (rows) {
            list($input, rows || [], function (row) {
              $input.val(row.name).data('np-ref', row.ref || '').data('np-label', row.name);
              if (c.settlementUrl) {
                $.getJSON(c.settlementUrl, { q: row.name }, function (places) {
                  if (places && places[0]) {
                    $input.data('np-settlement', places[0].ref || '');
                    if (places[0].delivery_city) $input.data('np-delivery', places[0].delivery_city);
                    save();
                  }
                });
              }
              save();
            });
          });
          return;
        }
        if (kind === 'point') {
          var cityRef = cityInput().data('np-ref') || '';
          var locker = method() === 'poshtomat' ? 1 : 0;
          $.getJSON(c.warehouseUrl, { city_ref: cityRef, q: q, locker: locker }, function (rows) {
            list($input, rows || [], function (row) {
              $input.val(row.name).data('np-ref', row.ref || '').data('np-id', row.division_id || '').data('np-label', row.name);
              save();
            });
          });
          return;
        }
        if (kind === 'street') {
          var settlement = cityInput().data('np-settlement') || '';
          if (!settlement) return;
          $.getJSON(c.streetUrl, { settlement_ref: settlement, q: q }, function (rows) {
            list($input, rows || [], function (row) {
              $input.val(row.name).data('np-ref', row.ref || '').data('np-label', row.name);
              save();
            });
          });
        }
      }, 250));
      $input.on('change', save);
    });
  }

  function remember() {
    var c = cfg();
    if (!c) return;
    c.saved = c.saved || {};
    var $city = $('.np-own-city');
    if (!$city.length) return;
    c.saved.city_name = $city.val() || '';
    c.saved.city_ref = $city.data('np-ref') || c.saved.city_ref || '';
    c.saved.settlement_ref = $city.data('np-settlement') || c.saved.settlement_ref || '';
    var $point = $('.np-own-point');
    if ($point.length) {
      c.saved.division_name = $point.val() || '';
      c.saved.division_ref = $point.data('np-ref') || '';
      c.saved.division_id = $point.data('np-id') || '';
    }
    var $street = $('.np-own-street');
    if ($street.length) {
      c.saved.street_name = $street.val() || '';
      c.saved.street_ref = $street.data('np-ref') || '';
    }
    var $building = $('.np-own-building');
    if ($building.length) c.saved.building = $building.val() || '';
    var $flat = $('.np-own-flat');
    if ($flat.length) c.saved.flat = $flat.val() || '';
  }

  function checkoutHasCity() {
    var c = cfg();
    var $city = nodes(c && c.city, 'input[name="city"], input[name="shipping_address[city]"]')
      .not('.np-own-city')
      .filter(':visible')
      .filter(function () {
        return !$(this).closest('.np-checkout').length;
      });
    return $city.length > 0;
  }

  function field(cls, label, value) {
    var $label = $('<label class="np-field"></label>');
    $label.append($('<span></span>').text(label || ''));
    var $input = $('<input type="text" autocomplete="off" class="form-control">').addClass(cls);
    if (value) $input.val(value);
    $label.append($input);
    return $label;
  }

  function mount() {
    var c = cfg();
    if (!c) return;
    var $radio = npRadio();
    if (!$radio.length || checkoutHasCity()) {
      return;
    }
    var kind = method();
    var $box = $('.np-checkout');
    if ($box.length && $box.data('np-kind') === kind && $.contains(document, $box[0])) {
      return;
    }
    remember();
    $('.np-checkout').remove();
    var labels = c.labels || {};
    var saved = c.saved || {};
    $box = $('<div class="np-checkout"></div>').data('np-kind', kind);
    $box.append(field('np-own-city', labels.city || 'Місто', saved.city_name));
    if (kind === 'doors') {
      var $door = $('<div class="np-door-row"></div>');
      $door.append(field('np-own-street', labels.street || 'Вулиця', saved.street_name));
      $door.append(field('np-own-building', labels.building || 'Будинок', saved.building));
      $door.append(field('np-own-flat', labels.flat || 'Квартира', saved.flat));
      $box.append($door);
    } else {
      var pointLabel = kind === 'poshtomat' ? (labels.locker || 'Поштомат') : (labels.point || 'Відділення');
      $box.append(field('np-own-point', pointLabel, saved.division_name));
    }
    var $city = $box.find('.np-own-city');
    if (saved.city_ref) $city.data('np-ref', saved.city_ref).data('np-label', saved.city_name || '');
    if (saved.settlement_ref) $city.data('np-settlement', saved.settlement_ref);
    var $point = $box.find('.np-own-point');
    if (saved.division_ref) $point.data('np-ref', saved.division_ref).data('np-id', saved.division_id || '').data('np-label', saved.division_name || '');
    var $street = $box.find('.np-own-street');
    if (saved.street_ref) $street.data('np-ref', saved.street_ref).data('np-label', saved.street_name || '');
    var $card = $radio.closest('label');
    if (!$card.length || $card.outerWidth() < 160) {
      $card = $radio.parent();
    }
    var $root = $card.parent();
    if ($root.length && $root[0] !== document.body) {
      $box.insertAfter($root);
    } else if ($card.length) {
      $box.insertAfter($card);
    } else {
      $radio.parent().after($box);
    }
    bind($box.find('.np-own-city'), 'city');
    bind($box.find('.np-own-point'), 'point');
    bind($box.find('.np-own-street'), 'street');
    bind($box.find('.np-own-building, .np-own-flat'), 'text');
  }

  function scan() {
    var c = cfg();
    if (!c) return;
    mount();
    bind(nodes(c.city, 'input[name="city"], input[name="shipping_address[city]"]').not('.np-own-city'), 'city');
    bind(nodes(c.point, 'input[name="address_1"], input[name="shipping_address[address_1]"]').not('.np-own-point'), 'point');
    bind(nodes(c.street).not('.np-own-street'), 'street');
    bind(nodes(c.building).not('.np-own-building'), 'text');
    bind(nodes(c.flat).not('.np-own-flat'), 'text');
  }

  var scanTimer;
  function schedule() {
    clearTimeout(scanTimer);
    scanTimer = setTimeout(scan, 80);
    setTimeout(scan, 400);
  }

  $(document).on('change', 'input[type="radio"]', function () {
    schedule();
  });

  $(document).on('click', function (e) {
    if (!$(e.target).closest('.np-list, input, textarea').length) {
      closeLists();
    }
  });

  $(document).on('ajaxComplete', function () {
    schedule();
  });

  if (window.MutationObserver && document.body) {
    var observer = new MutationObserver(function () {
      if (npRadio().length && !$('.np-checkout').filter(function () { return $.contains(document, this); }).length && !checkoutHasCity()) {
        schedule();
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  $(scan);
})(window.jQuery);
