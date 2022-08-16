<?php

error_reporting(1);

include 'functions.php';

//Handler Section
$handler = isset($_GET['oper']) ? $_GET['oper'] : "";

switch ($handler) {
  case 'tags':
    returnArtistTag();
    break;

  default:    
    $totalArtists = getTotalArtists();
    $sections = defineSections($totalArtists);

    ob_start();

    ?>

    <div class="panel panel-default">
      <div class="panel-heading">
        <h3 class="panel-title">
          <span class="glyphicon glyphicon-music" aria-hidden="true"></span>
          04 - Results
        </h3>
      </div>
      <div class="panel-body">
        <table class="table table-striped">
          <tbody>

            <?php

            $artists_general_list = array();
            foreach ($_REQUEST["tunning"] as $key => $value) :

              //Não serão feitas requisições para categorias não desejadas
              if($value <= 0 ) continue;

              $xml = getResults(getenv("LASTFM_PAGELIMIT"), rand($sections[$key][0], $sections[$key][1]));?>

              <?php
                $artists = $xml->artists->artist;
                $toKeep = randomGen(0, getenv("LASTFM_PAGELIMIT") -1, $value);
              ?>
              <?php foreach( $toKeep as $index ) : ?>
                <tr>
                  <?php $artistUrl = "tasker://".stripAccents($artists[$index]->name); ?>
                  <td><a href="<?=$artistUrl?>"><img src="<?php echo !empty(strval($artists[$index]->image[1])) ? strval($artists[$index]->image[1]) : $noimage ?>"></a></td>
                  <td>
                    <?php if(strlen($artists[$index]->name) > 0) : ?>
                      <a href="<?=$artistUrl?>" class="artistTitle"><?=$artists[$index]->name?></a><br/>
                      <span class="glyphicon glyphicon-fire" aria-hidden="true"></span>&nbsp;
                      <span style="font-size:15px;"><?=$artists[$index]->playcount?></span><br/>
                      <div class="tags-area" id="<?=$artists[$index]->mbid ?>"></div>
                    <?php endif ; ?>
                  </td>
                </tr>
              <?php endforeach; ?>

            <?php endforeach ; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php return ob_end_flush();

break;
}

?>