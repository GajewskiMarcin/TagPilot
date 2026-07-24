{* TagPilot - GTM head snippet *}
{* Note: Google Consent Mode v2 defaults are emitted by ConsentFlow module
   (hookDisplayHeader, registered with higher priority so it runs before this). *}

<script>
(function(w,d,s,l,i){ldelim}w[l]=w[l]||[];w[l].push({ldelim}'gtm.start':
new Date().getTime(),event:'gtm.js'{rdelim});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
{rdelim})(window,document,'script','dataLayer','{$tp_gtm_id|escape:'javascript'}');
</script>

{if $tp_debug}
<script>console.log('[TagPilot] GTM loaded: {$tp_gtm_id|escape:'javascript'}');</script>
{/if}
