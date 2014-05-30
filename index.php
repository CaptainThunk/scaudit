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
$db = new SQLite3(dirname($_SERVER['SCRIPT_FILENAME']) . "/" . $static_dump);

function dump_shit($shit) {
    echo "<pre>";
    print_r($shit);
    echo "</pre>"; 
}

function addScapItem($typeid, $quantity) {
    global $scap_items;
    
    if (!array_key_exists($typeid, $scap_items)) {
        printf("Adding %s (%s), Qty=%d<br/>", getTypeNamebyTypeID($typeid),
                                              getGroupNamebyTypeID($typeid),
                                              $quantity);
        $scap_items[$typeid] = $quantity;
    } else {
        $old_qty = $scap_items[$typeid];
        $scap_items[$typeid] += $quantity;
        printf("Updating quantity of %s [ Old=%d, New=%d ]<br/>",
                                            getTypeNamebyTypeID($typeid),
                                            $old_qty,
                                            $scap_items[$typeid]);
    }
}

function getTypeNamebyTypeID($typeid) {
    global $db;

    $sql = "select typeName from invTypes where typeID = :typeid";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':typeid', $typeid);
    $result = $stmt->execute();
    $rv = $result->fetchArray();

    return($rv[0]);
}

function getTypeIDbyTypeName($typename) {
    global $db;

    $sql = "select typeID from invTypes where typeName = :typename";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':typename', $typename);
    $result = $stmt->execute();
    $rv = $result->fetchArray();

    return($rv[0]);}

function getGroupNamebyTypeID($typeid) {
    global $db;
    
    $sql = "select groupName, (select groupID from invTypes where typeID = :typeid) as xGroupID from invGroups where groupID = xGroupID";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':typeid', $typeid);
    $result = $stmt->execute();
    $rv = $result->fetchArray();

    return($rv[0]);}

function getMetaLevelbyGroupID($groupid) {
    global $db;

    $sql = "select typeID, typeName, (select coalesce(valueInt,valueFloat) value from dgmTypeAttributes where attributeID=633 and invTypes.typeID = typeID) as MetaLevel from invTypes where groupID = :groupid order by MetaLevel";
    $query = $db->prepare($sql);
    $query->bindValue(':groupid', $groupid);
    $result = $query->execute();
    $rv = $result->fetchArray();
    
}

function displayScapItems($items) {
    
    echo "<pre>";
    foreach ($items as $typeid => $quantity) {
        printf("%d\t%s\n", $quantity, getTypeNamebyTypeID($typeid));
    }
    echo "</pre>";
    
}

function walkSuper($super) {

    foreach ($super as $item) {

        // we're not interested in SMA contents, skip this one
        if ($item['flag'] == 90)
            continue;

        if (array_key_exists('contents', $item)) {
            walkSuper($item['contents']);
        } else {
            if ($item['typeID'] && $item['quantity'])
                addScapItem($item['typeID'], $item['quantity']);
        }
    }
}

function walkAssets($assets) {
    global $super_caps;

    foreach ($assets as $asset) {  

        if ((array_key_exists('name', $asset)) && ($asset['name'] == 'contents')) {
            walkAssets($asset['name']);
        } else {
            if (in_array($asset['typeID'], $super_caps)) {
                printf("Super found, it's an %s (%s)<br/>", getTypeNamebyTypeID($asset['typeID']),
                                                            getGroupNamebyTypeID($asset['typeID']));
                walkSuper(array($asset));
            }
        }        
    }
}

try {

    $response = $pheal->AssetList(array("characterID" => $characterID));    
    $iterator = new RecursiveArrayIterator($response->assets);

    walkAssets($response->assets);
    
    ksort($scap_items);
    displayScapItems($scap_items);
    
    $db->close();

} catch (\Pheal\Exceptions\PhealException $e) {
    echo sprintf("an exception was caught! Type: %s Message: %s",
                                                    get_class($e),
                                                    $e->getMessage());
}

?>
