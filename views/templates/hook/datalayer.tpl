{* TagPilot - DataLayer push *}
<script>
(function() {ldelim}
    window.dataLayer = window.dataLayer || [];

    {* Clear ecommerce before pushing new data *}
    window.dataLayer.push({ldelim} ecommerce: null {rdelim});

    {* nofilter is required: the value is already a complete JSON document, so Smarty's HTML
       escaping would corrupt it. It is safe because tagpilot.php encodes it with
       JSON_INLINE_SCRIPT_FLAGS (JSON_HEX_TAG et al.), which escapes < and > and therefore makes
       it impossible for a value to close this <script> tag. Do not add JSON_UNESCAPED_SLASHES
       to that encode, and do not assign this variable from anywhere that skips those flags. *}
    var events = {$tp_datalayer_json nofilter};

    if (Array.isArray(events)) {ldelim}
        for (var i = 0; i < events.length; i++) {ldelim}
            window.dataLayer.push(events[i]);
            {if $tp_debug}
            console.log('[TagPilot] dataLayer.push:', events[i]);
            {/if}
        {rdelim}
    {rdelim} else {ldelim}
        window.dataLayer.push(events);
        {if $tp_debug}
        console.log('[TagPilot] dataLayer.push:', events);
        {/if}
    {rdelim}
{rdelim})();
</script>
