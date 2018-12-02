<?php
/**
 * Process/send clinical reminders.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2012-2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */


require_once(dirname(__FILE__) . "/../../interface/globals.php");
require_once(dirname(__FILE__) . "/../reminders.php");

if (!verifyCsrfToken($_POST["csrf_token_form"])) {
    csrfNotVerified();
}

//To improve performance and not freeze the session when running this
// report, turn off session writing. Note that php session variables
// can not be modified after the line below. So, if need to do any php
// session work in the future, then will need to remove this line.
session_write_close();

//Remove time limit, since script can take many minutes
set_time_limit(0);

// Set the "nice" level of the process for these reports. When the "nice" level
// is increased, these cpu intensive reports will have less affect on the performance
// of other server activities, albeit it may negatively impact the performance
// of this report (note this is only applicable for linux).
if (!empty($GLOBALS['pat_rem_clin_nice'])) {
    proc_nice($GLOBALS['pat_rem_clin_nice']);
}

//  Start a report, which will be stored in the report_results sql table..
if ((!empty($_POST['execute_report_id']) && !empty($_POST['process_type'])) && (($_POST['process_type'] == "process"  ) || ($_POST['process_type'] == "process_send"))) {
    if ($_POST['process_type'] == "process_send") {
        update_reminders_batch_method('', '', $_POST['execute_report_id'], true);
    } else { // $_POST['process_type'] == "process"
        update_reminders_batch_method('', '', $_POST['execute_report_id']);
    }
} else {
    echo "ERROR";
}
