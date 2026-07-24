{* TagPilot - DataLayer push *}
<script>
(function() {ldelim}
    window.dataLayer = window.dataLayer || [];

    {* Clear ecommerce before pushing new data *}
    window.dataLayer.push({ldelim} ecommerce: null {rdelim});

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
