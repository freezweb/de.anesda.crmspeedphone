<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'include/Dashlets/Dashlet.php';

#[\AllowDynamicProperties]
final class CRMSpeedPhoneDashlet extends Dashlet
{
    public function __construct($id, $def = null)
    {
        parent::__construct($id);
        $this->title = 'CRM SpeedPhone';
        $this->isConfigurable = false;
        $this->isRefreshable = false;
    }

    public function display()
    {
        global $sugar_config, $current_user, $db;

        require_once 'custom/CRM/SpeedPhone/bootstrap.php';
        try {
            (new Anesda\CRM\SpeedPhone\UserAccessService($db, $current_user))->assertAllowed();
        } catch (Throwable) {
            return parent::display()
                . '<p style="padding:18px;text-align:center;">SpeedPhone ist für diesen Benutzer nicht freigeschaltet.</p>';
        }

        $siteUrl = preg_replace('~/legacy$~', '', rtrim((string) ($sugar_config['site_url'] ?? ''), '/'));
        $url = htmlspecialchars($siteUrl . '/#/prospects/speedphone', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $legacyUrl = rtrim((string) ($sugar_config['site_url'] ?? ''), '/');
        if (!str_ends_with($legacyUrl, '/legacy')) {
            $legacyUrl .= '/legacy';
        }
        if (empty($_SESSION['crm_speedphone_csrf'])) {
            $_SESSION['crm_speedphone_csrf'] = bin2hex(random_bytes(32));
        }
        $options = htmlspecialchars(json_encode([
            'api' => $legacyUrl . '/index.php?entryPoint=crmSpeedPhoneApi',
            'speedphone' => $siteUrl . '/#/prospects/speedphone',
            'csrf' => $_SESSION['crm_speedphone_csrf'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return parent::display()
            . '<div style="padding:22px;text-align:center;background:linear-gradient(135deg,#edf9f7,#eaf3f6);">'
            . '<div style="font-size:34px;line-height:1;margin-bottom:10px;">☎</div>'
            . '<strong style="display:block;font-size:18px;margin-bottom:7px;color:#17202a;">Telefonliste starten</strong>'
            . '<p style="margin:0 0 16px;color:#617080;">Nächsten freien Kontakt reservieren und direkt loslegen.</p>'
            . '<a target="_top" href="' . $url . '" style="display:inline-block;padding:11px 18px;border-radius:10px;background:#155e75;color:#fff;text-decoration:none;font-weight:700;">SpeedPhone starten</a>'
            . '</div>'
            . '<div data-speedphone-dashboard-incoming data-options="' . $options . '" hidden '
            . 'style="position:fixed;right:22px;bottom:22px;z-index:10050;width:min(430px,calc(100vw - 32px));max-height:70vh;overflow:auto;padding:18px;border:1px solid #b9e5db;border-radius:16px;background:#fff;box-shadow:0 18px 60px rgba(10,30,45,.3);font-family:Inter,system-ui,sans-serif;color:#17202a;">'
            . '<button type="button" data-speedphone-incoming-close style="float:right;border:0;background:transparent;font-size:26px;cursor:pointer;">×</button>'
            . '<strong style="display:block;color:#16734b;font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Eingehender Festnetzanruf</strong>'
            . '<h3 style="margin:4px 32px 4px 0;">Kontakt in SpeedPhone öffnen</h3>'
            . '<p data-speedphone-incoming-number style="margin:0 0 12px;color:#617080;"></p>'
            . '<div data-speedphone-incoming-matches style="display:grid;gap:8px;"></div>'
            . '</div>'
            . '<script>(function(){if(window.crmSpeedPhoneIncomingDashboard)return;window.crmSpeedPhoneIncomingDashboard=true;'
            . 'const box=document.querySelector("[data-speedphone-dashboard-incoming]");if(!box)return;'
            . 'const opt=JSON.parse(box.dataset.options);const number=box.querySelector("[data-speedphone-incoming-number]");'
            . 'const matches=box.querySelector("[data-speedphone-incoming-matches]");let current="";'
            . 'async function send(operation,extra){const data=new FormData();data.set("operation",operation);data.set("csrf",opt.csrf);Object.entries(extra||{}).forEach(([k,v])=>data.set(k,v));const response=await fetch(opt.api,{method:"POST",body:data,credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}});const payload=await response.json();if(!response.ok||!payload.success)throw new Error(payload.error||"Anfrage fehlgeschlagen");return payload.data;}'
            . 'function show(call){if(!call||!call.event_id){box.hidden=true;current="";return;}if(current===call.event_id&&!box.hidden)return;current=call.event_id;number.textContent="Anrufer: "+(call.caller_phone||"Nummer nicht übermittelt");matches.replaceChildren();(call.matches||[]).forEach(match=>{const button=document.createElement("button");button.type="button";button.style.cssText="padding:11px 12px;border:1px solid #d9e1e8;border-radius:10px;background:#fff;text-align:left;cursor:pointer";const title=document.createElement("strong");title.textContent=match.display_name||"Kontakt ohne Namen";const detail=document.createElement("small");detail.style.cssText="display:block;margin-top:3px;color:#155e75";detail.textContent=[match.city,match.match_label].filter(Boolean).join(" · ");button.append(title,detail);button.addEventListener("click",async()=>{button.disabled=true;try{await send("open_incoming_pbx",{event_id:call.event_id,prospect_id:match.prospect_id});window.top.location.href=opt.speedphone;}catch(error){button.disabled=false;window.alert(error.message);}});matches.append(button);});box.hidden=false;}'
            . 'async function poll(){try{const data=await send("incoming_pbx_status");show(data.incoming_call);}catch(_){}}'
            . 'box.querySelector("[data-speedphone-incoming-close]").addEventListener("click",async()=>{if(current)await send("dismiss_incoming_pbx",{event_id:current});box.hidden=true;current="";});'
            . 'poll();window.setInterval(poll,10000);})();</script>';
    }
}
