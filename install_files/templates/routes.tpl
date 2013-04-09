<?php

/**
 * PHPR Router
 */

// Define backend URI
// 

$backend_url = (isset($CONFIG) && isset($CONFIG['BACKEND_URL'])) ? $CONFIG['BACKEND_URL'] : '/admin';

// Ensure backend URI does not start with a slash
if (substr($backend_url, 0, 1) == '/')
    $backend_url = substr($backend_url, 1);
        
// Admin routes
// 

$route = Phpr::$router->add_rule($backend_url."/:module/:controller/:action/:param1/:param2/:param3/:param4");
$route->folder('modules/:module/controllers');
$route->set_default('module', 'admin');
$route->set_default('controller', 'index');
$route->set_default('action', 'index');
$route->set_default('param1', null);
$route->set_default('param2', null);
$route->set_default('param3', null);
$route->set_default('param4', null);
$route->convert('controller', '/^.*$/', ':module_$0');

// Public routes
// 

$route = Phpr::$router->add_rule("/:param1/:param2/:param3/:param4/:param5/:param6");
$route->set_default('param1', null);
$route->set_default('param2', null);
$route->set_default('param3', null);
$route->set_default('param4', null);
$route->set_default('param5', null);
$route->set_default('param6', null);
$route->controller('application');
$route->action('index');
