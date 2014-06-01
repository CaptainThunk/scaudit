<?php
require_once 'vendor/autoload.php';
require 'include/settings.php';
require 'include/html_table.class.php';

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

function getMetaLevelbyTypeID($typeid) {
    global $db;

    $sql = "select coalesce(valueInt,valueFloat) value from dgmTypeAttributes where attributeID=633 and typeID = :typeid";
    $query = $db->prepare($sql);
    $query->bindValue(':typeid', $typeid);
    $result = $query->execute();
    $rv = $result->fetchArray();
    
    return($rv[0]);
}

function getTypeIDSbyMktGroupID($mktgroupid) {
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

function getTypeNameSbyMktGroupID($mktgroupid) {
    global $db;
    $typenames = [];
    $i = 0;

    $sql = "select typeName from invTypes where marketgroupID = :mgroupid";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':mgroupid', $mktgroupid);
    $result = $stmt->execute();
    
    while ($res = $result->fetchArray(SQLITE3_NUM)) {
        $typenames[$i] = $res[0];
        $i++;
    }
    
    return($typenames);
}

function printResults() {
    global $results;

    $tbl = new HTML_Table('', 'resultsTbl');
    $tbl->addCaption('SC-Legion Audit Results', 'cap', array('id'=> 'tblCap') );
    
    $tbl->addRow();
    $tbl->addCell('You Passed On:', '', 'header');
    
    foreach ($results['pass'] as $result) {
        $tbl->addRow();
        $tbl->addCell($result['description']);
    }

    $tbl->addRow();
    $tbl->addCell('&nbsp');

    $tbl->addRow();
    $tbl->addCell('You Failed On:', '', 'header');
    
    foreach ($results['fail'] as $result) {
        $tbl->addRow();
        $tbl->addCell($result['reason']);
    }
    
    echo "<HTML><BODY BGCOLOR=\"#ddd\">";
    echo $tbl->display();
    echo "</BODY></HTML>";
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

    foreach ($scap_required['items'] as $required_item) {

        // Initialize local vars to defaults
        $description = $nameContains = "";
        $groupID = $typeID = $minMeta = $minQty = 0;
        $storylineOK = $officerTrumpsAll = FALSE;
        $pass = FALSE;

        // Should always be defined
        $description = $required_item['description'];
        $minQty = $required_item['minQty'];

        // groupID OR typeID should always be defined
        if (array_key_exists('groupID', $required_item))
            $groupID = $required_item['groupID'];

        if (array_key_exists('typeID', $required_item))
            $typeID = $required_item['typeID'];

        // Optional parameters
        if (array_key_exists('minMeta', $required_item))
            $minMeta = $required_item['minMeta'];

        if (array_key_exists('nameContains', $required_item))
            $nameContains = $required_item['nameContains'];

        if (array_key_exists('storylineOK', $required_item))
            $storylineOK = $required_item['storylineOK'];

        if (array_key_exists('officerTrumpsAll', $required_item))
            $officerTrumpsAll = $required_item['officerTrumpsAll'];    

        // Let's see how bad this super pilot is

        // Items required by Type ID
        if ($typeID) {
            if (!array_key_exists($typeID, $scap_items)) {
                $item_name = getTypeNamebyTypeID($typeID);
                $results['fail'][$f]['typeid'] = $typeID;
                $results['fail'][$f]['description'] = $description;
                $results['fail'][$f]['reason'] = "You do not have at least $minQty $item_name";
                $f++;
                continue;
            }
            if ($scap_items[$typeID] < $minQty) {
                $item_name = getTypeNamebyTypeID($typeID);
                $results['fail'][$f]['typeid'] = $typeID;
                $results['fail'][$f]['description'] = $description;
                $results['fail'][$f]['reason'] = "You do not have at least $minQty $item_name";
                $f++;
                continue;
            }
            $results['pass'][$p]['typeid'] = $typeID;
            $results['pass'][$p]['description'] = $description;
            $results['pass'][$p]['quantity'] = $scap_items[$typeID];
            $p++;
            continue;
        }

        // Items required by Group ID
        if ($groupID) {
            $group_qty = 0;
            $group_items = getTypeIDSbyMktGroupID($groupID);

            foreach ($group_items as $group_item) {

                if (array_key_exists($group_item, $scap_items)) {
                    $group_item_meta = getMetaLevelbyTypeID($group_item);

                    if ($group_item_meta >= $minMeta) {

                        if ($nameContains) {
                            $pattern = "/" . $nameContains . "/";
                            $item_name = getTypeNamebyTypeID($group_item);

                            if (preg_match($pattern, $item_name)) {
                                $group_qty += $scap_items[$group_item];
                                continue;
                            }
                        }

                        if (($officerTrumpsAll) && ($group_item_meta >= 11)) {
                            $group_qty += $scap_items[$group_item];
                            continue;
                        }

                        if (($storylineOK) && ($group_item_meta = 6)) {
                            $group_qty += $scap_items[$group_item];
                            continue;
                        }

                        $group_qty += $scap_items[$group_item];
                    }
                }
            }

            if ($group_qty < $minQty) {

                $failmsg = "You do not have at least " . $minQty .
                           " of " . $description . 
                           " which need to be Meta " . $minMeta . " or better.";

                if ($storylineOK)
                    $failmsg = $failmsg . " The Storyline version is OK";

                $results['fail'][$f]['groupid'] = $groupID;
                $results['fail'][$f]['description'] = $description;
                $results['fail'][$f]['reason'] = $failmsg;
                $f++;
                continue;
            } else {
                $results['pass'][$p]['groupid'] = $groupID;
                $results['pass'][$p]['description'] = $description;
                $results['pass'][$p]['quantity'] = $group_qty;
                $p++;
                continue;
            }
            
        }
    }
}

try {

    $response = $pheal->AssetList(array("characterID" => $characterID));    

    walkAssets($response->assets);
    auditShip($scap_type, $scap_items);
    
    printResults();

    $db->close();

} catch (\Pheal\Exceptions\PhealException $e) {
    echo sprintf("an exception was caught! Type: %s Message: %s",
                                                    get_class($e),
                                                    $e->getMessage());
}

?>
