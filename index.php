<?php
require_once 'vendor/autoload.php';
require 'include/settings.php';

use Pheal\Pheal;
use Pheal\Core\Config;

error_reporting(E_ALL);
ini_set('display_errors', true);
ini_set('html_errors', true);


Config::getInstance()->cache = new \Pheal\Cache\FileStorage('/tmp/phealcache/');
Config::getInstance()->access = new \Pheal\Access\StaticCheck();

$pheal = new Pheal($keyID, $vCode, "char");
$dbh = new SQLite3(dirname($_SERVER['SCRIPT_FILENAME']) . '/static_dump/sqlite-latest.sqlite');

function dump_shit($shit) {
    echo "<pre>";
    print_r($shit);
    echo "</pre>"; 
}

function getTypeNamebyID($typeid) {
    $sql = "select typeName from invTypes where typeID = :typeid";
    $query = $db->prepare($sql);
    $query = $db->bindValue(':typeid', $typeid);
    
    return($statement->execute());
}

function getTypeIDbyName($typename) {
    $sql = "select typeID from invTypes where typeName = :typename";
    $query = $db->prepare($sql);
    $query = $db->bindValue(':typename', $typename);
    
    return($statement->execute());
}

function getGroupNamebyGroupID($groupid) {
    
}

function getMetaLevelbyGroupID($groupid) {
    $sql = "select typeID, typeName, (select coalesce(valueInt,valueFloat) value from dgmTypeAttributes where attributeID=633 and invTypes.typeID = typeID) as MetaLevel from invTypes where groupID = :groupid order by MetaLevel";
    $query = $db->prepare($sql);
    $query = $db->bindValue(':groupid', $groupid);
    
    return($statement->execute());
}

function traverseSuper($ship) {
    
    //dump_shit($ship);
    
    foreach ($ship->contents as $key => $value) {
                    
        // we're not interested in SMA contents
        if (($key == 'flag') && ($value == 90))
            continue;

    }
}

function traverseAssets($iterator) {
    $super_caps = [ 
    	"Revenant"  => 3514,
    	"Nation"    => 3628,
    	"Hel"       => 22852,
        "Nyx"       => 23913,
        "Wyvern"    => 23917,
    	"Aeon"      => 23919,
    	"Erebus"    => 671,
    	"Leviathan" => 3764,
    	"Avatar"    => 11567,
    	"Ragnarok"  => 23773
    ];

    while ($iterator->valid()) {
        if ($iterator->hasChildren()) {
            echo "going deeper...<br/>";
            traverseAssets($iterator->getChildren());
        } else {
            $key = $iterator->key();
            $value = $iterator->current();
            if (($key == 'typeID') && (in_array($value, $super_caps))) {
                $scap_name = getTypeNamebyID($value);
                echo "Super found, it's an " . $scap_name . "<br/>";
                traverseSuper(array($iterator->getArrayCopy()));
                break;
            }
        }
        $iterator->next(); 
    }
    echo "<br/>";
}

try {

    $response = $pheal->AssetList(array("characterID" => $characterID));    
    $iterator = new RecursiveArrayIterator($response->assets[0]);
    iterator_apply($iterator, 'traverseAssets', array($iterator));

    //dump_shit($response);

} catch (\Pheal\Exceptions\PhealException $e) {
    echo sprintf("an exception was caught! Type: %s Message: %s",
                                                    get_class($e),
                                                    $e->getMessage());
}
