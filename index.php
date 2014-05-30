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
$db = new SQLite3(dirname($_SERVER['SCRIPT_FILENAME']) . '/static_dump/sqlite-latest.sqlite');

function dump_shit($shit) {
    echo "<pre>";
    print_r($shit);
    echo "</pre>"; 
}

function addScapItem($typeid, $quantity) {
    global $scap_items;
    
    if (!array_key_exists($typeid, $scap_items)) {
        echo "Adding " . getTypeNamebyID($typeid) . ", Qty=" . $quantity . "<br/>";
        $scap_items[$typeid] = $quantity;
    } else {
        $old_qty = $scap_items[$typeid];
        $scap_items[$typeid] += $quantity;
        printf("Updating quantity of %s [ Old=%d, New=%d ]<br/>",
                                            getTypeNamebyID($typeid),
                                            $old_qty,
                                            $scap_items[$typeid]);
    }
}

function getTypeNamebyID($typeid) {
    global $db;

    $sql = "select typeName from invTypes where typeID = $typeid";
    return($db->querySingle($sql));
}

function getTypeIDbyName($typename) {
    global $db;

    $sql = "select typeID from invTypes where typeName = $typename";
    return($db->querySingle($sql));
}

function getGroupNamebyGroupID($groupid) {
    
}

function getMetaLevelbyGroupID($groupid) {
    global $db;

    $sql = "select typeID, typeName, (select coalesce(valueInt,valueFloat) value from dgmTypeAttributes where attributeID=633 and invTypes.typeID = typeID) as MetaLevel from invTypes where groupID = :groupid order by MetaLevel";
    $query = $db->prepare($sql);
    $query->bindValue(':groupid', $groupid);
    $result = $query->execute();
    
}

function traverseAssets($iterator) {
    global $scap_items;
    $typeid = NULL;
    $quantity = NULL;


    $super_caps = [ 
    	3514,    // Revenant
    	3628,    // Nation
    	22852,   // Hel
        23913,   // Nyx
        23917,   // Wyvern
    	23919,   // Aeon
    	671,     // Erebus
    	3764,    // Leviathan
    	11567,   // Avatar
    	23773    // Ragnarok
    ];

    while ($iterator->valid()) {        
        if ($iterator->hasChildren()) {
            $typeid = NULL;
            $quantity = NULL;
            traverseAssets($iterator->getChildren());
        } else {
            $key = $iterator->key();
            $value = $iterator->current();
            if (($key == 'typeID') && (in_array($value, $super_caps))) {
                $scap_name = getTypeNamebyID($value);
                echo "Super found, it's an " . $scap_name . "<br/>";
                $iterator->next();
                continue;
            }

            // we're not interested in SMA contents, skip this element
            if (($key == 'flag') && ($value == 90)) {
                echo "found a item in an SMA, skipping...<br/>";
                $iterator->next();
                continue;
            }
            
            if ($key == 'typeID') {
                // echo "Found a " . getTypeNamebyID($value) . "<br/>";
                $typeid = $value;
            }
            if ($key == 'quantity') {
                $quantity = $value;
            }
         
            if (isset($typeid) && isset($quantity)) {
                addScapItem($typeid, $quantity);
                break;
            }
            
        }        
        $iterator->next(); 
    }
}

try {

    $response = $pheal->AssetList(array("characterID" => $characterID));    
    $iterator = new RecursiveArrayIterator($response->assets[0]);
    iterator_apply($iterator, 'traverseAssets', array($iterator));

    //dump_shit($response);
    $db->close();
    
    ksort($scap_items);
    dump_shit($scap_items);

} catch (\Pheal\Exceptions\PhealException $e) {
    echo sprintf("an exception was caught! Type: %s Message: %s",
                                                    get_class($e),
                                                    $e->getMessage());
}

?>
