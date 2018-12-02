<?php
/**
 * track_anything_fragment.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Joe Slam <joe@produnis.de>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2014 Joe Slam <joe@produnis.de>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */


require_once("../../globals.php");

if (!verifyCsrfToken($_POST["csrf_token_form"])) {
    csrfNotVerified();
}

?>
<div id='labdata' style='margin-top: 3px; margin-left: 10px; margin-right: 10px'><!--outer div-->
<br>
<?php
//retrieve tracks.
$spell = "SELECT form_name, MAX(form_track_anything_results.track_timestamp) as maxdate, form_id " .
            "FROM forms " .
            "JOIN form_track_anything_results ON forms.form_id = form_track_anything_results.track_anything_id " .
            "WHERE forms.pid = ? " .
            "AND formdir = ? " .
            "GROUP BY form_name " .
            "ORDER BY maxdate DESC ";
$result = sqlQuery($spell, array($pid, 'track_anything'));
if (!$result) { //If there are no disclosures recorded
    ?>
  <span class='text'> <?php echo xlt("No tracks have been documented.");
?>
  </span>
<?php
} else {  // We have some tracks here...
    echo "<span class='text'>";
    echo xlt('Available Tracks') . ":";
    echo text($result);
    echo "<ul>";
    $result = sqlStatement($spell, array($pid, 'track_anything'));
    while ($myrow = sqlFetchArray($result)) {
        $formname = $myrow['form_name'];
        $thedate = $myrow['maxdate'];
        $formid = $myrow['form_id'];
        echo "<li><a href='../../forms/track_anything/history.php?formid=" . attr_url($formid) . "'>" . text($formname) . "</a></li> (" . text($thedate) . ")</li>";
    }

    echo "</ul>";
    echo "</span>";
} ?>
<br />
<br />
</div>
