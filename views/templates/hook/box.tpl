{*
 * @author    josra
 * @copyright josra
 *}
{if $jeg_badge}
  <div class="jeg-badge">
    <svg class="jeg-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h11v9H3zM14 8h4l3 3v3h-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="7" cy="17" r="2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17" cy="17" r="2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
    <span>{$jeg_badge|escape:'html':'UTF-8'}</span>
  </div>
{/if}
{if $jeg_view}
  <div class="jeg-box jeg-mode-{$jeg_view.mode|escape:'html':'UTF-8'} jeg-pos-{$jeg_pos|escape:'html':'UTF-8'}">
    {if $jeg_view.mode == 'achieved'}
      <div class="jeg-achieved">
        <svg class="jeg-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>{$jeg_view.text|escape:'html':'UTF-8'}</span>
      </div>
    {elseif $jeg_view.mode == 'remaining'}
      <div class="jeg-line">
        <svg class="jeg-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h11v9H3zM14 8h4l3 3v3h-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="7" cy="17" r="2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17" cy="17" r="2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
        <span>{$jeg_view.html nofilter}</span>
      </div>
      {if $jeg_view.show_progress}
        <div class="jeg-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$jeg_view.progress|intval}">
          <div class="jeg-progress-bar" style="width: {$jeg_view.progress|intval}%"></div>
        </div>
      {/if}
      {if $jeg_view.title}
        <div class="jeg-rule-title">{$jeg_view.title|escape:'html':'UTF-8'}</div>
      {/if}
    {elseif $jeg_view.mode == 'unknown'}
      {foreach $jeg_view.titles as $t}
        <div class="jeg-line">
          <svg class="jeg-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h11v9H3zM14 8h4l3 3v3h-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="7" cy="17" r="2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17" cy="17" r="2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
          <span>{$t|escape:'html':'UTF-8'}</span>
        </div>
      {/foreach}
    {/if}
  </div>
{/if}
