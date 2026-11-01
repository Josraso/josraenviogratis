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
