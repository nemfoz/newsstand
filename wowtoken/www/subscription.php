<?php

require_once(__DIR__.'/../../incl/incl.php');
require_once(__DIR__.'/../../incl/memcache.incl.php');

if ($_SERVER["REQUEST_METHOD"] != 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit();
}

function ReturnBadRequest() {
    header('HTTP/1.1 400 Bad Request');
    exit();
}

if (!isset($_POST['action'])) {
    ReturnBadRequest();
}

switch($_POST['action']) {
    case 'subscribe':
        ActSubscribe();
        break;
    case 'unsubscribe':
        ActUnsubscribe();
        break;
    case 'selection':
        ActSelection();
        break;
    case 'fetch':
        ActFetch();
        break;
    default:
        header('HTTP/1.1 400 Bad Request');
        exit();
}

header('Content-type: application/json; charset=UTF-8');

function ActSubscribe() {
    if (!isset($_POST['endpoint'])) {
        ReturnBadRequest();
    }

    $_POST['endpoint'] = substr($_POST['endpoint'], 0, 512);

    global $db;
    DBConnect();

    $oldId = 0;
    $allGood = true;

    $sql = 'select id from tblWowTokenSubs where endpoint=? limit 1';

    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $_POST['endpoint']);
    $stmt->execute();
    $stmt->bind_result($oldId);
    if (!$stmt->fetch()) {
        $oldId = 0;
    }
    $stmt->close();

    if ($oldId) {
        // already have this sub, I guess just update lastseen
        $sql = 'update tblWowTokenSubs set lastseen = now(), lastfail = null where id = ?';
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $oldId);
        $allGood &= $stmt->execute();
        $stmt->close();
    } else {
        if (isset($_POST['oldendpoint'])) {
            $_POST['oldendpoint'] = substr($_POST['oldendpoint'], 0, 512);

            $sql = 'select id from tblWowTokenSubs where endpoint=? limit 1';

            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $_POST['oldendpoint']);
            $stmt->execute();
            $stmt->bind_result($oldId);
            if (!$stmt->fetch()) {
                $oldId = 0;
            }
            $stmt->close();
        }

        if ($oldId) {
            $sql = 'update tblWowTokenSubs set endpoint=?, lastseen=now(), lastfail=null where id=?';
            $stmt = $db->prepare($sql);
            $stmt->bind_param('si', $_POST['endpoint'], $oldId);
            $allGood &= $stmt->execute();
            $stmt->close();
        } else {
            $sql = 'insert into tblWowTokenSubs (endpoint, firstseen, lastseen) values (?, now(), now())';
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $_POST['endpoint']);
            $allGood &= $stmt->execute();
            $stmt->close();
        }
    }

    if ($allGood) {
        $out = ['endpoint' => $_POST['endpoint']];

        if ($oldId) {
            $sql = 'SELECT concat(lower(region), \'-\', if(direction = 1, \'up\', \'dn\')) k, `value` FROM `tblWowTokenEvents` where subid = ?';
            $stmt = $db->prepare($sql);
            $stmt->bind_param('i', $oldId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $out[$row['k']] = $row['value'];
                }
                $result->close();
            }
            $stmt->close();
        }
        echo json_encode($out);
    } else {
        header('HTTP/1.1 500 Internal Server Error');
    }
}

function ActUnsubscribe() {
    if (!isset($_POST['endpoint'])) {
        ReturnBadRequest();
    }

    $_POST['endpoint'] = substr($_POST['endpoint'], 0, 512);

    global $db;
    DBConnect();

    $allGood = true;

    $sql = 'delete from tblWowTokenEvents where subid=(select id from tblWowTokenSubs where endpoint=?)';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $_POST['endpoint']);
    $allGood &= $stmt->execute();
    $stmt->close();

    $sql = 'delete from tblWowTokenSubs where endpoint=?';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $_POST['endpoint']);
    $allGood &= $stmt->execute();
    $stmt->close();

    if ($allGood) {
        echo json_encode(['endpoint' => $_POST['endpoint']]);
    } else {
        header('HTTP/1.1 500 Internal Server Error');
    }
}

function ActSelection() {
    $required = ['endpoint','dir','region','value'];
    foreach ($required as $v) {
        if (!isset($_POST[$v])) {
            ReturnBadRequest();
        }
        $_POST[$v] = substr($_POST[$v], 0, 512);
    }
    if (!in_array($_POST['dir'],['up','dn'])) {
        ReturnBadRequest();
    }
    if (!in_array($_POST['region'],['na','eu','cn'])) {
        ReturnBadRequest();
    }

    global $db;
    DBConnect();

    $allGood = true;
    $id = 0;

    $sql = 'select id from tblWowTokenSubs where endpoint=? limit 1';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $_POST['endpoint']);
    $stmt->execute();
    $stmt->bind_result($id);
    if (!$stmt->fetch()) {
        $id = 0;
        $allGood = false;
    }
    $stmt->close();

    if ($allGood && $id) {
        $sql = 'update tblWowTokenSubs set lastseen=now() where id = ?';
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $id);
        $allGood &= $stmt->execute();
        $stmt->close();
    }

    if ($allGood && $id) {
        $value = abs(intval($_POST['value'], 10));
        if ($value) {
            $sql = 'replace into tblWowTokenEvents (subid, region, direction, value, created) values (?, ?, ?, ?, now())';
            $stmt = $db->prepare($sql);
            $dir = ($_POST['dir'] == 'up') ? 'over' : 'under';
            $stmt->bind_param('issi', $id, $_POST['region'], $dir, $value);
            $allGood &= $stmt->execute();
            $stmt->close();
        } else {
            $sql = 'delete from tblWowTokenEvents where subid = ? and region = ? and direction = ?';
            $stmt = $db->prepare($sql);
            $dir = ($_POST['dir'] == 'up') ? 'over' : 'under';
            $stmt->bind_param('iss', $id, $_POST['region'], $dir);
            $allGood &= $stmt->execute();
            $stmt->close();
        }
    }

    if ($allGood) {
        echo json_encode(['name' => $_POST['region'].'-'.$_POST['dir'], 'value' => $value]);
    } else {
        header('HTTP/1.1 500 Internal Server Error');
    }
}

function ActFetch() {
    $required = ['endpoint'];
    foreach ($required as $v) {
        if (!isset($_POST[$v])) {
            ReturnBadRequest();
        }
        $_POST[$v] = substr($_POST[$v], 0, 512);
    }

    $key = 'tokennotify-'.md5($_POST['endpoint']);
    $msg = MCGet($key);
    if ($msg == false) {
        $msg = 'Couldn\'t find notification data, but something probably happened that you should check out at WoWToken.info.';
    } else {
        MCDelete($key);
    }

    echo json_encode([
            'title' => 'WoWToken.info',
            'notification' => [
                'body' => $msg,
                'tag' => 'wowtoken',
                'icon' => '/images/token-192x192.jpg'
            ],
        ]);
}