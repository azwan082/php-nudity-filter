<?php

error_reporting(E_ALL|E_STRICT);
ini_set('display_errors', 1);
define('DS', DIRECTORY_SEPARATOR);
define('ROOT', dirname(__FILE__));

require_once ROOT . DS . 'NudityFilter.class.php';

$nf = new NudityFilter();
$sample = array(
    ROOT . DS . 'sample' . DS . '1.jpg',
    ROOT . DS . 'sample' . DS . '2.jpg',
    ROOT . DS . 'sample' . DS . '3.jpg',
    ROOT . DS . 'sample' . DS . '4.jpg',
    ROOT . DS . 'sample' . DS . 'girl_sf2pe7o7.gif'
);
foreach ($sample as $sp) {
    echo '<p>'. basename($sp) .' - '. (($nf->check($sp)) ? 'nude picture!' : 'no nude..') . '</p>';
}
?>
