<?php

require_once('../../incl/incl.php');
require_once('../../incl/memcache.incl.php');
require_once('../../incl/api.incl.php');

if (!isset($_GET['house']) || !isset($_GET['item']))
    json_return(array());

$house = intval($_GET['house'], 10);
$item = intval($_GET['item'], 10);

if (!$item)
    json_return(array());

$json = array(
    'stats'     => ItemStats($house, $item),
    'history'   => ItemHistory($house, $item),
    'daily'     => ItemHistoryDaily($house, $item),
    'monthly'   => ItemHistoryMonthly($house, $item),
    'auctions'  => ItemAuctions($house, $item),
);

$ak = array_keys($json);
foreach ($ak as $k)
    if (count($json[$k]) == 0)
        unset($json[$k]);

json_return($json);

function ItemStats($house, $item)
{
    global $db;

    if (($tr = MCGetHouse($house, 'item_stats_'.$item)) !== false)
        return $tr;

    DBConnect();

    $sql = <<<EOF
select i.id, i.name, i.quality, i.icon, i.class as classid, i.subclass, i.quality, i.level, i.stacksize, i.binds, i.buyfromvendor, i.selltovendor, i.auctionable,
s.price, s.quantity, s.lastseen
from tblItem i
left join tblItemSummary s on s.house = ? and s.item = i.id
where i.id = ?
EOF;

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $house, $item);
    $stmt->execute();
    $result = $stmt->get_result();
    $tr = DBMapArray($result, null);
    if (count($tr))
        $tr = $tr[0];
    $stmt->close();

    MCSetHouse($house, 'item_stats_'.$item, $tr);

    return $tr;
}

function ItemHistory($house, $item)
{
    global $db;

    if (($tr = MCGetHouse($house, 'item_history_'.$item)) !== false)
        return $tr;

    DBConnect();

    $historyDays = HISTORY_DAYS;

    $sql = <<<EOF
select unix_timestamp(s.updated) snapshot, cast(if(quantity is null, @price, @price := price) as decimal(11,0)) `price`, ifnull(quantity,0) as quantity
from (select @price := null) priceSetup, tblSnapshot s
left join tblItemHistory ih on s.updated = ih.snapshot and ih.house=? and ih.item=?
where s.house = ? and s.updated >= timestampadd(day,-$historyDays,now())
order by s.updated asc
EOF;

    $stmt = $db->prepare($sql);
    $realHouse = abs($house);
    $stmt->bind_param('iii', $house, $item, $realHouse);
    $stmt->execute();
    $result = $stmt->get_result();
    $tr = DBMapArray($result, null);
    $stmt->close();

    while(count($tr) > 0 && is_null($tr[0]['price']))
        array_shift($tr);

    MCSetHouse($house, 'item_history_'.$item, $tr);

    return $tr;
}

function ItemHistoryDaily($house, $item)
{
    global $db;

    if (($tr = MCGet('item_historydaily_'.$house.'_'.$item)) !== false)
        return $tr;

    DBConnect();

    $sql = <<<EOF
select `when` as `date`,
`pricemin` as `silvermin`, `priceavg` as `silveravg`, `pricemax` as `silvermax`,
`pricestart` as `silverstart`, `priceend` as `silverend`,
`quantitymin`, `quantityavg`, `quantitymax`, round(`presence`/255*100,1) as `presence`
from tblItemHistoryDaily
where house = ? and item = ?
order by `when` asc
EOF;

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $house, $item);
    $stmt->execute();
    $result = $stmt->get_result();
    $tr = DBMapArray($result, null);
    $stmt->close();

    MCSet('item_historydaily_'.$house.'_'.$item, $tr, 60*60*8);

    return $tr;
}

function ItemHistoryMonthly($house, $item)
{
    global $db;

    if (($tr = MCGet('item_historymonthly_'.$house.'_'.$item)) !== false)
        return $tr;

    DBConnect();

    $sql = <<<EOF
select *
from tblItemHistoryMonthly
where house = ? and item = ?
order by `month` asc
EOF;

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $house, $item);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = DBMapArray($result, null);
    $stmt->close();

    $tr = array();
    $prevPrice = 0;
    for($x = 0; $x < count($rows); $x++)
    {
        $year = 2014 + floor(($rows[$x]['month']-1) / 12);
        $monthNum = $rows[$x]['month'] % 12;
        $month = ($monthNum < 10 ? '0' : '') . $monthNum;
        for ($dayNum = 1; $dayNum <= 31; $dayNum++)
        {
            $day = ($dayNum < 10 ? '0' : '') . $dayNum;
            if (!is_null($rows[$x]['mktslvr'.$day]))
            {
                $tr[] = array('date' => "$year-$month-$day", 'silver' => $rows[$x]['mktslvr'.$day], 'quantity' => $rows[$x]['qty'.$day]);
                $prevPrice = $rows[$x]['mktslvr'.$day];
            }
            elseif (checkdate($monthNum, $dayNum, $year) && $prevPrice)
                $tr[] = array('date' => "$year-$month-$day", 'silver' => $prevPrice, 'quantity' => 0);
        }
    }

    MCSet('item_historymonthly_'.$house.'_'.$item, $tr, 60*60*8);

    return $tr;
}

function ItemAuctions($house, $item)
{
    global $db;

    if (($tr = MCGetHouse($house, 'item_auctions_'.$item)) !== false)
        return $tr;

    DBConnect();

    $sql = <<<EOF
SELECT quantity, bid, buy, `rand`, seed, s.realm sellerrealm, s.name sellername
FROM `tblAuction` a
left join tblSeller s on a.seller=s.id
WHERE a.house=? and a.item=?
EOF;
    // order by buy/quantity, bid/quantity, quantity, s.name, a.id


    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $house, $item);
    $stmt->execute();
    $result = $stmt->get_result();
    $tr = DBMapArray($result, null);
    $stmt->close();

    MCSetHouse($house, 'item_auctions_'.$item, $tr);

    return $tr;
}