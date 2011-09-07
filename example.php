<?php

error_reporting(E_ALL|E_STRICT);
ini_set('display_errors', 1);
define('DS', DIRECTORY_SEPARATOR);
define('ROOT', dirname(__FILE__));

require_once ROOT . DS . 'NudityFilter.class.php';

$nf = new NudityFilter();
$sample = ROOT . DS . 'sample' . DS . 'girl_sf2pe7o7.gif';
if ($nf->check($sample)) {
    //echo 'nude picture!';
} else {
    //echo 'no nude..';
}
?>
