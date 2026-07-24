<?php

require_once 'include/MVC/View/SugarView.php';

final class ProspectsViewSpeedphone extends SugarView
{
    public function display(): void
    {
        require_once 'custom/CRM/SpeedPhone/bootstrap.php';

        global $current_user, $db;

        try {
            (new Anesda\CRM\SpeedPhone\UserAccessService($db, $current_user))->assertAllowed();
        } catch (Throwable) {
            ACLController::displayNoAccess(true);
            return;
        }

        require 'custom/CRM/SpeedPhone/page.php';
    }
}
