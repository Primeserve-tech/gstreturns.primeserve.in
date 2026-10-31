<?php
use CodeIgniter\Router\RouteCollection;
/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->get('/', 'ReturnsPortal::index');
$routes->get('health', 'ReturnsPortal::health');
$routes->get('api/state', 'ReturnsPortal::state');
$routes->post('api/otp', 'ReturnsPortal::otp');
$routes->post('api/connect', 'ReturnsPortal::connect');
$routes->post('api/disconnect', 'ReturnsPortal::disconnect');
$routes->post('api/batches', 'ReturnsPortal::batch');
$routes->post('api/jobs/(:segment)/retry', 'ReturnsPortal::retry/$1');
$routes->post('api/jobs/(:segment)/download', 'ReturnsPortal::download/$1');
$routes->post('api/download-all', 'ReturnsPortal::downloadAll');
$routes->post('api/downloads/ack', 'ReturnsPortal::acknowledgeDownload');

$routes->get('admin', 'ReturnAdmin::login');
$routes->post('admin/login', 'ReturnAdmin::authenticate');
$routes->get('admin/reports', 'ReturnAdmin::reports');
$routes->get('admin/api/activity', 'ReturnAdmin::data');
$routes->post('admin/logout', 'ReturnAdmin::logout');
$routes->post('admin/api/activity/(:segment)/key', 'ReturnAdmin::revealKey/$1');
$routes->post('admin/export', 'ReturnAdmin::export');
