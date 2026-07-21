<?php

require_once 'include/MVC/View/SugarView.php';

final class ProspectsViewSpeedphone extends SugarView
{
    public function display(): void
    {
        if (!ACLController::checkAccess('Prospects', 'list', true)) {
            ACLController::displayNoAccess(true);
            return;
        }

        require 'custom/CRM/SpeedPhone/page.php';
    }
}
