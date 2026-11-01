/**
 * josraenviogratis — back-office
 *
 * @author    josra
 * @copyright josra
 */
(function ($) {
  'use strict';

  if (!$) {
    return;
  }

  var cfg = window.jegAdmin || {};

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /* ---------------- Listas dobles (swap) ---------------- */

  function moveOptions($from, $to) {
    $from.find('option:selected').each(function () {
      $(this).prop('selected', false).css('display', '').appendTo($to);
    });
  }

  function initSwap($form) {
    $form.find('.swap-container').each(function () {
      var $c = $(this);
      if ($c.data('jegSwap')) {
        return;
      }
      $c.data('jegSwap', true);
      var $available = $c.find('.availableSwap');
      var $selected = $c.find('.selectedSwap');

      var $filter = $('<input type="text" class="form-control jeg-swap-filter" autocomplete="off">')
        .attr('placeholder', cfg.txtSearch || '');
      $available.before($filter);
      $filter.on('input keyup', function () {
        var q = $.trim($(this).val()).toLowerCase();
        $available.find('option').each(function () {
          var show = !q || $(this).text().toLowerCase().indexOf(q) !== -1;
          $(this).css('display', show ? '' : 'none');
        });
      });

      $c.find('.addSwap').on('click', function (e) {
        e.preventDefault();
        moveOptions($available, $selected);
      });
      $c.find('.removeSwap').on('click', function (e) {
        e.preventDefault();
        moveOptions($selected, $available);
      });
      $available.on('dblclick', 'option', function () {
        $(this).prop('selected', true);
        moveOptions($available, $selected);
      });
      $selected.on('dblclick', 'option', function () {
        $(this).prop('selected', true);
        moveOptions($selected, $available);
      });
    });

    $form.on('submit', function () {
      $form.find('.selectedSwap option').prop('selected', true);
      $form.find('.availableSwap option').prop('selected', false);
    });
  }

  /* ---------------- Buscador de productos ---------------- */

  function addChip($picker, id, label) {
    var name = $picker.data('name');
    if ($picker.find('input[type=hidden][value="' + parseInt(id, 10) + '"]').length) {
      return;
    }
    var $chip = $(
      '<span class="jeg-chip label label-info">' + esc(label) +
      ' <a href="#" class="jeg-chip-del" title="' + esc(cfg.txtRemove || '') + '">&times;</a>' +
      '<input type="hidden" name="' + esc(name) + '[]" value="' + parseInt(id, 10) + '"></span>'
    );
    $picker.find('.jeg-chips').append($chip);
  }

  function initPicker($picker) {
    var $input = $picker.find('.jeg-psearch');
    var $results = $picker.find('.jeg-results');
    var timer = null;
    var lastQ = '';

    $picker.on('click', '.jeg-chip-del', function (e) {
      e.preventDefault();
      $(this).closest('.jeg-chip').remove();
    });

    $input.on('input', function () {
      var q = $.trim($input.val());
      clearTimeout(timer);
      if (q.length < 2 && !/^\d+$/.test(q)) {
        $results.empty();
        return;
      }
      timer = setTimeout(function () {
        lastQ = q;
        $.ajax({
          url: cfg.ajaxUrl,
          data: { q: q },
          dataType: 'json',
          cache: false
        }).done(function (data) {
          if (q !== lastQ) {
            return;
          }
          $results.empty();
          var list = (data && data.results) ? data.results : [];
          if (!list.length) {
            $results.append('<span class="list-group-item disabled">' + esc(cfg.txtNoResults || '') + '</span>');
            return;
          }
          $.each(list, function (i, item) {
            $('<a href="#" class="list-group-item"></a>')
              .text(item.label)
              .attr('data-id', item.id)
              .appendTo($results);
          });
        });
      }, 250);
    });

    // En PS 9 el clic cierra el desplegable antes de registrarse: se usa mousedown.
    $results.on('mousedown', '.list-group-item[data-id]', function (e) {
      e.preventDefault();
      addChip($picker, $(this).attr('data-id'), $(this).text());
      $(this).remove();
    });
    $results.on('click', 'a', function (e) {
      e.preventDefault();
    });

    $input.on('blur', function () {
      setTimeout(function () {
        $results.empty();
      }, 200);
    });
    $input.on('keydown', function (e) {
      if (e.keyCode === 13) {
        e.preventDefault();
      }
    });
  }

  /* ---------------- Pestañas del formulario de regla ---------------- */

  function paneCount($p) {
    var n = $p.find('.jeg-chip').length;
    n += $p.find('.selectedSwap option').length;
    $p.find('select[multiple]').not('.availableSwap, .selectedSwap').each(function () {
      n += $(this).find('option:selected').length;
    });
    $p.find('input[type=text]').not('.jeg-psearch, .jeg-swap-filter').each(function () {
      var v = $.trim($(this).val());
      if (v !== '' && v !== '0' && !/^0+([.,]0+)?$/.test(v)) {
        n++;
      }
    });
    $p.find('select[name=customer_type]').each(function () {
      if ($(this).val() !== '0') {
        n++;
      }
    });
    $p.find('input[type=radio][name=first_order]:checked').each(function () {
      if ($(this).val() === '1') {
        n++;
      }
    });
    return n;
  }

  function initTabs($wrap, $form) {
    var $panels = $form.children('.panel');
    if ($panels.length < 2) {
      return;
    }
    var key = 'jegRuleTab';
    var $box = $('<div class="panel jeg-form-box"></div>');
    var heading = $wrap.attr('data-heading') || '';
    $box.append($('<div class="panel-heading"></div>').append('<i class="icon-truck"></i> ').append(document.createTextNode(heading)));
    var $nav = $('<ul class="nav nav-tabs jeg-form-tabs"></ul>');
    $box.append($nav);
    var $footer = $panels.last().children('.panel-footer').detach();
    $panels.first().before($box);

    $panels.each(function (i) {
      var $p = $(this);
      var $h = $p.children('.panel-heading');
      var label = $.trim($h.text());
      var icon = $h.find('i').first().attr('class') || '';
      $h.remove();
      $p.removeClass('panel').addClass('jeg-pane').attr('data-jeg-pane', i);
      $box.append($p);
      var $a = $('<a href="#" class="nav-link"></a>').attr('data-jeg-pane', i);
      if (icon) {
        $a.append($('<i></i>').attr('class', icon)).append(' ');
      }
      $a.append(document.createTextNode(label));
      if (i > 0) {
        $a.append(' <span class="badge jeg-count"></span>');
      }
      $nav.append($('<li class="nav-item"></li>').append($a));
    });
    if ($footer.length) {
      $box.append($footer);
    }

    function show(i) {
      $box.children('.jeg-pane').hide().filter('[data-jeg-pane=' + i + ']').show();
      $nav.find('li').removeClass('active').find('a').removeClass('active');
      $nav.find('a[data-jeg-pane=' + i + ']').addClass('active').parent().addClass('active');
      try {
        window.sessionStorage.setItem(key, String(i));
      } catch (e) {
        // sin almacenamiento: no pasa nada
      }
    }

    function counts() {
      $box.children('.jeg-pane').each(function () {
        var i = $(this).attr('data-jeg-pane');
        if (i === '0') {
          return;
        }
        var n = paneCount($(this));
        $nav.find('a[data-jeg-pane=' + i + '] .jeg-count').text(n ? n : '').toggle(n > 0);
      });
    }

    $nav.on('click', 'a', function (e) {
      e.preventDefault();
      show($(this).attr('data-jeg-pane'));
    });
    $form.on('change input click', function () {
      setTimeout(counts, 0);
    });

    var start = 0;
    try {
      var saved = window.sessionStorage.getItem(key);
      if (saved !== null && $box.children('[data-jeg-pane=' + saved + ']').length && $wrap.closest('.josraenviogratis-admin').find('.alert-danger').length) {
        start = saved;
      }
    } catch (e) {
      start = 0;
    }
    show(start);
    counts();
  }

  /* ---------------- Efecto ---------------- */

  function initEffect($form) {
    var $effect = $form.find('select[name=effect]');
    var $group = $form.find('.jeg-effect-value').closest('.form-group');
    function toggle() {
      $group.toggle($effect.val() !== '0');
    }
    $effect.on('change', toggle);
    toggle();
  }

  $(function () {
    var $wrap = $('.jeg-rule-form');
    if ($wrap.length) {
      var $form = $wrap.find('form').first();
      initSwap($form);
      initEffect($form);
      initTabs($wrap, $form);
      $wrap.find('.jeg-picker').each(function () {
        initPicker($(this));
      });
      if ($.fn.datepicker) {
        $wrap.find('.jeg-datepicker').datepicker({
          dateFormat: 'dd/mm/yy',
          firstDay: 1,
          dayNamesMin: ['Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá'],
          monthNames: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
        });
      }
    }
  });
})(window.jQuery);
