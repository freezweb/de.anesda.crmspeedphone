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
        global $sugar_config;

        $siteUrl = preg_replace('~/legacy$~', '', rtrim((string) ($sugar_config['site_url'] ?? ''), '/'));
        $url = htmlspecialchars($siteUrl . '/#/prospects/speedphone', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return parent::display()
            . '<div style="padding:22px;text-align:center;background:linear-gradient(135deg,#edf9f7,#eaf3f6);">'
            . '<div style="font-size:34px;line-height:1;margin-bottom:10px;">☎</div>'
            . '<strong style="display:block;font-size:18px;margin-bottom:7px;color:#17202a;">Telefonliste starten</strong>'
            . '<p style="margin:0 0 16px;color:#617080;">Nächsten freien Kontakt reservieren und direkt loslegen.</p>'
            . '<a target="_top" href="' . $url . '" style="display:inline-block;padding:11px 18px;border-radius:10px;background:#155e75;color:#fff;text-decoration:none;font-weight:700;">SpeedPhone starten</a>'
            . '</div>';
    }
}
