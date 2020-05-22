<?php

/* 
    get a $xml with the amount of artists requested in $limit parameter
    $page of the lastfm library
*/
function getResults($limit = 1, $page = "")
{

    $method = "library.getArtists";


    //global $baseUrl, $apiKey, $user, $xml;

    $params = parse_ini_file("params.ini");

    $url = $params["baseUrl"] . "api_key=" . $params["apiKey"] . "&method=$method&user=" . $params["user"] . "&limit=$limit";
    $url .= !empty($page) ? "&page=$page" : "";
    $results = file_get_contents($url);
    error_log($url);
    error_log($params);
    error_log($results);

    $xml = new SimpleXMLElement($results);

    return $xml;
}

/**
 * 
 */
function getArtistTags($mbid = '')
{
    //global $baseUrl, $apiKey;
    $params = parse_ini_file("params.ini");

    if (strlen($mbid) > 1) {
        $method = "artist.getInfo";
        $url = $params["baseUrl"] . "api_key=" . $params["apiKey"] . "&method=" . $method . "&mbid=$mbid";
        $results = file_get_contents($url);
        $xml = new SimpleXMLElement($results);
        $artistInfo = array("tags" => $xml->artist->tags, "summary" => $xml->artist->bio->summary);
    } else {
        $artistInfo = null;
    }

    return $artistInfo;
}

function returnArtistTag()
{
    ob_start();

    $mbid = $_REQUEST["mbid"];

?>
    <span style="font-size:11px; line-height:normal">
        <?php $artistInfo = getArtistTags($mbid);
        foreach ($artistInfo["tags"]->tag as $tag) : ?>
            <a href="<?= $tag->url ?>"><?= $tag->name ?></a>
            &middot;
        <?php endforeach; ?>
    </span>

<?php
    return ob_end_flush();
}

function getTotalArtists()
{
    $xml = getResults();
    $totalArtists = $xml->artists["total"];
    return $totalArtists;
}

function randomGen($min, $max, $quantity)
{
    $numbers = range($min, $max);
    shuffle($numbers);
    return array_slice($numbers, 0, $quantity);
}

function stripAccents($str)
{
    return strtr(utf8_decode($str), utf8_decode('àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ'), 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');
}

function defineSections($totalArtists)
{

    $sectionsPercentual = array(
        "classics" => 0.05,
        "known" => 0.15,
        "adventurous" => 0.25,
        "wild" => 0.25,
        "insane" => 0.30
    );

    $params = parse_ini_file("params.ini");

    $pageLimit = $params["pageLimit"];

    $sectionFim = 1;
    $sectionIni = 1;

    $sections = array(
        "classics" => array(),
        "known" => array(),
        "adventurous" => array(),
        "wild" => array(),
        "insane" => array()
    );

    foreach ($sections as $key => &$value) {
        $increment = floor($totalArtists * $sectionsPercentual[$key] / $pageLimit);
        $sectionFim += $increment;
        $value = array($sectionIni, $sectionFim);
        $sectionIni = $sectionFim + 1;
    }

    return $sections;
}
