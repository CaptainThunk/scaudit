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

if (!function_exists('json_last_error_msg')) {
    function json_last_error_msg() {
        static $errors = array(
            JSON_ERROR_NONE             => null,
            JSON_ERROR_DEPTH            => 'Maximum stack depth exceeded',
            JSON_ERROR_STATE_MISMATCH   => 'Underflow or the modes mismatch',
            JSON_ERROR_CTRL_CHAR        => 'Unexpected control character found',
            JSON_ERROR_SYNTAX           => 'Syntax error, malformed JSON',
            JSON_ERROR_UTF8             => 'Malformed UTF-8 characters, possibly incorrectly encoded'
        );
        $error = json_last_error();
        return array_key_exists($error, $errors) ? $errors[$error] : "Unknown error ({$error})";
    }
}

function open_json($scap_typeid) {

    $scap_name = getTypeNamebyTypeID($scap_typeid);
    $file = 'fittings/' . $scap_name . ".json";

    if (file_exists($file)) {
        $json = file_get_contents($file);
    } else {
        echo "could not open $file<br/>";
    }
    
    if (!$rv = json_decode($json, true)) {
        printf("JSON: could not decode %s: %s<br/>", $file, json_last_error_msg());
        return $rv;
    } else {
        return($rv);
    }
}

function addShipItem($typeid, $quantity) {
    global $scap_items;
    
    if (!array_key_exists($typeid, $scap_items)) {
        $scap_items[$typeid] = $quantity;
    } else {
        $scap_items[$typeid] += $quantity;
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

function getTypeIDSbyMktGroupID($mktgroupid){
    global $db;
    $typeids = [];
    $i = 0;

    $sql = "select typeID from invTypes where marketgroupID = :mgroupid";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':mgroupid', $mktgroupid);
    $result = $stmt->execute();
    
    while ($res = $result->fetchArray(SQLITE3_NUM)) {
        $typeids[$i] = $res[0];
        $i++;
    }
    
    return($typeids);
}

function displayShipItems($items) {
    
    echo "<pre>";
    foreach ($items as $typeid => $quantity) {
        printf("%d\t%s\n", $quantity, getTypeNamebyTypeID($typeid));
    }
    echo "</pre>";
}

function walkShip($super) {

    foreach ($super as $item) {

        // we're not interested in SMA contents, skip this one
        if ($item['flag'] == 90)
            continue;

        if (array_key_exists('contents', $item)) {
            walkShip($item['contents']);
        } else {
            if ($item['typeID'] && $item['quantity'])
                addShipItem($item['typeID'], $item['quantity']);
        }
    }
}

function walkAssets($assets) {
    global $super_caps;
    global $scap_type;

    foreach ($assets as $asset) {  

        if ((array_key_exists('name', $asset)) && ($asset['name'] == 'contents')) {
            walkAssets($asset['name']);
        } else {
            if (in_array($asset['typeID'], $super_caps)) {
                $scap_type = $asset['typeID'];
                walkShip(array($asset));
            }
        }        
    }
}

function auditShip($scap_type, $scap_items) {

    global $results;
    $p = 0;
    $f = 0;

    $scap_required = open_json($scap_type);

    foreach ($scap_required['items'] as $item) {

        // Initialize local vars to defaults
        $description = $nameContains = "";
        $groupID = $typeID = $minMeta = $minQty = 0;
        $storylineOK = $officerTrumpsAll = FALSE;
        $pass = FALSE;

        // Should always be defined
        $description = $item['description'];
        $minQty = $item['minQty'];

        // groupID OR typeID should always be defined
        if (array_key_exists('groupID', $item))
            $groupID = $item['groupID'];

        if (array_key_exists('typeID', $item))
            $typeID = $item['typeID'];

        // Optional parameters
        if (array_key_exists('minMeta', $item))
            $minMeta = $item['minMeta'];

        if (array_key_exists('nameContains', $item))
            $nameContains = $item['nameContains'];

        if (array_key_exists('storylineOK', $item))
            $storylineOK = $item['storylineOK'];

        if (array_key_exists('officerTrumpsAll', $item))
            $officerTrumpsAll = $item['officerTrumpsAll'];    

        // Let's see how bad this super pilot is
        
        // Items required by Type ID
        if ($typeID) {
            if (!array_key_exists($typeID, $scap_items)) {
                $item_name = getTypeNamebyTypeID($typeID);
                $results['fail']['typeid'][$typeID]['reason'] = "Not present. You need to have $minQty of $item_name";
                $f++;
                continue;
            }
            if ($scap_items[$typeID] < $minQty) {
                $item_name = getTypeNamebyTypeID($typeID);
                $results['fail']['typeid'][$typeID]['reason'] = "Not enough. You have $scap_items[$typeID] $item_name, You need $minQty ";
                $f++;
                continue;
            }
            $results['pass'][$p]['typeid'] = $typeID;
            $p++;
            continue;
        }
        
        // Items required by Group ID
        if ($groupID) {
            $group_items = getTypeIDSbyMktGroupID($groupID);

            foreach ($group_items as $item) {
                if ($f)
                    break;

                if (!array_key_exists($item, $scap_items)) {
                    $item_name = getTypeNamebyTypeID($item);
                    $results['fail']['groupid'][$groupID]['reason'] = "Not present. You need to have $minQty of $item_name";
                    $f++;
                    break;
                }
            }
            $results['pass'][$p]['groupid'] = $groupID;
            $p++;
        }
    }

    dump_shit($results);
}

try {

    $response = $pheal->AssetList(array("characterID" => $characterID));    

    walkAssets($response->assets);
    //ksort($scap_items);
    //displayShipItems($scap_items);

    auditShip($scap_type, $scap_items);

    $db->close();

} catch (\Pheal\Exceptions\PhealException $e) {
    echo sprintf("an exception was caught! Type: %s Message: %s",
                                                    get_class($e),
                                                    $e->getMessage());
}

?>
