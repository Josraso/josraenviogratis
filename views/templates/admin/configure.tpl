{*
 * Los textos de este archivo vienen de $this->l(), que ya los devuelve escapados; los datos
 * variables se escapan en PHP antes de llegar aquí.
 *
 * @author    josra
 * @copyright josra
 *}
<div class="josraenviogratis-admin">
  {if $jeg_confirm}
    <div class="alert alert-success">{$jeg_confirm nofilter}</div>
  {/if}
  {if $jeg_errors}
    <div class="alert alert-danger">
      <ul class="list-unstyled">
        {foreach $jeg_errors as $err}
          <li>{$err nofilter}</li>
        {/foreach}
      </ul>
    </div>
  {/if}
  {foreach $jeg_alerts as $alert}
    <div class="alert alert-{$alert.type|escape:'html':'UTF-8'}">{$alert.text nofilter}</div>
  {/foreach}

  <ul class="nav nav-tabs jeg-tabs">
    {foreach $jeg_tabs as $t}
      <li class="nav-item{if $t.key == $jeg_active_tab} active{/if}">
        <a class="nav-link{if $t.key == $jeg_active_tab} active{/if}" href="{$t.url|escape:'html':'UTF-8'}">{$t.label nofilter}</a>
      </li>
    {/foreach}
  </ul>

  <div class="jeg-tab-content">
    {$jeg_content nofilter}
  </div>

  <p class="help-block jeg-engine"><i class="icon-info-circle"></i> {$jeg_engine_label nofilter}</p>
</div>
