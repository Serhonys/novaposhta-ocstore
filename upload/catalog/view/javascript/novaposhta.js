(function ($) {
  function cfg() {
    return window.NovaPoshtaConfig || null;
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var args = arguments;
      var self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, ms);
    };
  }

  function closeLists() {
    $('.np-list').remove();
  }

  function list(input, rows, onPick) {
    closeLists();
    if (!rows.length) {
      return;
    }
    var $list = $('<div class="np-list"></div>');
    $.each(rows, function (_, row) {
      var label = row.area ? (row.name + ', ' + row.area) : row.name;
      var $item = $('<button type="button" class="np-item"></button>').text(label);
      $item.on('mousedown', function (e) {
        e.preventDefault();
        onPick(row);
        closeLists();
      });
      $list.append($item);
    });
    $(input).after($list);
  }

  function ensureBox($city) {
    if ($city.data('np-ready')) {
      return;
    }
    var c = cfg();
    if (!c) {
      return;
    }
    $city.data('np-ready', 1);
    var $form = $city.closest('form');
    if (!$form.find('.np-box').length) {
      var html = ''
        + '<div class="np-box' + (c.compact ? ' np-compact' : '') + '">'
        + '<div class="np-types">'
        + '<button type="button" data-method="department">' + c.textBranch + '</button>'
        + '<button type="button" data-method="poshtomat">' + c.textLocker + '</button>'
        + '<button type="button" data-method="doors">' + c.textCourier + '</button>'
        + '</div>'
        + '<div class="np-point"><input type="text" class="form-control np-point-input" autocomplete="off" placeholder="' + c.textPoint + '" /></div>'
        + '<div class="np-door" style="display:none">'
        + '<input type="text" class="form-control np-street" autocomplete="off" placeholder="' + c.textStreet + '" />'
        + '<div class="np-door-row"><input type="text" class="form-control np-building" placeholder="' + c.textBuilding + '" /><input type="text" class="form-control np-flat" placeholder="' + c.textFlat + '" /></div>'
        + '</div>'
        + '<div class="np-tools"><button type="button" class="np-nearest">' + (c.textNearest || '') + '</button><button type="button" class="np-map">' + (c.textMap || '') + '</button></div>'
        + '</div>';
      $city.closest('.form-group').after('<div class="form-group"><div class="col-sm-12">' + html + '</div></div>');
    }
    setMethod($city, c.method || 'department');
    restore($city);
  }

  function box($city) {
    return $city.closest('form').find('.np-box').first();
  }

  function setMethod($city, method) {
    var $box = box($city);
    $box.find('.np-types button').removeClass('is-on');
    $box.find('[data-method="' + method + '"]').addClass('is-on');
    $box.data('method', method);
    if (method === 'doors') {
      $box.find('.np-point').hide();
      $box.find('.np-door').show();
    } else {
      $box.find('.np-door').hide();
      $box.find('.np-point').show();
      $box.find('.np-point-input').attr('placeholder', method === 'poshtomat' ? cfg().textLocker : cfg().textPoint);
    }
  }

  function payload($city) {
    var $box = box($city);
    return {
      method: $box.data('method') || 'department',
      city_ref: $city.data('ref') || '',
      city_name: $city.val() || '',
      division_ref: $box.data('division-ref') || '',
      division_id: $box.data('division-id') || '',
      division_name: $box.find('.np-point-input').val() || '',
      settlement_ref: $city.data('settlement') || '',
      delivery_city: $city.data('delivery') || '',
      street_ref: $box.data('street-ref') || '',
      street_name: $box.find('.np-street').val() || '',
      building: $box.find('.np-building').val() || '',
      flat: $box.find('.np-flat').val() || ''
    };
  }

  function save($city) {
    var c = cfg();
    if (!c) return;
    var data = payload($city);
    document.cookie = 'np_pick=' + encodeURIComponent(JSON.stringify(data)) + ';path=/;max-age=31536000;SameSite=Lax';
    fillCustom($city, data);
    $.ajax({
      url: c.saveUrl,
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(data)
    });
  }

  function restore($city) {
    var c = cfg();
    var saved = c && c.saved;
    if (!saved || !saved.city_ref || $city.data('np-restored')) return;
    $city.data('np-restored', 1);
    if (!$city.val()) {
      $city.val(saved.city_name || '');
    }
    $city.data('ref', saved.city_ref || '');
    $city.data('settlement', saved.settlement_ref || '');
    setMethod($city, saved.method || 'department');
    var $box = box($city);
    if (saved.division_name) {
      $box.find('.np-point-input').val(saved.division_name);
      $box.data('division-ref', saved.division_ref || '');
      $box.data('division-id', saved.division_id || '');
    }
    if (saved.street_name) {
      $box.find('.np-street').val(saved.street_name);
      $box.data('street-ref', saved.street_ref || '');
      $box.find('.np-building').val(saved.building || '');
      $box.find('.np-flat').val(saved.flat || '');
    }
  }

  function fillCustom($city, data) {
    var c = cfg();
    if (!c) return;
    var map = {};
    if (c.fieldCity) map[c.fieldCity] = data.city_name || '';
    if (c.fieldPoint) map[c.fieldPoint] = data.division_name || '';
    if (c.fieldStreet) map[c.fieldStreet] = data.street_name || '';
    if (c.fieldBuilding) map[c.fieldBuilding] = data.building || '';
    if (c.fieldFlat) map[c.fieldFlat] = data.flat || '';
    var $form = $city.closest('form');
    $.each(map, function (id, value) {
      if (!id || !value) return;
      var $input = $form.find('[name="custom_field[address][' + id + ']"], [name="custom_field[' + id + ']"]');
      if ($input.length) {
        $input.val(value).trigger('change');
      }
    });
  }

  function bindCity($city) {
    ensureBox($city);
    if ($city.data('np-bound')) return;
    $city.data('np-bound', 1);
    $city.attr('autocomplete', 'off');

    $city.on('input', debounce(function () {
      var c = cfg();
      var q = $.trim($city.val());
      $city.data('ref', '');
      if (!c || q.length < 2) {
        closeLists();
        return;
      }
      var method = box($city).data('method');
      var url = method === 'doors' ? c.settlementUrl : c.cityUrl;
      $.getJSON(url, { q: q }, function (rows) {
        list($city, rows || [], function (row) {
          if (method === 'doors') {
            $city.val(row.main || row.name);
            $city.data('settlement', row.ref);
            $city.data('delivery', row.delivery_city);
            $city.data('ref', row.delivery_city);
          } else {
            $city.val(row.name);
            $city.data('ref', row.ref);
          }
          save($city);
        });
      });
    }, 250));
  }

  function bindPoint($city) {
    var $input = box($city).find('.np-point-input');
    if ($input.data('np-bound')) return;
    $input.data('np-bound', 1);
    $input.on('input', debounce(function () {
      var c = cfg();
      var ref = $city.data('ref');
      if (!c || !ref) return;
      var locker = box($city).data('method') === 'poshtomat' ? 1 : 0;
      $.getJSON(c.warehouseUrl, { city_ref: ref, q: $input.val(), locker: locker }, function (rows) {
        list($input, rows || [], function (row) {
          $input.val(row.name);
          var $address = $city.closest('form').find('input[name="address_1"]');
          if ($address.length) {
            $address.val(row.name);
          }
          box($city).data('division-ref', row.ref);
          box($city).data('division-id', row.division_id || '');
          if (c.hoursUrl && row.ref) {
            $.getJSON(c.hoursUrl, { ref: row.ref }, function (json) {
              box($city).find('.np-hours').remove();
              if (json && json.hours) {
                $input.after('<div class="np-hours">' + json.hours + '</div>');
              }
            });
          }
          save($city);
        });
      });
    }, 250));
  }

  function bindStreet($city) {
    var $input = box($city).find('.np-street');
    if ($input.data('np-bound')) return;
    $input.data('np-bound', 1);
    $input.on('input', debounce(function () {
      var c = cfg();
      var settlement = $city.data('settlement');
      if (!c || !settlement) return;
      $.getJSON(c.streetUrl, { settlement_ref: settlement, q: $input.val() }, function (rows) {
        list($input, rows || [], function (row) {
          $input.val(row.name);
          box($city).data('street-ref', row.ref);
          var $address = $city.closest('form').find('input[name="address_1"]');
          if ($address.length) {
            $address.val(row.name);
          }
          save($city);
        });
      });
    }, 250));
    box($city).find('.np-building, .np-flat').on('change', function () {
      save($city);
    });
  }

  function scan() {
    var c = cfg();
    if (!c) return;
    $('input[name="city"]').each(function () {
      var $city = $(this);
      var country = $city.closest('form').find('select[name="country_id"]').val();
      if (c.countryId && country && String(country) !== String(c.countryId)) {
        return;
      }
      bindCity($city);
      bindPoint($city);
      bindStreet($city);
    });
  }

  $(document).on('click', '.np-nearest', function () {
    var c = cfg();
    var $city = $(this).closest('form').find('input[name="city"]').first();
    if (!c || !navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(function (pos) {
      var locker = box($city).data('method') === 'poshtomat' ? 1 : 0;
      $.getJSON(c.nearestUrl, {
        lat: pos.coords.latitude,
        lng: pos.coords.longitude,
        city_ref: $city.data('ref') || '',
        locker: locker
      }, function (rows) {
        if (!rows || !rows.length) return;
        var row = rows[0];
        if (row.city_name) {
          $city.val(row.city_name);
          $city.data('ref', row.city_ref);
        }
        var $input = box($city).find('.np-point-input');
        $input.val(row.name);
        box($city).data('division-ref', row.ref);
        box($city).data('division-id', row.division_id || '');
        var $address = $city.closest('form').find('input[name="address_1"]');
        if ($address.length) $address.val(row.name);
        save($city);
      });
    });
  });

  function loadLeaflet(done) {
    if (window.L) {
      done();
      return;
    }
    if (!document.getElementById('np-leaflet-css')) {
      var css = document.createElement('link');
      css.id = 'np-leaflet-css';
      css.rel = 'stylesheet';
      css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
      document.head.appendChild(css);
    }
    var script = document.createElement('script');
    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    script.onload = done;
    document.head.appendChild(script);
  }

  $(document).on('click', '.np-map', function () {
    var c = cfg();
    var $city = $(this).closest('form').find('input[name="city"]').first();
    var ref = $city.data('ref');
    if (!c || !ref) {
      window.alert(c && c.textMapNeed ? c.textMapNeed : 'City');
      return;
    }
    var locker = box($city).data('method') === 'poshtomat' ? 1 : 0;
    $.getJSON(c.mapUrl, { city_ref: ref, locker: locker }, function (rows) {
      if (!rows || !rows.length) return;
      var $overlay = $('<div class="np-overlay"><div class="np-map-box"><button type="button" class="np-map-close">×</button><div class="np-map-canvas"></div></div></div>');
      $('body').append($overlay);
      $overlay.find('.np-map-close').on('click', function () { $overlay.remove(); });
      loadLeaflet(function () {
        var map = L.map($overlay.find('.np-map-canvas')[0]).setView([rows[0].lat, rows[0].lng], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18 }).addTo(map);
        $.each(rows, function (_, row) {
          L.marker([row.lat, row.lng]).addTo(map).on('click', function () {
            var $input = box($city).find('.np-point-input');
            $input.val(row.name);
            box($city).data('division-ref', row.ref);
            box($city).data('division-id', row.division_id || '');
            var $address = $city.closest('form').find('input[name="address_1"]');
            if ($address.length) $address.val(row.name);
            save($city);
            $overlay.remove();
          });
        });
        setTimeout(function () { map.invalidateSize(); }, 100);
      });
    });
  });

  $(document).on('click', '.np-types button', function () {
    var $city = $(this).closest('form').find('input[name="city"]').first();
    setMethod($city, $(this).data('method'));
    save($city);
  });

  $(document).on('click', function (e) {
    if (!$(e.target).closest('.np-box, input[name="city"]').length) {
      closeLists();
    }
  });

  $(document).ajaxComplete(function (e, xhr, settings) {
    if (!settings || !settings.url) return;
    if (settings.url.indexOf('checkout/shipping_method') !== -1) {
      var c = cfg();
      var method = (cfg() && $('.np-box').data('method')) || (c && c.method);
      if (method) {
        var $radio = $('input[name="shipping_method"][value="novaposhta.' + method + '"]');
        if ($radio.length && !$radio.prop('checked')) {
          $radio.prop('checked', true).trigger('change');
        }
      }
    }
    scan();
  });

  $(scan);
})(window.jQuery);
