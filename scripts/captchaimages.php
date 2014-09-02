<?php

chdir(__DIR__);

define('CAPTCHA_DIR', '../public/captcha');

require_once('../incl/incl.php');
require_once('../incl/heartbeat.incl.php');

ini_set('memory_limit','512M');

RunMeNTimes(1);
CatchKill();

$region = 'us';

if (!DBConnect())
    DebugMessage('Cannot connect to db!', E_USER_ERROR);

$auctionJson = '';
$retries = 0;
while (!$auctionJson) {
    heartbeat();
    if ($caughtKill)
        exit;
    if ($retries++ > 10)
        DebugMessage("Quitting after $retries tries.", E_USER_ERROR);

    $sql = <<<EOF
SELECT slug, name, canonical
FROM `tblRealm` r
WHERE region=? and name not like '% %' and canonical is not null
order by (select count(*) from tblRealm r2 where r2.house = r.house) asc, rand() limit 1
EOF;

    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $region);
    $stmt->execute();
    $stmt->bind_result($slug, $realm, $canonical);
    $stmt->fetch();
    $stmt->close();

    DebugMessage("Fetching $region $slug");
    $url = sprintf('http://local.theunderminejournal.com/api/bnetapi.php?region=%s&path=%s', strtolower($region), rawurlencode("wow/auction/data/$slug"));

    $json = FetchHTTP($url);
    $dta = json_decode($json, true);
    if (!isset($dta['files']))
    {
        DebugMessage("$region $slug returned no files.", E_USER_WARNING);
        continue;
    }

    $url = $dta['files'][0]['url'];
    $auctionJson = FetchHTTP($url, [], $outHeaders);
    if (!$auctionJson)
    {
        heartbeat();
        DebugMessage("No data from $url, waiting 5 secs");
        http_persistent_handles_clean();
        sleep(5);
        $auctionJson = FetchHTTP($url, [], $outHeaders);
    }

    if (!$auctionJson)
    {
        heartbeat();
        DebugMessage("No data from $url, waiting 15 secs");
        http_persistent_handles_clean();
        sleep(15);
        $auctionJson = FetchHTTP($url, [], $outHeaders);
    }

    if ($auctionJson)
    {
        heartbeat();
        $auctionJson = json_decode($auctionJson, true);
        if (json_last_error() != JSON_ERROR_NONE)
            $auctionJson = false;
    }
}

heartbeat();
$factions = array('alliance', 'horde');

foreach ($factions as $faction)
{
    heartbeat();
    if ($caughtKill)
        exit;

    $hits = 0;
    $seenToons = array();
    $seenGuilds = array();
    $tries = 0;
    for ($x = 0; $x < count($auctionJson[$faction]['auctions']); $x++)
    {
        if ($tries > 30)
            break;
        if ($hits > 50)
            break;

        heartbeat();
        if ($caughtKill)
            exit;

        $auc = $auctionJson[$faction]['auctions'][$x];
        if ($auc['ownerRealm'] != $realm)
            continue;
        if (isset($seenToons[$auc['owner']]))
            continue;
        $seenToons[$auc['owner']] = true;

        $toon = $auc['owner'];

        $tries++;
        DebugMessage("Fetching $region $slug $toon");
        $url = sprintf('http://local.theunderminejournal.com/api/bnetapi.php?region=%s&path=%s', strtolower($region), rawurlencode("wow/character/$slug/$toon?fields=guild"));
        $json = json_decode(FetchHTTP($url), true);

        if (!isset($json['guild']))
            continue;

        $guild = $json['guild']['name'];

        $tries++;
        DebugMessage("Fetching $region $slug <$guild>");
        $url = sprintf('http://local.theunderminejournal.com/api/bnetapi.php?region=%s&path=%s', strtolower($region), rawurlencode("wow/guild/$slug/$guild?fields=members"));
        $json = json_decode(FetchHTTP($url), true);

        if (!isset($json['members']))
            continue;

        for ($y = 0; $y < count($json['members']); $y++)
        {
            heartbeat();
            if ($caughtKill)
                exit;

            $c = $json['members'][$y]['character'];
            if ($c['level'] < 20)
                continue;
            $toon = $c['name'];

            DebugMessage("Fetching $region $slug $toon of <$guild>");
            $url = sprintf('http://local.theunderminejournal.com/api/bnetapi.php?region=%s&path=%s', strtolower($region), rawurlencode("wow/character/$slug/$toon?fields=appearance"));
            $cjson = json_decode(FetchHTTP($url), true);

            if (!isset($cjson['appearance']))
                continue;

            $imgUrl = "http://$region.battle.net/static-render/$region/" . preg_replace('/-avatar\.jpg$/', '-inset.jpg', $cjson['thumbnail']);
            DebugMessage("Fetching $imgUrl");
            $img = FetchHTTP($imgUrl);

            if ($img != '')
            {
                $hits++;

                $id = MakeID();
                $helm = $cjson['appearance']['showHelm'] ? 1 : 0;
                DebugMessage("Saving $id as {$c['race']} {$c['gender']} $helm");

                file_put_contents(CAPTCHA_DIR.'/'.$id.'.jpg', $img);

                $sql = 'insert into tblCaptcha (id, race, gender, helm) values (?, ?, ?, ?)';
                $stmt = $db->prepare($sql);
                $stmt->bind_param('iiii', $id, $c['race'], $c['gender'], $helm);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

DebugMessage('Done!');

function MakeID()
{
    static $lastTime = 0, $lastIncrement = 0;
    if ($lastTime != time())
    {
        $lastTime = time();
        $lastIncrement = 0;
    }
    return (($lastTime << 8) + $lastIncrement++) & 0xFFFFFFFF;
}