<?php
require_once('script_setup.php'); //this is to set up interactions to the MVC
$controller = new Front($registry);
$action = new Action('webmention/queue/sender');
$controller->dispatch($action, new Action('error/not_found'));
?>
