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

  function method() {
    var $checked = $('input[name="shipping_method"]:checked');
    if ($checked.length && String($checked.val()).indexOf('novaposhta.') === 0) {
      return String($checked.val()).split('.')[1];
    }
    var c = cfg();
    return (c && c.method) || 'department';
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
    var $city = nodes(c.city, 'input[name="city"], input[name="shipping_address[city]"]').first();
    var $point = nodes(c.point, 'input[name="address_1"], input[name="shipping_address[address_1]"]').first();
    var $street = nodes(c.street).first();
    var $building = nodes(c.building).first();
    var $flat = nodes(c.flat).first();
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
          var cityRef = nodes(c.city, 'input[name="city"], input[name="shipping_address[city]"]').first().data('np-ref') || '';
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
          var settlement = nodes(c.city, 'input[name="city"], input[name="shipping_address[city]"]').first().data('np-settlement') || '';
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

  function scan() {
    var c = cfg();
    if (!c) return;
    bind(nodes(c.city, 'input[name="city"], input[name="shipping_address[city]"]'), 'city');
    bind(nodes(c.point, 'input[name="address_1"], input[name="shipping_address[address_1]"]'), 'point');
    bind(nodes(c.street), 'street');
    bind(nodes(c.building), 'text');
    bind(nodes(c.flat), 'text');
  }

  $(document).on('click', function (e) {
    if (!$(e.target).closest('.np-list, input, textarea').length) {
      closeLists();
    }
  });

  $(document).on('ajaxComplete', function (e, xhr, settings) {
    if (!settings || !settings.url) return;
    if (settings.url.indexOf('checkout') !== -1 || settings.url.indexOf('simplecheckout') !== -1) {
      scan();
    }
  });

  $(scan);
})(window.jQuery);
