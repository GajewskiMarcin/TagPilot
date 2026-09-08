{* TagPilot - Consent Mode v2 defaults + GTM head snippet *}

{if $tp_consent_json}
{* MUST come before gtm.js loads: gtag ignores a consent default that arrives after the first
   consent-requiring command. That ordering is why this lives in the same template as the GTM
   snippet instead of a separate hook.

   Empty, and therefore skipped, when Consent Mode is switched off or when a CMP module owns the
   defaults -- see tagpilot::getConsentDefaults(). A CMP is still required either way: this only
   establishes the safe starting state. Collecting the visitor's choice and calling
   gtag('consent', 'update', ...) is the cookie banner's job. *}
<script>
window.dataLayer = window.dataLayer || [];
window.gtag = window.gtag || function(){ldelim}dataLayer.push(arguments);{rdelim};
if (!window.__tagPilotConsentDefaults) {ldelim}
    window.__tagPilotConsentDefaults = true;
    window.gtag('consent', 'default', {$tp_consent_json nofilter});
{rdelim}
</script>
{/if}

<script>
(function(w,d,s,l,i){ldelim}w[l]=w[l]||[];w[l].push({ldelim}'gtm.start':
new Date().getTime(),event:'gtm.js'{rdelim});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
{rdelim})(window,document,'script','dataLayer','{$tp_gtm_id|escape:'javascript'}');
</script>

{if $tp_debug}
<script>
console.log('[TagPilot] GTM loaded: {$tp_gtm_id|escape:'javascript'}');
{if $tp_consent_json}
console.log('[TagPilot] Consent Mode v2 defaults set by TagPilot:', {$tp_consent_json nofilter});
{else}
console.log('[TagPilot] Consent Mode defaults NOT set by TagPilot (switched off, or a CMP module owns them)');
{/if}
</script>
{/if}
